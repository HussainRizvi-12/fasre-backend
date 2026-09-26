<?php

namespace App\Http\Controllers\Faculty;

use App\Enums\AuditAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditAssignment;
use App\Models\AuditEvidenceFile;
use App\Models\AuditProvenanceLog;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Evidence upload/download pipeline for faculty peer audits.
 *
 *  - POST /api/faculty/audits/{id}/evidence     (multipart upload with EXIF sanitization & quota validation)
 *  - GET  /api/faculty/audits/{id}/evidence     (list attachments)
 *  - GET  /api/faculty/evidence/{file}/download (authorized stream with compatible Response signature)
 *  - DELETE /api/faculty/evidence/{file}        (authorized removal during drafting)
 */
class AuditEvidenceController extends Controller
{
    private const MAX_FILE_BYTES = 20 * 1024 * 1024; // 20 MB individual file limit
    private const MAX_AUDIT_TOTAL_BYTES = 50 * 1024 * 1024; // 50 MB total quota per audit
    private const MAX_AUDIT_FILES = 10; // Max 10 evidence files per observation

    public function upload(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:20480'],
            'question_id' => ['nullable', 'integer'],
        ]);

        $audit = AuditAssignment::with(['formVersion', 'evidenceFiles'])->findOrFail($id);

        if ($audit->auditor_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden. You are not the assigned auditor.'], 403);
        }

        if (! $audit->isEditableByAuditor()) {
            throw ValidationException::withMessages([
                'audit' => "Cannot attach evidence. This audit is in '{$audit->status->value}' status and is finalized.",
            ]);
        }

        // Quota check: file count
        if ($audit->evidenceFiles->count() >= self::MAX_AUDIT_FILES) {
            throw ValidationException::withMessages([
                'file' => 'Maximum evidence file limit reached (10 files per observation).',
            ]);
        }

        $file = $request->file('file');
        $fileSize = (int) $file->getSize();

        // Quota check: total bytes
        $currentTotalBytes = (int) $audit->evidenceFiles->sum('size_bytes');
        if (($currentTotalBytes + $fileSize) > self::MAX_AUDIT_TOTAL_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'Cumulative evidence quota exceeded (max 50 MB per observation).',
            ]);
        }

        // Question membership validation
        $questionId = $request->filled('question_id') ? (int) $request->input('question_id') : null;
        if ($questionId !== null && $audit->formVersion) {
            $validQIds = collect($audit->formVersion->getQuestions())->pluck('id')->all();
            if (! in_array($questionId, $validQIds, true) && ! in_array((string) $questionId, $validQIds, true)) {
                throw ValidationException::withMessages([
                    'question_id' => 'The specified question does not belong to this audit form version.',
                ]);
            }
        }

        $uploaded = DB::transaction(function () use ($file, $audit, $questionId, $fileSize) {
            $extension = strtolower($file->getClientOriginalExtension());
            $hashed = Str::uuid()->toString() . '.' . $extension;
            $relativeDir = "audit-evidence/{$audit->id}";

            // Strip EXIF metadata from photos to minimize classroom facial/GPS exposure
            $this->stripImageMetadata($file->getRealPath(), $extension);

            $storedPath = Storage::disk('local')->putFileAs($relativeDir, $file, $hashed);

            return AuditEvidenceFile::create([
                'audit_assignment_id' => $audit->id,
                'question_id' => $questionId,
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $fileSize,
            ]);
        });

        ActivityLogger::log($audit, 'audit.evidence_uploaded', [
            'file' => $uploaded->original_name,
            'size' => $uploaded->size_bytes,
        ]);

        AuditProvenanceLog::record(
            $audit,
            'evidence_uploaded',
            $request->user(),
            "Uploaded evidence attachment '{$uploaded->original_name}' ({$uploaded->size_bytes} bytes).",
            null,
            ['file_id' => $uploaded->id, 'filename' => $uploaded->original_name, 'size_bytes' => $uploaded->size_bytes]
        );

        return response()->json([
            'message' => 'Evidence uploaded successfully.',
            'data' => $this->transform($uploaded),
        ], 201);
    }

    public function index(Request $request, int $id): JsonResponse
    {
        $audit = AuditAssignment::with(['evidenceFiles', 'section.course', 'auditee'])->findOrFail($id);

        $user = $request->user();
        $isAuditor = $audit->auditor_id === $user->id;
        $isAuditee = $audit->auditee_id === $user->id;
        $auditDeptId = $audit->section?->course?->department_id ?? $audit->auditee?->department_id;
        $isAdmin = $user->isAdmin() && $user->canAccessDepartment($auditDeptId);

        $isPostApproval = in_array($audit->status, [
            AuditAssignmentStatus::Approved,
            AuditAssignmentStatus::FacultyResponded,
            AuditAssignmentStatus::ActionPlanActive,
            AuditAssignmentStatus::Closed,
        ], true);

        if (! $isAuditor && ! $isAdmin && ! ($isAuditee && $isPostApproval)) {
            return response()->json(['message' => 'Forbidden. Access restricted.'], 403);
        }

        return response()->json([
            'data' => $audit->evidenceFiles->map(fn ($f) => $this->transform($f)),
        ]);
    }

    /**
     * Authorized streaming download of evidence attachments.
     * Uses Response return type to cleanly accommodate both StreamedResponse and error JsonResponse.
     */
    public function download(Request $request, int $fileId): Response
    {
        $file = AuditEvidenceFile::with(['auditAssignment.section.course', 'auditAssignment.auditee'])->findOrFail($fileId);
        $audit = $file->auditAssignment;

        $user = $request->user();
        $isAuditor = $audit && $audit->auditor_id === $user->id;
        $isAuditee = $audit && $audit->auditee_id === $user->id;
        $auditDeptId = $audit?->section?->course?->department_id ?? $audit?->auditee?->department_id;
        $isAdmin = $user->isAdmin() && $user->canAccessDepartment($auditDeptId);
        $isPostApproval = $audit && in_array($audit->status, [
            AuditAssignmentStatus::Approved,
            AuditAssignmentStatus::FacultyResponded,
            AuditAssignmentStatus::ActionPlanActive,
            AuditAssignmentStatus::Closed,
        ], true);

        if (! $isAuditor && ! $isAdmin && ! ($isAuditee && $isPostApproval)) {
            return response()->json(['message' => 'Forbidden. Access restricted.'], 403);
        }

        if (! $audit || ! Storage::disk('local')->exists($file->stored_path)) {
            return response()->json(['message' => 'Evidence file is missing from storage.'], 404);
        }

        return Storage::disk('local')->download($file->stored_path, $file->original_name);
    }

    /**
     * Removes EXIF metadata from uploaded images using GD to safeguard privacy.
     */
    private function stripImageMetadata(string $path, string $extension): void
    {
        if (! in_array($extension, ['jpg', 'jpeg', 'png'], true) || ! file_exists($path)) {
            return;
        }

        try {
            if (in_array($extension, ['jpg', 'jpeg'], true) && function_exists('imagecreatefromjpeg')) {
                $image = @imagecreatefromjpeg($path);
                if ($image !== false) {
                    imagejpeg($image, $path, 90);
                    imagedestroy($image);
                }
            } elseif ($extension === 'png' && function_exists('imagecreatefrompng')) {
                $image = @imagecreatefrompng($path);
                if ($image !== false) {
                    imagepng($image, $path, 6);
                    imagedestroy($image);
                }
            }
        } catch (\Throwable) {
            // Silently preserve file if stripping fails
        }
    }

    private function transform(AuditEvidenceFile $f): array
    {
        return [
            'id' => $f->id,
            'question_id' => $f->question_id,
            'original_name' => $f->original_name,
            'mime_type' => $f->mime_type,
            'size_bytes' => $f->size_bytes,
            'uploaded_at' => $f->created_at?->toIso8601String(),
        ];
    }
}

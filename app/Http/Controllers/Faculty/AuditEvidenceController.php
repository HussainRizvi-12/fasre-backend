<?php

namespace App\Http\Controllers\Faculty;

use App\Enums\AuditAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditAssignment;
use App\Models\AuditEvidenceFile;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Evidence upload/download pipeline for faculty peer audits.
 *
 *  - POST /api/faculty/audits/{id}/evidence   (multipart upload)
 *  - GET  /api/faculty/audits/{id}/evidence   (list attachments)
 *  - GET  /api/faculty/evidence/{file}/download (authorized stream)
 *
 * Files are validated server-side (mime + size), stored with generated
 * names (path-traversal safe), and only reachable by the auditor, the
 * auditee (once approved), and admins.
 */
class AuditEvidenceController extends Controller
{
    private const MAX_BYTES = 20 * 1024 * 1024; // 20 MB — mirrors client-side EvidenceRules

    public function upload(Request $request, int $id): JsonResponse
    {
        $request->validate([
            // Client-side picker already restricts to jpg/png/pdf; the
            // backend is the authoritative validator.
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:20480'],
            'question_id' => ['nullable', 'integer', 'exists:questions,id'],
        ]);

        $audit = AuditAssignment::findOrFail($id);

        if ($audit->auditor_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden. You are not the assigned auditor.'], 403);
        }

        // Evidence capture is part of drafting: once submitted/approved the
        // audit is immutable and no new files may be attached.
        if (in_array($audit->status, [AuditAssignmentStatus::Submitted, AuditAssignmentStatus::Approved], true)) {
            throw ValidationException::withMessages([
                'audit' => 'Cannot attach evidence. This audit has already been submitted and is finalized.',
            ]);
        }

        $uploaded = DB::transaction(function () use ($request, $audit) {
            $file = $request->file('file');

            // Hashed, server-generated name — never trust client filenames
            // on disk. Original name is preserved in the DB for display.
            $hashed = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension());
            $storedPath = $file->storeAs(
                "audit-evidence/{$audit->id}",
                $hashed,
                'local'
            );

            return AuditEvidenceFile::create([
                'audit_assignment_id' => $audit->id,
                'question_id' => $request->filled('question_id') ? (int) $request->input('question_id') : null,
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
            ]);
        });

        ActivityLogger::log($audit, 'audit.evidence_uploaded', [
            'file' => $uploaded->original_name,
            'size' => $uploaded->size_bytes,
        ]);

        return response()->json([
            'message' => 'Evidence uploaded successfully.',
            'data' => $this->transform($uploaded),
        ], 201);
    }

    public function index(Request $request, int $id): JsonResponse
    {
        $audit = AuditAssignment::with('evidenceFiles')->findOrFail($id);

        $userId = $request->user()->id;
        $isAuditor = $audit->auditor_id === $userId;
        $isAuditee = $audit->auditee_id === $userId;
        $isAdmin = $request->user()->isAdmin();

        if (! $isAuditor && ! $isAdmin && ! ($isAuditee && $audit->status === AuditAssignmentStatus::Approved)) {
            return response()->json(['message' => 'Forbidden. Access restricted.'], 403);
        }

        return response()->json([
            'data' => $audit->evidenceFiles->map(fn ($f) => $this->transform($f)),
        ]);
    }

    public function download(Request $request, int $fileId): StreamedResponse
    {
        $file = AuditEvidenceFile::with('auditAssignment')->findOrFail($fileId);
        $audit = $file->auditAssignment;

        $userId = $request->user()->id;
        $isAuditor = $audit && $audit->auditor_id === $userId;
        $isAuditee = $audit && $audit->auditee_id === $userId;
        $isAdmin = $request->user()->isAdmin();

        if (! $isAuditor && ! $isAdmin && ! ($isAuditee && $audit && $audit->status === AuditAssignmentStatus::Approved)) {
            return response()->json(['message' => 'Forbidden. Access restricted.'], 403);
        }

        if (! $audit || ! Storage::disk('local')->exists($file->stored_path)) {
            return response()->json(['message' => 'Evidence file is missing from storage.'], 404);
        }

        return Storage::disk('local')->download($file->stored_path, $file->original_name);
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

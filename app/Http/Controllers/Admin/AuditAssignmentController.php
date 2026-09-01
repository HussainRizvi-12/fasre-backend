<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAuditAssignmentRequest;
use App\Models\AuditAssignment;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuditAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditAssignment::with(['auditor', 'auditee', 'section.course', 'assignedByUser']);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('auditor_id')) {
            $query->where('auditor_id', $request->query('auditor_id'));
        }

        if ($request->has('auditee_id')) {
            $query->where('auditee_id', $request->query('auditee_id'));
        }

        return response()->json([
            'data' => $query->orderByDesc('created_at')->get()->map(fn ($a) => $this->transform($a)),
            'message' => 'Audit assignments retrieved successfully.',
        ]);
    }

    public function store(StoreAuditAssignmentRequest $request): JsonResponse
    {
        $assignment = AuditAssignment::create([
            'auditor_id' => (int) $request->input('auditor_id'),
            'auditee_id' => (int) $request->input('auditee_id'),
            'section_id' => $request->filled('section_id') ? (int) $request->input('section_id') : null,
            'assigned_by' => $request->user()->id,
            'status' => AuditAssignmentStatus::Assigned,
            'due_date' => $request->filled('due_date') ? $request->input('due_date') : null,
        ]);

        ActivityLogger::log($assignment, 'audit_assignment.created', [
            'auditor' => $assignment->auditor?->name,
            'auditee' => $assignment->auditee?->name,
        ]);

        $dueText = $assignment->due_date ? " by {$assignment->due_date->toFormattedDateString()}" : '';
        NotificationService::send(
            $assignment->auditor,
            'audit',
            'New peer audit assigned',
            "You have been assigned to audit {$assignment->auditee?->name}'s class{$dueText}. Open the Assigned Audits tab to begin.",
            ['audit_assignment_id' => $assignment->id, 'route' => '/'],
        );
        NotificationService::send(
            $assignment->auditee,
            'audit',
            'Peer audit scheduled',
            "{$assignment->auditor?->name} will observe your class{$dueText}. No action is needed from you right now.",
            ['audit_assignment_id' => $assignment->id],
        );

        return response()->json([
            'data' => $this->transform($assignment->load(['auditor', 'auditee', 'section.course'])),
            'message' => 'Audit assignment created successfully.',
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $assignment = AuditAssignment::with(['auditor', 'auditee', 'section.course', 'assignedByUser'])->find($id);

        if (! $assignment) {
            return response()->json(['message' => 'Audit assignment not found.'], 404);
        }

        return response()->json([
            'data' => $this->transform($assignment, true),
            'message' => 'Audit assignment retrieved successfully.',
        ]);
    }

    /**
     * POST /api/admin/audit-assignments/{id}/approve
     * Admin decision on a submitted audit: approve (rendered final).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $assignment = AuditAssignment::findOrFail($id);

        if ($assignment->status !== AuditAssignmentStatus::Submitted) {
            throw ValidationException::withMessages([
                'status' => "Cannot approve: audit must be in 'submitted' status. Current status: {$assignment->status->value}.",
            ]);
        }

        $assignment->update([
            'status' => AuditAssignmentStatus::Approved,
            'admin_remarks' => $request->input('admin_remarks', $assignment->admin_remarks),
            'approved_at' => now(),
        ]);

        ActivityLogger::log($assignment, 'audit_assignment.approved', [
            'auditee' => $assignment->auditee?->name,
            'score' => $assignment->total_score,
        ]);

        NotificationService::send(
            $assignment->auditee,
            'audit',
            'Peer audit report approved',
            "Your peer audit report ({$assignment->total_score}/100) has been approved and finalized. View it in the My Reports tab.",
            ['audit_assignment_id' => $assignment->id, 'route' => '/reports'],
        );
        NotificationService::send(
            $assignment->auditor,
            'audit',
            'Audit approved by admin',
            "Your audit of {$assignment->auditee?->name} has been approved by the Quality Assurance office.",
            ['audit_assignment_id' => $assignment->id],
        );

        return response()->json([
            'data' => $this->transform($assignment->load(['auditor', 'auditee', 'section.course'])),
            'message' => 'Audit approved successfully.',
        ]);
    }

    /**
     * POST /api/admin/audit-assignments/{id}/reject
     * Admin decision on a submitted audit: reject (sent back to auditor).
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $assignment = AuditAssignment::findOrFail($id);

        if ($assignment->status !== AuditAssignmentStatus::Submitted) {
            throw ValidationException::withMessages([
                'status' => "Cannot reject: audit must be in 'submitted' status. Current status: {$assignment->status->value}.",
            ]);
        }

        $request->validate([
            'admin_remarks' => ['required', 'string'],
        ]);

        $assignment->update([
            'status' => AuditAssignmentStatus::Rejected,
            'admin_remarks' => $request->input('admin_remarks'),
            'rejected_at' => now(),
        ]);

        ActivityLogger::log($assignment, 'audit_assignment.rejected', [
            'auditor' => $assignment->auditor?->name,
            'remarks' => $request->input('admin_remarks'),
        ]);

        NotificationService::send(
            $assignment->auditor,
            'audit',
            'Audit sent back for revision',
            "Your audit of {$assignment->auditee?->name} was sent back with remarks: \"{$request->input('admin_remarks')}\" Please revise and resubmit.",
            ['audit_assignment_id' => $assignment->id, 'route' => '/'],
        );

        return response()->json([
            'data' => $this->transform($assignment->load(['auditor', 'auditee', 'section.course'])),
            'message' => 'Audit rejected and sent back to the auditor.',
        ]);
    }

    private function transform(AuditAssignment $a, bool $withAnswers = false): array
    {
        $payload = [
            'id' => $a->id,
            'auditor' => [
                'id' => $a->auditor?->id,
                'name' => $a->auditor?->name,
                'email' => $a->auditor?->email,
            ],
            'auditee' => [
                'id' => $a->auditee?->id,
                'name' => $a->auditee?->name,
                'email' => $a->auditee?->email,
            ],
            'section' => $a->section ? [
                'id' => $a->section->id,
                'name' => $a->section->name,
                'term' => $a->section->term,
            ] : null,
            'course' => $a->section?->course ? [
                'id' => $a->section->course->id,
                'code' => $a->section->course->code,
                'title' => $a->section->course->title,
            ] : null,
            'assigned_by' => $a->assignedByUser?->name,
            'status' => $a->status->value,
            'due_date' => $a->due_date?->toDateString(),
            'total_score' => $a->total_score !== null ? (float) $a->total_score : null,
            'admin_remarks' => $a->admin_remarks,
            'submitted_at' => $a->submitted_at?->toIso8601String(),
            'approved_at' => $a->approved_at?->toIso8601String(),
            'rejected_at' => $a->rejected_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
        ];

        if ($withAnswers) {
            $payload['answers_json'] = $a->answers_json;
        }

        return $payload;
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAssignmentStatus;
use App\Enums\FormType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAuditAssignmentRequest;
use App\Models\AuditAssignment;
use App\Models\AuditImprovementAction;
use App\Models\AuditProvenanceLog;
use App\Models\FormVersion;
use App\Models\Section;
use App\Services\ActivityLogger;
use App\Services\AuditScoringService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuditAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = AuditAssignment::with(['auditor', 'auditee', 'section.course.department', 'assignedByUser', 'formVersion']);

        // Departmental scope: delegated administrators can only view their department's assignments
        if ($user->isAdmin() && ! $user->isCentralQa()) {
            $query->whereHas('section.course', fn ($q) => $q->where('department_id', $user->department_id));
        }

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
        $sectionId = $request->filled('section_id') ? (int) $request->input('section_id') : null;

        // Department scope check
        if ($sectionId) {
            $section = Section::with('course')->find($sectionId);
            $deptId = $section?->course?->department_id;
            if (! $request->user()->canAccessDepartment($deptId)) {
                return response()->json([
                    'message' => 'Forbidden. You do not have administrative authority over this department.',
                ], 403);
            }
        }

        // Form version binding
        $formVersionId = $request->input('form_version_id');
        if (! $formVersionId) {
            $latestVersion = FormVersion::where('form_type', FormType::FacultyAudit->value)
                ->where('is_published', true)
                ->latest()
                ->first();
            $formVersionId = $latestVersion?->id;
        }

        $assignment = AuditAssignment::create([
            'auditor_id' => (int) $request->input('auditor_id'),
            'auditee_id' => (int) $request->input('auditee_id'),
            'section_id' => $sectionId,
            'form_version_id' => $formVersionId,
            'assigned_by' => $request->user()->id,
            'status' => AuditAssignmentStatus::Assigned,
            'due_date' => $request->filled('due_date') ? $request->input('due_date') : null,
            'observation_date' => $request->filled('observation_date') ? $request->input('observation_date') : null,
            'observation_context' => $request->input('observation_context'),
            'conflict_declared' => $request->boolean('conflict_declared', false),
        ]);

        ActivityLogger::log($assignment, 'audit_assignment.created', [
            'auditor' => $assignment->auditor?->name,
            'auditee' => $assignment->auditee?->name,
        ]);

        AuditProvenanceLog::record(
            $assignment,
            'assigned',
            $request->user(),
            'Peer observation audit assigned.',
            null,
            [
                'auditor_id' => $assignment->auditor_id,
                'auditee_id' => $assignment->auditee_id,
                'section_id' => $assignment->section_id,
                'due_date' => $assignment->due_date?->toDateString(),
            ]
        );

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
            'data' => $this->transform($assignment->load(['auditor', 'auditee', 'section.course', 'formVersion'])),
            'message' => 'Audit assignment created successfully.',
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $assignment = AuditAssignment::with(['auditor', 'auditee', 'section.course', 'assignedByUser', 'formVersion'])->find($id);

        if (! $assignment) {
            return response()->json(['message' => 'Audit assignment not found.'], 404);
        }

        if (! $request->user()->canAccessDepartment($assignment->section?->course?->department_id)) {
            return response()->json(['message' => 'Forbidden. Access restricted by department scope.'], 403);
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
        $assignment = DB::transaction(function () use ($request, $id) {
            $assignment = AuditAssignment::whereKey($id)->lockForUpdate()->first();

            if (! $assignment) {
                abort(404, 'Audit assignment not found.');
            }

            if (! $request->user()->canAccessDepartment($assignment->section?->course?->department_id)) {
                abort(403, 'Forbidden. Access restricted by department scope.');
            }

            if ($assignment->status !== AuditAssignmentStatus::Submitted) {
                throw ValidationException::withMessages([
                    'status' => "Cannot approve: audit must be in 'submitted' status. Current status: {$assignment->status->value}.",
                ]);
            }

            $beforeState = [
                'status' => $assignment->status->value,
                'total_score' => $assignment->total_score,
            ];

            $outcomeBand = $assignment->outcome_band;
            if (! $outcomeBand && $assignment->answers_json) {
                $scoring = AuditScoringService::evaluate($assignment->answers_json, $assignment->formVersion);
                $outcomeBand = $scoring['outcome_band'];
            }

            $assignment->update([
                'status' => AuditAssignmentStatus::Approved,
                'outcome_band' => $outcomeBand,
                'admin_remarks' => $request->input('admin_remarks', $assignment->admin_remarks),
                'approved_at' => now(),
            ]);

            ActivityLogger::log($assignment, 'audit_assignment.approved', [
                'auditee' => $assignment->auditee?->name,
                'score' => $assignment->total_score,
                'outcome_band' => $assignment->outcome_band,
            ]);

            AuditProvenanceLog::record(
                $assignment,
                'approved',
                $request->user(),
                $request->input('admin_remarks') ?? 'Audit approved by QA.',
                $beforeState,
                [
                    'status' => 'approved',
                    'total_score' => $assignment->total_score,
                    'outcome_band' => $assignment->outcome_band,
                    'approved_at' => $assignment->approved_at?->toIso8601String(),
                ]
            );

            return $assignment;
        });

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
            'data' => $this->transform($assignment->load(['auditor', 'auditee', 'section.course', 'formVersion'])),
            'message' => 'Audit approved successfully.',
        ]);
    }

    /**
     * POST /api/admin/audit-assignments/{id}/reject
     * Admin decision on a submitted audit: reject (sent back to auditor with revision preservation).
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'admin_remarks' => ['required', 'string'],
        ]);

        $assignment = DB::transaction(function () use ($request, $id) {
            $assignment = AuditAssignment::whereKey($id)->lockForUpdate()->first();

            if (! $assignment) {
                abort(404, 'Audit assignment not found.');
            }

            if (! $request->user()->canAccessDepartment($assignment->section?->course?->department_id)) {
                abort(403, 'Forbidden. Access restricted by department scope.');
            }

            if ($assignment->status !== AuditAssignmentStatus::Submitted) {
                throw ValidationException::withMessages([
                    'status' => "Cannot reject: audit must be in 'submitted' status. Current status: {$assignment->status->value}.",
                ]);
            }

            $beforeState = [
                'status' => $assignment->status->value,
                'revision_number' => $assignment->revision_number,
            ];

            // Preserve submitted revision in previous_version columns
            $assignment->update([
                'status' => AuditAssignmentStatus::Rejected,
                'admin_remarks' => $request->input('admin_remarks'),
                'rejection_reason' => $request->input('admin_remarks'),
                'rejected_at' => now(),
                'previous_version_answers_json' => $assignment->answers_json,
                'previous_version_comments_json' => $assignment->comments_json,
                'revision_number' => $assignment->revision_number + 1,
            ]);

            ActivityLogger::log($assignment, 'audit_assignment.rejected', [
                'auditor' => $assignment->auditor?->name,
                'remarks' => $request->input('admin_remarks'),
            ]);

            AuditProvenanceLog::record(
                $assignment,
                'sent_back_for_revision',
                $request->user(),
                $request->input('admin_remarks'),
                $beforeState,
                [
                    'status' => 'rejected',
                    'revision_number' => $assignment->revision_number,
                    'rejection_reason' => $assignment->rejection_reason,
                ]
            );

            return $assignment;
        });

        NotificationService::send(
            $assignment->auditor,
            'audit',
            'Audit sent back for revision',
            "Your audit of {$assignment->auditee?->name} was sent back with remarks: \"{$request->input('admin_remarks')}\" Please revise and resubmit.",
            ['audit_assignment_id' => $assignment->id, 'route' => '/'],
        );

        return response()->json([
            'data' => $this->transform($assignment->load(['auditor', 'auditee', 'section.course', 'formVersion'])),
            'message' => 'Audit rejected and sent back to the auditor.',
        ]);
    }

    /**
     * GET /api/admin/audit-assignments/{id}/actions
     * Returns list of improvement actions for this audit assignment.
     */
    public function actions(Request $request, int $id): JsonResponse
    {
        $assignment = AuditAssignment::whereKey($id)->first();
        if (! $assignment) {
            abort(404, 'Audit assignment not found.');
        }

        if (! $request->user()->canAccessDepartment($assignment->section?->course?->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        $actions = $assignment->improvementActions()->with(['owner', 'closedByUser'])->orderBy('due_date')->get();

        return response()->json([
            'data' => $actions->map(fn ($a) => [
                'id' => $a->id,
                'audit_assignment_id' => $a->audit_assignment_id,
                'finding' => $a->finding,
                'agreed_action' => $a->agreed_action,
                'owner' => [
                    'id' => $a->owner?->id,
                    'name' => $a->owner?->name,
                    'email' => $a->owner?->email,
                ],
                'due_date' => $a->due_date?->toDateString(),
                'status' => $a->status,
                'follow_up_note' => $a->follow_up_note,
                'closed_by' => [
                    'id' => $a->closedByUser?->id,
                    'name' => $a->closedByUser?->name,
                ],
                'closed_at' => $a->closed_at?->toIso8601String(),
                'created_at' => $a->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * POST /api/admin/audit-assignments/{id}/actions
     * Creates an improvement action plan item and advances audit to action_plan_active.
     */
    public function storeAction(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'finding' => ['required', 'string', 'max:2000'],
            'agreed_action' => ['required', 'string', 'max:2000'],
            'owner_id' => ['required', 'exists:users,id'],
            'due_date' => ['required', 'date'],
            'status' => ['sometimes', 'string', 'in:open,in_progress,completed,closed'],
            'follow_up_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $assignment = AuditAssignment::whereKey($id)->first();
        if (! $assignment) {
            abort(404, 'Audit assignment not found.');
        }

        if (! $request->user()->canAccessDepartment($assignment->section?->course?->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        $action = DB::transaction(function () use ($assignment, $validated, $request) {
            $action = $assignment->improvementActions()->create([
                'finding' => $validated['finding'],
                'agreed_action' => $validated['agreed_action'],
                'owner_id' => $validated['owner_id'],
                'due_date' => $validated['due_date'],
                'status' => $validated['status'] ?? 'open',
                'follow_up_note' => $validated['follow_up_note'] ?? null,
            ]);

            // Transition audit status to action_plan_active if it was approved or faculty responded
            if (in_array($assignment->status, [AuditAssignmentStatus::Approved, AuditAssignmentStatus::FacultyResponded], true)) {
                $assignment->update([
                    'status' => AuditAssignmentStatus::ActionPlanActive,
                ]);
            }

            AuditProvenanceLog::record(
                $assignment,
                'improvement_action_created',
                $request->user(),
                "Improvement action created: {$action->agreed_action}",
                [],
                [
                    'action_id' => $action->id,
                    'finding' => $action->finding,
                    'agreed_action' => $action->agreed_action,
                    'owner_id' => $action->owner_id,
                    'due_date' => $action->due_date?->toDateString(),
                    'audit_status' => $assignment->status->value,
                ]
            );

            return $action;
        });

        return response()->json([
            'message' => 'Improvement action created successfully.',
            'data' => [
                'id' => $action->id,
                'audit_assignment_id' => $action->audit_assignment_id,
                'finding' => $action->finding,
                'agreed_action' => $action->agreed_action,
                'owner_id' => $action->owner_id,
                'due_date' => $action->due_date?->toDateString(),
                'status' => $action->status,
                'audit_status' => $assignment->status->value,
            ],
        ], 201);
    }

    /**
     * PATCH /api/admin/audit-assignments/{id}/actions/{actionId}
     * Updates an improvement action, records closure provenance if closed, and closes audit if all actions completed.
     */
    public function updateAction(Request $request, int $id, int $actionId): JsonResponse
    {
        $validated = $request->validate([
            'finding' => ['sometimes', 'string', 'max:2000'],
            'agreed_action' => ['sometimes', 'string', 'max:2000'],
            'owner_id' => ['sometimes', 'exists:users,id'],
            'due_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:open,in_progress,completed,closed'],
            'follow_up_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $assignment = AuditAssignment::whereKey($id)->first();
        if (! $assignment) {
            abort(404, 'Audit assignment not found.');
        }

        if (! $request->user()->canAccessDepartment($assignment->section?->course?->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        $action = $assignment->improvementActions()->whereKey($actionId)->first();
        if (! $action) {
            abort(404, 'Improvement action not found.');
        }

        $beforeState = [
            'status' => $action->status,
            'follow_up_note' => $action->follow_up_note,
            'due_date' => $action->due_date?->toDateString(),
        ];

        DB::transaction(function () use ($action, $assignment, $validated, $request, $beforeState) {
            $updates = array_filter($validated, fn ($v) => $v !== null);
            if (isset($validated['status']) && $validated['status'] === 'closed' && $action->status !== 'closed') {
                $updates['closed_by'] = $request->user()->id;
                $updates['closed_at'] = now();
            }

            $action->update($updates);

            // If all actions on the audit are closed, advance audit status to Closed
            $openActionsCount = $assignment->improvementActions()->where('status', '!=', 'closed')->count();
            if ($openActionsCount === 0 && $assignment->status === AuditAssignmentStatus::ActionPlanActive) {
                $assignment->update([
                    'status' => AuditAssignmentStatus::Closed,
                ]);
            }

            AuditProvenanceLog::record(
                $assignment,
                $action->status === 'closed' ? 'improvement_action_closed' : 'improvement_action_updated',
                $request->user(),
                $action->status === 'closed' ? "Action #{$action->id} closed by QA." : "Action #{$action->id} updated.",
                $beforeState,
                [
                    'action_id' => $action->id,
                    'status' => $action->status,
                    'follow_up_note' => $action->follow_up_note,
                    'closed_by' => $action->closed_by,
                    'closed_at' => $action->closed_at?->toIso8601String(),
                    'audit_status' => $assignment->status->value,
                ]
            );
        });

        return response()->json([
            'message' => 'Improvement action updated successfully.',
            'data' => [
                'id' => $action->id,
                'status' => $action->status,
                'follow_up_note' => $action->follow_up_note,
                'closed_by' => $action->closedByUser?->name,
                'closed_at' => $action->closed_at?->toIso8601String(),
                'audit_status' => $assignment->status->value,
            ],
        ]);
    }

    /**
     * POST /api/admin/audit-assignments/{id}/close
     * QA closure of audit lifecycle (either after faculty response, or directly with no-action rationale).
     */
    public function close(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'closure_remarks' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $closedAssignment = DB::transaction(function () use ($id, $validated, $request) {
            $assignment = AuditAssignment::whereKey($id)->lockForUpdate()->first();

            if (! $assignment) {
                abort(404, 'Audit assignment not found.');
            }

            if (! $request->user()->canAccessDepartment($assignment->section?->course?->department_id)) {
                abort(403, 'Forbidden. Access restricted by department scope.');
            }

            if (! in_array($assignment->status, [
                AuditAssignmentStatus::Approved,
                AuditAssignmentStatus::FacultyResponded,
                AuditAssignmentStatus::ActionPlanActive,
            ], true)) {
                abort(422, "Cannot close audit: current status is '{$assignment->status->value}'. Only approved, faculty-responded, or action-plan-active audits can be closed.");
            }

            // If action plan is active, ensure all improvement actions are completed or closed
            if ($assignment->status === AuditAssignmentStatus::ActionPlanActive) {
                $hasOpenActions = $assignment->improvementActions()->where('status', '!=', 'closed')->exists();
                if ($hasOpenActions) {
                    abort(422, 'Cannot close audit while open improvement actions remain. Complete and close all actions first.');
                }
            }

            $beforeState = [
                'status' => $assignment->status->value,
                'admin_remarks' => $assignment->admin_remarks,
            ];

            $remarks = $validated['closure_remarks'];
            $newAdminRemarks = $assignment->admin_remarks
                ? $assignment->admin_remarks . "\n\n[QA Closure Note]: " . $remarks
                : "[QA Closure Note]: " . $remarks;

            $assignment->update([
                'status' => AuditAssignmentStatus::Closed,
                'admin_remarks' => $newAdminRemarks,
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
            ]);

            AuditProvenanceLog::record(
                $assignment,
                'audit_closed',
                $request->user(),
                'Audit record closed by QA administrator.',
                $beforeState,
                [
                    'status' => 'closed',
                    'closure_remarks' => $remarks,
                    'closed_by' => $request->user()->id,
                    'closed_at' => $assignment->closed_at?->toIso8601String(),
                ]
            );

            return $assignment;
        });

        return response()->json([
            'message' => 'Audit assignment closed successfully.',
            'data' => $this->transform($closedAssignment->fresh(['auditor', 'auditee', 'section.course', 'formVersion', 'closedByUser'])),
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
                'department_id' => $a->section->course->department_id,
            ] : null,
            'form_version' => $a->formVersion ? [
                'id' => $a->formVersion->id,
                'version_code' => $a->formVersion->version_code,
                'title' => $a->formVersion->title,
            ] : null,
            'status' => $a->status->value,
            'due_date' => $a->due_date?->toDateString(),
            'observation_date' => $a->observation_date?->toDateString(),
            'observation_context' => $a->observation_context,
            'conflict_declared' => $a->conflict_declared,
            'revision_number' => $a->revision_number,
            'total_score' => $a->total_score,
            'outcome_band' => $a->outcome_band,
            'admin_remarks' => $a->admin_remarks,
            'rejection_reason' => $a->rejection_reason,
            'faculty_response' => $a->faculty_response,
            'faculty_responded_at' => $a->faculty_responded_at?->toIso8601String(),
            'submitted_at' => $a->submitted_at?->toIso8601String(),
            'approved_at' => $a->approved_at?->toIso8601String(),
            'rejected_at' => $a->rejected_at?->toIso8601String(),
            'assigned_by' => [
                'id' => $a->assignedByUser?->id,
                'name' => $a->assignedByUser?->name,
            ],
            'closed_by' => $a->closedByUser ? [
                'id' => $a->closedByUser->id,
                'name' => $a->closedByUser->name,
            ] : null,
            'closed_at' => $a->closed_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
        ];

        if ($withAnswers) {
            $payload['answers_json'] = $a->answers_json;
            $payload['comments_json'] = $a->comments_json;
            $payload['previous_version_answers_json'] = $a->previous_version_answers_json;
            $payload['previous_version_comments_json'] = $a->previous_version_comments_json;
        }

        return $payload;
    }
}

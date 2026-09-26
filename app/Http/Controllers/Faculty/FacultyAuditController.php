<?php

namespace App\Http\Controllers\Faculty;

use App\Enums\AuditAssignmentStatus;
use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Faculty\SaveAuditDraftRequest;
use App\Http\Requests\Faculty\SubmitAuditRequest;
use App\Models\AuditAssignment;
use App\Models\AuditImprovementAction;
use App\Models\AuditProvenanceLog;
use App\Models\Question;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AuditScoringService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacultyAuditController extends Controller
{
    /**
     * Normalizes the incoming answers array into qId-keyed maps for
     * answers_json and comments_json. The optional top-level
     * `recommendations` free text (review summary box) is stored under the
     * reserved `recommendations` key in comments_json.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function normalizeAnswers(array $submittedAnswers, ?string $recommendations = null): array
    {
        $formattedAnswers = [];
        $formattedComments = [];
        foreach ($submittedAnswers as $answer) {
            $formattedAnswers[(string) $answer['question_id']] = $answer['value'];
            $comment = $answer['comment'] ?? null;
            if (is_string($comment) && trim($comment) !== '') {
                $formattedComments[(string) $answer['question_id']] = trim($comment);
            }
        }

        if (is_string($recommendations) && trim($recommendations) !== '') {
            $formattedComments['recommendations'] = trim($recommendations);
        }

        return [$formattedAnswers, $formattedComments];
    }

    /**
     * GET /api/faculty/assigned-audits
     * Returns audits assigned to the authenticated faculty member as auditor.
     */
    public function assignedAudits(Request $request): JsonResponse
    {
        $audits = AuditAssignment::with(['auditee', 'section.course'])
            ->where('auditor_id', $request->user()->id)
            ->orderBy('due_date')
            ->get();

        // Published faculty-audit question ids let clients compute honest
        // progress ("3 of 12 answered") instead of guessing a denominator.
        $questionIds = Question::where('form_type', FormType::FacultyAudit)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        return response()->json([
            'data' => $audits->map(fn ($a) => [
                'id' => $a->id,
                'auditee' => [
                    'id' => $a->auditee?->id,
                    'name' => $a->auditee?->name,
                    'email' => $a->auditee?->email,
                ],
                'section' => [
                    'id' => $a->section?->id,
                    'name' => $a->section?->name,
                    'term' => $a->section?->term,
                ],
                'course' => [
                    'id' => $a->section?->course?->id,
                    'code' => $a->section?->course?->code,
                    'title' => $a->section?->course?->title,
                ],
                'status' => $a->status->value,
                'due_date' => $a->due_date?->toDateString(),
                'is_overdue' => $a->due_date ? ($a->due_date->endOfDay()->isPast() && ! in_array($a->status, [AuditAssignmentStatus::Submitted, AuditAssignmentStatus::Approved], true)) : false,
                'due_in_days' => $a->due_date ? (int) now()->startOfDay()->diffInDays($a->due_date->startOfDay(), false) : null,
                'answers_json' => $a->answers_json,
                'comments_json' => $a->comments_json,
                'published_question_ids' => $questionIds,
                'admin_remarks' => $a->admin_remarks,
                'total_score' => $a->total_score,
            ]),
        ]);
    }

    /**
     * GET /api/faculty/audits/{id}
     * Returns single audit details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $audit = AuditAssignment::with(['auditor', 'auditee', 'section.course', 'formVersion'])->find($id);

        if (! $audit) {
            return response()->json(['message' => 'Audit assignment not found.'], 404);
        }

        $userId = $request->user()->id;

        // Auditor can view their assignment; Auditee can view once approved.
        if ($audit->auditor_id !== $userId && ($audit->auditee_id !== $userId || $audit->status !== AuditAssignmentStatus::Approved)) {
            return response()->json(['message' => 'Forbidden. Access restricted.'], 403);
        }

        $publishedQuestionIds = $audit->form_version_id && $audit->formVersion
            ? collect($audit->formVersion->getQuestions())->pluck('id')->all()
            : Question::where('form_type', FormType::FacultyAudit)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->pluck('id')
                ->all();

        return response()->json([
            'data' => [
                'id' => $audit->id,
                'auditor' => [
                    'id' => $audit->auditor?->id,
                    'name' => $audit->auditor?->name,
                ],
                'auditee' => [
                    'id' => $audit->auditee?->id,
                    'name' => $audit->auditee?->name,
                ],
                'section' => [
                    'id' => $audit->section?->id,
                    'name' => $audit->section?->name,
                    'term' => $audit->section?->term,
                ],
                'course' => [
                    'id' => $audit->section?->course?->id,
                    'code' => $audit->section?->course?->code,
                    'title' => $audit->section?->course?->title,
                ],
                'form_version' => $audit->formVersion ? [
                    'id' => $audit->formVersion->id,
                    'version_code' => $audit->formVersion->version_code,
                    'title' => $audit->formVersion->title,
                ] : null,
                'form_questions' => $audit->formVersion ? $audit->formVersion->getQuestions() : null,
                'status' => $audit->status->value,
                'due_date' => $audit->due_date?->toDateString(),
                'total_score' => $audit->total_score,
                'admin_remarks' => $audit->admin_remarks,
                'answers_json' => $audit->answers_json,
                'comments_json' => $audit->comments_json,
                'published_question_ids' => $publishedQuestionIds,
                'submitted_at' => $audit->submitted_at?->toIso8601String(),
                'approved_at' => $audit->approved_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/faculty/audit-form
     * Returns active peer audit criteria questions (or bound form version questions if audit_id provided).
     */
    public function auditForm(Request $request): JsonResponse
    {
        if ($request->filled('audit_id')) {
            $audit = AuditAssignment::with('formVersion')->find($request->input('audit_id'));
            if ($audit && $audit->form_version_id && $audit->formVersion) {
                return response()->json([
                    'data' => $audit->formVersion->getQuestions(),
                    'form_version' => [
                        'id' => $audit->formVersion->id,
                        'version_code' => $audit->formVersion->version_code,
                        'title' => $audit->formVersion->title,
                    ],
                ]);
            }
        }

        $questions = Question::where('form_type', FormType::FacultyAudit)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'question_text', 'question_type', 'is_required', 'sort_order']);

        return response()->json([
            'data' => $questions,
        ]);
    }

    /**
     * POST /api/faculty/audits/{id}/save-draft
     * Saves partial audit answers and marks status in_progress.
     */
    public function saveDraft(SaveAuditDraftRequest $request, int $id): JsonResponse
    {
        [$formattedAnswers, $formattedComments] = $this->normalizeAnswers(
            $request->input('answers', []),
            $request->input('recommendations'),
        );

        // Lock the row inside a transaction so a concurrent submit/approve
        // cannot interleave between the status check and the write (TOCTOU).
        $audit = DB::transaction(function () use ($id, $formattedAnswers, $formattedComments) {
            $audit = AuditAssignment::whereKey($id)->lockForUpdate()->first();

            if (! $audit) {
                abort(404, 'Audit assignment not found.');
            }

            if (! $audit->isEditableByAuditor()) {
                throw ValidationException::withMessages([
                    'audit' => "Cannot save draft. This audit is in '{$audit->status->value}' status and is finalized.",
                ]);
            }

            $audit->update([
                'answers_json' => $formattedAnswers,
                'comments_json' => $formattedComments,
                'status' => AuditAssignmentStatus::InProgress,
            ]);

            return $audit;
        });

        return response()->json([
            'message' => 'Audit draft saved successfully.',
            'data' => [
                'id' => $audit->id,
                'status' => $audit->status->value,
                'answers_json' => $audit->answers_json,
                'comments_json' => $audit->comments_json,
            ],
        ]);
    }

    /**
     * POST /api/faculty/audits/{id}/submit
     * Submits completed audit, computes total_score, and renders it immutable.
     */
    public function submit(SubmitAuditRequest $request, int $id): JsonResponse
    {
        [$formattedAnswers, $formattedComments] = $this->normalizeAnswers(
            $request->input('answers', []),
            $request->input('recommendations'),
        );

        // Compute total_score = (average of all scorable [rating + yes_no]) * 20
        $activeQuestions = Question::where('form_type', FormType::FacultyAudit)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $scorableValues = [];

        foreach ($formattedAnswers as $qId => $value) {
            $question = $activeQuestions->get((int) $qId);
            if (! $question) {
                continue;
            }

            if ($question->question_type === QuestionType::Rating && is_numeric($value)) {
                $scorableValues[] = (float) $value; // 1 to 5
            } elseif ($question->question_type === QuestionType::YesNo) {
                $isYes = in_array($value, [true, 1, '1', 'yes', 'true'], true);
                $scorableValues[] = $isYes ? 5.0 : 0.0; // Scaled to 5-point scale
            }
        }

        // Lock the row and re-check finality inside the transaction: a draft
        // being saved concurrently, or an admin approving concurrently, can
        // never interleave with this submission (prevents an approved audit
        // from being overwritten back to submitted/in_progress).
        $audit = DB::transaction(function () use ($id, $formattedAnswers, $formattedComments) {
            $audit = AuditAssignment::with('formVersion')->whereKey($id)->lockForUpdate()->first();

            if (! $audit) {
                abort(404, 'Audit assignment not found.');
            }

            if (in_array($audit->status, [
                AuditAssignmentStatus::Submitted,
                AuditAssignmentStatus::Approved,
                AuditAssignmentStatus::FacultyResponded,
                AuditAssignmentStatus::ActionPlanActive,
                AuditAssignmentStatus::Closed,
            ], true)) {
                throw ValidationException::withMessages([
                    'audit' => 'This audit has already been submitted and is finalized.',
                ]);
            }

            $scoring = AuditScoringService::evaluate($formattedAnswers, $audit->formVersion);
            $totalScore = $scoring['total_score'];
            $outcomeBand = $scoring['outcome_band'];

            $audit->update([
                'answers_json' => $formattedAnswers,
                'comments_json' => $formattedComments,
                'total_score' => $totalScore,
                'outcome_band' => $outcomeBand,
                'status' => AuditAssignmentStatus::Submitted,
                'submitted_at' => now(),
                // A (re)submission supersedes any earlier rejection.
                'rejected_at' => null,
            ]);

            ActivityLogger::log($audit, 'audit.submitted', [
                'auditor' => $audit->auditor?->name,
                'auditee' => $audit->auditee?->name,
                'score' => $totalScore,
                'outcome_band' => $outcomeBand,
            ]);

            return $audit;
        });

        // Notifications fire after commit — they must never roll back with
        // the transaction, and never notify about an uncommitted state.
        NotificationService::sendMany(
            User::where('role', UserRole::Admin)->where('is_active', true)->get(),
            'audit',
            'Audit submitted for review',
            "{$audit->auditor?->name} submitted a peer audit of {$audit->auditee?->name}".($audit->total_score !== null ? " (score: {$audit->total_score}/100)" : '').'. Awaiting your decision in the portal.',
            ['audit_assignment_id' => $audit->id],
        );

        return response()->json([
            'message' => 'Audit submitted successfully.',
            'data' => [
                'id' => $audit->id,
                'status' => $audit->status->value,
                'total_score' => $audit->total_score,
                'outcome_band' => $audit->outcome_band,
                'submitted_at' => $audit->submitted_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/faculty/my-submissions
     * Returns audits submitted by the authenticated faculty member as auditor.
     */
    public function mySubmissions(Request $request): JsonResponse
    {
        $submissions = AuditAssignment::with(['auditee', 'section.course'])
            ->where('auditor_id', $request->user()->id)
            ->whereIn('status', [
                AuditAssignmentStatus::Submitted,
                AuditAssignmentStatus::Approved,
                AuditAssignmentStatus::Rejected,
                AuditAssignmentStatus::FacultyResponded,
                AuditAssignmentStatus::ActionPlanActive,
                AuditAssignmentStatus::Closed,
            ])
            ->orderByDesc('submitted_at')
            ->get();

        return response()->json([
            'data' => $submissions->map(fn ($s) => [
                'id' => $s->id,
                'auditee_name' => $s->auditee?->name,
                'course_title' => $s->section?->course?->title,
                'course_code' => $s->section?->course?->code,
                'section_name' => $s->section?->name,
                'total_score' => $s->total_score,
                'status' => $s->status->value,
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'admin_remarks' => $s->admin_remarks,
            ]),
        ]);
    }

    /**
     * GET /api/faculty/my-reports
     * Returns approved reports for the authenticated faculty member as auditee.
     */
    public function myReports(Request $request): JsonResponse
    {
        $reports = AuditAssignment::with(['auditor', 'section.course', 'formVersion', 'improvementActions.owner'])
            ->where('auditee_id', $request->user()->id)
            ->whereIn('status', [
                AuditAssignmentStatus::Approved,
                AuditAssignmentStatus::FacultyResponded,
                AuditAssignmentStatus::ActionPlanActive,
                AuditAssignmentStatus::Closed,
            ])
            ->orderByDesc('approved_at')
            ->get();

        $auditQuestions = Question::where('form_type', FormType::FacultyAudit)
            ->orderBy('sort_order')
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $reports->map(function ($r) use ($auditQuestions) {
                $breakdown = [];
                $answers = $r->answers_json ?? [];

                $frozenQuestions = [];
                if ($r->formVersion) {
                    foreach ($r->formVersion->getQuestions() as $fq) {
                        $fqId = (int) ($fq['id'] ?? 0);
                        $frozenQuestions[$fqId] = $fq;
                    }
                }

                foreach ($answers as $qId => $val) {
                    $intQId = (int) $qId;
                    if (isset($frozenQuestions[$intQId])) {
                        $fq = $frozenQuestions[$intQId];
                        $qText = $fq['question_text'] ?? "Metric #{$qId}";
                        $qType = $fq['question_type'] ?? 'text';
                        if ($qType instanceof QuestionType) {
                            $qType = $qType->value;
                        }
                    } else {
                        $q = $auditQuestions->get($intQId);
                        $qText = $q?->question_text ?? "Metric #{$qId}";
                        $qType = $q?->question_type?->value ?? 'text';
                    }

                    $breakdown[] = [
                        'question_id' => $intQId,
                        'question_text' => $qText,
                        'question_type' => $qType,
                        'value' => $val,
                    ];
                }

                $scoring = AuditScoringService::evaluate($answers, $r->formVersion);
                $band = $r->outcome_band ?? $scoring['outcome_band'] ?? 'Satisfactory';
                $bandColor = $scoring['band_color'] ?? 'info';
                $outcome = $scoring['outcome'] ?? 'meetsStandard';

                return [
                    'id' => $r->id,
                    'course_title' => $r->section?->course?->title,
                    'course_code' => $r->section?->course?->code,
                    'section_name' => $r->section?->name,
                    'term' => $r->section?->term,
                    'auditor_name' => $r->auditor?->name,
                    'total_score' => $r->total_score,
                    'outcome_band' => $band,
                    'band_label' => $band,
                    'band_color' => $bandColor,
                    'outcome' => $outcome,
                    'status' => $r->status->value,
                    'faculty_response' => $r->faculty_response,
                    'faculty_responded_at' => $r->faculty_responded_at?->toIso8601String(),
                    'has_responded' => ! empty($r->faculty_response),
                    'admin_remarks' => $r->admin_remarks,
                    'submitted_at' => $r->submitted_at?->toIso8601String(),
                    'approved_at' => $r->approved_at?->toIso8601String(),
                    'breakdown' => $breakdown,
                    'improvement_actions' => $r->improvementActions->map(fn ($a) => [
                        'id' => $a->id,
                        'finding' => $a->finding,
                        'agreed_action' => $a->agreed_action,
                        'owner_name' => $a->owner?->name,
                        'due_date' => $a->due_date?->toDateString(),
                        'status' => $a->status,
                        'follow_up_note' => $a->follow_up_note,
                        'closed_at' => $a->closed_at?->toIso8601String(),
                    ]),
                ];
            }),
        ]);
    }

    /**
     * POST /api/faculty/my-reports/{id}/response
     * Submits a formal faculty response/acknowledgment to an approved audit report.
     */
    public function respondToReport(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'response' => ['sometimes', 'nullable', 'string', 'min:3', 'max:5000'],
            'response_text' => ['sometimes', 'nullable', 'string', 'min:3', 'max:5000'],
        ]);

        $responseText = trim($validated['response_text'] ?? $validated['response'] ?? '');
        if ($responseText === '') {
            throw ValidationException::withMessages([
                'response' => 'Formal response text is required.',
            ]);
        }

        $audit = AuditAssignment::whereKey($id)->first();
        if (! $audit) {
            abort(404, 'Audit report not found.');
        }

        if ((int) $audit->auditee_id !== (int) $request->user()->id) {
            abort(403, 'You are not authorized to respond to this audit report.');
        }

        if (! in_array($audit->status, [
            AuditAssignmentStatus::Approved,
            AuditAssignmentStatus::FacultyResponded,
            AuditAssignmentStatus::ActionPlanActive,
        ], true)) {
            throw ValidationException::withMessages([
                'audit' => 'Responses can only be submitted for approved audit reports.',
            ]);
        }

        $beforeState = [
            'status' => $audit->status->value,
            'faculty_response' => $audit->faculty_response,
            'faculty_responded_at' => $audit->faculty_responded_at?->toIso8601String(),
        ];

        DB::transaction(function () use ($audit, $responseText) {
            $audit->update([
                'faculty_response' => $responseText,
                'faculty_responded_at' => now(),
                'status' => $audit->status === AuditAssignmentStatus::Approved
                    ? AuditAssignmentStatus::FacultyResponded
                    : $audit->status,
            ]);
        });

        AuditProvenanceLog::record(
            $audit,
            'faculty_response_submitted',
            $request->user(),
            'Faculty auditee submitted formal acknowledgment/response.',
            $beforeState,
            [
                'status' => $audit->status->value,
                'faculty_response' => $audit->faculty_response,
                'faculty_responded_at' => $audit->faculty_responded_at?->toIso8601String(),
            ]
        );

        ActivityLogger::log($audit, 'audit.faculty_responded', [
            'auditee' => $request->user()->name,
            'response_length' => strlen($responseText),
        ]);

        NotificationService::sendMany(
            User::where('role', UserRole::Admin)->where('is_active', true)->get(),
            'audit',
            'Faculty Response Received',
            "{$request->user()->name} submitted a formal response to Audit #{$audit->id}.",
            ['audit_assignment_id' => $audit->id]
        );

        return response()->json([
            'message' => 'Formal response submitted successfully.',
            'data' => [
                'id' => $audit->id,
                'status' => $audit->status->value,
                'faculty_response' => $audit->faculty_response,
                'faculty_responded_at' => $audit->faculty_responded_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/faculty/my-reports/{id}/actions
     * Returns improvement actions for the audit report.
     */
    public function reportActions(Request $request, int $id): JsonResponse
    {
        $audit = AuditAssignment::whereKey($id)->first();
        if (! $audit) {
            abort(404, 'Audit report not found.');
        }

        if ((int) $audit->auditee_id !== (int) $request->user()->id && (int) $audit->auditor_id !== (int) $request->user()->id) {
            abort(403, 'Unauthorized.');
        }

        $actions = $audit->improvementActions()->with(['owner', 'closedByUser'])->orderBy('due_date')->get();

        return response()->json([
            'data' => $actions->map(fn ($a) => [
                'id' => $a->id,
                'audit_assignment_id' => $a->audit_assignment_id,
                'finding' => $a->finding,
                'agreed_action' => $a->agreed_action,
                'owner' => [
                    'id' => $a->owner?->id,
                    'name' => $a->owner?->name,
                ],
                'due_date' => $a->due_date?->toDateString(),
                'status' => $a->status,
                'follow_up_note' => $a->follow_up_note,
                'closed_by' => $a->closedByUser?->name,
                'closed_at' => $a->closed_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * PATCH /api/faculty/my-reports/{id}/actions/{actionId}
     * Allows faculty auditee to update progress (status: in_progress, completed) and follow-up notes.
     */
    public function updateReportAction(Request $request, int $id, int $actionId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:open,in_progress,completed'],
            'follow_up_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $audit = AuditAssignment::whereKey($id)->first();
        if (! $audit) {
            abort(404, 'Audit report not found.');
        }

        $action = $audit->improvementActions()->whereKey($actionId)->first();
        if (! $action) {
            abort(404, 'Improvement action not found.');
        }

        $userId = (int) $request->user()->id;
        if ((int) $audit->auditee_id !== $userId && (int) $action->owner_id !== $userId) {
            abort(403, 'Unauthorized. Only the auditee or action owner can update this action item.');
        }

        if ($action->status === 'closed') {
            throw ValidationException::withMessages([
                'status' => 'Closed improvement actions cannot be edited.',
            ]);
        }

        $beforeState = [
            'status' => $action->status,
            'follow_up_note' => $action->follow_up_note,
        ];

        $action->update(array_filter([
            'status' => $validated['status'] ?? null,
            'follow_up_note' => array_key_exists('follow_up_note', $validated) ? $validated['follow_up_note'] : null,
        ], fn ($v) => $v !== null));

        AuditProvenanceLog::record(
            $audit,
            'improvement_action_progress_updated',
            $request->user(),
            "Faculty updated action item #{$action->id}: status={$action->status}",
            $beforeState,
            [
                'action_id' => $action->id,
                'status' => $action->status,
                'follow_up_note' => $action->follow_up_note,
                'updated_by' => $request->user()->id,
            ]
        );

        ActivityLogger::log($audit, 'audit.action_progress_updated', [
            'action_id' => $action->id,
            'status' => $action->status,
            'user' => $request->user()->name,
        ]);

        return response()->json([
            'message' => 'Action plan updated.',
            'data' => [
                'id' => $action->id,
                'status' => $action->status,
                'follow_up_note' => $action->follow_up_note,
            ],
        ]);
    }
}

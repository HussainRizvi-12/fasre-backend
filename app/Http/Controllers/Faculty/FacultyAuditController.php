<?php

namespace App\Http\Controllers\Faculty;

use App\Enums\AuditAssignmentStatus;
use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Faculty\SaveAuditDraftRequest;
use App\Http\Requests\Faculty\SubmitAuditRequest;
use App\Models\AuditAssignment;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacultyAuditController extends Controller
{
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
        $audit = AuditAssignment::with(['auditor', 'auditee', 'section.course'])->find($id);

        if (! $audit) {
            return response()->json(['message' => 'Audit assignment not found.'], 404);
        }

        $userId = $request->user()->id;

        // Auditor can view their assignment; Auditee can view once approved.
        if ($audit->auditor_id !== $userId && ($audit->auditee_id !== $userId || $audit->status !== AuditAssignmentStatus::Approved)) {
            return response()->json(['message' => 'Forbidden. Access restricted.'], 403);
        }

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
                'status' => $audit->status->value,
                'due_date' => $audit->due_date?->toDateString(),
                'total_score' => $audit->total_score,
                'admin_remarks' => $audit->admin_remarks,
                'answers_json' => $audit->answers_json,
                'submitted_at' => $audit->submitted_at?->toIso8601String(),
                'approved_at' => $audit->approved_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/faculty/audit-form
     * Returns active peer audit criteria questions.
     */
    public function auditForm(): JsonResponse
    {
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
        $audit = AuditAssignment::findOrFail($id);

        if ($audit->auditor_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden. You are not the assigned auditor.'], 403);
        }

        $submittedAnswers = $request->input('answers', []);
        $formattedAnswers = [];
        foreach ($submittedAnswers as $answer) {
            $formattedAnswers[(string) $answer['question_id']] = $answer['value'];
        }

        $audit->update([
            'answers_json' => $formattedAnswers,
            'status' => AuditAssignmentStatus::InProgress,
        ]);

        return response()->json([
            'message' => 'Audit draft saved successfully.',
            'data' => [
                'id' => $audit->id,
                'status' => $audit->status->value,
                'answers_json' => $audit->answers_json,
            ],
        ]);
    }

    /**
     * POST /api/faculty/audits/{id}/submit
     * Submits completed audit, computes total_score, and renders it immutable.
     */
    public function submit(SubmitAuditRequest $request, int $id): JsonResponse
    {
        $audit = AuditAssignment::findOrFail($id);

        if ($audit->auditor_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden. You are not the assigned auditor.'], 403);
        }

        $submittedAnswers = $request->input('answers', []);
        $formattedAnswers = [];
        foreach ($submittedAnswers as $answer) {
            $formattedAnswers[(string) $answer['question_id']] = $answer['value'];
        }

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

        $totalScore = null;
        if (count($scorableValues) > 0) {
            $average = array_sum($scorableValues) / count($scorableValues);
            $totalScore = round($average * 20, 2); // Scales 0-5 to 0-100
        }

        $audit->update([
            'answers_json' => $formattedAnswers,
            'total_score' => $totalScore,
            'status' => AuditAssignmentStatus::Submitted,
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Audit submitted successfully.',
            'data' => [
                'id' => $audit->id,
                'status' => $audit->status->value,
                'total_score' => $audit->total_score,
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
        $reports = AuditAssignment::with(['auditor', 'section.course'])
            ->where('auditee_id', $request->user()->id)
            ->where('status', AuditAssignmentStatus::Approved)
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

                foreach ($answers as $qId => $val) {
                    $q = $auditQuestions->get((int) $qId);
                    $breakdown[] = [
                        'question_id' => (int) $qId,
                        'question_text' => $q?->question_text ?? "Metric #{$qId}",
                        'question_type' => $q?->question_type?->value ?? 'text',
                        'value' => $val,
                    ];
                }

                return [
                    'id' => $r->id,
                    'course_title' => $r->section?->course?->title,
                    'course_code' => $r->section?->course?->code,
                    'section_name' => $r->section?->name,
                    'term' => $r->section?->term,
                    'auditor_name' => $r->auditor?->name,
                    'total_score' => $r->total_score,
                    'admin_remarks' => $r->admin_remarks,
                    'submitted_at' => $r->submitted_at?->toIso8601String(),
                    'approved_at' => $r->approved_at?->toIso8601String(),
                    'breakdown' => $breakdown,
                ];
            }),
        ]);
    }
}

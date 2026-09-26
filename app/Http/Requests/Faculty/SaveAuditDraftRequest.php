<?php

namespace App\Http\Requests\Faculty;

use App\Enums\AuditAssignmentStatus;
use App\Models\AuditAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveAuditDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user() || ! $this->user()->isFaculty()) {
            return false;
        }

        $auditId = $this->route('id') ?? $this->route('audit');
        if (! $auditId) {
            return true;
        }

        $audit = AuditAssignment::find($auditId);
        if ($audit && $audit->auditor_id !== $this->user()->id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'answers' => ['nullable', 'array', 'max:100'],
            'answers.*.question_id' => ['required_with:answers', 'integer'],
            // Scalar-only + bounded length (see SubmitStudentReviewRequest).
            'answers.*.value' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_array($value) || is_object($value)) {
                    $fail('Answer values must be plain text, numbers, or booleans.');
                    return;
                }
                if (is_string($value) && strlen($value) > 5000) {
                    $fail('Each text answer may not exceed 5000 characters.');
                }
            }],
            // Free-text recommendations from the review summary box.
            'recommendations' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $auditId = $this->route('id') ?? $this->route('audit');
                $audit = AuditAssignment::with('formVersion')->find($auditId);

                if (! $audit) {
                    $validator->errors()->add('audit', 'Audit assignment not found.');
                    return;
                }

                // Only Assigned, InProgress, and Rejected audits can receive drafts.
                // Finalized/post-approval states (Submitted, Approved, FacultyResponded, ActionPlanActive, Closed) are locked.
                if (! $audit->isEditableByAuditor()) {
                    $validator->errors()->add('audit', "Cannot save draft. This audit is in '{$audit->status->value}' status and is finalized.");
                    return;
                }

                $submittedAnswers = collect($this->input('answers', []));
                if ($submittedAnswers->isEmpty()) {
                    return;
                }

                if ($audit->form_version_id && $audit->formVersion) {
                    $validIds = collect($audit->formVersion->getQuestions())->pluck('id')->map(fn ($id) => (int) $id)->all();
                } else {
                    $validIds = \App\Models\Question::where('form_type', \App\Enums\FormType::FacultyAudit)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                }

                $seen = [];
                foreach ($submittedAnswers as $index => $answer) {
                    $qId = isset($answer['question_id']) ? (int) $answer['question_id'] : null;
                    if ($qId !== null) {
                        if (in_array($qId, $seen, true)) {
                            $validator->errors()->add("answers.{$index}.question_id", "Duplicate question ID {$qId} in draft.");
                            continue;
                        }
                        $seen[] = $qId;
                        if (! in_array($qId, $validIds, true)) {
                            $validator->errors()->add("answers.{$index}.question_id", "Question ID {$qId} does not belong to this audit instrument.");
                        }
                    }
                }
            },
        ];
    }
}

<?php

namespace App\Http\Requests\Faculty;

use App\Enums\AuditAssignmentStatus;
use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Models\AuditAssignment;
use App\Models\Question;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitAuditRequest extends FormRequest
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
            'answers' => ['required', 'array', 'min:1', 'max:100'],
            'answers.*.question_id' => ['required', 'integer'],
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

                // Submitted and Approved audits are finalized. A Rejected audit
                // MUST be resubmittable — that is the whole point of sending it back.
                if (in_array($audit->status, [
                    AuditAssignmentStatus::Submitted,
                    AuditAssignmentStatus::Approved,
                    AuditAssignmentStatus::FacultyResponded,
                    AuditAssignmentStatus::ActionPlanActive,
                    AuditAssignmentStatus::Closed,
                ], true)) {
                    $validator->errors()->add('audit', 'This audit has already been submitted and is finalized.');
                    return;
                }

                // Validate questions against frozen form version if present, otherwise live active questions
                if ($audit->form_version_id && $audit->formVersion) {
                    $validQuestions = collect($audit->formVersion->getQuestions())->map(fn ($q) => [
                        'id' => (int) $q['id'],
                        'question_text' => $q['question_text'] ?? '',
                        'question_type' => $q['question_type'] instanceof QuestionType ? $q['question_type']->value : ($q['question_type'] ?? 'text'),
                        'is_required' => (bool) ($q['is_required'] ?? false),
                    ])->keyBy('id');
                } else {
                    $validQuestions = Question::where('form_type', FormType::FacultyAudit)
                        ->where('is_active', true)
                        ->get()
                        ->map(fn ($q) => [
                            'id' => (int) $q->id,
                            'question_text' => $q->question_text,
                            'question_type' => $q->question_type instanceof QuestionType ? $q->question_type->value : (string) $q->question_type,
                            'is_required' => (bool) $q->is_required,
                        ])
                        ->keyBy('id');
                }

                $submittedAnswers = collect($this->input('answers', []));
                $seenQIds = [];

                foreach ($submittedAnswers as $index => $answer) {
                    $qId = isset($answer['question_id']) ? (int) $answer['question_id'] : null;
                    if ($qId === null) {
                        continue;
                    }

                    if (in_array($qId, $seenQIds, true)) {
                        $validator->errors()->add("answers.{$index}.question_id", "Duplicate answer submitted for question ID {$qId}.");
                        continue;
                    }
                    $seenQIds[] = $qId;

                    if (! $validQuestions->has($qId)) {
                        $validator->errors()->add("answers.{$index}.question_id", "Question ID {$qId} is not a valid question for this audit rubric.");
                        continue;
                    }

                    $question = $validQuestions->get($qId);
                    $val = $answer['value'] ?? null;
                    $qType = $question['question_type'];
                    $qText = $question['question_text'];

                    if (! is_null($val) && $val !== '') {
                        if ($qType === 'rating' || $qType === QuestionType::Rating->value) {
                            if (! is_numeric($val) || (int) $val < 1 || (int) $val > 5) {
                                $validator->errors()->add("answers.{$index}.value", "Rating question '{$qText}' must be an integer between 1 and 5.");
                            }
                        } elseif ($qType === 'yes_no' || $qType === QuestionType::YesNo->value) {
                            if (! in_array($val, [true, false, 1, 0, '1', '0', 'yes', 'no', 'true', 'false'], true)) {
                                $validator->errors()->add("answers.{$index}.value", "Yes/No question '{$qText}' must be a boolean value.");
                            }
                        }
                    }
                }

                // Verify all required questions have non-empty answers
                $submittedQuestionIds = $submittedAnswers
                    ->filter(fn ($a) => isset($a['value']) && $a['value'] !== '' && ! is_null($a['value']))
                    ->pluck('question_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                foreach ($validQuestions as $question) {
                    if ($question['is_required'] && ! in_array($question['id'], $submittedQuestionIds, true)) {
                        $validator->errors()->add('answers', "Required question '{$question['question_text']}' is missing an answer.");
                    }
                }
            },
        ];
    }
}

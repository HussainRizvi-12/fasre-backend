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
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer', 'exists:questions,id'],
            'answers.*.value' => ['nullable'],
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
                $audit = AuditAssignment::find($auditId);

                if (! $audit) {
                    $validator->errors()->add('audit', 'Audit assignment not found.');
                    return;
                }

                if (in_array($audit->status, [AuditAssignmentStatus::Submitted, AuditAssignmentStatus::Approved, AuditAssignmentStatus::Rejected], true)) {
                    $validator->errors()->add('audit', 'This audit has already been submitted and is finalized.');
                    return;
                }

                // Validate active faculty audit questions
                $activeQuestions = Question::where('form_type', FormType::FacultyAudit)
                    ->where('is_active', true)
                    ->get()
                    ->keyBy('id');

                $submittedAnswers = collect($this->input('answers', []));

                foreach ($submittedAnswers as $index => $answer) {
                    $qId = $answer['question_id'] ?? null;
                    if (! $activeQuestions->has($qId)) {
                        $validator->errors()->add("answers.{$index}.question_id", "Question ID {$qId} is not an active faculty audit question.");
                        continue;
                    }

                    $question = $activeQuestions->get($qId);
                    $val = $answer['value'] ?? null;

                    if (! is_null($val) && $val !== '') {
                        if ($question->question_type === QuestionType::Rating) {
                            if (! is_numeric($val) || (int) $val < 1 || (int) $val > 5) {
                                $validator->errors()->add("answers.{$index}.value", "Rating question '{$question->question_text}' must be an integer between 1 and 5.");
                            }
                        } elseif ($question->question_type === QuestionType::YesNo) {
                            if (! in_array($val, [true, false, 1, 0, '1', '0', 'yes', 'no', 'true', 'false'], true)) {
                                $validator->errors()->add("answers.{$index}.value", "Yes/No question '{$question->question_text}' must be a boolean value.");
                            }
                        }
                    }
                }

                // Verify all required questions have non-empty answers
                $submittedQuestionIds = $submittedAnswers->where('value', '!==', null)
                    ->where('value', '!==', '')
                    ->pluck('question_id')
                    ->all();

                foreach ($activeQuestions as $question) {
                    if ($question->is_required && ! in_array($question->id, $submittedQuestionIds, true)) {
                        $validator->errors()->add('answers', "Required question '{$question->question_text}' is missing an answer.");
                    }
                }
            },
        ];
    }
}

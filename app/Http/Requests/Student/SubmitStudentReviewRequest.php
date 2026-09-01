<?php

namespace App\Http\Requests\Student;

use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Enums\ReviewWindowStatus;
use App\Models\Question;
use App\Models\ReviewParticipation;
use App\Models\ReviewWindow;
use App\Models\StudentEnrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitStudentReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isStudent();
    }

    public function rules(): array
    {
        return [
            'review_window_id' => ['required', 'integer', 'exists:review_windows,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
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

                $user = $this->user();
                $windowId = $this->input('review_window_id');
                $sectionId = $this->input('section_id');
                $submittedAnswers = collect($this->input('answers', []));

                // 1. Verify review window is Active and currently within open date range
                $window = ReviewWindow::find($windowId);
                if (! $window || $window->status !== ReviewWindowStatus::Active || ! now()->between($window->starts_at, $window->ends_at)) {
                    $validator->errors()->add('review_window_id', 'The selected review window is not currently open for submissions (it is either not active or outside the start and end date window).');
                    return;
                }

                // 2. Verify student is enrolled in the section
                $isEnrolled = StudentEnrollment::where('section_id', $sectionId)
                    ->where('student_id', $user->id)
                    ->exists();

                if (! $isEnrolled) {
                    $validator->errors()->add('section_id', 'You are not enrolled in this section.');
                    return;
                }

                // 3. Verify student has not already submitted
                $hasSubmitted = ReviewParticipation::where('review_window_id', $windowId)
                    ->where('section_id', $sectionId)
                    ->where('student_id', $user->id)
                    ->exists();

                if ($hasSubmitted) {
                    $validator->errors()->add('review', 'You have already submitted a review for this section in this review window.');
                    return;
                }

                // 4. Validate active student review questions
                $activeQuestions = Question::where('form_type', FormType::StudentReview)
                    ->where('is_active', true)
                    ->get()
                    ->keyBy('id');

                // Check that submitted question_ids belong to active student_review questions
                foreach ($submittedAnswers as $index => $answer) {
                    $qId = $answer['question_id'] ?? null;
                    if (! $activeQuestions->has($qId)) {
                        $validator->errors()->add("answers.{$index}.question_id", "Question ID {$qId} is not an active student review question.");
                        continue;
                    }

                    $question = $activeQuestions->get($qId);
                    $val = $answer['value'] ?? null;

                    // Check value shape according to question type
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

                // Check that all required active questions have a non-empty answer
                $submittedQuestionIds = $submittedAnswers
                    ->filter(fn ($a) => isset($a['value']) && $a['value'] !== '' && ! is_null($a['value']))
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

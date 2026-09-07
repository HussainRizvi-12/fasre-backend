<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Models\ReviewResponse;

/**
 * Single source of truth for student-review results aggregation.
 *
 * Previously this logic was copy-pasted across three controllers
 * (Admin\ReviewResultsController, Student\StudentReviewController,
 * Admin\ExportController) — a change to the k-anonymity rule or scoring
 * math had to be applied in three places. Every consumer must use this
 * service so the anonymity threshold and aggregation math stay identical
 * everywhere.
 */
class ReviewAggregationService
{
    /**
     * Anonymity threshold: sections with fewer responses than this are
     * suppressed entirely (k-anonymity rule).
     */
    public const ANONYMITY_THRESHOLD = 5;

    /**
     * Aggregate all active student-review answers for one
     * window × section pair.
     *
     * @param  iterable<\App\Models\Question>  $questions  Active student-review questions.
     * @param  bool  $includeTextResponses  When true, free-text answers are
     *               returned verbatim for authorized surfaces (admin QA
     *               review). Responses are anonymous by table isolation —
     *               NEVER enable this for student-facing endpoints.
     * @return array{response_count: int, is_suppressed: bool, questions: array<int, array<string, mixed>>}
     */
    public function aggregateSection(int $windowId, int $sectionId, iterable $questions, bool $includeTextResponses = false): array
    {
        $responses = ReviewResponse::where('review_window_id', $windowId)
            ->where('section_id', $sectionId)
            ->get();

        $responseCount = $responses->count();
        $isSuppressed = $responseCount < self::ANONYMITY_THRESHOLD;

        $questionAggregates = [];

        if (! $isSuppressed) {
            foreach ($questions as $q) {
                $qId = (string) $q->id;
                $answers = $responses->pluck("answers_json.{$qId}")->filter(fn ($v) => ! is_null($v) && $v !== '');

                if ($q->question_type === QuestionType::Rating) {
                    $questionAggregates[] = [
                        'question_id' => $q->id,
                        'question_text' => $q->question_text,
                        'type' => 'rating',
                        'average' => $answers->count() > 0 ? round((float) $answers->avg(), 2) : 0.0,
                        'response_count' => $answers->count(),
                    ];
                } elseif ($q->question_type === QuestionType::YesNo) {
                    $yesCount = $answers->filter(fn ($v) => in_array($v, [true, 1, '1', 'yes', 'true'], true))->count();
                    $questionAggregates[] = [
                        'question_id' => $q->id,
                        'question_text' => $q->question_text,
                        'type' => 'yes_no',
                        'percentage_yes' => $answers->count() > 0 ? round(($yesCount / $answers->count()) * 100, 2) : 0.0,
                        'response_count' => $answers->count(),
                    ];
                } else {
                    $entry = [
                        'question_id' => $q->id,
                        'question_text' => $q->question_text,
                        'type' => 'text',
                        'submission_count' => $answers->count(),
                    ];

                    if ($includeTextResponses) {
                        $entry['responses'] = $answers->map(fn ($v) => (string) $v)->values()->all();
                    }

                    $questionAggregates[] = $entry;
                }
            }
        }

        return [
            'response_count' => $responseCount,
            'is_suppressed' => $isSuppressed,
            'questions' => $questionAggregates,
        ];
    }
}

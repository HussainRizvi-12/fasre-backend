<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\ReviewResponse;
use Illuminate\Support\Collection;

/**
 * Single source of truth for student-review results aggregation.
 *
 * Implements disclosure controls:
 * 1. Section-level suppression threshold (ANONYMITY_THRESHOLD = 5).
 * 2. Per-question item-level suppression: optional items with < 5 valid answers
 *    are suppressed even if the section as a whole has >= 5 responses.
 * 3. Text responses are restricted to authorized QA surfaces and suppressed
 *    if the response count is below threshold.
 */
class ReviewAggregationService
{
    /**
     * Minimum disclosure threshold for section and item-level results.
     */
    public const ANONYMITY_THRESHOLD = 5;

    /**
     * Aggregate all active student-review answers for one window × section pair.
     *
     * @param  iterable<Question|object>  $questions
     * @return array{response_count: int, is_suppressed: bool, message: ?string, questions: array<int, array<string, mixed>>}
     */
    public function aggregateSection(int $windowId, int $sectionId, iterable $questions, bool $includeTextResponses = false): array
    {
        $responses = ReviewResponse::where('review_window_id', $windowId)
            ->where('section_id', $sectionId)
            ->get();

        return $this->aggregateResponses($responses, $questions, $includeTextResponses);
    }

    /**
     * Load only the requested sections in bounded batches. The result contract
     * and disclosure rules are shared with the single-section/mobile path.
     *
     * @return array<int, array<string, mixed>>
     */
    public function aggregateSections(int $windowId, array $sectionIds, iterable $questions, bool $includeTextResponses = false): array
    {
        $questions = collect($questions);
        $aggregates = [];
        foreach (array_chunk(array_values(array_unique($sectionIds)), 100) as $ids) {
            $responses = ReviewResponse::where('review_window_id', $windowId)
                ->whereIn('section_id', $ids)->get(['section_id', 'answers_json'])->groupBy('section_id');
            foreach ($ids as $id) {
                $aggregates[$id] = $this->aggregateResponses($responses->get($id, collect()), $questions, $includeTextResponses);
            }
        }

        return $aggregates;
    }

    private function aggregateResponses(Collection $responses, iterable $questions, bool $includeTextResponses): array
    {
        $responseCount = $responses->count();
        $isSectionSuppressed = $responseCount < self::ANONYMITY_THRESHOLD;

        $questionAggregates = [];

        if (! $isSectionSuppressed) {
            foreach ($questions as $q) {
                $qId = (string) $q->id;
                $answers = $responses->pluck("answers_json.{$qId}")->filter(fn ($v) => ! is_null($v) && $v !== '');
                $validCount = $answers->count();
                $isItemSuppressed = $validCount < self::ANONYMITY_THRESHOLD;

                $qType = $q->question_type instanceof QuestionType ? $q->question_type : QuestionType::tryFrom($q->question_type);

                if ($qType === QuestionType::Rating) {
                    $questionAggregates[] = [
                        'question_id' => $q->id,
                        'question_text' => $q->question_text,
                        'type' => 'rating',
                        'average' => (! $isItemSuppressed && $validCount > 0) ? round((float) $answers->avg(), 2) : ($validCount > 0 && ! $isItemSuppressed ? 0.0 : null),
                        'response_count' => $validCount,
                        'is_suppressed' => $isItemSuppressed,
                        'status' => $isItemSuppressed ? 'suppressed_low_item_count' : 'available',
                    ];
                } elseif ($qType === QuestionType::YesNo) {
                    $yesCount = $answers->filter(fn ($v) => in_array($v, [true, 1, '1', 'yes', 'true'], true))->count();
                    $questionAggregates[] = [
                        'question_id' => $q->id,
                        'question_text' => $q->question_text,
                        'type' => 'yes_no',
                        'percentage_yes' => (! $isItemSuppressed && $validCount > 0) ? round(($yesCount / $validCount) * 100, 2) : null,
                        'response_count' => $validCount,
                        'is_suppressed' => $isItemSuppressed,
                        'status' => $isItemSuppressed ? 'suppressed_low_item_count' : 'available',
                    ];
                } else {
                    $entry = [
                        'question_id' => $q->id,
                        'question_text' => $q->question_text,
                        'type' => 'text',
                        'submission_count' => $validCount,
                        'is_suppressed' => $isItemSuppressed,
                    ];

                    if ($includeTextResponses && ! $isItemSuppressed) {
                        $entry['responses'] = $answers->map(fn ($v) => (string) $v)->values()->all();
                    }

                    $questionAggregates[] = $entry;
                }
            }
        }

        return [
            'response_count' => $responseCount,
            'is_suppressed' => $isSectionSuppressed,
            'message' => $isSectionSuppressed ? 'Insufficient responses to display section results (< 5 responses).' : null,
            'questions' => $questionAggregates,
        ];
    }
}

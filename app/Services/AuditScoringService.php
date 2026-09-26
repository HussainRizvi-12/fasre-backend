<?php

namespace App\Services;

use App\Models\AuditAssignment;
use App\Models\FormVersion;

class AuditScoringService
{
    /**
     * Default institutional outcome bands when form version has no custom bands.
     */
    public const DEFAULT_BANDS = [
        [
            'key' => 'needs_improvement',
            'label' => 'Needs Improvement',
            'min' => 0.0,
            'max' => 59.99,
            'color' => 'danger',
            'outcome' => 'needsImprovement',
        ],
        [
            'key' => 'satisfactory',
            'label' => 'Satisfactory',
            'min' => 60.0,
            'max' => 84.99,
            'color' => 'warning',
            'outcome' => 'meetsStandard',
        ],
        [
            'key' => 'exemplary',
            'label' => 'Exemplary',
            'min' => 85.0,
            'max' => 100.0,
            'color' => 'success',
            'outcome' => 'exceedsStandard',
        ],
    ];

    /**
     * Calculates the rubric score and assigns the centralized outcome band.
     *
     * @param  array<string, mixed>  $answers
     * @return array{total_score: float|null, outcome_band: string|null, band_label: string|null, band_color: string|null, outcome: string|null}
     */
    public static function evaluate(array $answers, ?FormVersion $formVersion = null): array
    {
        $questionTypes = [];
        if ($formVersion) {
            foreach ($formVersion->getQuestions() as $q) {
                $qId = (string) ($q['id'] ?? '');
                $type = $q['question_type'] ?? null;
                if ($type instanceof \App\Enums\QuestionType) {
                    $type = $type->value;
                }
                $questionTypes[$qId] = (string) $type;
            }
        } else {
            // For legacy / unversioned audits: lookup explicit question definitions from Question bank
            $qIds = [];
            foreach (array_keys($answers) as $k) {
                if (is_numeric($k)) {
                    $qIds[] = (int) $k;
                }
            }
            if (! empty($qIds)) {
                $dbQuestions = \App\Models\Question::whereIn('id', $qIds)->get();
                foreach ($dbQuestions as $q) {
                    $type = $q->question_type instanceof \App\Enums\QuestionType
                        ? $q->question_type->value
                        : (string) $q->question_type;
                    $questionTypes[(string) $q->id] = $type;
                }
            }
        }

        $scorableValues = [];
        foreach ($answers as $key => $val) {
            $qType = $questionTypes[(string) $key] ?? null;

            // Free text, comments, and attachments never contribute to rubric scores
            if (in_array($qType, ['text', 'textarea', 'comment', 'file', 'attachment'], true)) {
                continue;
            }

            if ($qType === 'yes_no') {
                if (in_array($val, [true, 1, '1', 'yes', 'true', 'Yes', 'TRUE'], true)) {
                    $scorableValues[] = 100.0;
                } elseif (in_array($val, [false, 0, '0', 'no', 'false', 'No', 'FALSE'], true)) {
                    $scorableValues[] = 0.0;
                }
            } elseif ($qType === 'rating') {
                if (is_numeric($val)) {
                    $num = (float) $val;
                    if ($num <= 5.0 && $num >= 0.0) {
                        $scorableValues[] = $num * 20.0;
                    } else {
                        $scorableValues[] = max(0.0, min(100.0, $num));
                    }
                }
            } else {
                // Fallback / question type unknown:
                if (is_bool($val)) {
                    $scorableValues[] = $val ? 100.0 : 0.0;
                } elseif ($val === 1 || $val === '1' || (is_string($val) && in_array(strtolower($val), ['yes', 'true'], true))) {
                    $scorableValues[] = 100.0;
                } elseif ($val === 0 || $val === '0' || (is_string($val) && in_array(strtolower($val), ['no', 'false'], true))) {
                    $scorableValues[] = 0.0;
                } elseif (is_numeric($val)) {
                    $num = (float) $val;
                    if ($num <= 5.0 && $num >= 0.0) {
                        $scorableValues[] = $num * 20.0;
                    } else {
                        $scorableValues[] = max(0.0, min(100.0, $num));
                    }
                }
            }
        }

        if (empty($scorableValues)) {
            return [
                'total_score' => null,
                'outcome_band' => null,
                'band_label' => null,
                'band_color' => null,
                'outcome' => null,
            ];
        }

        $average = array_sum($scorableValues) / count($scorableValues);
        $totalScore = round($average, 2);

        $bands = self::DEFAULT_BANDS;
        if ($formVersion && is_array($formVersion->scoring_rules_json) && ! empty($formVersion->scoring_rules_json['bands'])) {
            $bands = $formVersion->scoring_rules_json['bands'];
        }

        $matchedBand = null;
        foreach ($bands as $band) {
            $min = (float) ($band['min'] ?? 0);
            $max = (float) ($band['max'] ?? 100);
            if ($totalScore >= $min && $totalScore <= $max) {
                $matchedBand = $band;
                break;
            }
        }

        if (! $matchedBand && ! empty($bands)) {
            $matchedBand = $totalScore >= 85 ? end($bands) : reset($bands);
        }

        $bandLabel = $matchedBand['label'] ?? null;
        $outcome = $matchedBand['outcome'] ?? null;

        // Never report a critical, action-plan-required, or failing band as meetsStandard
        if (! $outcome) {
            $labelLower = strtolower($bandLabel ?? '');
            if (str_contains($labelLower, 'critical')
                || str_contains($labelLower, 'concern')
                || str_contains($labelLower, 'needs improvement')
                || str_contains($labelLower, 'developmental')
                || ($matchedBand['requires_action_plan'] ?? false)
                || (float) ($matchedBand['min'] ?? 0) < 60.0
            ) {
                $outcome = 'needsImprovement';
            } elseif (str_contains($labelLower, 'commendable')
                || str_contains($labelLower, 'exemplary')
                || (float) ($matchedBand['min'] ?? 0) >= 85.0
            ) {
                $outcome = 'exceedsStandard';
            } else {
                $outcome = 'meetsStandard';
            }
        }

        $color = $matchedBand['color'] ?? ($outcome === 'needsImprovement' ? 'danger' : ($outcome === 'exceedsStandard' ? 'success' : 'info'));

        return [
            'total_score' => $totalScore,
            'outcome_band' => $bandLabel,
            'band_label' => $bandLabel,
            'band_color' => $color,
            'outcome' => $outcome,
        ];
    }
}

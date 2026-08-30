<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewResultsController extends Controller
{
    /**
     * GET /api/admin/review-results?review_window_id=&section_id=
     * Returns aggregated evaluation metrics with server-side anonymity suppression.
     */
    public function index(Request $request): JsonResponse
    {
        $windowId = $request->query('review_window_id') ?? ReviewWindow::latest('starts_at')->first()?->id;

        if (! $windowId) {
            return response()->json([
                'data' => [],
                'message' => 'No review window found.',
            ]);
        }

        $window = ReviewWindow::find($windowId);
        if (! $window) {
            return response()->json(['message' => 'Review window not found.'], 404);
        }

        $sectionsQuery = Section::with(['course.department', 'facultyAssignments.faculty']);
        if ($request->filled('section_id')) {
            $sectionsQuery->where('id', $request->query('section_id'));
        }

        $sections = $sectionsQuery->get();
        $questions = Question::where('form_type', FormType::StudentReview)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $data = [];

        foreach ($sections as $section) {
            $responses = ReviewResponse::where('review_window_id', $window->id)
                ->where('section_id', $section->id)
                ->get();

            $responseCount = $responses->count();
            $isSuppressed = $responseCount < 5;

            $primaryFaculty = $section->facultyAssignments->firstWhere('is_primary', true)?->faculty;
            $questionAggregates = [];

            if (! $isSuppressed) {
                foreach ($questions as $q) {
                    $qId = (string) $q->id;
                    $answers = $responses->pluck("answers_json.{$qId}")->filter(fn ($v) => ! is_null($v) && $v !== '');

                    if ($q->question_type === QuestionType::Rating) {
                        $avg = $answers->count() > 0 ? round((float) $answers->avg(), 2) : 0.0;
                        $questionAggregates[] = [
                            'question_id' => $q->id,
                            'question_text' => $q->question_text,
                            'type' => 'rating',
                            'average' => $avg,
                            'response_count' => $answers->count(),
                        ];
                    } elseif ($q->question_type === QuestionType::YesNo) {
                        $yesCount = $answers->filter(fn ($v) => in_array($v, [true, 1, '1', 'yes', 'true'], true))->count();
                        $yesPct = $answers->count() > 0 ? round(($yesCount / $answers->count()) * 100, 2) : 0.0;
                        $questionAggregates[] = [
                            'question_id' => $q->id,
                            'question_text' => $q->question_text,
                            'type' => 'yes_no',
                            'percentage_yes' => $yesPct,
                            'response_count' => $answers->count(),
                        ];
                    } else {
                        // Text / Textarea: Return submission count only (no individual text responses exposed)
                        $questionAggregates[] = [
                            'question_id' => $q->id,
                            'question_text' => $q->question_text,
                            'type' => 'text',
                            'submission_count' => $answers->count(),
                        ];
                    }
                }
            }

            $data[] = [
                'section_id' => $section->id,
                'section_name' => $section->name,
                'term' => $section->term,
                'course' => [
                    'id' => $section->course?->id,
                    'code' => $section->course?->code,
                    'title' => $section->course?->title,
                ],
                'primary_faculty_name' => $primaryFaculty?->name,
                'response_count' => $responseCount,
                'is_suppressed' => $isSuppressed,
                'message' => $isSuppressed ? 'Insufficient responses to display results (< 5 responses).' : null,
                'questions' => $isSuppressed ? [] : $questionAggregates,
            ];
        }

        return response()->json([
            'review_window' => [
                'id' => $window->id,
                'title' => $window->title,
                'status' => $window->status->value,
            ],
            'data' => $data,
        ]);
    }
}

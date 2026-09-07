<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FormType;
use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Services\ReviewAggregationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewResultsController extends Controller
{
    /**
     * GET /api/admin/review-results?review_window_id=&section_id=
     * Returns aggregated evaluation metrics with server-side anonymity suppression.
     * Includes anonymous free-text responses (admin QA surface only).
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

        $aggregator = app(ReviewAggregationService::class);
        $data = [];

        foreach ($sections as $section) {
            $aggregate = $aggregator->aggregateSection(
                (int) $window->id,
                (int) $section->id,
                $questions,
                includeTextResponses: true
            );

            $data[] = [
                'section_id' => $section->id,
                'section_name' => $section->name,
                'term' => $section->term,
                'course' => [
                    'id' => $section->course?->id,
                    'code' => $section->course?->code,
                    'title' => $section->course?->title,
                ],
                'primary_faculty_name' => $section->facultyAssignments->firstWhere('is_primary', true)?->faculty?->name,
                'response_count' => $aggregate['response_count'],
                'is_suppressed' => $aggregate['is_suppressed'],
                'message' => $aggregate['is_suppressed'] ? 'Insufficient responses to display results (< 5 responses).' : null,
                'questions' => $aggregate['questions'],
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

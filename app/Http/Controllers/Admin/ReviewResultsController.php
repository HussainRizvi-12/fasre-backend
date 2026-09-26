<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FormType;
use App\Enums\ReviewWindowStatus;
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
     * Controlled release: active collection cycles are locked against live querying.
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

        $window = ReviewWindow::with('formVersion')->find($windowId);
        if (! $window) {
            return response()->json(['message' => 'Review window not found.'], 404);
        }

        // Controlled release gate: Live answer totals cannot be queried while window is active
        if ($window->status === ReviewWindowStatus::Active || $window->status === ReviewWindowStatus::Draft) {
            return response()->json([
                'review_window' => [
                    'id' => $window->id,
                    'title' => $window->title,
                    'status' => $window->status->value,
                ],
                'data' => [],
                'is_locked' => true,
                'message' => 'Results are locked during active review collection to safeguard confidentiality. Full aggregates are released upon cycle closure and authorized publication.',
            ]);
        }

        // Department isolation check for delegated administrators
        if ($request->user()->department_id) {
            $userDeptId = $request->user()->department_id;
            if ($window->department_id && $window->department_id !== $userDeptId) {
                return response()->json(['message' => 'Forbidden. This review window belongs to another department.'], 403);
            }
            $hasEligibleDeptSection = $window->sections()->whereHas('course', function ($q) use ($userDeptId) {
                $q->where('department_id', $userDeptId);
            })->exists();
            if (! $hasEligibleDeptSection && $window->department_id !== $userDeptId) {
                return response()->json(['message' => 'Forbidden. You do not have permission to view results outside your department.'], 403);
            }
        }

        $sectionsQuery = Section::with(['course.department', 'facultyAssignments.faculty']);
        if ($request->user()->department_id) {
            $sectionsQuery->whereHas('course', fn ($q) => $q->where('department_id', $request->user()->department_id));
        }
        if ($request->filled('section_id')) {
            $sectionsQuery->where('id', $request->query('section_id'));
        }

        $sections = $sectionsQuery->get();

        // Use frozen form version questions if available
        if ($window->formVersion) {
            $questions = collect($window->formVersion->getQuestions())->map(fn ($q) => (object) [
                'id' => $q['id'],
                'question_text' => $q['question_text'],
                'question_type' => \App\Enums\QuestionType::tryFrom($q['question_type']) ?? $q['question_type'],
            ]);
        } else {
            $questions = Question::where('form_type', FormType::StudentReview)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        }

        $aggregator = app(ReviewAggregationService::class);
        $data = [];

        foreach ($sections as $section) {
            if (! $window->isSectionEligible($section->id)) {
                continue;
            }

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
                'message' => $aggregate['message'],
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

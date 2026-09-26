<?php

namespace App\Http\Controllers\Student;

use App\Enums\FormType;
use App\Enums\ReviewWindowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\SubmitStudentReviewRequest;
use App\Models\Question;
use App\Models\ReviewParticipation;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\StudentEnrollment;
use App\Services\ReviewAggregationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StudentReviewController extends Controller
{
    /**
     * 4.1 GET /api/student/enrolled-sections
     * Returns the authenticated student's enrolled sections with review status.
     * Preserves historical enrollment records across past semesters.
     */
    public function enrolledSections(Request $request): JsonResponse
    {
        $student = $request->user();
        $activeWindow = ReviewWindow::with(['department', 'formVersion', 'sections'])
            ->where('status', ReviewWindowStatus::Active)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->orderByDesc('starts_at')
            ->first();

        $enrollments = StudentEnrollment::with([
            'section.course.department',
            'section.facultyAssignments.faculty',
        ])
            ->where('student_id', $student->id)
            ->get();

        $data = $enrollments->map(function ($enrollment) use ($activeWindow, $student) {
            $section = $enrollment->section;
            $course = $section?->course;
            $primaryFaculty = $section?->facultyAssignments?->firstWhere('is_primary', true)?->faculty
                ?? $section?->facultyAssignments?->first()?->faculty;

            // Compute review status flag for active window
            if (! $activeWindow) {
                $reviewStatus = 'no_active_window';
                $isEligible = false;
            } else {
                $hasSubmitted = ReviewParticipation::where('review_window_id', $activeWindow->id)
                    ->where('section_id', $section->id)
                    ->where('student_id', $student->id)
                    ->exists();

                $isScopeEligible = $activeWindow->isSectionEligible($section->id);
                $isRosterEligible = $activeWindow->isStudentEligible($student->id, $section->id);
                $isEligible = $isScopeEligible && $isRosterEligible;

                if ($hasSubmitted) {
                    $reviewStatus = 'submitted';
                } elseif (! $isEligible) {
                    $reviewStatus = 'out_of_scope';
                } else {
                    $reviewStatus = 'not_started';
                }
            }

            return [
                'enrollment_id' => $enrollment->id,
                'section' => [
                    'id' => $section?->id,
                    'name' => $section?->name,
                    'term' => $section?->term,
                ],
                'course' => [
                    'id' => $course?->id,
                    'code' => $course?->code,
                    'title' => $course?->title,
                    'credit_hours' => $course?->credit_hours,
                ],
                'primary_faculty_name' => $primaryFaculty?->name,
                'review_status' => $reviewStatus,
                'is_eligible' => $isEligible,
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * 4.2 GET /api/student/review-windows/active
     * Returns currently active review window or null.
     */
    public function activeReviewWindow(): JsonResponse
    {
        $activeWindow = ReviewWindow::with(['department', 'formVersion'])
            ->where('status', ReviewWindowStatus::Active)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->orderByDesc('starts_at')
            ->first();

        if (! $activeWindow) {
            return response()->json([
                'data' => null,
                'message' => 'No active review window at this time.',
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $activeWindow->id,
                'title' => $activeWindow->title,
                'term' => $activeWindow->term,
                'description' => $activeWindow->description,
                'starts_at' => $activeWindow->starts_at?->toIso8601String(),
                'ends_at' => $activeWindow->ends_at?->toIso8601String(),
                'status' => $activeWindow->status->value,
                'form_version' => $activeWindow->formVersion ? [
                    'id' => $activeWindow->formVersion->id,
                    'version_code' => $activeWindow->formVersion->version_code,
                    'title' => $activeWindow->formVersion->title,
                ] : null,
            ],
        ]);
    }

    /**
     * 4.3 GET /api/student/review-form?section_id=&review_window_id=
     * Validates academic scope & eligibility in order, then returns questions.
     */
    public function reviewForm(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'review_window_id' => ['required', 'integer'],
            'section_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid or missing query parameters.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $student = $request->user();
        $windowId = (int) $request->query('review_window_id');
        $sectionId = (int) $request->query('section_id');

        // Check 1: Review Window is active and within date range
        $window = ReviewWindow::with('formVersion')->find($windowId);
        if (! $window || $window->status !== ReviewWindowStatus::Active || ! now()->between($window->starts_at, $window->ends_at)) {
            return response()->json([
                'message' => 'The selected review window is not currently active or is outside the open submission date range.',
            ], 422);
        }

        // Check 2: Academic scope
        if (! $window->isSectionEligible($sectionId)) {
            return response()->json([
                'message' => 'Forbidden. This course section is not within the academic scope of this review cycle.',
            ], 403);
        }

        // Check 3: Student is enrolled in the section
        $isEnrolled = StudentEnrollment::where('section_id', $sectionId)
            ->where('student_id', $student->id)
            ->exists();

        if (! $isEnrolled) {
            return response()->json([
                'message' => 'Forbidden. You are not enrolled in this course section.',
            ], 403);
        }

        // Check 4: Student is in eligible roster
        if (! $window->isStudentEligible($student->id, $sectionId)) {
            return response()->json([
                'message' => 'Forbidden. You are not eligible to review this section in this cycle.',
            ], 403);
        }

        // Check 5: Student has not already submitted
        $hasSubmitted = ReviewParticipation::where('review_window_id', $windowId)
            ->where('section_id', $sectionId)
            ->where('student_id', $student->id)
            ->exists();

        if ($hasSubmitted) {
            return response()->json([
                'message' => 'Forbidden. You have already submitted a review for this section in this review window.',
            ], 403);
        }

        // Return frozen questions from form version if bound, else fallback to active question bank
        if ($window->formVersion) {
            $questions = collect($window->formVersion->getQuestions())->map(fn ($q) => [
                'id' => $q['id'],
                'question_text' => $q['question_text'],
                'question_type' => $q['question_type'],
                'is_required' => $q['is_required'] ?? true,
                'sort_order' => $q['sort_order'] ?? 0,
            ])->values()->all();
        } else {
            $questions = Question::where('form_type', FormType::StudentReview)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'question_text', 'question_type', 'is_required', 'sort_order']);
        }

        return response()->json([
            'data' => [
                'review_window_id' => $window->id,
                'section_id' => $sectionId,
                'questions' => $questions,
                'form_version' => $window->formVersion ? [
                    'id' => $window->formVersion->id,
                    'version_code' => $window->formVersion->version_code,
                    'title' => $window->formVersion->title,
                ] : null,
            ],
        ]);
    }

    /**
     * 4.4 POST /api/student/reviews
     * Submits an atomic, confidential review response.
     */
    public function store(SubmitStudentReviewRequest $request): JsonResponse
    {
        $student = $request->user();
        $windowId = (int) $request->input('review_window_id');
        $sectionId = (int) $request->input('section_id');
        $submittedAnswers = $request->input('answers');

        // Check window active and scope inside controller
        $window = ReviewWindow::with('formVersion')->find($windowId);
        if (! $window || $window->status !== ReviewWindowStatus::Active || ! now()->between($window->starts_at, $window->ends_at)) {
            return response()->json([
                'message' => 'The selected review window is not currently active or is outside the open submission date range.',
            ], 422);
        }

        if (! $window->isSectionEligible($sectionId) || ! $window->isStudentEligible($student->id, $sectionId)) {
            return response()->json([
                'message' => 'Forbidden. You are not eligible to review this section in this cycle.',
            ], 403);
        }

        // Generate non-reversible random token (never derived from student ID)
        $pseudonymToken = (string) Str::uuid();

        // Format answers for JSON storage (key-by question_id and list)
        $formattedAnswers = [];
        foreach ($submittedAnswers as $answer) {
            $formattedAnswers[(string) $answer['question_id']] = $answer['value'];
        }

        try {
            DB::transaction(function () use ($windowId, $sectionId, $student, $pseudonymToken, $formattedAnswers) {
                // 0. Concurrency serialization: lock window row to serialize against admin closure
                $lockedWindow = ReviewWindow::whereKey($windowId)->lockForUpdate()->first();
                if (! $lockedWindow || $lockedWindow->status !== ReviewWindowStatus::Active || ! now()->between($lockedWindow->starts_at, $lockedWindow->ends_at)) {
                    abort(422, 'The review window was closed or expired before your submission could be recorded.');
                }

                // 1. Insert confidential response (Coarse date only to prevent timestamp correlation attack)
                ReviewResponse::create([
                    'review_window_id' => $windowId,
                    'section_id' => $sectionId,
                    'pseudonym_token' => $pseudonymToken,
                    'answers_json' => $formattedAnswers,
                    'submitted_at' => now()->startOfDay(),
                ]);

                // 2. Insert participation record for duplicate prevention (Exact timestamp for audit log)
                ReviewParticipation::create([
                    'review_window_id' => $windowId,
                    'section_id' => $sectionId,
                    'student_id' => $student->id,
                    'submitted_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            return response()->json([
                'message' => 'You have already submitted a review for this section in this review window.',
            ], 409);
        }

        $year = now()->year;
        $confirmationCode = 'FASRE-' . $year . '-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));

        return response()->json([
            'message' => 'Review submitted successfully.',
            'data' => [
                'confirmation_code' => $confirmationCode,
                'submitted_at' => now()->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * 6.1 GET /api/student/review-results/published
     * Returns aggregated evaluation results for published windows for student's enrolled sections.
     */
    public function publishedResults(Request $request): JsonResponse
    {
        $student = $request->user();

        // Fetch published review windows
        $publishedWindows = ReviewWindow::with('formVersion')
            ->where('status', ReviewWindowStatus::Published)
            ->orderByDesc('ends_at')
            ->get();

        if ($publishedWindows->isEmpty()) {
            return response()->json([
                'data' => [],
                'message' => 'No published review results found.',
            ]);
        }

        $enrolledSections = StudentEnrollment::with([
            'section.course',
            'section.facultyAssignments.faculty',
        ])
            ->where('student_id', $student->id)
            ->get()
            ->map(fn ($e) => $e->section);

        $aggregator = app(ReviewAggregationService::class);
        $results = [];

        foreach ($publishedWindows as $window) {
            // Use frozen questions from form version if available
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

            $windowSections = [];
            foreach ($enrolledSections as $section) {
                if (! $section || ! $window->isSectionEligible($section->id)) {
                    continue;
                }

                $aggregate = $aggregator->aggregateSection(
                    (int) $window->id,
                    (int) $section->id,
                    $questions,
                    includeTextResponses: false
                );

                $windowSections[] = [
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
                    'message' => $aggregate['is_suppressed'] ? 'Results suppressed (< 5 responses).' : null,
                    'questions' => $aggregate['questions'],
                ];
            }

            $results[] = [
                'review_window' => [
                    'id' => $window->id,
                    'title' => $window->title,
                    'term' => $window->term,
                    'published_at' => $window->published_at?->toIso8601String(),
                ],
                'sections' => $windowSections,
            ];
        }

        return response()->json([
            'data' => $results,
        ]);
    }
}

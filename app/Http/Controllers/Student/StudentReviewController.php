<?php

namespace App\Http\Controllers\Student;

use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Enums\ReviewWindowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\SubmitStudentReviewRequest;
use App\Models\Question;
use App\Models\ReviewParticipation;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\StudentEnrollment;
use Illuminate\Database\QueryException;
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
     */
    public function enrolledSections(Request $request): JsonResponse
    {
        $student = $request->user();
        $activeWindow = ReviewWindow::where('status', ReviewWindowStatus::Active)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->orderByDesc('starts_at')
            ->first();

        $enrollments = StudentEnrollment::with([
            'section.course',
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
            } else {
                $hasSubmitted = ReviewParticipation::where('review_window_id', $activeWindow->id)
                    ->where('section_id', $section->id)
                    ->where('student_id', $student->id)
                    ->exists();

                $reviewStatus = $hasSubmitted ? 'submitted' : 'not_started';
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
        $activeWindow = ReviewWindow::where('status', ReviewWindowStatus::Active)
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
                'description' => $activeWindow->description,
                'starts_at' => $activeWindow->starts_at?->toIso8601String(),
                'ends_at' => $activeWindow->ends_at?->toIso8601String(),
                'status' => $activeWindow->status->value,
            ],
        ]);
    }

    /**
     * 4.3 GET /api/student/review-form?section_id=&review_window_id=
     * Validates eligibility in order, then returns active student review questions.
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
        $window = ReviewWindow::find($windowId);
        if (! $window || $window->status !== ReviewWindowStatus::Active || ! now()->between($window->starts_at, $window->ends_at)) {
            return response()->json([
                'message' => 'The selected review window is not currently active or is outside the open submission date range.',
            ], 422);
        }

        // Check 2: Student is enrolled in the section
        $isEnrolled = StudentEnrollment::where('section_id', $sectionId)
            ->where('student_id', $student->id)
            ->exists();

        if (! $isEnrolled) {
            return response()->json([
                'message' => 'Forbidden. You are not enrolled in this course section.',
            ], 403);
        }

        // Check 3: Student has not already submitted
        $hasSubmitted = ReviewParticipation::where('review_window_id', $windowId)
            ->where('section_id', $sectionId)
            ->where('student_id', $student->id)
            ->exists();

        if ($hasSubmitted) {
            return response()->json([
                'message' => 'Forbidden. You have already submitted a review for this section in this review window.',
            ], 403);
        }

        // Return active questions for student review
        $questions = Question::where('form_type', FormType::StudentReview)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'question_text', 'question_type', 'is_required', 'sort_order']);

        return response()->json([
            'data' => [
                'review_window_id' => $window->id,
                'section_id' => $sectionId,
                'questions' => $questions,
            ],
        ]);
    }

    /**
     * 4.4 POST /api/student/reviews
     * Submits an atomic, anonymous review response.
     */
    public function store(SubmitStudentReviewRequest $request): JsonResponse
    {
        $student = $request->user();
        $windowId = (int) $request->input('review_window_id');
        $sectionId = (int) $request->input('section_id');
        $submittedAnswers = $request->input('answers');

        // Generate non-reversible random token (never derived from student ID)
        $pseudonymToken = (string) Str::uuid();

        // Format answers for JSON storage (key-by question_id and list)
        $formattedAnswers = [];
        foreach ($submittedAnswers as $answer) {
            $formattedAnswers[(string) $answer['question_id']] = $answer['value'];
        }

        try {
            DB::transaction(function () use ($windowId, $sectionId, $student, $pseudonymToken, $formattedAnswers) {
                // 1. Insert anonymous response (Coarse date only to prevent timestamp correlation attack)
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
        } catch (UniqueConstraintViolationException|QueryException $e) {
            return response()->json([
                'message' => 'You have already submitted a review for this section in this review window.',
            ], 409);
        }

        return response()->json([
            'message' => 'Review submitted successfully.',
            'data' => [
                'pseudonym_token' => $pseudonymToken,
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
        $publishedWindows = ReviewWindow::where('status', ReviewWindowStatus::Published)
            ->orderByDesc('ends_at')
            ->get();

        if ($publishedWindows->isEmpty()) {
            return response()->json([
                'data' => [],
                'message' => 'No published review results available at this time.',
            ]);
        }

        $enrollments = StudentEnrollment::with([
            'section.course',
            'section.facultyAssignments.faculty',
        ])
            ->where('student_id', $student->id)
            ->get();

        $questions = Question::where('form_type', FormType::StudentReview)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $data = [];

        foreach ($publishedWindows as $window) {
            $sectionsData = [];

            foreach ($enrollments as $enrollment) {
                $section = $enrollment->section;
                if (! $section) {
                    continue;
                }

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
                            $questionAggregates[] = [
                                'question_id' => $q->id,
                                'question_text' => $q->question_text,
                                'type' => 'text',
                                'submission_count' => $answers->count(),
                            ];
                        }
                    }
                }

                $sectionsData[] = [
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

            $data[] = [
                'review_window' => [
                    'id' => $window->id,
                    'title' => $window->title,
                    'published_at' => $window->updated_at?->toIso8601String(),
                ],
                'sections' => $sectionsData,
            ];
        }

        return response()->json([
            'data' => $data,
        ]);
    }
}

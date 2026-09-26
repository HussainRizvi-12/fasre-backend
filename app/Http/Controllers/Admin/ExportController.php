<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAssignmentStatus;
use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Enums\ReviewWindowStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AuditAssignment;
use App\Models\Course;
use App\Models\Question;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\ReviewAggregationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function users(Request $request): StreamedResponse
    {
        return $this->streamCsv('fasre-users.csv', function ($file) {
            $this->writeRow($file, ['id', 'name', 'email', 'role', 'is_active', 'created_at']);

            User::query()->orderBy('id')->chunk(500, function ($users) use ($file) {
                foreach ($users as $u) {
                    $this->writeRow($file, [$u->id, $u->name, $u->email, $u->role->value, $u->is_active ? 'active' : 'inactive', $u->created_at?->toDateTimeString()]);
                }
            });
        });
    }

    public function courses(Request $request): StreamedResponse
    {
        return $this->streamCsv('fasre-courses.csv', function ($file) use ($request) {
            $this->writeRow($file, ['department', 'code', 'title', 'credit_hours']);

            $query = Course::with('department')->orderBy('code');
            if ($request->user()->department_id) {
                $query->where('department_id', $request->user()->department_id);
            }

            $query->chunk(500, function ($courses) use ($file) {
                foreach ($courses as $c) {
                    $this->writeRow($file, [$c->department?->name, $c->code, $c->title, $c->credit_hours]);
                }
            });
        });
    }

    public function sections(Request $request): StreamedResponse
    {
        return $this->streamCsv('fasre-sections.csv', function ($file) use ($request) {
            $this->writeRow($file, ['course_code', 'course_title', 'section', 'term']);

            $query = Section::with('course')->orderBy('id');
            if ($request->user()->department_id) {
                $query->whereHas('course', fn ($q) => $q->where('department_id', $request->user()->department_id));
            }

            $query->chunk(500, function ($sections) use ($file) {
                foreach ($sections as $s) {
                    $this->writeRow($file, [$s->course?->code, $s->course?->title, $s->name, $s->term]);
                }
            });
        });
    }

    public function enrollments(Request $request): StreamedResponse
    {
        return $this->streamCsv('fasre-enrollments.csv', function ($file) use ($request) {
            $this->writeRow($file, ['student_name', 'student_email', 'course_code', 'section', 'term', 'enrolled_at']);

            $query = StudentEnrollment::with(['student', 'section.course'])->orderBy('id');
            if ($request->user()->department_id) {
                $query->whereHas('section.course', fn ($q) => $q->where('department_id', $request->user()->department_id));
            }

            $query->chunk(500, function ($enrollments) use ($file) {
                foreach ($enrollments as $e) {
                    $this->writeRow($file, [
                        $e->student?->name,
                        $e->student?->email,
                        $e->section?->course?->code,
                        $e->section?->name,
                        $e->section?->term,
                        $e->created_at?->toDateString(),
                    ]);
                }
            });
        });
    }

    /**
     * Aggregated review results export with server-side anonymity suppression and release gates.
     */
    public function reviewResults(Request $request): StreamedResponse
    {
        $windowId = $request->query('review_window_id') ?? ReviewWindow::latest('starts_at')->first()?->id;

        $window = $windowId ? ReviewWindow::with('formVersion')->find($windowId) : null;
        abort_if(! $window, 404, 'Review window not found.');

        // Department isolation check for delegated administrators
        if ($request->user()->department_id) {
            $userDeptId = $request->user()->department_id;
            if ($window->department_id && $window->department_id !== $userDeptId) {
                abort(403, 'Forbidden. This review window belongs to another department.');
            }
        }

        // Controlled release gate: cannot export results while review collection is live
        abort_if(
            in_array($window->status, [ReviewWindowStatus::Draft, ReviewWindowStatus::Active], true),
            422,
            'Cannot export evaluation results while collection cycle is active or in draft.'
        );

        if ($window->formVersion) {
            $questions = collect($window->formVersion->getQuestions())->map(fn ($q) => (object) [
                'id' => $q['id'],
                'question_text' => $q['question_text'],
                'question_type' => QuestionType::tryFrom($q['question_type']) ?? $q['question_type'],
            ]);
        } else {
            $questions = Question::where('form_type', FormType::StudentReview)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        }

        $aggregator = app(ReviewAggregationService::class);

        return $this->streamCsv("fasre-review-results-{$window->id}.csv", function ($file) use ($window, $questions, $aggregator, $request) {
            $this->writeRow($file, ['course_code', 'course_title', 'section', 'term', 'primary_faculty', 'responses', 'suppressed', 'question', 'question_type', 'average_or_percentage']);

            $sectionsQuery = Section::with(['course', 'facultyAssignments.faculty'])->orderBy('id');
            if ($request->user()->department_id) {
                $sectionsQuery->whereHas('course', fn ($q) => $q->where('department_id', $request->user()->department_id));
            }
            $sections = $sectionsQuery->get();

            foreach ($sections as $section) {
                if (! $window->isSectionEligible($section->id)) {
                    continue;
                }

                $aggregate = $aggregator->aggregateSection((int) $window->id, (int) $section->id, $questions);

                $primary = $section->facultyAssignments->firstWhere('is_primary', true)?->faculty?->name;
                $base = [$section->course?->code, $section->course?->title, $section->name, $section->term, $primary, $aggregate['response_count'], $aggregate['is_suppressed'] ? 'yes' : 'no'];

                if ($aggregate['is_suppressed']) {
                    $this->writeRow($file, [...$base, '(suppressed — fewer than 5 responses)', '', '']);
                    continue;
                }

                foreach ($aggregate['questions'] as $q) {
                    if ($q['is_suppressed'] ?? false) {
                        $value = '(suppressed — fewer than 5 answers)';
                    } else {
                        $value = match ($q['type']) {
                            'rating' => $q['average'],
                            'yes_no' => $q['percentage_yes'],
                            default => $q['submission_count'],
                        };
                    }

                    $typeLabel = match ($q['type']) {
                        'rating' => 'rating (1-5)',
                        'yes_no' => 'yes/no (% yes)',
                        default => 'text (comments)',
                    };
                    $this->writeRow($file, [...$base, $q['question_text'], $typeLabel, $value]);
                }
            }
        });
    }

    public function auditAssignments(Request $request): StreamedResponse
    {
        return $this->streamCsv('fasre-audit-assignments.csv', function ($file) use ($request) {
            $this->writeRow($file, ['id', 'auditor', 'auditee', 'course_code', 'section', 'term', 'status', 'total_score', 'due_date', 'submitted_at', 'approved_at', 'admin_remarks']);

            $query = AuditAssignment::with(['auditor', 'auditee', 'section.course'])
                ->orderByDesc('created_at');

            if ($request->user()->department_id) {
                $userDeptId = $request->user()->department_id;
                $query->where(function ($q) use ($userDeptId) {
                    $q->whereHas('section.course', fn ($sq) => $sq->where('department_id', $userDeptId))
                      ->orWhereHas('auditee', fn ($aq) => $aq->where('department_id', $userDeptId));
                });
            }

            $query->chunk(500, function ($audits) use ($file) {
                foreach ($audits as $a) {
                    $this->writeRow($file, [
                        $a->id,
                        $a->auditor?->name,
                        $a->auditee?->name,
                        $a->section?->course?->code,
                        $a->section?->name,
                        $a->section?->term,
                        $a->status->value,
                        $a->total_score,
                        $a->due_date?->toDateString(),
                        $a->submitted_at?->toDateTimeString(),
                        $a->approved_at?->toDateTimeString(),
                        $a->admin_remarks,
                    ]);
                }
            });
        });
    }

    public function activityLogs(Request $request): StreamedResponse
    {
        return $this->streamCsv('fasre-activity-logs.csv', function ($file) {
            $this->writeRow($file, ['id', 'created_at', 'user', 'action', 'subject', 'properties']);

            ActivityLog::with('user')->orderByDesc('id')->chunk(500, function ($logs) use ($file) {
                foreach ($logs as $l) {
                    $this->writeRow($file, [
                        $l->id,
                        $l->created_at?->toIso8601String(),
                        $l->user?->name ?? 'System',
                        $l->action,
                        $l->subject_type ? class_basename($l->subject_type) . "#{$l->subject_id}" : '',
                        json_encode($l->properties),
                    ]);
                }
            });
        });
    }

    /**
     * Sanitizes CSV cell contents against Formula Injection attacks.
     */
    private function sanitizeCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        // Prefix leading formula trigger characters
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function writeRow($file, array $row): void
    {
        $sanitized = array_map([$this, 'sanitizeCell'], $row);
        fputcsv($file, $sanitized);
    }

    private function streamCsv(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer) {
            $file = fopen('php://output', 'w');

            // UTF-8 BOM so Excel renders non-ASCII characters correctly
            fwrite($file, "\xEF\xBB\xBF");
            $writer($file);
            fclose($file);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}

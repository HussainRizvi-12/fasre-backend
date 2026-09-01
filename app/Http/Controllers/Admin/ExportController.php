<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAssignmentStatus;
use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AuditAssignment;
use App\Models\Course;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function users(Request $request): StreamedResponse
    {
        return $this->streamCsv('fasre-users.csv', function ($file) {
            fputcsv($file, ['id', 'name', 'email', 'role', 'is_active', 'created_at']);

            User::query()->orderBy('id')->chunk(500, function ($users) use ($file) {
                foreach ($users as $u) {
                    fputcsv($file, [$u->id, $u->name, $u->email, $u->role->value, $u->is_active ? 'active' : 'inactive', $u->created_at?->toDateTimeString()]);
                }
            });
        });
    }

    public function courses(Request $request): StreamedResponse
    {
        return $this->streamCsv('fasre-courses.csv', function ($file) {
            fputcsv($file, ['department', 'code', 'title', 'credit_hours']);

            Course::with('department')->orderBy('code')->chunk(500, function ($courses) use ($file) {
                foreach ($courses as $c) {
                    fputcsv($file, [$c->department?->name, $c->code, $c->title, $c->credit_hours]);
                }
            });
        });
    }

    public function sections(Request $request): StreamedResponse
    {
        return $this->streamCsv('fasre-sections.csv', function ($file) {
            fputcsv($file, ['course_code', 'course_title', 'section', 'term']);

            Section::with('course')->orderBy('id')->chunk(500, function ($sections) use ($file) {
                foreach ($sections as $s) {
                    fputcsv($file, [$s->course?->code, $s->course?->title, $s->name, $s->term]);
                }
            });
        });
    }

    public function enrollments(Request $request): StreamedResponse
    {
        return $this->streamCsv('fasre-enrollments.csv', function ($file) {
            fputcsv($file, ['student_name', 'student_email', 'course_code', 'section', 'term', 'enrolled_at']);

            \App\Models\StudentEnrollment::with(['student', 'section.course'])
                ->orderBy('id')
                ->chunk(500, function ($enrollments) use ($file) {
                    foreach ($enrollments as $e) {
                        fputcsv($file, [
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
     * Aggregated (anonymity-preserving) review results — same k≥5
     * suppression rules as the live API, flattened to CSV.
     */
    public function reviewResults(Request $request): StreamedResponse
    {
        $windowId = $request->query('review_window_id') ?? ReviewWindow::latest('starts_at')->first()?->id;

        $window = $windowId ? ReviewWindow::find($windowId) : null;
        abort_if(! $window, 404, 'Review window not found.');

        $questions = \App\Models\Question::where('form_type', FormType::StudentReview)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return $this->streamCsv("fasre-review-results-{$window->id}.csv", function ($file) use ($window, $questions) {
            fputcsv($file, ['course_code', 'course_title', 'section', 'term', 'primary_faculty', 'responses', 'suppressed', 'question', 'question_type', 'average_or_percentage']);

            $sections = Section::with(['course', 'facultyAssignments.faculty'])->orderBy('id')->get();

            foreach ($sections as $section) {
                $responses = ReviewResponse::where('review_window_id', $window->id)
                    ->where('section_id', $section->id)
                    ->get();

                $suppressed = $responses->count() < 5;
                $primary = $section->facultyAssignments->firstWhere('is_primary', true)?->faculty?->name;
                $base = [$section->course?->code, $section->course?->title, $section->name, $section->term, $primary, $responses->count(), $suppressed ? 'yes' : 'no'];

                if ($suppressed) {
                    fputcsv($file, [...$base, '(suppressed — fewer than 5 responses)', '', '']);
                    continue;
                }

                foreach ($questions as $q) {
                    $answers = $responses->pluck("answers_json.{$q->id}")->filter(fn ($v) => ! is_null($v) && $v !== '');

                    if ($q->question_type === QuestionType::Rating) {
                        $avg = $answers->count() > 0 ? round((float) $answers->avg(), 2) : 0.0;
                        fputcsv($file, [...$base, $q->question_text, 'rating (1-5)', $avg]);
                    } elseif ($q->question_type === QuestionType::YesNo) {
                        $yesCount = $answers->filter(fn ($v) => in_array($v, [true, 1, '1', 'yes', 'true'], true))->count();
                        $pct = $answers->count() > 0 ? round(($yesCount / $answers->count()) * 100, 2) : 0.0;
                        fputcsv($file, [...$base, $q->question_text, 'yes/no (% yes)', $pct]);
                    } else {
                        fputcsv($file, [...$base, $q->question_text, 'text (comments)', $answers->count()]);
                    }
                }
            }
        });
    }

    public function auditAssignments(Request $request): StreamedResponse
    {
        return $this->streamCsv('fasre-audit-assignments.csv', function ($file) {
            fputcsv($file, ['id', 'auditor', 'auditee', 'course_code', 'section', 'term', 'status', 'total_score', 'due_date', 'submitted_at', 'approved_at', 'admin_remarks']);

            AuditAssignment::with(['auditor', 'auditee', 'section.course'])
                ->orderByDesc('created_at')
                ->chunk(500, function ($audits) use ($file) {
                    foreach ($audits as $a) {
                        fputcsv($file, [
                            $a->id,
                            $a->auditor?->name,
                            $a->auditee?->name,
                            $a->section?->course?->code,
                            $a->section?->name,
                            $a->section?->term,
                            $a->status->value,
                            $a->total_score,
                            $a->due_date?->toDateString(),
                            $a->submitted_at?->toIso8601String(),
                            $a->approved_at?->toIso8601String(),
                            $a->admin_remarks,
                        ]);
                    }
                });
        });
    }

    public function activityLogs(Request $request): StreamedResponse
    {
        return $this->streamCsv('fasre-activity-logs.csv', function ($file) {
            fputcsv($file, ['timestamp', 'admin', 'action', 'subject', 'properties']);

            ActivityLog::with('user')->orderByDesc('id')->chunk(500, function ($logs) use ($file) {
                foreach ($logs as $l) {
                    fputcsv($file, [
                        $l->created_at?->toIso8601String(),
                        $l->user?->name ?? 'System',
                        $l->action,
                        $l->subject_type ? class_basename($l->subject_type)."#{$l->subject_id}" : '',
                        json_encode($l->properties),
                    ]);
                }
            });
        });
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

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Department;
use App\Models\FacultyAssignment;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bulk CSV import for institutional data.
 *
 * Supported entity types (post field: type):
 *   - users                : name,email,role,is_active
 *   - courses              : department_code,code,title,credit_hours
 *   - sections             : course_code,name,term
 *   - student-enrollments  : student_email,course_code,section_name,term
 *   - faculty-assignments  : faculty_email,course_code,section_name,term,is_primary
 */
class BulkImportController extends Controller
{
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'in:users,courses,sections,student-enrollments,faculty-assignments'],
            'csv' => ['required', 'string', 'max:2048000'],
        ]);

        $type = $request->input('type');
        $rows = $this->parseCsv($request->input('csv'));

        if (empty($rows)) {
            throw ValidationException::withMessages([
                'csv' => 'The CSV content is empty or has no data rows.',
            ]);
        }

        if (count($rows) > 5000) {
            throw ValidationException::withMessages([
                'csv' => 'Maximum 5000 rows per import. Split the file and import in batches.',
            ]);
        }

        $result = match ($type) {
            'users' => $this->importUsers($rows),
            'courses' => $this->importCourses($rows),
            'sections' => $this->importSections($rows),
            'student-enrollments' => $this->importEnrollments($rows),
            'faculty-assignments' => $this->importFacultyAssignments($rows),
            default => throw ValidationException::withMessages(['type' => 'Unsupported import type.']),
        };

        ActivityLogger::log(null, "bulk_import.{$type}", [
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]);

        return response()->json([
            'data' => $result,
            'message' => "Import finished: {$result['created']} created, {$result['skipped']} skipped.",
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $csv): array
    {
        $csv = str_replace(["\r\n", "\r"], "\n", trim($csv));
        $lines = array_values(array_filter(explode("\n", $csv), fn ($l) => trim($l) !== ''));

        if (count($lines) < 2) {
            return [];
        }

        $headers = array_map(fn ($h) => strtolower(trim($h)), str_getcsv($lines[0]));
        $rows = [];

        for ($i = 1; $i < count($lines); $i++) {
            $values = str_getcsv($lines[$i]);
            if (count($values) === 1 && trim((string) $values[0]) === '') {
                continue;
            }

            $row = [];
            foreach ($headers as $idx => $header) {
                $row[$header] = isset($values[$idx]) ? trim((string) $values[$idx]) : '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function importUsers(array $rows): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($rows, &$created, &$skipped, &$errors) {
            foreach ($rows as $i => $row) {
                $lineNo = $i + 2; // +1 header, +1 human numbering
                $email = strtolower($row['email'] ?? '');
                $name = $row['name'] ?? '';
                $role = strtolower($row['role'] ?? '');

                if ($email === '' || $name === '') {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: missing name or email.";
                    continue;
                }

                if (! in_array($role, ['admin', 'faculty', 'student'], true)) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: invalid role '{$role}' (admin/faculty/student).";
                    continue;
                }

                if (User::where('email', $email)->exists()) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: {$email} already exists.";
                    continue;
                }

                User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => ($row['password'] ?? '') !== '' ? $row['password'] : 'Password@123',
                    'role' => $role,
                    'is_active' => ! in_array(strtolower($row['is_active'] ?? ''), ['0', 'false', 'no'], true),
                ]);
                $created++;
            }
        });

        return compact('created', 'skipped', 'errors');
    }

    private function importCourses(array $rows): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        $departments = Department::all()->keyBy(fn ($d) => strtolower($d->code ?? $d->name));

        DB::transaction(function () use ($rows, &$created, &$skipped, &$errors, $departments) {
            foreach ($rows as $i => $row) {
                $lineNo = $i + 2;
                $deptKey = strtolower($row['department_code'] ?? $row['department'] ?? '');
                $code = strtoupper($row['code'] ?? '');

                /** @var Department|null $department */
                $department = $departments->get($deptKey);
                if (! $department) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: department '{$deptKey}' not found. Create it first.";
                    continue;
                }

                if ($code === '' || ($row['title'] ?? '') === '') {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: missing code or title.";
                    continue;
                }

                if (Course::where('department_id', $department->id)->where('code', $code)->exists()) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: course {$code} already exists in {$department->name}.";
                    continue;
                }

                Course::create([
                    'department_id' => $department->id,
                    'code' => $code,
                    'title' => $row['title'],
                    'credit_hours' => is_numeric($row['credit_hours'] ?? null) ? (int) $row['credit_hours'] : null,
                ]);
                $created++;
            }
        });

        return compact('created', 'skipped', 'errors');
    }

    private function importSections(array $rows): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        $courses = Course::all()->keyBy('code');

        DB::transaction(function () use ($rows, &$created, &$skipped, &$errors, $courses) {
            foreach ($rows as $i => $row) {
                $lineNo = $i + 2;
                $courseCode = strtoupper($row['course_code'] ?? '');
                $name = $row['name'] ?? '';
                $term = $row['term'] ?? '';

                /** @var Course|null $course */
                $course = $courses->get($courseCode);
                if (! $course) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: course '{$courseCode}' not found.";
                    continue;
                }

                if ($name === '' || $term === '') {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: missing name or term.";
                    continue;
                }

                $exists = Section::where('course_id', $course->id)
                    ->where('name', $name)
                    ->where('term', $term)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: section {$courseCode} · {$name} ({$term}) already exists.";
                    continue;
                }

                Section::create([
                    'course_id' => $course->id,
                    'name' => $name,
                    'term' => $term,
                ]);
                $created++;
            }
        });

        return compact('created', 'skipped', 'errors');
    }

    private function importEnrollments(array $rows): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        [$sectionMap, $studentsByEmail] = $this->buildLookups();

        DB::transaction(function () use ($rows, &$created, &$skipped, &$errors, $sectionMap, $studentsByEmail) {
            foreach ($rows as $i => $row) {
                $lineNo = $i + 2;
                $studentEmail = strtolower($row['student_email'] ?? '');
                $key = $this->sectionKey($row);

                /** @var User|null $student */
                $student = $studentsByEmail->get($studentEmail);
                $section = $sectionMap->get($key);

                if (! $student || $student->role !== UserRole::Student) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: student '{$studentEmail}' not found.";
                    continue;
                }
                if (! $section) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: section '{$key}' not found.";
                    continue;
                }
                if (StudentEnrollment::where('section_id', $section->id)->where('student_id', $student->id)->exists()) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: student already enrolled in {$key}.";
                    continue;
                }

                StudentEnrollment::create([
                    'section_id' => $section->id,
                    'student_id' => $student->id,
                ]);
                $created++;
            }
        });

        return compact('created', 'skipped', 'errors');
    }

    private function importFacultyAssignments(array $rows): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        [$sectionMap, $facultyByEmail] = $this->buildLookups(UserRole::Faculty);

        DB::transaction(function () use ($rows, &$created, &$skipped, &$errors, $sectionMap, $facultyByEmail) {
            foreach ($rows as $i => $row) {
                $lineNo = $i + 2;
                $facultyEmail = strtolower($row['faculty_email'] ?? '');
                $key = $this->sectionKey($row);
                $isPrimary = ! in_array(strtolower($row['is_primary'] ?? 'true'), ['0', 'false', 'no'], true);

                /** @var User|null $faculty */
                $faculty = $facultyByEmail->get($facultyEmail);
                $section = $sectionMap->get($key);

                if (! $faculty || $faculty->role !== UserRole::Faculty) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: faculty '{$facultyEmail}' not found.";
                    continue;
                }
                if (! $section) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: section '{$key}' not found.";
                    continue;
                }
                if (FacultyAssignment::where('section_id', $section->id)->where('faculty_id', $faculty->id)->exists()) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: faculty already assigned to {$key}.";
                    continue;
                }

                if ($isPrimary) {
                    FacultyAssignment::where('section_id', $section->id)
                        ->where('is_primary', true)
                        ->update(['is_primary' => false]);
                }

                FacultyAssignment::create([
                    'section_id' => $section->id,
                    'faculty_id' => $faculty->id,
                    'is_primary' => $isPrimary,
                ]);
                $created++;
            }
        });

        return compact('created', 'skipped', 'errors');
    }

    /**
     * @return array{\Illuminate\Support\Collection<string, Section>, \Illuminate\Support\Collection<string, User>}
     */
    private function buildLookups(?UserRole $userRole = null): array
    {
        $sectionMap = Section::with('course')->get()->keyBy(
            fn (Section $s) => $this->makeSectionKey($s->course?->code ?? '', $s->name, $s->term),
        );

        $userQuery = User::query();
        if ($userRole) {
            $userQuery->where('role', $userRole);
        }

        return [$sectionMap, $userQuery->get()->keyBy(fn (User $u) => strtolower($u->email))];
    }

    private function sectionKey(array $row): string
    {
        return $this->makeSectionKey(
            strtoupper($row['course_code'] ?? ''),
            $row['section_name'] ?? $row['section'] ?? '',
            $row['term'] ?? '',
        );
    }

    private function makeSectionKey(string $courseCode, string $sectionName, string $term): string
    {
        return strtolower("{$courseCode}|{$sectionName}|{$term}");
    }
}

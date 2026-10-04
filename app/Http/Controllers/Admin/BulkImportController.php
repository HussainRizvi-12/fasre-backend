<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditProvenanceLog;
use App\Models\Course;
use App\Models\Department;
use App\Models\FacultyAssignment;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bulk CSV import for institutional data with validation, dry-run preview,
 * reconciliation summary, and append-only provenance logging.
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
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $type = $request->input('type');
        $isDryRun = $request->boolean('dry_run');
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

        // If dry run, execute inside a rolling-back transaction to simulate real DB constraints
        if ($isDryRun) {
            DB::beginTransaction();
            try {
                $result = $this->executeImport($type, $rows, $request->user());
            } finally {
                DB::rollBack();
            }

            return response()->json([
                'data' => [
                    'dry_run' => true,
                    'total_rows' => count($rows),
                    'valid_count' => $result['created'] ?? 0,
                    'skipped_count' => $result['skipped'] ?? 0,
                    'errors' => $result['errors'] ?? [],
                    'preview' => array_map(fn ($row) => array_diff_key($row, ['password' => true]), array_slice($rows, 0, 5)),
                ],
                'message' => 'Dry-run validation complete: '.($result['created'] ?? 0).' valid, '.($result['skipped'] ?? 0).' skipped.',
            ]);
        }

        $result = $this->executeImport($type, $rows, $request->user());

        ActivityLogger::log(null, "bulk_import.{$type}", [
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]);

        // Audit provenance log
        AuditProvenanceLog::record(
            $request->user(),
            "bulk_import.{$type}",
            $request->user(),
            "Imported {$result['created']} records for entity '{$type}' ({$result['skipped']} skipped).",
            null,
            ['type' => $type, 'created' => $result['created'], 'skipped' => $result['skipped']]
        );

        return response()->json([
            'data' => [
                'dry_run' => false,
                'total_rows' => count($rows),
                'created' => $result['created'],
                'skipped' => $result['skipped'],
                'errors' => $result['errors'],
            ],
            'message' => "Import finished: {$result['created']} created, {$result['skipped']} skipped.",
        ]);
    }

    private function executeImport(string $type, array $rows, User $actor): array
    {
        return match ($type) {
            'users' => $this->importUsers($rows, $actor),
            'courses' => $this->importCourses($rows, $actor),
            'sections' => $this->importSections($rows, $actor),
            'student-enrollments' => $this->importEnrollments($rows, $actor),
            'faculty-assignments' => $this->importFacultyAssignments($rows, $actor),
            default => throw ValidationException::withMessages(['type' => 'Unsupported import type.']),
        };
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $csv): array
    {
        $csv = str_replace(["\r\n", "\r"], "\n", $csv);
        $csv = trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        $stream = fopen('php://temp', 'r+');
        try {
            fwrite($stream, $csv);
            rewind($stream);
            $headerRow = fgetcsv($stream, null, ',', '"', '');
            if ($headerRow === false || $headerRow === [null]) {
                return [];
            }
            $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $headerRow);
            if (in_array('', $headers, true) || count(array_unique($headers)) !== count($headers)) {
                throw ValidationException::withMessages(['csv' => 'Column names must be non-empty and unique.']);
            }
            $rows = [];
            $line = 1 + substr_count(substr($csv, 0, ftell($stream)), "\n");
            $offset = ftell($stream);
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $rowLine = $line;
                $nextOffset = ftell($stream);
                $line += substr_count(substr($csv, $offset, $nextOffset - $offset), "\n");
                $offset = $nextOffset;
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw ValidationException::withMessages(['csv' => "Line {$rowLine}: column count does not match the header."]);
                }
                $row = array_combine($headers, array_map(fn ($v) => trim((string) $v), $values));
                $row['_line'] = $rowLine;
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function importUsers(array $rows, User $actor): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($rows, $actor, &$created, &$skipped, &$errors) {
            foreach ($rows as $i => $row) {
                $lineNo = $row['_line'];
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

                $departmentId = $actor->isCentralQa() ? null : $actor->department_id;
                if (($row['department_code'] ?? '') !== '') {
                    $departmentId = Department::whereRaw('LOWER(code) = ?', [strtolower($row['department_code'])])->value('id');
                    if ($departmentId === null || ! $actor->canAccessDepartment($departmentId)) {
                        $skipped++;
                        $errors[] = "Line {$lineNo}: department not found or outside your authorized scope.";

                        continue;
                    }
                }
                if ($role === 'admin' && ! $actor->isCentralQa()) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: only Central QA can import administrator accounts.";

                    continue;
                }
                $centralGrant = $role === 'admin' && in_array(strtolower($row['is_central_qa'] ?? ''), ['1', 'true', 'yes'], true);
                if ($role === 'admin' && (($centralGrant && $departmentId !== null) || (! $centralGrant && $departmentId === null))) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: administrator accounts require a department_code or an explicit is_central_qa grant, never both.";
                    continue;
                }
                $validation = Validator::make($row, [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email', 'max:255'],
                    'password' => ['nullable', 'string', 'min:8'],
                ]);
                if ($validation->fails()) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: ".$validation->errors()->first();

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
                    // Omitted passwords require the existing email reset flow; never use a shared demo password.
                    'password' => ($row['password'] ?? '') !== '' ? $row['password'] : Str::random(64),
                    'role' => $role,
                    'department_id' => $departmentId,
                    'is_central_qa' => $centralGrant,
                    'is_active' => ! in_array(strtolower($row['is_active'] ?? ''), ['0', 'false', 'no'], true),
                ]);
                $created++;
            }
        });

        return compact('created', 'skipped', 'errors');
    }

    private function importCourses(array $rows, User $actor): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        $departments = Department::query()
            ->when(! $actor->isCentralQa(), fn ($q) => $q->where('id', $actor->department_id))
            ->get()->keyBy(fn ($d) => strtolower($d->code ?? $d->name));

        DB::transaction(function () use ($rows, &$created, &$skipped, &$errors, $departments) {
            foreach ($rows as $i => $row) {
                $lineNo = $row['_line'];
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

                $validation = Validator::make($row, [
                    'code' => ['required', 'string', 'max:50'],
                    'title' => ['required', 'string', 'max:255'],
                    'credit_hours' => ['nullable', 'integer', 'min:1', 'max:12'],
                ]);
                if ($validation->fails()) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: ".$validation->errors()->first();

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

    private function importSections(array $rows, User $actor): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        $courses = Course::query()
            ->when(! $actor->isCentralQa(), fn ($q) => $q->where('department_id', $actor->department_id))
            ->get()->groupBy(fn ($c) => strtoupper($c->code));

        DB::transaction(function () use ($rows, &$created, &$skipped, &$errors, $courses) {
            foreach ($rows as $i => $row) {
                $lineNo = $row['_line'];
                $courseCode = strtoupper($row['course_code'] ?? '');
                $name = $row['name'] ?? '';
                $term = $row['term'] ?? '';

                /** @var Course|null $course */
                $matches = $courses->get($courseCode, collect());
                $course = $matches->count() === 1 ? $matches->first() : null;
                if (! $course) {
                    $skipped++;
                    $errors[] = "Line {$lineNo}: course '{$courseCode}' not found or ambiguous within your authorized scope.";

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

    private function importEnrollments(array $rows, User $actor): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        [$sectionMap, $studentsByEmail] = $this->buildLookups($actor, UserRole::Student);

        DB::transaction(function () use ($rows, &$created, &$skipped, &$errors, $sectionMap, $studentsByEmail) {
            foreach ($rows as $i => $row) {
                $lineNo = $row['_line'];
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

    private function importFacultyAssignments(array $rows, User $actor): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        [$sectionMap, $facultyByEmail] = $this->buildLookups($actor, UserRole::Faculty);

        DB::transaction(function () use ($rows, &$created, &$skipped, &$errors, $sectionMap, $facultyByEmail) {
            foreach ($rows as $i => $row) {
                $lineNo = $row['_line'];
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
     * @return array{Collection<string, Section>, Collection<string, User>}
     */
    private function buildLookups(User $actor, UserRole $userRole): array
    {
        $sectionMap = Section::with('course')->whereHas('course')
            ->when(! $actor->isCentralQa(), fn ($q) => $q->whereHas('course', fn ($c) => $c->where('department_id', $actor->department_id)))
            ->get()->groupBy(
                fn (Section $s) => $this->makeSectionKey($s->course?->code ?? '', $s->name, $s->term),
            )->filter(fn ($matches) => $matches->count() === 1)->map(fn ($matches) => $matches->first());

        $userQuery = User::where('role', $userRole)
            ->when(! $actor->isCentralQa(), fn ($q) => $q->where('department_id', $actor->department_id));

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

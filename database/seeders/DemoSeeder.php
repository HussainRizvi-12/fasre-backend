<?php

namespace Database\Seeders;

use App\Enums\AuditAssignmentStatus;
use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Enums\ReviewWindowStatus;
use App\Enums\UserRole;
use App\Models\AuditAssignment;
use App\Models\Course;
use App\Models\Department;
use App\Models\FacultyAssignment;
use App\Models\Question;
use App\Models\ReviewParticipation;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    /**
     * Run the demo database seeds.
     *
     * Produces a realistic, demo-ready dataset matching the FASRE MVP spec.
     * NOTE: Password "Password@123" is for DEMO / DEVELOPMENT USE ONLY.
     */
    public function run(): void
    {
        $password = 'Password@123';

        // ── 1. Users ────────────────────────────────────────────────
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@fasre.test',
            'password' => $password,
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $faculty1 = User::create([
            'name' => 'Dr. Ahmed Khan',
            'email' => 'ahmed.khan@fasre.test',
            'password' => $password,
            'role' => UserRole::Faculty,
            'is_active' => true,
        ]);

        $faculty2 = User::create([
            'name' => 'Dr. Sara Ali',
            'email' => 'sara.ali@fasre.test',
            'password' => $password,
            'role' => UserRole::Faculty,
            'is_active' => true,
        ]);

        $faculty3 = User::create([
            'name' => 'Dr. Usman Raza',
            'email' => 'usman.raza@fasre.test',
            'password' => $password,
            'role' => UserRole::Faculty,
            'is_active' => true,
        ]);

        $student1 = User::create([
            'name' => 'Ali Hassan',
            'email' => 'ali.hassan@fasre.test',
            'password' => $password,
            'role' => UserRole::Student,
            'is_active' => true,
        ]);

        $student2 = User::create([
            'name' => 'Fatima Noor',
            'email' => 'fatima.noor@fasre.test',
            'password' => $password,
            'role' => UserRole::Student,
            'is_active' => true,
        ]);

        $student3 = User::create([
            'name' => 'Bilal Tariq',
            'email' => 'bilal.tariq@fasre.test',
            'password' => $password,
            'role' => UserRole::Student,
            'is_active' => true,
        ]);

        $student4 = User::create([
            'name' => 'Ayesha Malik',
            'email' => 'ayesha.malik@fasre.test',
            'password' => $password,
            'role' => UserRole::Student,
            'is_active' => true,
        ]);

        $student5 = User::create([
            'name' => 'Zain Ul Abideen',
            'email' => 'zain.abideen@fasre.test',
            'password' => $password,
            'role' => UserRole::Student,
            'is_active' => true,
        ]);

        $student6 = User::create([
            'name' => 'Hamza Farooq',
            'email' => 'hamza.farooq@fasre.test',
            'password' => $password,
            'role' => UserRole::Student,
            'is_active' => true,
        ]);

        // ── 2. Department ───────────────────────────────────────────
        $dept = Department::create([
            'name' => 'Computer Science',
            'code' => 'CS',
            'is_active' => true,
        ]);

        // ── 3. Courses ──────────────────────────────────────────────
        $course1 = Course::create([
            'department_id' => $dept->id,
            'code' => 'CS101',
            'title' => 'Introduction to Programming',
            'credit_hours' => 3,
            'is_active' => true,
        ]);

        $course2 = Course::create([
            'department_id' => $dept->id,
            'code' => 'CS201',
            'title' => 'Data Structures & Algorithms',
            'credit_hours' => 3,
            'is_active' => true,
        ]);

        $course3 = Course::create([
            'department_id' => $dept->id,
            'code' => 'CS301',
            'title' => 'Database Systems',
            'credit_hours' => 4,
            'is_active' => true,
        ]);

        // ── 4. Sections ─────────────────────────────────────────────
        $section1 = Section::create([
            'course_id' => $course1->id,
            'name' => 'Section A',
            'term' => 'Fall 2026',
            'is_active' => true,
        ]);

        $section2 = Section::create([
            'course_id' => $course2->id,
            'name' => 'Section A',
            'term' => 'Fall 2026',
            'is_active' => true,
        ]);

        $section3 = Section::create([
            'course_id' => $course3->id,
            'name' => 'Section B',
            'term' => 'Fall 2026',
            'is_active' => true,
        ]);

        // ── 5. Faculty Assignments (1 primary per section) ──────────
        FacultyAssignment::create([
            'section_id' => $section1->id,
            'faculty_id' => $faculty1->id,
            'is_primary' => true,
        ]);

        FacultyAssignment::create([
            'section_id' => $section2->id,
            'faculty_id' => $faculty2->id,
            'is_primary' => true,
        ]);

        FacultyAssignment::create([
            'section_id' => $section3->id,
            'faculty_id' => $faculty3->id,
            'is_primary' => true,
        ]);

        // ── 6. Student Enrollments ──────────────────────────────────
        // Section 1: All students enrolled
        StudentEnrollment::create(['section_id' => $section1->id, 'student_id' => $student1->id]);
        StudentEnrollment::create(['section_id' => $section1->id, 'student_id' => $student2->id]);
        StudentEnrollment::create(['section_id' => $section1->id, 'student_id' => $student3->id]);
        StudentEnrollment::create(['section_id' => $section1->id, 'student_id' => $student4->id]);
        StudentEnrollment::create(['section_id' => $section1->id, 'student_id' => $student5->id]);
        StudentEnrollment::create(['section_id' => $section1->id, 'student_id' => $student6->id]);

        // Section 2: Students 1, 2, 3 enrolled
        StudentEnrollment::create(['section_id' => $section2->id, 'student_id' => $student1->id]);
        StudentEnrollment::create(['section_id' => $section2->id, 'student_id' => $student2->id]);
        StudentEnrollment::create(['section_id' => $section2->id, 'student_id' => $student3->id]);

        // Section 3: Students 4, 5 enrolled
        StudentEnrollment::create(['section_id' => $section3->id, 'student_id' => $student4->id]);
        StudentEnrollment::create(['section_id' => $section3->id, 'student_id' => $student5->id]);

        // ── 7. Evaluation Questions ─────────────────────────────────
        // Student Review Questions
        $sq1 = Question::create([
            'form_type' => FormType::StudentReview,
            'question_text' => 'How clear was the instructor in explaining concepts?',
            'question_type' => QuestionType::Rating,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $sq2 = Question::create([
            'form_type' => FormType::StudentReview,
            'question_text' => 'Was the instructor available for consultation outside class hours?',
            'question_type' => QuestionType::YesNo,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $sq3 = Question::create([
            'form_type' => FormType::StudentReview,
            'question_text' => 'Rate the quality of course materials and assignments provided.',
            'question_type' => QuestionType::Rating,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $sq4 = Question::create([
            'form_type' => FormType::StudentReview,
            'question_text' => 'Any additional feedback or suggestions for the instructor?',
            'question_type' => QuestionType::Textarea,
            'is_required' => false,
            'is_active' => true,
            'sort_order' => 4,
        ]);

        // Faculty Audit Questions
        $aq1 = Question::create([
            'form_type' => FormType::FacultyAudit,
            'question_text' => 'How well-prepared was the faculty member for the class lecture?',
            'question_type' => QuestionType::Rating,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $aq2 = Question::create([
            'form_type' => FormType::FacultyAudit,
            'question_text' => 'Rate the effectiveness of the interactive teaching methodology used.',
            'question_type' => QuestionType::Rating,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $aq3 = Question::create([
            'form_type' => FormType::FacultyAudit,
            'question_text' => 'Were constructive formative assessments conducted during class?',
            'question_type' => QuestionType::YesNo,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $aq4 = Question::create([
            'form_type' => FormType::FacultyAudit,
            'question_text' => 'Auditor general remarks and suggestions for teaching enhancement.',
            'question_type' => QuestionType::Textarea,
            'is_required' => false,
            'is_active' => true,
            'sort_order' => 4,
        ]);

        // ── 8. Active 14-Day Review Window ──────────────────────────
        $window = ReviewWindow::create([
            'title' => 'Fall 2026 Student Reviews',
            'description' => 'End-of-semester faculty evaluation cycle for all undergraduate courses.',
            'starts_at' => Carbon::now()->subDays(2),
            'ends_at' => Carbon::now()->addDays(12),
            'status' => ReviewWindowStatus::Active,
        ]);

        // ── 9. Seeded Student Reviews (Above & Below < 5 Threshold) ──

        // Section 1 (CS101): Seed 5 responses from Students 2, 3, 4, 5, 6
        // Student 1 (Ali Hassan) is kept as NOT_STARTED for live demo & testing!
        $section1Students = [$student2, $student3, $student4, $student5, $student6];
        $sampleRatingsQ1 = [5, 4, 5, 4, 5]; // avg = 4.6
        $sampleYesNosQ2 = [true, true, true, false, true]; // 80% Yes
        $sampleRatingsQ3 = [4, 5, 4, 4, 5]; // avg = 4.4
        $sampleFeedbacks = [
            'Great instructor, very approachable.',
            'Clear explanations of complex programming concepts.',
            'Loved the hands-on lab exercises.',
            'Would like more practice coding assignments.',
            'One of the best CS professors this semester.',
        ];

        for ($i = 0; $i < 5; $i++) {
            $student = $section1Students[$i];
            $token = (string) Str::uuid();

            // 1. Anonymous review response (coarse startOfDay timestamp)
            ReviewResponse::create([
                'review_window_id' => $window->id,
                'section_id' => $section1->id,
                'pseudonym_token' => $token,
                'answers_json' => [
                    (string) $sq1->id => $sampleRatingsQ1[$i],
                    (string) $sq2->id => $sampleYesNosQ2[$i],
                    (string) $sq3->id => $sampleRatingsQ3[$i],
                    (string) $sq4->id => $sampleFeedbacks[$i],
                ],
                'submitted_at' => Carbon::now()->startOfDay(),
            ]);

            // 2. Duplicate prevention participation record (precise audit timestamp)
            ReviewParticipation::create([
                'review_window_id' => $window->id,
                'section_id' => $section1->id,
                'student_id' => $student->id,
                'submitted_at' => Carbon::now()->subHours(5 - $i),
            ]);
        }

        // Section 2 (CS201): Seed 2 responses from Students 2, 3 -> BELOW THRESHOLD (< 5, Anonymity Suppressed)
        $section2Students = [$student2, $student3];
        for ($i = 0; $i < 2; $i++) {
            $student = $section2Students[$i];
            $token = (string) Str::uuid();

            ReviewResponse::create([
                'review_window_id' => $window->id,
                'section_id' => $section2->id,
                'pseudonym_token' => $token,
                'answers_json' => [
                    (string) $sq1->id => 4,
                    (string) $sq2->id => true,
                    (string) $sq3->id => 4,
                    (string) $sq4->id => 'Good explanations.',
                ],
                'submitted_at' => Carbon::now()->startOfDay(),
            ]);

            ReviewParticipation::create([
                'review_window_id' => $window->id,
                'section_id' => $section2->id,
                'student_id' => $student->id,
                'submitted_at' => Carbon::now()->subHours(2 - $i),
            ]);
        }

        // ── 10. Audit Assignments (1 Assigned + 1 Approved) ─────────

        // Audit 1: Still `assigned` (For live end-to-end demo)
        // Auditor: Dr. Ahmed Khan -> Auditee: Dr. Sara Ali (CS201 Section A)
        AuditAssignment::create([
            'auditor_id' => $faculty1->id,
            'auditee_id' => $faculty2->id,
            'section_id' => $section2->id,
            'assigned_by' => $admin->id,
            'status' => AuditAssignmentStatus::Assigned,
            'due_date' => Carbon::now()->addDays(5),
        ]);

        // Audit 2: Already `approved` with total_score and remarks (For immediate My Reports demo)
        // Auditor: Dr. Usman Raza -> Auditee: Dr. Ahmed Khan (CS101 Section A)
        AuditAssignment::create([
            'auditor_id' => $faculty3->id,
            'auditee_id' => $faculty1->id,
            'section_id' => $section1->id,
            'assigned_by' => $admin->id,
            'status' => AuditAssignmentStatus::Approved,
            'due_date' => Carbon::now()->subDays(3),
            'answers_json' => [
                (string) $aq1->id => 5, // Rating 5 (100)
                (string) $aq2->id => 4, // Rating 4 (80)
                (string) $aq3->id => true, // YesNo (100)
                (string) $aq4->id => 'Excellent lecture delivery, high student engagement, and structured slides.',
            ],
            'total_score' => 93.33,
            'admin_remarks' => 'Approved. Commendable teaching excellence demonstrated.',
            'submitted_at' => Carbon::now()->subDays(2),
            'approved_at' => Carbon::now()->subDay(),
        ]);
    }
}

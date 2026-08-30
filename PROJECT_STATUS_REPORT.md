# FASRE — Faculty Audit & Student Review Ecosystem
# Master Project Status & Architecture Report

## 📌 Executive Summary

The **Faculty Audit & Student Review Ecosystem (FASRE)** has been successfully built and verified across all **7 Planned Phases**. The entire architecture operates on a streamlined, production-ready MVP foundation:

- **Single PostgreSQL 18 Database:** 12 core relational tables (no dual-database or ledger overhead).
- **3 Strictly Enforced Roles:** `admin`, `faculty`, `student`.
- **Anonymity Guarantee:** Absolute cryptographic and structural isolation (`review_responses` carries zero user identity; `review_participations` handles duplicate-prevention; $< 5$ response suppression rule enforced server-side).
- **Web Admin Portal:** 14 custom Stitch Blade views with Dark Navy (`#1E3A8A`) and Gold accents (`#F5C518`).
- **Mobile Applications:** Two Flutter apps (Student Review App & Faculty Audit App) with 100% visual preservation and clean REST data-layer connectivity.
- **Automated Verification:** **36 PHPUnit Feature Tests with 233 Assertions (100% Pass Rate)**.

---

## 🏛️ System Architecture & Database Schema (12 Tables)

1. `users` — `id`, `name`, `email`, `password`, `role` (`admin`/`faculty`/`student`), `is_active`
2. `departments` — `id`, `name`, `code`, `is_active`
3. `courses` — `id`, `department_id`, `code`, `title`, `credit_hours`, `is_active`
4. `sections` — `id`, `course_id`, `name`, `term`, `is_active`
5. `faculty_assignments` — `id`, `section_id`, `faculty_id`, `is_primary`
6. `student_enrollments` — `id`, `section_id`, `student_id`
7. `questions` — `id`, `form_type` (`student_review`/`faculty_audit`), `question_text`, `question_type` (`rating`/`yes_no`/`text`/`textarea`), `is_required`, `is_active`, `sort_order`
8. `review_windows` — `id`, `title`, `description`, `starts_at`, `ends_at`, `status` (`draft`/`active`/`closed`/`published`)
9. `review_participations` — `id`, `review_window_id`, `section_id`, `student_id`, `submitted_at` *(Duplicate prevention only)*
10. `review_responses` — `id`, `review_window_id`, `section_id`, `pseudonym_token`, `answers_json`, `submitted_at` *(NO student_id or user_id column ever)*
11. `audit_assignments` — `id`, `auditor_id`, `auditee_id`, `section_id`, `assigned_by`, `status` (`assigned`/`in_progress`/`submitted`/`approved`/`rejected`), `due_date`, `answers_json`, `total_score`, `admin_remarks`, `submitted_at`, `approved_at`, `rejected_at`
12. `personal_access_tokens` — Laravel Sanctum token storage

---

## 🚀 Phases Breakdown & Verification Status

### Phase 1–3: Core Backend, Sanctum Auth & Web Admin Portal
- **Status:** ✅ Complete & Verified
- Full Admin CRUD APIs under `/api/admin/*`.
- 14 Stitch-styled custom Blade views at `/admin/*` with session authentication (`EnsureUserIsAdmin`).

### Phase 4: Student Review APIs
- **Status:** ✅ Complete & Verified
- `GET /api/student/enrolled-sections` (with dynamic `review_status`).
- `GET /api/student/review-windows/active` (active window payload).
- `GET /api/student/review-form` (active question list with triple gating).
- `POST /api/student/reviews` (atomic transaction, UUID pseudonym token, anonymous payload separation).

### Phase 5: Faculty Peer Audit APIs
- **Status:** ✅ Complete & Verified
- `GET /api/faculty/assigned-audits` (active observation assignments).
- `GET /api/faculty/audits/{id}` (audit detail).
- `GET /api/faculty/audit-form` (active evaluation criteria rubrics).
- `POST /api/faculty/audits/{id}/save-draft` (in-progress draft saves).
- `POST /api/faculty/audits/{id}/submit` (atomic submission, total score calculation, and immutability lock).
- `GET /api/faculty/my-submissions` (submitted audits archive).
- `GET /api/faculty/my-reports` (auditee-facing approved reports with joined question labels and scores).

### Phase 6: Results Aggregation, Anonymity Suppression & Scoring
- **Status:** ✅ Complete & Verified
- `GET /api/admin/review-results` & `GET /api/student/review-results/published`:
  - Enforces server-side suppression when responses $< 5$.
  - 2-decimal rating averages, yes/no percentages, and response counts (individual text feedback is never exposed to protect anonymity).
- **Audit Score Formula:** $\text{total\_score} = \text{average} \times 20$ (e.g. $4.0 / 5.0 \rightarrow 80\%$).

### Phase 7: Demo Data, Demo Script & Regression Pass
- **Status:** ✅ Complete & Verified
- `DemoSeeder.php`: Loaded via `php artisan migrate:fresh --seed`.
- `DEMO_SCRIPT.md`: Step-by-step scripted demo walkthrough.
- `README.md`: Master environment configuration and Flutter API setup.
- **36 Tests, 233 Assertions (100% Passing)**.

---

## 🔑 Demo Accounts Reference (Password: `Password@123`)

| Role | Name | Email |
| :--- | :--- | :--- |
| **Admin** | Administrator | `admin@fasre.test` |
| **Faculty 1** | Dr. Ahmed Khan | `ahmed.khan@fasre.test` |
| **Faculty 2** | Dr. Sara Ali | `sara.ali@fasre.test` |
| **Faculty 3** | Dr. Usman Raza | `usman.raza@fasre.test` |
| **Student 1** | Ali Hassan | `ali.hassan@fasre.test` |
| **Student 2** | Fatima Noor | `fatima.noor@fasre.test` |
| **Student 3** | Bilal Tariq | `bilal.tariq@fasre.test` |
| **Student 4** | Ayesha Malik | `ayesha.malik@fasre.test` |
| **Student 5** | Zain Ul Abideen | `zain.abideen@fasre.test` |
| **Student 6** | Hamza Farooq | `hamza.farooq@fasre.test` |

---

## 🧪 Regression Testing Output

```text
PASS  Tests\Feature\Phase123ApiTest
PASS  Tests\Feature\FilamentAdminPanelTest
PASS  Tests\Feature\StitchWebAdminPortalTest
PASS  Tests\Feature\StudentReviewApiTest
PASS  Tests\Feature\FacultyAuditApiTest
PASS  Tests\Feature\ResultsAggregationAndReportTest
PASS  Tests\Feature\ExampleTest

Tests:    36 passed (233 assertions)
Duration: 3.52s
```

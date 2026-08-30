# FASRE (Faculty Audit & Student Review Ecosystem)
## Phase 1, Phase 2, & Phase 3 Technical Report

---

## 1. Executive Summary

The Faculty Audit & Student Review Ecosystem (FASRE) backend has been completely redesigned and rebuilt as a clean, standardized, and high-performance **Laravel 13 + PostgreSQL** backend.

### Key Architectural Principles:
- **3 Simple Roles Only:** `admin`, `faculty`, and `student`.
- **Anonymity by Table Isolation:** Review responses are isolated without foreign keys to users/students to guarantee 100% submission anonymity. Duplicate submission is prevented via a separate tracking table (`review_participations`).
- **Single PostgreSQL Database:** Direct relational structure without multi-database or over-engineered ledger overhead.
- **RESTful API Backend:** All business rules, validations, and state machines are enforced at the backend level.

---

## 2. Environment & Tech Stack

| Component | Specification |
|---|---|
| **Framework** | Laravel 13.x |
| **PHP Version** | PHP 8.3.33 |
| **Database** | PostgreSQL 18 (Port `5433`, Database: `fasre`) |
| **Authentication** | Laravel Sanctum v4.3.3 (Bearer Token) |
| **Server URL** | `http://127.0.0.1:8000` |

---

## 3. Database Schema (12 Tables)

| # | Table Name | Key Columns & Constraints | Purpose |
|---|---|---|---|
| 1 | **`users`** | `id`, `name`, `email` (unique), `password`, `role` (enum: `admin`, `faculty`, `student`), `is_active` (bool) | User authentication and role management |
| 2 | **`departments`** | `id`, `name`, `code` (nullable), `is_active` (bool) | Academic departments |
| 3 | **`courses`** | `id`, `department_id` (FK), `code`, `title`, `credit_hours`, `is_active` | Course catalog |
| 4 | **`sections`** | `id`, `course_id` (FK), `name`, `term`, `is_active` | Course offerings per term |
| 5 | **`faculty_assignments`** | `id`, `section_id` (FK), `faculty_id` (FK), `is_primary` (bool), `unique(section_id, faculty_id)` | Teachers assigned to sections |
| 6 | **`student_enrollments`** | `id`, `section_id` (FK), `student_id` (FK), `unique(section_id, student_id)` | Student course section enrollment |
| 7 | **`questions`** | `id`, `form_type` (`student_review`/`faculty_audit`), `question_text`, `question_type` (`rating`/`yes_no`/`text`/`textarea`), `is_required`, `is_active`, `sort_order` | Form questions template repository |
| 8 | **`review_windows`** | `id`, `title`, `description`, `starts_at`, `ends_at`, `status` (`draft`/`active`/`closed`/`published`) | Student review evaluation timeline |
| 9 | **`review_participations`** | `id`, `review_window_id` (FK), `section_id` (FK), `student_id` (FK), `submitted_at`, `unique(review_window_id, section_id, student_id)` | Dedup check only — **stores zero answer data** |
| 10 | **`review_responses`** | `id`, `review_window_id` (FK), `section_id` (FK), `pseudonym_token` (indexed), `answers_json`, `submitted_at` | Anonymous answers — **no user_id/student_id** |
| 11 | **`audit_assignments`** | `id`, `auditor_id` (FK), `auditee_id` (FK), `section_id` (FK, nullable), `assigned_by` (FK), `status` (`assigned`/`in_progress`/`submitted`/`approved`/`rejected`), `due_date`, `answers_json`, `total_score`, `admin_remarks`, timestamps | Peer/Faculty audit tracking |
| 12 | **`personal_access_tokens`** | `id`, `tokenable_type`, `tokenable_id`, `name`, `token`, `abilities`, timestamps | Sanctum API token storage |

---

## 4. Eloquent Models & PHP Enums

### Backed Enums (`app/Enums/`)
- **`UserRole`**: `Admin = 'admin'`, `Faculty = 'faculty'`, `Student = 'student'`
- **`FormType`**: `StudentReview = 'student_review'`, `FacultyAudit = 'faculty_audit'`
- **`QuestionType`**: `Rating = 'rating'`, `YesNo = 'yes_no'`, `Text = 'text'`, `Textarea = 'textarea'`
- **`ReviewWindowStatus`**: `Draft = 'draft'`, `Active = 'active'`, `Closed = 'closed'`, `Published = 'published'`
- **`AuditAssignmentStatus`**: `Assigned = 'assigned'`, `InProgress = 'in_progress'`, `Submitted = 'submitted'`, `Approved = 'approved'`, `Rejected = 'rejected'`

### Models (`app/Models/`)
- `User` (with `isAdmin()`, `isFaculty()`, `isStudent()` helpers)
- `Department`
- `Course`
- `Section`
- `FacultyAssignment`
- `StudentEnrollment`
- `Question`
- `ReviewWindow`
- `ReviewParticipation`
- `ReviewResponse` (Identity isolation enforced)
- `AuditAssignment`

---

## 5. Seeded Test Accounts & Data

Default password for all seeded accounts: **`Password@123`**

### Users
| Role | Name | Email |
|---|---|---|
| **Admin** | Admin User | `admin@fasre.test` |
| **Faculty** | Dr. Ahmed Khan | `ahmed.khan@fasre.test` |
| **Faculty** | Dr. Sara Ali | `sara.ali@fasre.test` |
| **Faculty** | Dr. Usman Raza | `usman.raza@fasre.test` |
| **Student** | Ali Hassan | `ali.hassan@fasre.test` |
| **Student** | Fatima Noor | `fatima.noor@fasre.test` |
| **Student** | Bilal Tariq | `bilal.tariq@fasre.test` |
| **Student** | Ayesha Malik | `ayesha.malik@fasre.test` |
| **Student** | Zain Ul Abideen | `zain.abideen@fasre.test` |

### Master Data:
- **Department:** Computer Science (`CS`)
- **Courses:** `CS101`, `CS201`, `CS301`
- **Sections:** 3 sections seeded with primary faculty assignments and student distributions
- **Questions:** 4 Student Review questions + 4 Faculty Audit questions
- **Review Window:** 1 Active window (`Fall 2026 Student Reviews`)
- **Audit Assignments:** 2 Assigned audits between faculty members

---

## 6. Complete API Endpoints Reference

### 6.1 Authentication (`/api/*`)
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `POST` | `/api/login` | Public | Authenticates user and returns Sanctum Bearer token |
| `POST` | `/api/logout` | `auth:sanctum` | Revokes current token |
| `GET` | `/api/me` | `auth:sanctum` | Returns authenticated user profile |

### 6.2 Admin Master Data CRUD (`/api/admin/*`)
*Requires `auth:sanctum` + `EnsureUserIsAdmin` middleware (non-admins receive `403 Forbidden`).*

| Method | Endpoint | Query / Body Params | Description |
|---|---|---|---|
| `GET` | `/api/admin/users` | `?role=faculty`, `?is_active=true` | Filtered list of users |
| `POST` | `/api/admin/users` | `name`, `email`, `password`, `role`, `is_active` | Create new user |
| `GET` | `/api/admin/users/{id}` | - | Show user details |
| `PUT` | `/api/admin/users/{id}` | Optional updated fields | Update user |
| `DELETE` | `/api/admin/users/{id}` | - | Delete user |
| `GET` | `/api/admin/departments` | - | List departments |
| `POST` | `/api/admin/departments` | `name`, `code`, `is_active` | Create department |
| `GET` | `/api/admin/departments/{id}` | - | Show department |
| `PUT` | `/api/admin/departments/{id}` | `name`, `code`, `is_active` | Update department |
| `DELETE` | `/api/admin/departments/{id}` | - | Delete department |
| `GET` | `/api/admin/courses` | - | List courses (with department) |
| `POST` | `/api/admin/courses` | `department_id`, `code`, `title`, `credit_hours`, `is_active` | Create course |
| `GET` | `/api/admin/courses/{id}` | - | Show course |
| `PUT` | `/api/admin/courses/{id}` | `department_id`, `code`, `title`, `credit_hours`, `is_active` | Update course |
| `DELETE` | `/api/admin/courses/{id}` | - | Delete course |
| `GET` | `/api/admin/sections` | - | List sections (with course & department) |
| `POST` | `/api/admin/sections` | `course_id`, `name`, `term`, `is_active` | Create section |
| `GET` | `/api/admin/sections/{id}` | - | Show section |
| `PUT` | `/api/admin/sections/{id}` | `course_id`, `name`, `term`, `is_active` | Update section |
| `DELETE` | `/api/admin/sections/{id}` | - | Delete section |
| `GET` | `/api/admin/faculty-assignments` | `?section_id=`, `?faculty_id=` | List faculty section assignments |
| `POST` | `/api/admin/faculty-assignments` | `section_id`, `faculty_id`, `is_primary` | Assign faculty (auto-unsets previous primary) |
| `DELETE` | `/api/admin/faculty-assignments/{id}` | - | Remove faculty assignment |
| `GET` | `/api/admin/student-enrollments` | `?section_id=`, `?student_id=` | List student section enrollments |
| `POST` | `/api/admin/student-enrollments` | `section_id`, `student_id` | Enroll student in section |
| `DELETE` | `/api/admin/student-enrollments/{id}` | - | Remove student enrollment |

### 6.3 Questions & Review Windows (`/api/admin/*`)
| Method | Endpoint | Query / Body Params | Description |
|---|---|---|---|
| `GET` | `/api/admin/questions` | `?form_type=student_review` / `faculty_audit` | List questions ordered by `sort_order` |
| `POST` | `/api/admin/questions` | `form_type`, `question_text`, `question_type`, `is_required`, `sort_order` | Create question |
| `PUT` | `/api/admin/questions/{id}` | Updated question fields | Update question & sort order |
| `DELETE` | `/api/admin/questions/{id}` | - | Delete question |
| `GET` | `/api/admin/review-windows` | - | List all review windows |
| `POST` | `/api/admin/review-windows` | `title`, `description`, `starts_at`, `ends_at` | Create review window (starts as `draft`) |
| `PUT` | `/api/admin/review-windows/{id}` | `title`, `description`, `starts_at`, `ends_at` | Edit window (allowed only in `draft` state) |
| `POST` | `/api/admin/review-windows/{id}/activate` | - | State transition: `draft` ➔ `active` |
| `POST` | `/api/admin/review-windows/{id}/close` | - | State transition: `active` ➔ `closed` |
| `POST` | `/api/admin/review-windows/{id}/publish-results` | - | State transition: `closed` ➔ `published` |

---

## 7. Security & Business Logic Guarantees

1. **Strict Anonymity:** Responses submitted in `review_responses` never include `user_id` or `student_id`.
2. **Deterministic State Machine:** Review window status transitions are strictly linear (`draft` ➔ `active` ➔ `closed` ➔ `published`). Skipping or reversing states returns `422 Unprocessable Entity`.
3. **Primary Faculty Logic:** Marking an assignment as primary automatically unsets any existing primary faculty in that section inside a database transaction.
4. **Active Check & Role Guarding:** Deactivated accounts are rejected at login with `401 Unauthorized`. Non-admins calling `/api/admin/*` are blocked with `403 Forbidden`.

---

## 8. Quick Artisan Commands

```bash
# Refresh and seed database
php artisan migrate:fresh --seed

# Start development server
php artisan serve --port=8000

# View all registered routes
php artisan route:list
```

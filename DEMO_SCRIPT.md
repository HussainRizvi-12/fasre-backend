# FASRE — End-to-End Live Demonstration Script

This document provides a step-by-step script for demonstrating the complete **Faculty Audit & Student Review Ecosystem (FASRE)**. Follow the steps in the exact order below. All demo accounts, URLs, and sample inputs are pre-scripted.

---

## 🔑 Demo Accounts Reference

| Role | Name | Email | Password |
| :--- | :--- | :--- | :--- |
| **Admin** | System Administrator | `admin@fasre.test` | `Password@123` |
| **Faculty 1** | Dr. Ahmed Khan (Auditor / Auditee) | `ahmed.khan@fasre.test` | `Password@123` |
| **Faculty 2** | Dr. Sara Ali (Auditee) | `sara.ali@fasre.test` | `Password@123` |
| **Faculty 3** | Dr. Usman Raza (Auditor) | `usman.raza@fasre.test` | `Password@123` |
| **Student 1** | Ali Hassan (Student Reviewer) | `ali.hassan@fasre.test` | `Password@123` |

---

## 🎬 Act 1: Web Admin Portal Tour & Setup Verification

**Screen:** Web Browser at `http://127.0.0.1:8000/login`

1. **Login as Admin:**
   - **Email:** `admin@fasre.test`
   - **Password:** `Password@123`
   - Click **"Sign In to Dashboard"**.
2. **Dashboard Overview:**
   - Point out the Stitch Navy (`#1E3A8A`) and Gold navigation sidebar.
   - Show live summary statistics cards (Active Review Window, Total Departments, Courses, Enrolled Students, Completed Audits).
3. **Academic Structure Navigation:**
   - Navigate to **"Departments"** $\rightarrow$ Show `Computer Science (CS)`.
   - Navigate to **"Courses"** $\rightarrow$ Show `CS101`, `CS201`, `CS301`.
   - Navigate to **"Sections"** $\rightarrow$ Show `Section A` and `Section B`.
   - Navigate to **"Faculty Assignments"** $\rightarrow$ Show primary instructors assigned per section.
   - Navigate to **"Student Enrollments"** $\rightarrow$ Show enrolled student rosters.
4. **Evaluation Engine & Rubrics:**
   - Navigate to **"Review Questions"** $\rightarrow$ Show 4 student review questions (Rating, Yes/No, Textarea).
   - Navigate to **"Audit Questions"** $\rightarrow$ Show 4 faculty peer audit rubrics.
5. **Review Windows & Peer Audits:**
   - Navigate to **"Review Windows"** $\rightarrow$ Show `Fall 2026 Student Reviews` (Status: `ACTIVE`, 14-day window).
   - Navigate to **"Audit Assignments"** $\rightarrow$ Show:
     - Audit #1: `assigned` (Dr. Ahmed Khan $\rightarrow$ Dr. Sara Ali).
     - Audit #2: `approved` (Dr. Usman Raza $\rightarrow$ Dr. Ahmed Khan, Score: 93.33%).

---

## 📱 Act 2: Student Review App — Anonymous Live Submission

**Screen:** Student Review App (Flutter)

1. **Login as Student:**
   - **Email:** `ali.hassan@fasre.test`
   - **Password:** `Password@123`
   - Tap **"Sign In"**.
2. **Review Home Screen:**
   - Note the banner displaying the active `Fall 2026 Student Reviews` window.
   - Look at `CS101 (Introduction to Programming) — Section A` with status **"Not Started"**.
   - Tap **"Start Evaluation"**.
3. **Fill Anonymous Review:**
   - **Q1 (Clarity Rating):** Select `5 ★ (Strongly Agree)`
   - **Q2 (Availability Yes/No):** Select `Yes`
   - **Q3 (Course Materials Rating):** Select `5 ★ (Excellent)`
   - **Q4 (Feedback Text):** Type:
     > *"Dr. Ahmed explains pointers and algorithms very clearly with practical live coding examples."*
4. **Submit & Show Cryptographic Receipt:**
   - Tap **"Submit Review"**.
   - Show the **Submission Receipt** dialog with the generated `Pseudonym Token` UUID (e.g. `9f8e7d6c-...`).
   - Explain to the audience that no student ID or user identity is attached to the review response table.

---

## 📊 Act 3: Admin Portal — Live Results Aggregation & Anonymity Suppression

**Screen:** Web Browser at `http://127.0.0.1:8000/admin/review-results`

1. **Navigate to "Review Results":**
   - Select **Review Window:** `Fall 2026 Student Reviews`.
2. **Demonstrate ABOVE Threshold ($\ge 5$ responses):**
   - Look at `CS101 - Section A` (Total Responses: 6).
   - Show that aggregate data is fully calculated and displayed:
     - **Explanation Clarity:** `4.67 / 5.00`
     - **Instructor Availability:** `83.3% Yes`
     - **Course Materials Quality:** `4.50 / 5.00`
     - **Student Comments:** `6 Submissions` (Individual text strings are suppressed server-side).
3. **Demonstrate BELOW Threshold ($< 5$ responses) Anonymity Suppression:**
   - Look at `CS201 - Section A` (Total Responses: 2).
   - Point out the server-side suppression banner:
     > *"⚠️ Insufficient responses to display results (< 5 responses) — Anonymity Protected."*
   - Explain that all individual averages and percentages are withheld to protect student identity in small cohorts.

---

## 🧑‍🏫 Act 4: Faculty Audit App — Auditor Live Peer Observation Submission

**Screen:** Faculty Audit App (Flutter)

1. **Login as Auditor:**
   - **Email:** `ahmed.khan@fasre.test`
   - **Password:** `Password@123`
   - Tap **"Sign In"**.
2. **Assigned Audits Screen:**
   - Look at the pending audit card for `Dr. Sara Ali — CS201 Section A` (Status: `Assigned`).
   - Tap **"Conduct Evaluation"**.
3. **Fill Peer Audit Form:**
   - **Q1 (Lecture Preparation):** Select `5 ★`
   - **Q2 (Interactive Methodology):** Select `4 ★`
   - **Q3 (Formative Assessment):** Select `Yes`
   - **Q4 (General Remarks):** Type:
     > *"Engaging classroom dynamic, well-structured slide deck, and effective code demonstration."*
4. **Submit Audit:**
   - Tap **"Submit Peer Audit"**.
   - Confirmation toast appears: *"Audit submitted successfully."*
   - The card status transitions to `Submitted` (Immutable).

---

## ⚖️ Act 5: Web Admin Portal — Audit Review & Formal Approval

**Screen:** Web Browser at `http://127.0.0.1:8000/admin/audit-assignments`

1. **Locate Submitted Audit:**
   - Find the newly submitted audit (Auditor: Dr. Ahmed Khan, Auditee: Dr. Sara Ali).
   - Note the server-computed score: `90.0%` (Formula: $\text{average} \times 20$).
2. **Review & Approve:**
   - Click **"Review / Take Action"**.
   - Enter **Admin Remarks**:
     > *"Approved. Commendable adherence to active learning pedagogical principles."*
   - Click **"Approve Audit"**.
   - Status updates immediately to **Approved** with a green badge.

---

## 📑 Act 6: Faculty Audit App — Auditee "My Reports" Live View

**Screen:** Faculty Audit App (Flutter)

1. **Switch Login to Auditee:**
   - Tap **Settings / Logout**.
   - **Email:** `sara.ali@fasre.test`
   - **Password:** `Password@123`
   - Tap **"Sign In"**.
2. **Open "My Reports" Tab:**
   - Tap the **"Reports"** tab in the bottom navigation bar.
3. **Inspect Approved Evaluation Report:**
   - Open the newly approved report for `CS201 — Data Structures`.
   - Highlight the key elements:
     - **Performance Band:** `Exemplary (90%)`
     - **Approval Date:** Today's date
     - **Admin Remarks:** *"Approved. Commendable adherence to active learning pedagogical principles."*
     - **Detailed Criteria Breakdown:** All 4 observation indicators with ratings and confirmation flags.
4. **Conclusion:**
   - Conclude the demo showing the seamless multi-app workflow from student anonymity to administrative oversight and faculty development feedback!

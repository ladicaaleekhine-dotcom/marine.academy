# Current System and Flow

This document describes the enrollment system as it exists in the current repository, based on the live code and schema. The implementation is the source of truth; older notes and planning artifacts are not treated as authoritative if they disagree with the app.

## 1. System overview

This is a PHP + MySQL enrollment platform for NCST Maritime Academy with a role-based portal and a server-rendered Bootstrap layout. The modules in the live app are:

- `auth/` — account creation and login
- `enrollee/` — applicant registration and document workflow
- `registrar/` — application decisions, course/section review, grade approvals
- `cashier/` — payment recording and validation
- `student/` — student enrollment, profile, academic records, and payment visibility
- `teacher/` — attendance and gradebook pages
- `admin/` — system configuration and administration

The active operational flow is:

Application -> Review -> Approval -> Payment -> Student activation -> Section enrollment -> Registrar approval -> Academic progression

## 1.1 Verified live features and exceptions

The following are verified in the current repository state:

- `student/academic_records.php` is a live academic transcript page. It pulls finalized grade entries, computes cumulative GWA and units, and provides a transcript download and print view.
- The transcript query is intentionally restricted to grade submissions that are already approved or locked by the registrar. This matches the student-facing visibility rule used in the current code.
- `student/dashboard.php` still uses hard-coded KPI values; `registrar/dashboard.php` now loads its overview KPIs from live active-term data.
- Walk-In Validation and Reports modules have been removed completely from the system flow and navigation to streamline the enrolment process.
- The schema and action code still rely on `academic_terms` as the active academic term model. Older mentions of a separate academic-year/semester structure are not current repository truth.
- Section selection for students is strictly scoped to the cadet's registered program (`BSMT` / `BSMarE`) and current year level (`1st Year` – `4th Year`).

### Needs verification

- Whether the `locked` grade submission status is still used anywhere in the live workflow beyond the transcript query.
- Whether application and student number generation is ever implemented in the real database layer; the current schema does not include those fields.

---

## 2. Current roles and responsibilities

### Enrollee
Primary folder: `enrollee/`

Responsibilities:
- Registers a new account with role `enrollee`
- Completes application form in `enrollee/apply.php`
- Selects the City / Municipality through a type-to-search combobox covering all Philippine cities and municipalities (PSGC data in `assets/data/ph/cities.json`), with province auto-fill and dynamic barangay loading via `actions/get_barangays.php`
- Uploads required supporting documents
- Saves draft or submits application for registrar review
- Tracks application status and revision messages in `enrollee/status.php`
- Receives notifications and registrar remarks

### Student
Primary folder: `student/`

Responsibilities:
- Accesses the student portal only after the account is upgraded from `enrollee` to `student`
- Enrolls in class sections only if `students.enrollment_status = 'paid'`, with section offerings strictly filtered to the student's chosen program (`BSMT` / `BSMarE`) and current year level (`1st Year` – `4th Year`)
- Views enrollment, payment, academic records, and profile pages
- Checks COR and current enrollment status

### Registrar
Primary folder: `registrar/`

Responsibilities:
- Reviews pending applicant records in `registrar/enrollee_applications.php`
- Verifies or rejects individual documents
- Requests revision notes when data/documents are incomplete
- Approves or rejects admission applications
- Reviews submitted enrollment records and approves/rejects them
- Reviews grade submissions and approves locked grade data
- Maintains courses, sections, and student records

### Cashier
Primary folder: `cashier/`

Responsibilities:
- Records payment with OR number and amount
- Validates pending OR records
- Checks assessment totals and remaining balance
- Upgrades eligible `enrollee` accounts to `student` based on payment validation threshold
- Prints receipt views

### Teacher
Primary folder: `teacher/`

Responsibilities:
- Views assigned sections in `teacher/my_classes.php`
- Records attendance in `teacher/attendance.php`
- Manages gradebook entries in `teacher/gradebook.php`
- Reviews class rosters in `teacher/class_list.php`

### Admin
Primary folder: `admin/`

Responsibilities:
- Manages academic terms
- Configures fee rules
- Reviews users and system audit data
- Accesses role-management UI and admin dashboard tools
- Maintains recovery and audit records

---

## 3. Actual user flow

### Account creation and login

```text
START
  guest visits index.php
    ↓
  auth/register.php -> auth_actions.php
    ↓
  creates users row with role = 'enrollee'
    ↓
  creates students row with application_status = 'draft'
    ↓
  session is created
    ↓
  user is redirected to enrollee/welcome.php
```

Login is handled by `auth/login.php` and `actions/auth_actions.php`.
- Username or email is accepted.
- Password is verified.
- User role determines the landing dashboard.

### Enrollee application flow

```text
enrollee/welcome.php
    ↓
enrollee/apply.php
    ↓
validate fields and uploads
    ↓
application saved as draft or final submission
    ↓
students.application_status = 'draft' or 'pending'
    ↓
student reviews documents and status
    ↓
registrar/enrollee_applications.php
```

### Application status progression

```text
draft
  -> pending
  -> under_review
  -> needs_revision
  -> approved
  -> rejected
```

Important implementation detail:
- The project uses `needs_revision`, not `requires_revision`.
- The code explicitly checks `needs_revision` in several places.
- Old planning notes that use `requires_revision` are stale.

### Approval and payment flow

```text
approved application
    ↓
Cashier validates payment OR
    ↓
payments.or_status = 'validated'
    ↓
assessment passes configured payment requirement percentage
    ↓
users.role is updated from 'enrollee' to 'student'
    ↓
students.enrollment_status = 'paid'
    ↓
student is allowed to enroll in sections
```

### Student enrollment flow

```text
student/enroll.php
    ↓
eligible only when students.enrollment_status = 'paid'
    ↓
sections strictly filtered to student program & year level
    ↓
student selects section_ids
    ↓
validation checks:
  - section belongs to active term
  - section program matches student's program (BSMT / BSMarE)
  - section year level matches student's current year level (1st-4th Year)
  - no duplicate courses
  - no overlapping schedules
  - prerequisites satisfied
  - max unit limit not exceeded
  - capacity not full
    ↓
INSERT INTO enrollments with status = 'pending'
    ↓
registrar/enrollments.php
    ↓
approve -> status = 'enrolled'
    reject/drop -> status = 'dropped'
```

---

## 4. Document workflow

The live document model is in `documents`.

Supported document types:
- `form_137`
- `shs_diploma`
- `good_moral`
- `birth_certificate`
- `marriage_certificate`
- `medical_clearance`
- `id_photo`

Document statuses:
- `pending`
- `verified`
- `rejected`

Actual workflow:

```text
Applicant uploads file
    ↓
file is MIME-checked (PDF/JPG/PNG)
    ↓
record saved to documents table
    ↓
registrar verifies or rejects each document
    ↓
application may be approved only after required docs are verified
    ↓
if not all required docs are valid, registrar may request revisions
```

Important rule in current code:
- If the applicant acknowledges submission without certain documents, the application may still move forward if the registrar allows it.
- This is enforced in `actions/enrollee_actions.php` and is not merely cosmetic.

---

## 5. Payment workflow

### Fee configuration
Admin configures fee rules in `admin/fee_setup.php`.

Data stored in:
- `academic_terms`
- `fee_configurations`

Fee scopes include:
- academic term
- program applying for
- year level
- fixed or per-unit calculation

### Assessment generation
`includes/assessments.php` creates a per-student, per-term assessment and itemized charges.

Flow:

```text
student + term selected
    ↓
fee rules are matched
    ↓
assessment record created
    ↓
assessment_items created
    ↓
assessment total is computed
```

### OR validation
Cashier flow in `actions/payment_actions.php`:

```text
cashier records payment
    ↓
payment inserted with or_status = 'pending'
    ↓
OR validated by cashier
    ↓
payments.or_status = 'validated'
    ↓
validated amount offsets assessment items
    ↓
if payment requirement threshold is reached, applicant becomes student
```

Important rules enforced in code:
- OR numbers must be unique.
- Amount cannot exceed the outstanding assessment balance for the payment record.
- Only validated payment allocations count toward the student activation threshold.
- Student activation depends on `academic_terms.payment_requirement_percent`.

---

## 6. Academic term and admin configuration

The active term model is `academic_terms`.

Fields include:
- `school_year`
- `semester`
- `starts_on`
- `ends_on`
- `payment_requirement_percent`
- `max_units`
- `is_active`

Operational meaning:
- Only one term may be active at a time from the current design.
- Applications, fee rules, payment requirements, and enrollment rules all use the active term.

The admin pages managing this are:
- `admin/academic_terms.php`
- `admin/fee_setup.php`
- `admin/manage_users.php`
- `admin/audit_log.php`
- `admin/trash_bin.php`

---

## 7. Course and section rules

### Courses
`courses` stores:
- `course_code`
- `course_name`
- `units`

### Subject prerequisites
`subject_prerequisites` stores prerequisite relationships between subjects.

### Sections
`sections` stores:
- `section_name`
- `program` (`BSMT`, `BSMarE`)
- `year_level` (`1st Year`, `2nd Year`, `3rd Year`, `4th Year`)
- `course_id`
- `academic_term_id`
- `schedule`
- `room`
- `capacity`
- `teacher_id`
- `status` (`active`, `inactive`)

### Registration rules in live code
The student enrollment validation checks:
- program matching (`sections.program` matches student's program)
- year level matching (`sections.year_level` matches student's current year level)
- schedule conflicts
- duplicate course registrations
- unmet prerequisites
- maximum unit load per term
- active term membership
- payment status
- section capacity

These are enforced in `actions/enrollment_actions.php` and `includes/registration_rules.php`.

---

## 8. Attendance and grades

### Attendance tables
- `attendance_sessions`
- `attendance_records`

### Grade tables
- `grade_submissions`
- `student_grades`

### Teacher workflow
From the code:

```text
teacher/my_classes.php
    ↓
teacher/attendance.php or teacher/gradebook.php
    ↓
records or grade submissions
    ↓
registrar/grade_approvals.php
    ↓
approval/lock status
```

Student academic records are shown from `student/academic_records.php` for approved/locked grades.

---

## 9. Database relationships and entity model

Key relationships currently used in code:

- `users.id` -> `students.user_id`
- `academic_terms.id` -> `students.academic_term_id`
- `academic_terms.id` -> `fee_configurations.academic_term_id`
- `students.id` -> `assessments.student_id`
- `academic_terms.id` -> `assessments.academic_term_id`
- `assessments.id` -> `assessment_items.assessment_id`
- `assessment_items.id` -> `payment_allocations.assessment_item_id`
- `payments.id` -> `payment_allocations.payment_id`
- `courses.id` -> `sections.course_id`
- `students.id` -> `enrollments.student_id`
- `sections.id` -> `enrollments.section_id`
- `students.id` -> `documents.student_id`
- `users.id` -> `notifications.user_id`
- `students.id` -> `application_remarks.student_id`

---

## 10. Business rules the app currently enforces

- Registration creates an `enrollee` account before application approval.
- A student cannot enroll in class sections until payment validation has completed and `students.enrollment_status = 'paid'`.
- Students can only view and select class sections that belong to their chosen program (`BSMT` / `BSMarE`) and current year level (`1st Year` – `4th Year`).
- Admission approval requires required documents to be verified or explicitly acknowledged as submitted without documents.
- Students can edit draft or revision applications only if the status allows it.
- Registrar actions move the application to `needs_revision`, `approved`, or `rejected`.
- `academic_terms` is the single active term model in this codebase.
- Section capacity, program/year alignment, and schedule conflict checks are enforced at submission time.
- Prerequisite completion is checked against already approved/locked grades before enrollment.

---

## 11. Current end-to-end flow summary

```text
Guest -> Register -> Enrollee account -> Fill app -> Upload docs -> Submit
  -> pending/under_review -> registrar verifies documents -> approve/reject/request revision
  -> approved -> cashier validates payment -> student role activated -> enrollment_status = 'paid'
  -> student enrolls in sections (scoped to program & year level) -> registrar approves selected section registrations
  -> student status becomes enrolled -> teacher takes attendance -> teacher submits grades
  -> registrar approves grade submissions -> student sees academic records
```

This is the currently implemented live system flow in the repository.

## 12. Student LMS access (initial implementation)

- `includes/lms_access.php` is the shared LMS guard. It checks the LMS role allowlist (`student`, `teacher`, `registrar`, `admin`) before applying student-specific eligibility. Other account types, including `enrollee`, are denied by the existing role guard.
- Student LMS access requires `students.enrollment_status = 'enrolled'`, a confirmed `enrollments.status = 'enrolled'` record for the student's current academic term, and validated payment allocations meeting the existing term downpayment rule (`max(minimum_downpayment, downpayment_percentage × assessment total)`). Pending or unvalidated ORs do not count.
- `student/lms.php` lists subjects joined through the student's confirmed enrollment and `section_subjects`; students cannot add or select LMS courses. Its summary progress indicator counts the student's approved prelim, midterm, and final grade checkpoints for each subject.
- `student/lms_course.php` repeats the enrollment-scoped subject check on direct requests, so an unassigned subject ID is rejected server-side. It provides course hub navigation for Overview, Announcements, Lessons / Modules, Learning Materials, Assignments, Quizzes / Exams, Grades, and Course Progress. Grades show approved/locked records from existing grade tables.
- The student Lessons / Modules view uses published `lms_modules` and `lms_lessons` attached to a section-subject assignment, ordered by their instructor-managed display-order fields. Text, image, presentation, PDF, video, and external-link content are supported. Lesson assets are streamed through `student/lms_lesson_file.php`, which rechecks LMS eligibility and current subject enrollment; direct access to the private storage folder is denied.
- Instructor content-management controls are not part of this student-facing phase. Announcements, assignments, quizzes, learning materials, calendar, chat, and reports still need their own data models/routes.

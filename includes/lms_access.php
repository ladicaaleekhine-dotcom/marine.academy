<?php
require_once __DIR__ . '/auth_check.php';

const LMS_PERMITTED_ROLES = ['student', 'teacher', 'registrar', 'admin'];

function lmsRoleIsPermitted(string $role): bool
{
    return in_array($role, LMS_PERMITTED_ROLES, true);
}

function lmsStudentMeetsAccessRequirements(array $record): bool
{
    $assessmentTotal = max(0.0, (float)($record['assessment_total'] ?? 0));
    $minimumDownpayment = max(0.0, (float)($record['minimum_downpayment'] ?? 0));
    $downpaymentPercentage = max(0.0, (float)($record['downpayment_percentage'] ?? 0));
    $requiredDownpayment = max(
        $minimumDownpayment,
        $assessmentTotal * $downpaymentPercentage / 100
    );

    return ($record['enrollment_status'] ?? '') === 'enrolled'
        && !empty($record['has_confirmed_enrollment'])
        && (float)($record['validated_paid'] ?? 0) + 0.00001 >= $requiredDownpayment;
}

function lmsCourseProgressPercent(int $completedGradeCheckpoints): int
{
    $completedGradeCheckpoints = max(0, min(3, $completedGradeCheckpoints));
    return (int)round(($completedGradeCheckpoints / 3) * 100);
}

function fetchLmsStudentAccessRecord(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT s.id AS student_id,
                s.enrollment_status,
                COALESCE(a.total_amount, 0) AS assessment_total,
                COALESCE(t.minimum_downpayment, 0) AS minimum_downpayment,
                COALESCE(t.downpayment_percentage, 0) AS downpayment_percentage,
                COALESCE((
                    SELECT SUM(pa.amount)
                    FROM assessment_items ai
                    JOIN payment_allocations pa ON pa.assessment_item_id = ai.id
                    JOIN payments p ON p.id = pa.payment_id
                    WHERE ai.assessment_id = a.id
                      AND p.or_status = 'validated'
                ), 0) AS validated_paid,
                EXISTS (
                    SELECT 1
                    FROM enrollments e
                    WHERE e.student_id = s.id
                      AND e.academic_term_id = s.academic_term_id
                      AND e.status = 'enrolled'
                ) AS has_confirmed_enrollment
         FROM students s
         LEFT JOIN assessments a
           ON a.student_id = s.id AND a.academic_term_id = s.academic_term_id
         LEFT JOIN academic_terms t ON t.id = s.academic_term_id
         WHERE s.user_id = :user_id
         LIMIT 1"
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchLmsEnrolledSubjects(PDO $pdo, int $studentId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT sub.id AS subject_id, sub.subject_code, sub.subject_name, sub.units,
                sec.section_name, e.school_year, e.semester,
                COALESCE(ss.day_of_week, sec.day_of_week) AS day_of_week,
                COALESCE(ss.start_time, sec.start_time) AS start_time,
                COALESCE(ss.end_time, sec.end_time) AS end_time,
                COALESCE(ss.room, sec.room) AS room,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS instructor_name,
                COALESCE((
                    SELECT (sg.prelim_grade IS NOT NULL)
                         + (sg.midterm_grade IS NOT NULL)
                         + (sg.final_exam_grade IS NOT NULL)
                    FROM student_grades sg
                    JOIN grade_submissions gs ON gs.id = sg.grade_submission_id
                    WHERE sg.enrollment_id = e.id
                      AND sg.section_subject_id = ss.id
                      AND gs.academic_term_id = e.academic_term_id
                                            AND gs.section_id = e.section_id
                      AND gs.status IN ('approved','locked')
                    LIMIT 1
                ), 0) AS completed_grade_checkpoints
         FROM enrollments e
         JOIN sections sec ON sec.id = e.section_id
         JOIN section_subjects ss ON ss.section_id = sec.id
         JOIN subjects sub ON sub.id = ss.subject_id
         JOIN students s ON s.id = e.student_id
         LEFT JOIN users u ON u.id = COALESCE(ss.instructor_id, sec.teacher_id)
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.status = 'enrolled'
         ORDER BY sub.subject_code, sub.subject_name"
    );
    $stmt->execute(['student_id' => $studentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLmsSubjectForStudent(PDO $pdo, int $studentId, int $subjectId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT sub.id AS subject_id, sub.subject_code, sub.subject_name, sub.units,
                sec.section_name, e.school_year, e.semester,
                COALESCE(ss.day_of_week, sec.day_of_week) AS day_of_week,
                COALESCE(ss.start_time, sec.start_time) AS start_time,
                COALESCE(ss.end_time, sec.end_time) AS end_time,
                COALESCE(ss.room, sec.room) AS room,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS instructor_name,
                u.email AS instructor_email
         FROM enrollments e
         JOIN sections sec ON sec.id = e.section_id
         JOIN section_subjects ss ON ss.section_id = sec.id
         JOIN subjects sub ON sub.id = ss.subject_id
         JOIN students s ON s.id = e.student_id
         LEFT JOIN users u ON u.id = COALESCE(ss.instructor_id, sec.teacher_id)
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.status = 'enrolled'
           AND sub.id = :subject_id
         LIMIT 1"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchLmsCourseGrades(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare(
        "SELECT sg.prelim_grade, sg.midterm_grade, sg.final_exam_grade, sg.final_grade, sg.remarks
         FROM student_grades sg
         JOIN grade_submissions gs ON gs.id = sg.grade_submission_id
         JOIN enrollments e ON e.id = sg.enrollment_id
         JOIN students s ON s.id = e.student_id AND s.id = sg.student_id
         JOIN section_subjects ss ON ss.id = sg.section_subject_id
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND gs.academic_term_id = e.academic_term_id
           AND gs.section_id = e.section_id
           AND gs.status IN ('approved','locked')
           AND ss.section_id = e.section_id
           AND ss.subject_id = :subject_id
         ORDER BY sg.id DESC"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLmsModulesForStudentSubject(PDO $pdo, int $studentId, int $subjectId): array
{
    $stmt = $pdo->prepare(
        "SELECT m.id AS module_id, m.title AS module_title, m.description AS module_description,
                m.display_order AS module_order,
                l.id AS lesson_id, l.title AS lesson_title, l.description AS lesson_description,
                l.content_type, l.display_order AS lesson_order
         FROM lms_modules m
         JOIN section_subjects ss ON ss.id = m.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id
         JOIN students s ON s.id = e.student_id
         LEFT JOIN lms_lessons l ON l.module_id = m.id AND l.is_published = 1
         WHERE s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
           AND m.is_published = 1
         ORDER BY m.display_order, m.id, l.display_order, l.id"
    );
    $stmt->execute([
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLmsLessonForStudentSubject(PDO $pdo, int $studentId, int $subjectId, int $lessonId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT l.id, l.title, l.description, l.content_type, l.content_body, l.content_path,
                m.id AS module_id, m.title AS module_title
         FROM lms_lessons l
         JOIN lms_modules m ON m.id = l.module_id AND m.is_published = 1
         JOIN section_subjects ss ON ss.id = m.section_subject_id
         JOIN sections sec ON sec.id = ss.section_id
         JOIN enrollments e ON e.section_id = sec.id
         JOIN students s ON s.id = e.student_id
         WHERE l.id = :lesson_id
           AND l.is_published = 1
           AND s.id = :student_id
           AND s.enrollment_status = 'enrolled'
           AND e.status = 'enrolled'
           AND e.academic_term_id = s.academic_term_id
           AND e.academic_term_id = sec.academic_term_id
           AND ss.subject_id = :subject_id
         LIMIT 1"
    );
    $stmt->execute([
        'lesson_id' => $lessonId,
        'student_id' => $studentId,
        'subject_id' => $subjectId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function requireLmsAccess(array $pageRoles = ['student']): array
{
    $pageRoles = array_values(array_intersect($pageRoles, LMS_PERMITTED_ROLES));
    checkRole($pageRoles);

    $role = (string)($_SESSION['role'] ?? '');
    if (!lmsRoleIsPermitted($role)) {
        $_SESSION['flash_error'] = 'Access denied: You do not have permission to access the LMS.';
        header('Location: ' . resolveAppUrl('index'));
        exit;
    }

    global $pdo;
    if (!isset($pdo) || !$pdo instanceof PDO) {
        require_once __DIR__ . '/../config/database.php';
    }

    if ($role !== 'student') {
        return ['role' => $role];
    }

    $student = fetchLmsStudentAccessRecord($pdo, (int)$_SESSION['user_id']);

    if (!$student || !lmsStudentMeetsAccessRequirements($student)) {
        $_SESSION['flash_error'] = 'LMS access is available after your downpayment is validated and your section enrollment is confirmed.';
        header('Location: ' . resolveAppUrl('student/dashboard'));
        exit;
    }

    $student['role'] = $role;
    return $student;
}
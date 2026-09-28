<?php
/**
 * Teacher Gradebook Management
 * Allows recording, updating, and submitting student grades by section and subject.
 */

require_once '../includes/auth_check.php';
checkRole(['teacher']);
ensureCsrfToken();
require_once '../config/database.php';

$sectionId = (int)($_GET['section_id'] ?? 0);
$teacherId = (int)$_SESSION['user_id'];
$section = null;
$sectionSubjects = [];
$activeSubjectId = null;
$activeSubject = null;
$rows = [];
$status = 'draft';
$isLead = false;

// Fetch available sections for selector/switcher
$availableSections = [];
try {
    $secListStmt = $pdo->prepare("
        SELECT s.id, s.section_name, s.schedule, s.room,
               COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
               COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
               (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id AND e.status = 'enrolled') AS enrolled_count
        FROM sections s
        LEFT JOIN courses c ON c.id = s.course_id
        WHERE s.teacher_id = :tid_a
           OR EXISTS (
               SELECT 1 FROM section_subjects ss
               WHERE ss.section_id = s.id AND ss.instructor_id = :tid_b
           )
        ORDER BY course_code ASC, s.section_name ASC
    ");
    $secListStmt->execute(['tid_a' => $teacherId, 'tid_b' => $teacherId]);
    $availableSections = $secListStmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch gradebook available sections failed: " . $e->getMessage());
}

if ($sectionId) {
    $q = $pdo->prepare('
        SELECT s.id, s.section_name, s.academic_term_id, s.teacher_id,
               COALESCE(c.course_code, s.section_name, \'Section\') AS course_code,
               COALESCE(c.course_name, s.section_name, \'Block Section\') AS course_name,
               (SELECT COUNT(*) FROM section_subjects WHERE section_id = s.id) AS subject_count,
               (SELECT SUM(sub2.units) FROM section_subjects ss2
                JOIN subjects sub2 ON sub2.id = ss2.subject_id
                WHERE ss2.section_id = s.id) AS total_units
        FROM sections s 
        LEFT JOIN courses c ON c.id = s.course_id 
        WHERE s.id = :id
          AND (
              s.teacher_id = :teacher_id_a
              OR EXISTS (
                  SELECT 1 FROM section_subjects ss
                  WHERE ss.section_id = s.id AND ss.instructor_id = :teacher_id_b
              )
          )
    ');

    $q->execute([
        'id'           => $sectionId,
        'teacher_id_a' => $teacherId,
        'teacher_id_b' => $teacherId
    ]);
    $section = $q->fetch();

    if ($section) {
        $isLead = ((int)$section['teacher_id'] === $teacherId);
        if ((int)$section['subject_count'] > 0) {
            if ($isLead) {
                $qSub = $pdo->prepare('
                    SELECT ss.id AS section_subject_id, sub.subject_code, sub.subject_name, sub.units
                    FROM section_subjects ss
                    JOIN subjects sub ON sub.id = ss.subject_id
                    WHERE ss.section_id = :id
                    ORDER BY sub.subject_code ASC
                ');
                $qSub->execute(['id' => $sectionId]);
            } else {
                $qSub = $pdo->prepare('
                    SELECT ss.id AS section_subject_id, sub.subject_code, sub.subject_name, sub.units
                    FROM section_subjects ss
                    JOIN subjects sub ON sub.id = ss.subject_id
                    WHERE ss.section_id = :id
                      AND ss.instructor_id = :teacher_id
                    ORDER BY sub.subject_code ASC
                ');
                $qSub->execute([
                    'id'         => $sectionId,
                    'teacher_id' => $teacherId
                ]);
            }
            $sectionSubjects = $qSub->fetchAll();

            if (!empty($sectionSubjects)) {
                $reqSubjectId = (int)($_GET['subject_id'] ?? 0);
                foreach ($sectionSubjects as $ss) {
                    if ((int)$ss['section_subject_id'] === $reqSubjectId) {
                        $activeSubject = $ss;
                        $activeSubjectId = (int)$ss['section_subject_id'];
                        break;
                    }
                }
                if (!$activeSubject) {
                    $activeSubject = $sectionSubjects[0];
                    $activeSubjectId = (int)$activeSubject['section_subject_id'];
                }
            } elseif (!$isLead) {
                $section = null;
            }
        }

        $q = $pdo->prepare("
            SELECT 
                e.id AS enrollment_id,
                st.first_name,
                st.last_name,
                sg.prelim_grade,
                sg.midterm_grade,
                sg.final_exam_grade,
                sg.final_grade,
                sg.remarks,
                COALESCE(gs.status, 'draft') AS grade_status 
            FROM enrollments e 
            JOIN students st ON st.id = e.student_id 
            LEFT JOIN grade_submissions gs 
                ON gs.section_id = e.section_id 
               AND gs.academic_term_id = e.academic_term_id 
            LEFT JOIN student_grades sg 
                ON sg.grade_submission_id = gs.id 
               AND sg.enrollment_id = e.id 
               AND (
                    (:active_ss_id_a IS NULL AND sg.section_subject_id IS NULL)
                    OR (sg.section_subject_id = :active_ss_id_b)
               )
            WHERE e.section_id = :id 
              AND e.status = 'enrolled' 
            ORDER BY st.last_name, st.first_name
        ");
        $q->execute([
            'active_ss_id_a' => $activeSubjectId,
            'active_ss_id_b' => $activeSubjectId,
            'id'             => $sectionId
        ]);
        $rows = $q->fetchAll();
        if ($rows) {
            $status = $rows[0]['grade_status'];
        }
    }
}

$page_title = 'Gradebook';
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Gradebook</h3>
        <p class="text-muted small m-0">Draft grades can be edited; submitted grades require Registrar approval.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if ($sectionId > 0 && !empty($availableSections)): ?>
            <form method="get" action="gradebook" class="d-inline-flex align-items-center gap-2">
                <label for="switchGradebookSection" class="visually-hidden">Switch Section</label>
                <select name="section_id" id="switchGradebookSection" class="form-select form-select-sm" onchange="this.form.submit()" style="max-width: 260px;">
                    <?php foreach ($availableSections as $secOpt): ?>
                        <option value="<?php echo (int)$secOpt['id']; ?>" <?php echo (int)$secOpt['id'] === $sectionId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($secOpt['course_code'] . ' (' . $secOpt['section_name'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <a href="my_classes" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1">
            <i class="bi bi-arrow-left"></i> Back to My Classes
        </a>
    </div>
</div>

<?php if (!$section): ?>
    <div class="card card-premium shadow-sm">
        <div class="card-body card-body-premium py-5 px-3 px-md-5">
            <div class="text-center mx-auto" style="max-width: 580px;">
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-brand-primary rounded-circle" style="width: 64px; height: 64px; font-size: 1.75rem;">
                        <i class="bi bi-award"></i>
                    </span>
                </div>
                <h4 class="text-darker fw-bold mb-2">Select a Class Section</h4>
                <p class="text-muted mb-4">Choose an assigned section below to access its gradebook and record student evaluations.</p>
                
                <?php if (!empty($availableSections)): ?>
                    <form method="get" action="gradebook" class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                        <label for="gradebookSectionSelector" class="visually-hidden">Choose Section</label>
                        <select name="section_id" id="gradebookSectionSelector" class="form-select form-select-lg" required style="font-size: 0.95rem;">
                            <option value="">-- Choose a Section --</option>
                            <?php foreach ($availableSections as $secOpt): ?>
                                <option value="<?php echo (int)$secOpt['id']; ?>">
                                    <?php echo htmlspecialchars($secOpt['course_code'] . ' (' . $secOpt['section_name'] . ') — ' . $secOpt['course_name'] . ' [' . (int)$secOpt['enrolled_count'] . ' Students]'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-brand-primary btn-lg text-nowrap px-4" style="font-size: 0.95rem;">
                            <i class="bi bi-arrow-right-circle me-1"></i> Open Gradebook
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info d-inline-flex align-items-center gap-2 text-start">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>No assigned sections found for grade entry under your faculty account.</div>
                    </div>
                    <div class="mt-3">
                        <a href="my_classes" class="btn btn-outline-secondary btn-sm">Go to My Classes</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php $locked = ($status !== 'draft'); ?>

    <?php if (!empty($sectionSubjects)): ?>
        <ul class="nav nav-pills mb-3 gap-2 flex-wrap">
            <?php foreach ($sectionSubjects as $subj): ?>
                <?php $isActive = ((int)$subj['section_subject_id'] === (int)$activeSubjectId); ?>
                <li class="nav-item">
                    <a class="nav-link py-2 px-3 <?php echo $isActive ? 'active bg-brand-primary text-white fw-bold shadow-sm' : 'bg-white border text-dark'; ?>" 
                       href="gradebook?section_id=<?php echo $sectionId; ?>&subject_id=<?php echo (int)$subj['section_subject_id']; ?>">
                        <span><?php echo htmlspecialchars($subj['subject_code']); ?></span>
                        <small class="<?php echo $isActive ? 'text-white-50' : 'text-muted'; ?>"> &mdash; <?php echo htmlspecialchars($subj['subject_name']); ?> (<?php echo number_format((float)$subj['units'], 0); ?>u)</small>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="../actions/academic_actions" class="card card-premium shadow-sm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="section_id" value="<?php echo $sectionId; ?>">
        <input type="hidden" name="section_subject_id" value="<?php echo $activeSubjectId !== null ? (int)$activeSubjectId : ''; ?>">

        <div class="card-header card-header-premium d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <strong><?php
                    if ($activeSubject) {
                        echo htmlspecialchars($section['section_name']) . ' &mdash; ' . htmlspecialchars($activeSubject['subject_code'] . ': ' . $activeSubject['subject_name']);
                    } elseif ((int)$section['subject_count'] > 0) {
                        echo htmlspecialchars($section['section_name'])
                            . ' &mdash; ' . (int)$section['subject_count']
                            . ' Subjects (' . number_format((float)($section['total_units'] ?? 0), 0) . ' units)';
                    } else {
                        echo htmlspecialchars($section['course_code'] . ' — ' . $section['course_name']);
                    }
                ?></strong>
            </div>
            <span class="badge bg-<?php echo $locked ? 'warning' : 'secondary'; ?>-subtle text-dark border px-2.5 py-1">
                <i class="bi <?php echo $locked ? 'bi-lock-fill' : 'bi-pencil-square'; ?> me-1"></i> Status: <?php echo ucfirst($status); ?>
            </span>
        </div>

        <div class="card-body card-body-premium p-0">
            <div class="scroll-hint d-md-none text-muted small p-3 pb-0 d-flex align-items-center gap-1">
                <i class="bi bi-arrow-left-right text-brand-primary"></i>
                <span>Scroll horizontally to view and enter grades</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sticky-first align-middle mb-0" style="min-width: 780px;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" style="min-width: 180px; width: 25%;">Student</th>
                            <th style="min-width: 90px; width: 12%;">Prelim</th>
                            <th style="min-width: 90px; width: 12%;">Midterm</th>
                            <th style="min-width: 95px; width: 12%;">Final Exam</th>
                            <th style="min-width: 95px; width: 12%;">Final Grade</th>
                            <th class="pe-4" style="min-width: 170px; width: 27%;">Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    No enrolled students found in this section.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td class="ps-4 fw-semibold text-darker">
                                        <?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?>
                                    </td>
                                    <?php foreach (['prelim' => 'prelim_grade', 'midterm' => 'midterm_grade', 'exam' => 'final_exam_grade', 'final' => 'final_grade'] as $input => $column): ?>
                                        <td>
                                            <input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" 
                                                   style="min-width: 75px;"
                                                   name="<?php echo $input; ?>[<?php echo (int)$row['enrollment_id']; ?>]" 
                                                   value="<?php echo htmlspecialchars($row[$column] ?? ''); ?>" 
                                                   placeholder="0.00"
                                                   <?php echo $locked ? 'disabled' : ''; ?>>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="pe-4">
                                        <input class="form-control form-control-sm" 
                                               style="min-width: 140px;"
                                               name="remarks[<?php echo (int)$row['enrollment_id']; ?>]" 
                                               value="<?php echo htmlspecialchars($row['remarks'] ?? ''); ?>" 
                                               placeholder="Optional remark..."
                                               <?php echo $locked ? 'disabled' : ''; ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!$locked && !empty($rows)): ?>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
                <div class="text-muted small">
                    <?php if (!$isLead): ?>
                        <i class="bi bi-info-circle me-1"></i>You are saving drafts for your assigned subjects. The section lead submits final grades for Registrar approval.
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2 ms-auto">
                    <button name="action" value="save_grades" class="btn btn-outline-secondary btn-sm px-3">
                        <i class="bi bi-save me-1"></i> Save Draft
                    </button>
                    <?php if ($isLead): ?>
                        <button name="action" value="submit_grades" class="btn btn-brand-primary btn-sm px-3">
                            <i class="bi bi-send-check me-1"></i> Submit for Approval
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </form>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>

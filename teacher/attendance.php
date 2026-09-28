<?php
/**
 * Teacher Attendance Management
 * Records class session attendance for assigned sections.
 */

require_once '../includes/auth_check.php';
checkRole(['teacher']);
ensureCsrfToken();
require_once '../config/database.php';

$userId = (int)$_SESSION['user_id'];
$sectionId = (int)($_GET['section_id'] ?? 0);
$section = null;
$students = [];

// Fetch available sections for attendance
$availableSections = [];
try {
    $secListStmt = $pdo->prepare("
        SELECT s.id, s.section_name, s.schedule, s.room,
               COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
               COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
               (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id AND e.status = 'enrolled') AS enrolled_count
        FROM sections s
        LEFT JOIN courses c ON c.id = s.course_id
        WHERE s.teacher_id = :tid
        ORDER BY course_code ASC, s.section_name ASC
    ");
    $secListStmt->execute(['tid' => $userId]);
    $availableSections = $secListStmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch attendance sections failed: " . $e->getMessage());
}

if ($sectionId) {
    $q = $pdo->prepare('
        SELECT s.id, s.schedule, s.room,
               COALESCE(c.course_code, s.section_name, \'Section\') AS course_code,
               COALESCE(c.course_name, s.section_name, \'Block Section\') AS course_name
        FROM sections s 
        LEFT JOIN courses c ON c.id = s.course_id 
        WHERE s.id = :id AND s.teacher_id = :teacher_id
    ');

    $q->execute([
        'id' => $sectionId,
        'teacher_id' => $userId
    ]);
    $section = $q->fetch();

    if ($section) {
        $q = $pdo->prepare("
            SELECT e.student_id, s.first_name, s.last_name 
            FROM enrollments e 
            JOIN students s ON s.id = e.student_id 
            WHERE e.section_id = :id AND e.status = 'enrolled' 
            ORDER BY s.last_name, s.first_name
        ");
        $q->execute(['id' => $sectionId]);
        $students = $q->fetchAll();
    }
}

$page_title = 'Attendance';
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Class Attendance</h3>
        <p class="text-muted small m-0">Record a session for your assigned section.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if ($sectionId > 0 && !empty($availableSections)): ?>
            <form method="get" action="attendance" class="d-inline-flex align-items-center gap-2">
                <label for="switchAttendanceSection" class="visually-hidden">Switch Section</label>
                <select name="section_id" id="switchAttendanceSection" class="form-select form-select-sm" onchange="this.form.submit()" style="max-width: 260px;">
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
                        <i class="bi bi-clipboard-check"></i>
                    </span>
                </div>
                <h4 class="text-darker fw-bold mb-2">Select a Class Section</h4>
                <p class="text-muted mb-4">Choose one of your assigned sections below to take and record session attendance.</p>
                
                <?php if (!empty($availableSections)): ?>
                    <form method="get" action="attendance" class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                        <label for="attendanceSectionSelector" class="visually-hidden">Choose Section</label>
                        <select name="section_id" id="attendanceSectionSelector" class="form-select form-select-lg" required style="font-size: 0.95rem;">
                            <option value="">-- Choose a Section --</option>
                            <?php foreach ($availableSections as $secOpt): ?>
                                <option value="<?php echo (int)$secOpt['id']; ?>">
                                    <?php echo htmlspecialchars($secOpt['course_code'] . ' (' . $secOpt['section_name'] . ') — ' . $secOpt['course_name'] . ' [' . (int)$secOpt['enrolled_count'] . ' Students]'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-brand-primary btn-lg text-nowrap px-4" style="font-size: 0.95rem;">
                            <i class="bi bi-arrow-right-circle me-1"></i> Open Attendance
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info d-inline-flex align-items-center gap-2 text-start">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>No lead sections available for attendance recording under your faculty account.</div>
                    </div>
                    <div class="mt-3">
                        <a href="my_classes" class="btn btn-outline-secondary btn-sm">Go to My Classes</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php else: ?>
    <form method="post" action="../actions/academic_actions" class="card card-premium shadow-sm">
        <input type="hidden" name="action" value="save_attendance">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="section_id" value="<?php echo $sectionId; ?>">

        <div class="card-header card-header-premium d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <strong><?php echo htmlspecialchars($section['course_code'] . ' — ' . $section['course_name']); ?></strong>
                <div class="text-muted small">
                    <i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($section['schedule']); ?>
                    <?php if (!empty($section['room'])): ?> &middot; <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($section['room']); ?><?php endif; ?>
                </div>
            </div>
            <span class="badge bg-secondary-subtle text-brand-primary border border-secondary px-2.5 py-1 text-uppercase fw-semibold" style="font-size: 0.7rem;">
                <?php echo count($students); ?> Enrolled Students
            </span>
        </div>

        <div class="card-body card-body-premium">
            <div class="row g-3 mb-4">
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label fw-semibold small text-darker">Session Date</label>
                    <input type="date" class="form-control form-control-sm" name="session_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-6 col-sm-6 col-md-3">
                    <label class="form-label fw-semibold small text-darker">Start Time</label>
                    <input type="time" class="form-control form-control-sm" name="start_time">
                </div>
                <div class="col-6 col-sm-6 col-md-3">
                    <label class="form-label fw-semibold small text-darker">End Time</label>
                    <input type="time" class="form-control form-control-sm" name="end_time">
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label fw-semibold small text-darker">Topic</label>
                    <input class="form-control form-control-sm" name="topic" maxlength="255" placeholder="Session topic...">
                </div>
            </div>

            <div class="scroll-hint d-md-none text-muted small mb-2 d-flex align-items-center gap-1">
                <i class="bi bi-arrow-left-right text-brand-primary"></i>
                <span>Scroll horizontally to view attendance status and remarks</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sticky-first align-middle mb-0" style="min-width: 580px;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="min-width: 180px; width: 35%;">Student</th>
                            <th style="min-width: 140px; width: 25%;">Status</th>
                            <th class="pe-3" style="min-width: 200px; width: 40%;">Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="3" class="text-center py-4 text-muted">
                                    No enrolled students found in this section.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold text-darker"><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                                    <td>
                                        <select class="form-select form-select-sm" style="min-width: 130px;" name="status[<?php echo (int)$student['student_id']; ?>]">
                                            <?php foreach (['present', 'absent', 'late', 'excused'] as $status): ?>
                                                <option value="<?php echo $status; ?>"><?php echo ucfirst($status); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="pe-3">
                                        <input class="form-control form-control-sm" style="min-width: 180px;" name="student_remarks[<?php echo (int)$student['student_id']; ?>]" maxlength="255" placeholder="Optional remark...">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer bg-white text-end py-3">
            <button type="submit" class="btn btn-brand-primary px-4 fw-semibold" <?php echo empty($students) ? 'disabled' : ''; ?>>
                <i class="bi bi-check2-circle me-1"></i> Save Attendance
            </button>
        </div>
    </form>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>

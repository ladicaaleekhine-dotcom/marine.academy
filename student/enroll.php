<?php
/**
 * Student Class Enrollment Form
 * Allows eligible students (enrollment_status = 'paid') to select sections.
 * Strictly filters class sections to the student's registered Program and Year Level.
 * Unified NCST Maritime Academy Theme.
 */

require_once '../includes/auth_check.php';
checkRole(['student']);

require_once '../config/database.php';
require_once '../includes/academic_terms.php';
require_once '../includes/assessments.php';
require_once '../includes/reservation.php';

$userId = (int)$_SESSION['user_id'];
$studentId = 0;
$enrollmentStatus = '';
$isEligible = false;
$activeTerm = null;
$hasOutstandingBalance = false;
$hasPreviousSemesterBalance = false;
$balanceWarning = '';
$studentProgram = 'BSMT';
$studentYearLevel = '1st Year';
$activeReservation = null;

// Fetch student profile ID, program, year level, and enrollment status
try {
    $activeTerm = getActiveAcademicTerm($pdo);
    $stmt = $pdo->prepare("SELECT id, enrollment_status, outstanding_balance, academic_term_id, program_code, program_applying_for, year_level FROM students WHERE user_id = :user_id LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $student = $stmt->fetch();
    if ($student) {
        $studentId = (int)$student['id'];
        $enrollmentStatus = $student['enrollment_status'];
        $hasOutstandingBalance = (float)($student['outstanding_balance'] ?? 0.00) > 0;

        // Sweep any expired reservations and fetch student's active hold if one exists
        sweepExpiredReservations($pdo);
        $activeReservation = getActiveStudentReservation($pdo, $studentId);

        // Resolve student program
        $prog = trim($student['program_code'] ?? '');
        if ($prog === '') {
            $progApp = trim($student['program_applying_for'] ?? '');
            if (stripos($progApp, 'Marine Transportation') !== false || stripos($progApp, 'BSMT') !== false) {
                $prog = 'BSMT';
            } elseif (stripos($progApp, 'Marine Engineering') !== false || stripos($progApp, 'BSMarE') !== false) {
                $prog = 'BSMarE';
            } else {
                $prog = 'BSMT';
            }
        }
        $studentProgram = $prog;

        // Resolve student year level
        $yl = trim($student['year_level'] ?? '');
        if ($yl === '') {
            $yl = '1st Year';
        }
        $studentYearLevel = $yl;

        if ($activeTerm && $studentId > 0) {
            $previousBalance = getPreviousUnpaidAssessmentBalance($pdo, $studentId, (int)($student['academic_term_id'] ?? $activeTerm['id']));
            $hasPreviousSemesterBalance = $previousBalance !== null;
            if ($hasPreviousSemesterBalance) {
                $balanceWarning = 'Enrollment Blocked. You have an outstanding balance of ₱' . number_format((float)$previousBalance['outstanding_balance'], 2) . ' from the previous semester. Please settle your outstanding balance before enrolling for the next semester.';
            } elseif ($hasOutstandingBalance) {
                $balanceWarning = 'You have an outstanding balance from the current assessment. Please settle your dues before enrolling in classes.';
            }
        } elseif ($hasOutstandingBalance) {
            $balanceWarning = 'You have an outstanding balance from the current assessment. Please settle your dues before enrolling in classes.';
        }

        $isEligible = $enrollmentStatus === 'paid' && !$hasOutstandingBalance && !$hasPreviousSemesterBalance && $activeTerm !== null;
    }
} catch (\PDOException $e) {
    error_log("Enrollment fetch student status failed: " . $e->getMessage());
}

$sections = [];
$myActiveEnrollments = []; // maps section_id => status

if ($isEligible && $studentId > 0) {
    // Fetch ONLY sections matching the student's program and year level for the active term
    // Capacity count includes confirmed/approved enrollments AND active holds from other students
    try {
        $stmtSec = $pdo->prepare("
            SELECT s.*, 
                   c.course_code, c.course_name, c.units,
                   u.username AS teacher_name, u.email AS teacher_email,
                   ((SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id AND e.status IN ('approved', 'enrolled'))
                    + (SELECT COUNT(*) FROM section_reservations sr WHERE sr.section_id = s.id AND sr.status = 'active' AND sr.expires_at > NOW() AND sr.student_id != :ex_sid)) AS filled,
                   (SELECT COUNT(*) FROM section_subjects ss WHERE ss.section_id = s.id) AS subject_count,
                   (SELECT COALESCE(SUM(sub.units), 0) FROM section_subjects ss JOIN subjects sub ON ss.subject_id = sub.id WHERE ss.section_id = s.id) AS total_section_units
            FROM sections s
            LEFT JOIN courses c ON s.course_id = c.id
            LEFT JOIN users u ON s.teacher_id = u.id
            WHERE s.academic_term_id = :academic_term_id
              AND s.program = :student_program
              AND s.year_level = :student_year_level
              AND s.status = 'active'
            ORDER BY s.section_name ASC
        ");
        $stmtSec->execute([
            'academic_term_id' => (int)$activeTerm['id'],
            'student_program' => $studentProgram,
            'student_year_level' => $studentYearLevel,
            'ex_sid' => $studentId
        ]);
        $sections = $stmtSec->fetchAll();
    } catch (\PDOException $e) {
        error_log("Enrollment fetch sections failed: " . $e->getMessage());
    }

    // Fetch student's existing active enrollments
    try {
        $stmtMy = $pdo->prepare("SELECT section_id, status FROM enrollments WHERE student_id = :student_id AND academic_term_id = :academic_term_id AND status != 'dropped'");
        $stmtMy->execute(['student_id' => $studentId, 'academic_term_id' => (int)$activeTerm['id']]);
        $myActiveEnrollments = $stmtMy->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (\PDOException $e) {
        error_log("Enrollment fetch my enrollments failed: " . $e->getMessage());
    }

    // Fetch subjects mapping for preview modal
    $studentSectionSubjectsMap = [];
    if (!empty($sections)) {
        $secIds = array_column($sections, 'id');
        $placeholders = implode(',', array_fill(0, count($secIds), '?'));
        try {
            $subStmt = $pdo->prepare("
                SELECT ss.id, ss.section_id, ss.subject_id, ss.day_of_week, ss.start_time, ss.end_time, ss.room,
                       sub.subject_code, sub.subject_name, sub.units,
                       IFNULL(u.username, '') AS teacher_username,
                       IFNULL(u.first_name, '') AS teacher_first,
                       IFNULL(u.last_name, '') AS teacher_last
                FROM section_subjects ss
                JOIN subjects sub ON sub.id = ss.subject_id
                LEFT JOIN users u ON u.id = ss.instructor_id
                WHERE ss.section_id IN ($placeholders)
                ORDER BY ss.section_id ASC, 
                         FIELD(ss.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
                         ss.start_time ASC
            ");
            $subStmt->execute($secIds);
            $subs = $subStmt->fetchAll();
            foreach ($subs as $row) {
                $studentSectionSubjectsMap[$row['section_id']][] = $row;
            }
        } catch (\Throwable $e) {
            error_log('Error fetching student section subjects: ' . $e->getMessage());
        }
    }
}

$page_title = "Class Enrollment";
require_once '../includes/header.php';
?>

<!-- Page Heading -->
<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-mortarboard me-1"></i> Cadet Enrollment</div>
        <h3 class="m-0 text-navy-alt fw-bold">Class Section Enrollment</h3>
        <p class="text-muted small m-0">Register in scheduled class sections for <strong><?php echo htmlspecialchars($studentProgram); ?> &bull; <?php echo htmlspecialchars($studentYearLevel); ?></strong> (<?php echo $activeTerm ? htmlspecialchars($activeTerm['school_year'] . ' / ' . ucfirst($activeTerm['semester']) . ' Sem') : 'Active Term'; ?>).</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="badge" style="background: var(--surface-tint); color: var(--brand-dark); border: 1px solid var(--brand-primary-soft); padding: 8px 14px; font-size: 0.8rem; font-weight: 700; border-radius: 999px;">
            <i class="bi bi-person-badge me-1 text-brand-primary"></i> <?php echo htmlspecialchars($studentProgram); ?> &bull; <?php echo htmlspecialchars($studentYearLevel); ?>
        </span>
    </div>
</div>

<?php if ($activeReservation): ?>
    <!-- Active 48-Hour Temporary Slot Hold Banner -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; overflow: hidden; border: 1px solid #99f6e4 !important; background: linear-gradient(135deg, #f0fdfa 0%, #ccfbf1 100%);">
        <div class="d-flex align-items-center gap-3 px-4 py-3 flex-wrap">
            <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: #0b9b98;">
                <i class="bi bi-clock-history fs-5"></i>
            </div>
            <div class="flex-fill">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="fw-bold text-dark" style="font-size: 0.98rem;">
                        Active 48-Hour Temporary Slot Hold: <?php echo htmlspecialchars($activeReservation['section_name'] ?? 'Section'); ?>
                    </span>
                    <span class="badge bg-warning text-dark border border-warning px-2.5 py-1" style="font-size: 0.72rem; border-radius: 999px; font-weight: 700;">
                        Hold Active
                    </span>
                </div>
                <div class="text-muted small mt-1">
                    Your temporary reservation expires in <strong id="studentReservationCountdown" class="text-danger font-monospace fs-6">--:--:--</strong> (<?php echo date('M d, Y h:i A', strtotime($activeReservation['expires_at'])); ?>).
                </div>
                <div class="small text-danger fw-semibold mt-1">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    What happens on expiry: If not confirmed by the Registrar within 48 hours, your reserved slot is automatically released and you must re-select your class section.
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="my_enrollments" class="btn btn-sm btn-brand-primary fw-semibold px-3 shadow-sm d-inline-flex align-items-center gap-1.5">
                    <i class="bi bi-check-circle"></i> View My Enrollments
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$isEligible): ?>
    <!-- Ineligible Cadet Banner Warning -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-body p-5 text-center bg-white">
            <div class="mb-4 text-warning" style="font-size: 4rem;">
                <i class="bi bi-shield-exclamation"></i>
            </div>
            <h4 class="fw-bold text-navy mb-2">Enrollment Blocked</h4>
            <p class="text-muted mx-auto mb-4" style="max-width: 500px;">
                <?php echo htmlspecialchars($balanceWarning !== '' ? $balanceWarning : 'You are currently not eligible to register for classes. Complete the required payment first. Access to enrollment is restricted to students with a confirmed Paid admission status.'); ?>
            </p>
            <div class="d-inline-flex gap-2 flex-wrap justify-content-center">
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-3 py-2 text-uppercase fw-bold" style="font-size: 0.8rem;">
                    Status: <?php echo htmlspecialchars($enrollmentStatus ?: 'Unapplied'); ?>
                </span>
                <?php if ($hasOutstandingBalance || $hasPreviousSemesterBalance): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger px-3 py-2 fw-bold" style="font-size: 0.8rem;">
                        <i class="bi bi-cash-coin me-1"></i> Balance: <?php echo formatCurrency($currentBalance); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="mt-4 pt-2">
                <a href="payment" class="btn btn-brand-primary px-4 py-2 fw-semibold shadow-sm d-inline-flex align-items-center gap-2" style="border-radius: var(--radius-sm, 8px);">
                    <i class="bi bi-credit-card-2-front"></i> Go to Payment Center
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Active Available Sections List -->
    <div class="card shadow-sm border-0 mb-4" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
            <div>
                <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
                    <i class="bi bi-grid-3x3-gap-fill"></i> Available Class Sections
                </h5>
                <div style="color: rgba(255,255,255,0.85); font-size: 0.76rem;">Select your desired class section for registration</div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="input-group input-group-sm" style="width: 220px;">
                    <span class="input-group-text bg-white bg-opacity-25 border-0 text-white"><i class="bi bi-search"></i></span>
                    <input type="text" id="enrollmentSearch" class="form-control form-control-sm border-0 bg-white bg-opacity-10 text-white placeholder-white" placeholder="Search sections...">
                </div>
                <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 6px 14px; font-weight: 600;">
                    <?php echo count($sections); ?> Section<?php echo count($sections) !== 1 ? 's' : ''; ?> Available
                </span>
            </div>
        </div>
        
        <div class="card-body p-0">
            <form action="../actions/enrollment_actions" method="POST" class="needs-validation" novalidate id="enrollForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="enroll">
                
                <div class="table-responsive">
                    <table class="table table-hover table-maritime align-middle m-0" id="enrollmentTable" style="width: 100%;">
                        <thead>
                            <tr>
                                <th class="ps-4 text-center" tabulator-field="select" style="width: 80px;">Select</th>
                                <th tabulator-field="section" style="width: 26%;">Class Section</th>
                                <th tabulator-field="curriculum" style="width: 22%;">Curriculum Load</th>
                                <th tabulator-field="capacity" style="width: 18%;">Cadet Capacity</th>
                                <th class="text-center" tabulator-field="status" style="width: 14%;">Status</th>
                                <th class="pe-4 text-end" tabulator-field="timetable" style="width: 14%;">Timetable</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($sections)): ?>
                                <?php foreach ($sections as $s): 
                                    $cap = (int)$s['capacity'];
                                    $filled = (int)$s['filled'];
                                    $isFull = $cap > 0 && $filled >= $cap;
                                    $hasRegistered = isset($myActiveEnrollments[$s['id']]);
                                    $myStatus = $hasRegistered ? $myActiveEnrollments[$s['id']] : '';
                                    $pct = $cap > 0 ? min(100, round(($filled / $cap) * 100)) : 0;
                                    $progressColor = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#0b9b98');
                                    
                                    $rowClass = '';
                                    if ($hasRegistered) {
                                        $rowClass = 'table-success-subtle';
                                    } elseif ($isFull) {
                                        $rowClass = 'table-light-subtle opacity-75';
                                    }
                                ?>
                                    <tr class="<?php echo $rowClass; ?>" id="row-<?php echo $s['id']; ?>">
                                        <td class="ps-4 text-center">
                                            <?php if (!$hasRegistered && !$isFull): ?>
                                                <input class="form-check-input section-checkbox border-secondary" type="checkbox" name="section_ids[]" value="<?php echo $s['id']; ?>" onchange="toggleRowHighlight(this)">
                                            <?php else: ?>
                                                <input class="form-check-input" type="checkbox" disabled>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-navy fs-6"><?php echo htmlspecialchars($s['section_name'] ?? ('Section #' . $s['id'])); ?></div>
                                            <div class="d-flex align-items-center gap-1 mt-1">
                                                <span class="badge bg-light text-navy border px-2 py-0.5" style="font-size: 0.7rem;"><?php echo htmlspecialchars($s['program']); ?></span>
                                                <span class="badge bg-secondary-subtle text-dark" style="font-size: 0.7rem;"><?php echo htmlspecialchars($s['year_level']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-1.5">
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 fw-semibold">
                                                    <i class="bi bi-book-half me-1"></i><?php echo (int)($s['subject_count'] ?: 1); ?> Subjects
                                                </span>
                                                <?php if ((float)$s['total_section_units'] > 0 || (float)($s['units'] ?? 0) > 0): ?>
                                                    <span class="badge bg-light text-dark border px-2 py-1 font-monospace" style="font-size: 0.72rem;">
                                                        <?php echo (float)($s['total_section_units'] ?: ($s['units'] ?? 0)); ?> Units
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-between small fw-bold mb-1" style="max-width: 130px;">
                                                <span class="text-dark"><?php echo $filled; ?> / <?php echo $cap; ?></span>
                                                <span class="text-muted" style="font-size: 0.72rem;"><?php echo $pct; ?>%</span>
                                            </div>
                                            <div style="height: 6px; width: 130px; background: #e5e7eb; border-radius: 999px; overflow: hidden;">
                                                <div style="height: 100%; width: <?php echo $pct; ?>%; background-color: <?php echo $progressColor; ?>; border-radius: 999px;"></div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($hasRegistered): ?>
                                                <?php if ($myStatus === 'pending'): ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Pending Approval</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success-subtle text-success border border-success px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;"><?php echo ucfirst($myStatus); ?></span>
                                                <?php endif; ?>
                                            <?php elseif ($isFull): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Full</span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Open</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="pe-4 text-end">
                                            <button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" onclick="openStudentSectionPreview(<?php echo $s['id']; ?>)">
                                                <i class="bi bi-eye"></i> Preview
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Submit Action Panel -->
                <?php if (!empty($sections)): ?>
                    <div class="p-3 bg-light border-top d-flex justify-content-between align-items-center rounded-bottom-4 flex-wrap gap-2">
                        <span class="text-muted small fw-medium" id="selectedCounter">Selected: 0 sections</span>
                        <button type="submit" class="btn btn-brand-primary px-5 py-2 fw-bold shadow-sm d-flex align-items-center gap-1.5">
                            <i class="bi bi-box-arrow-in-up"></i> Submit Section Registration
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- =========================================================================
     MODAL: STUDENT SECTION SCHEDULE PREVIEW
     ========================================================================= -->
<div class="modal fade" id="studentSectionModal" tabindex="-1" aria-labelledby="studentSectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <div>
                    <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2 mb-0" id="studentSectionModalLabel">
                        <i class="bi bi-calendar3-week"></i> <span id="stuModalSectionTitle">Section Schedule Preview</span>
                    </h5>
                    <small style="color: rgba(255,255,255,0.85); font-size: 0.78rem;">Timetable details, rooms, and instructors</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="p-3 rounded-3 bg-light border mb-3 d-flex flex-wrap gap-2 align-items-center justify-content-between">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 font-monospace" id="stuModalProgramBadge">BSMT</span>
                        <span class="badge bg-secondary-subtle text-dark border px-2.5 py-1" id="stuModalYearBadge">1st Year</span>
                        <span class="badge bg-light text-navy border px-2.5 py-1" id="stuModalUnitsBadge">0 Units</span>
                    </div>
                    <div class="small text-muted fw-semibold" id="stuModalCapacityText">Capacity: 0 / 0</div>
                </div>

                <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                    <table class="table table-hover align-middle border mb-0">
                        <thead style="background: #f4fafa; color: var(--brand-dark); font-size: 0.74rem; text-transform: uppercase; font-weight: 700; position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th class="ps-3" style="width: 35%;">Subject / Course</th>
                                <th style="width: 10%;">Units</th>
                                <th style="width: 15%;">Day</th>
                                <th style="width: 20%;">Time</th>
                                <th style="width: 10%;">Room</th>
                                <th class="pe-3" style="width: 10%;">Instructor</th>
                            </tr>
                        </thead>
                        <tbody id="stuModalSubjectsBody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer p-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     JAVASCRIPT CONTROLLERS
     ========================================================================= -->
<script>
    const studentSectionsData = <?= json_encode($sections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const studentSubjectsData = <?= json_encode($studentSectionSubjectsMap ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function formatTimeStudent(timeStr) {
        if (!timeStr) return '';
        const parts = timeStr.split(':');
        if (parts.length < 2) return timeStr;
        let h = parseInt(parts[0], 10);
        const m = parts[1];
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12;
        h = h ? h : 12;
        return `${h}:${m} ${ampm}`;
    }

    function openStudentSectionPreview(sectionId) {
        const sec = studentSectionsData.find(s => parseInt(s.id, 10) === parseInt(sectionId, 10));
        if (!sec) return;

        const subjects = studentSubjectsData[sectionId] || [];
        const secLabel = sec.section_name || ('Section #' + sec.id);

        document.getElementById('stuModalSectionTitle').textContent = secLabel;
        document.getElementById('stuModalProgramBadge').textContent = sec.program || '<?= htmlspecialchars($studentProgram) ?>';
        document.getElementById('stuModalYearBadge').textContent = sec.year_level || '<?= htmlspecialchars($studentYearLevel) ?>';
        
        let totalCredits = 0;
        subjects.forEach(sub => {
            totalCredits += parseFloat(sub.units || 0);
        });
        document.getElementById('stuModalUnitsBadge').textContent = (totalCredits || sec.total_section_units || sec.units || 0) + ' Total Units';
        document.getElementById('stuModalCapacityText').textContent = `Enrolled: ${sec.filled || 0} / ${sec.capacity || 0} cadets`;

        const tbody = document.getElementById('stuModalSubjectsBody');
        if (subjects.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center py-4 text-muted">
                        <i class="bi bi-info-circle fs-3 d-block mb-1 text-muted-light"></i>
                        <strong>No specific timetable blocks assigned yet.</strong><br>
                        <span class="small">Curriculum subjects will be credited upon enrollment confirmation.</span>
                    </td>
                </tr>
            `;
        } else {
            let html = '';
            subjects.forEach(sub => {
                const timeRange = (sub.start_time && sub.end_time) 
                    ? `${formatTimeStudent(sub.start_time)} – ${formatTimeStudent(sub.end_time)}`
                    : 'TBA';
                const teacher = (sub.teacher_first || sub.teacher_last)
                    ? `${sub.teacher_first} ${sub.teacher_last}`.trim()
                    : (sub.teacher_username || 'Unassigned');

                html += `
                    <tr>
                        <td class="ps-3">
                            <div class="fw-bold text-navy" style="font-size: 0.88rem;">${escapeHtml(sub.subject_code)}</div>
                            <div class="text-muted small" style="font-size: 0.78rem;">${escapeHtml(sub.subject_name)}</div>
                        </td>
                        <td>
                            <span class="badge bg-light text-navy border px-2 py-1 font-monospace">${escapeHtml(sub.units || '0')}</span>
                        </td>
                        <td>
                            <span class="badge bg-secondary-subtle text-dark border px-2.5 py-1" style="font-size: 0.74rem;">
                                ${escapeHtml(sub.day_of_week || 'TBA')}
                            </span>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark" style="font-size: 0.82rem;">
                                <i class="bi bi-clock me-1 text-brand-primary"></i>${escapeHtml(timeRange)}
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border px-2 py-1" style="font-size: 0.74rem;">
                                ${escapeHtml(sub.room || 'TBA')}
                            </span>
                        </td>
                        <td class="pe-3">
                            <div class="fw-semibold text-dark" style="font-size: 0.82rem;">${escapeHtml(teacher)}</div>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        const modal = new bootstrap.Modal(document.getElementById('studentSectionModal'));
        modal.show();
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    const selectedCounter = document.getElementById('selectedCounter');
    const enrollForm = document.getElementById('enrollForm');
    let selectedSectionCount = document.querySelectorAll('.section-checkbox:checked').length;

    function updateSelectedCounter() {
        if (selectedCounter) selectedCounter.textContent = `Selected: ${selectedSectionCount} sections`;
    }

    // Highlight rows on checkbox state change and update selected counter.
    window.toggleRowHighlight = function(checkbox) {
        const row = document.getElementById('row-' + checkbox.value);
        if (row) row.style.backgroundColor = checkbox.checked ? 'rgba(0, 128, 128, 0.06)' : '';
        selectedSectionCount = document.querySelectorAll('.section-checkbox:checked').length;
        updateSelectedCounter();
    };

    if (enrollForm) {
        enrollForm.addEventListener('submit', function (event) {
            selectedSectionCount = document.querySelectorAll('.section-checkbox:checked').length;
            if (selectedSectionCount !== 0) return;
            event.preventDefault();
            event.stopPropagation();
            Swal.fire({
                icon: 'warning',
                title: 'Selection Required',
                text: 'Please select at least one class section to submit your enrollment.',
                iconColor: '#d99a1d',
                confirmButtonColor: 'var(--brand-primary)',
                cancelButtonColor: '#6c757d',
                background: '#ffffff',
                color: '#1f2937'
            });
        }, false);
    }

    updateSelectedCounter();

    // Tabulator.js Initialization
    document.addEventListener('DOMContentLoaded', function() {
        var enrollmentTableEl = document.getElementById('enrollmentTable');
        if (enrollmentTableEl) {
            var enrollmentTable = new Tabulator("#enrollmentTable", {
                layout: "fitColumns",
                responsiveLayout: "collapse",
                responsiveLayoutCollapseStartOpen: false,
                rowHeader: {
                    formatter: "responsiveCollapse",
                    width: 36,
                    minWidth: 36,
                    hozAlign: "center",
                    resizable: false,
                    headerSort: false
                },
                pagination: "local",
                paginationSize: 25,
                paginationSizeSelector: [10, 25, 50, 100],
                placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-calendar-x fs-1 d-block mb-2 text-muted-light'></i>No active class sections are currently open for your program and year level.</div>",
                columns: [
                    { title: "Select", field: "select", width: 80, hozAlign: "center", headerSort: false, formatter: "html" },
                    { title: "Class Section", field: "section", minWidth: 180, formatter: "html" },
                    { title: "Curriculum Load", field: "curriculum", minWidth: 160, formatter: "html" },
                    { title: "Cadet Capacity", field: "capacity", width: 160, formatter: "html" },
                    { title: "Status", field: "status", width: 140, hozAlign: "center", formatter: "html" },
                    { title: "Timetable", field: "timetable", width: 120, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
                ]
            });

            var searchInput = document.getElementById('enrollmentSearch');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var term = this.value.trim().toLowerCase();
                    if (!term) {
                        enrollmentTable.clearFilter();
                    } else {
                        function stripHtml(html) {
                            var tmp = document.createElement('div');
                            tmp.innerHTML = html;
                            return tmp.textContent || tmp.innerText || '';
                        }
                        enrollmentTable.setFilter(function(data) {
                            return stripHtml(data.section).toLowerCase().includes(term) ||
                                   stripHtml(data.curriculum).toLowerCase().includes(term) ||
                                   stripHtml(data.status).toLowerCase().includes(term);
                        });
                    }
                });
            }
        }
    });
</script>

<style>
.tabulator {
    font-family: inherit;
    border: none;
    background-color: transparent;
    width: 100% !important;
    min-width: 0 !important;
}
.tabulator .tabulator-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    color: #475569;
    font-weight: 600;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.tabulator .tabulator-header .tabulator-col {
    background: transparent;
    border-right: none;
    padding: 8px 4px;
}
.tabulator .tabulator-row {
    border-bottom: 1px solid #f1f5f9;
    min-height: 48px;
    background: #ffffff;
}
.tabulator .tabulator-row:hover {
    background-color: #f8fafc !important;
}
.tabulator .tabulator-row .tabulator-cell {
    padding: 10px 12px;
    border-right: none;
    vertical-align: middle;
    font-size: 0.86rem;
    display: inline-flex;
    align-items: center;
}
.tabulator-row.tabulator-responsive-collapse {
    background: #f8fafc !important;
    padding: 8px 16px !important;
}
.tabulator .tabulator-footer {
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    padding: 8px 16px;
}
.tabulator-responsive-collapse table {
    width: 100%;
    font-size: 0.85rem;
}
.tabulator-responsive-collapse td {
    padding: 4px 8px;
}
</style>

<?php if ($activeReservation && (int)($activeReservation['seconds_left'] ?? 0) > 0): ?>
<script>
(function() {
    let secondsLeft = <?php echo (int)$activeReservation['seconds_left']; ?>;
    const el = document.getElementById('studentReservationCountdown');
    if (!el) return;
    function updateTimer() {
        if (secondsLeft <= 0) {
            el.textContent = 'Expired';
            setTimeout(function() { window.location.reload(); }, 2000);
            return;
        }
        const hours = Math.floor(secondsLeft / 3600);
        const minutes = Math.floor((secondsLeft % 3600) / 60);
        const seconds = secondsLeft % 60;
        el.textContent = 
            String(hours).padStart(2, '0') + ':' + 
            String(minutes).padStart(2, '0') + ':' + 
            String(seconds).padStart(2, '0');
        secondsLeft--;
    }
    updateTimer();
    setInterval(updateTimer, 1000);
})();
</script>
<?php endif; ?>

<?php
require_once '../includes/footer.php';
?>

<?php
/**
 * Student My Enrollments Overview
 * Displays the list of all section registrations submitted by the cadet,
 * detailing class schedules, room allocations, instructor assignments, and verification statuses.
 */

require_once '../includes/auth_check.php';
checkRole(['student']);

require_once '../config/database.php';

$userId = (int)$_SESSION['user_id'];
$studentId = 0;
$myEnrollments = [];
$totalUnits = 0;
$pendingCount = 0;
$enrolledCount = 0;

// Fetch student profile ID
try {
    $stmt = $pdo->prepare("SELECT id FROM students WHERE user_id = :user_id LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $student = $stmt->fetch();
    if ($student) {
        $studentId = (int)$student['id'];
    }
} catch (\PDOException $e) {
    error_log("My Enrollments student query failed: " . $e->getMessage());
}

if ($studentId > 0) {
    // Fetch cadet registrations
    try {
        $stmtMy = $pdo->prepare("
            SELECT e.id AS enrollment_id, e.school_year, e.semester, e.status AS reg_status, e.created_at,
                   s.id AS section_id, s.section_name, s.schedule, s.room,
                   COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                   COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                   COALESCE(
                       (SELECT SUM(sub2.units) FROM section_subjects ss2
                        JOIN subjects sub2 ON sub2.id = ss2.subject_id
                        WHERE ss2.section_id = s.id),
                       c.units,
                       0
                   ) AS units,
                   (SELECT COUNT(*) FROM section_subjects WHERE section_id = s.id) AS subject_count,
                   u.username AS teacher_name, u.email AS teacher_email
            FROM enrollments e
            JOIN sections s ON e.section_id = s.id
            LEFT JOIN courses c ON s.course_id = c.id
            LEFT JOIN users u ON s.teacher_id = u.id
            WHERE e.student_id = :student_id
            ORDER BY e.school_year DESC, e.semester DESC, course_code ASC
        ");

        $stmtMy->execute(['student_id' => $studentId]);
        $myEnrollments = $stmtMy->fetchAll();
        
        // Count totals
        foreach ($myEnrollments as $r) {
            if ($r['reg_status'] === 'enrolled') {
                $totalUnits += (int)$r['units'];
                $enrolledCount++;
            } elseif ($r['reg_status'] === 'pending') {
                $pendingCount++;
            }
        }
    } catch (\PDOException $e) {
        error_log("My Enrollments fetch registrations failed: " . $e->getMessage());
    }
}

$page_title = "My Class Schedule";
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">My Enrollments</h3>
        <p class="text-muted small m-0">Track your registered sections, check class timings, and verify enrollment approvals.</p>
    </div>
    <div class="d-flex gap-2"><a href="cor" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1"><i class="bi bi-file-earmark-text"></i>My COR</a><a href="enroll" class="btn btn-sm btn-brand-primary d-flex align-items-center gap-1 shadow-sm"><i class="bi bi-journal-plus"></i> Add Class Sections</a></div>
</div>

<!-- Quick Statistics Row -->
<div class="row g-3 mb-4">
    <!-- Total Registered Sections -->
    <div class="col-12 col-sm-4">
        <div class="card card-premium shadow-sm border-start border-4 border-brand-primary">
            <div class="card-body p-3.5 d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="text-uppercase text-muted-alt small fw-bold m-0" style="font-size: 0.72rem; letter-spacing: 0.5px;">Registered Sections</h6>
                    <h3 class="fw-extrabold text-navy-alt mt-1.5 mb-0"><?php echo count($myEnrollments); ?></h3>
                </div>
                <div class="fs-1 text-brand-primary opacity-25"><i class="bi bi-journals"></i></div>
            </div>
        </div>
    </div>
    
    <!-- Enrolled Credits -->
    <div class="col-12 col-sm-4">
        <div class="card card-premium shadow-sm border-start border-4 border-success">
            <div class="card-body p-3.5 d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="text-uppercase text-muted-alt small fw-bold m-0" style="font-size: 0.72rem; letter-spacing: 0.5px;">Enrolled Credits</h6>
                    <h3 class="fw-extrabold text-navy-alt mt-1.5 mb-0"><?php echo $totalUnits; ?> <span class="fs-6 text-muted fw-normal">Units</span></h3>
                </div>
                <div class="fs-1 text-success opacity-25"><i class="bi bi-mortarboard"></i></div>
            </div>
        </div>
    </div>

    <!-- Pending Verification -->
    <div class="col-12 col-sm-4">
        <div class="card card-premium shadow-sm border-start border-4 border-warning">
            <div class="card-body p-3.5 d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="text-uppercase text-muted-alt small fw-bold m-0" style="font-size: 0.72rem; letter-spacing: 0.5px;">Awaiting Registrar</h6>
                    <h3 class="fw-extrabold text-navy-alt mt-1.5 mb-0"><?php echo $pendingCount; ?> <span class="fs-6 text-muted fw-normal">Pending</span></h3>
                </div>
                <div class="fs-1 text-warning opacity-25"><i class="bi bi-clock-history"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Enrollments Table Card -->
<div class="card card-premium shadow-sm">
    <div class="card-header card-header-premium d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <h5 class="card-title m-0 fw-semibold text-navy-alt">Registered Curriculum Schedule</h5>
        <div class="d-flex align-items-center gap-2 ms-auto">
            <div class="input-group input-group-sm" style="min-width: 240px;">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="myEnrollmentsSearch" class="form-control form-control-sm border-start-0 ps-0" placeholder="Search my classes...">
            </div>
        </div>
    </div>
    
    <div class="card-body card-body-premium p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle m-0" id="myEnrollmentsTable" style="width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" tabulator-field="term" style="width: 140px;">Academic Term</th>
                        <th tabulator-field="course" style="width: 25%;">Course Subject</th>
                        <th tabulator-field="schedule" style="width: 20%;">Schedule</th>
                        <th tabulator-field="room" style="width: 15%;">Room</th>
                        <th tabulator-field="instructor" style="width: 15%;">Instructor</th>
                        <th tabulator-field="credits" style="width: 110px;">Credits</th>
                        <th class="pe-4 text-center" tabulator-field="status" style="width: 150px;">Verification Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($myEnrollments)): ?>
                        <?php foreach ($myEnrollments as $r): ?>
                            <tr>
                                <td class="ps-4 text-dark font-monospace small">
                                    SY <?php echo htmlspecialchars($r['school_year']); ?><br>
                                    <span class="text-muted-alt font-sans" style="font-size: 0.76rem;"><?php echo $r['semester']; ?> Semester</span>
                                </td>
                                <td>
                                    <?php if ((int)$r['subject_count'] > 0): ?>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($r['section_name']); ?></div>
                                        <div class="text-muted small" style="font-size: 0.8rem;"><?php echo (int)$r['subject_count']; ?> Subjects</div>
                                    <?php else: ?>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($r['course_code']); ?></div>
                                        <div class="text-muted small text-truncate" style="max-width: 200px; font-size: 0.8rem;" title="<?php echo htmlspecialchars($r['course_name']); ?>">
                                            <?php echo htmlspecialchars($r['course_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold text-darker" style="font-size: 0.88rem;">
                                    <i class="bi bi-clock me-1 text-muted"></i><?php echo htmlspecialchars($r['schedule']); ?>
                                </td>
                                <td>
                                    <i class="bi bi-geo-alt me-1 text-muted"></i><?php echo htmlspecialchars($r['room'] ?: 'No room allocated'); ?>
                                </td>
                                <td>
                                    <?php if ($r['teacher_name']): ?>
                                        <div class="fw-semibold text-darker"><?php echo htmlspecialchars($r['teacher_name']); ?></div>
                                        <div class="text-muted-light small" style="font-size: 0.7rem;"><?php echo htmlspecialchars($r['teacher_email']); ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-navy border px-2.5 py-1">
                                        <?php echo htmlspecialchars($r['units']); ?> Units
                                    </span>
                                </td>
                                <td class="pe-4 text-center">
                                    <?php if ($r['reg_status'] === 'enrolled'): ?>
                                        <span class="badge bg-success-subtle text-success-dark border border-success px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Enrolled</span>
                                    <?php elseif ($r['reg_status'] === 'pending'): ?>
                                        <span class="badge bg-warning-subtle text-warning-dark border border-warning px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Pending Approval</span>
                                    <?php elseif ($r['reg_status'] === 'dropped'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Dropped</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;"><?php echo htmlspecialchars($r['reg_status']); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

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

<script>
document.addEventListener('DOMContentLoaded', function () {
    function stripHtml(html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        return tmp.textContent || tmp.innerText || '';
    }

    var myEnrollmentsTable = new Tabulator("#myEnrollmentsTable", {
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
        placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-journal-check fs-1 d-block mb-2 text-muted-light'></i>You haven't registered in any class sections for this term yet.<br><a href='enroll' class='btn btn-sm btn-brand-primary mt-3 px-4 py-2 small fw-bold'>Enroll in Classes Now</a></div>",
        columns: [
            { title: "Academic Term", field: "term", width: 140, formatter: "html" },
            { title: "Course Subject", field: "course", minWidth: 180, formatter: "html" },
            { title: "Schedule", field: "schedule", minWidth: 140, formatter: "html" },
            { title: "Room", field: "room", width: 130, formatter: "html" },
            { title: "Instructor", field: "instructor", width: 150, formatter: "html" },
            { 
                title: "Credits", 
                field: "credits", 
                width: 110, 
                hozAlign: "center", 
                formatter: "html",
                sorter: function(a, b) {
                    var aNum = parseFloat(stripHtml(a)) || 0;
                    var bNum = parseFloat(stripHtml(b)) || 0;
                    return aNum - bNum;
                }
            },
            { title: "Verification Status", field: "status", width: 150, hozAlign: "center", formatter: "html" }
        ]
    });

    var searchInput = document.getElementById('myEnrollmentsSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var term = this.value.trim().toLowerCase();
            if (!term) {
                myEnrollmentsTable.clearFilter();
            } else {
                myEnrollmentsTable.setFilter(function (data) {
                    return stripHtml(data.term).toLowerCase().includes(term) ||
                           stripHtml(data.course).toLowerCase().includes(term) ||
                           stripHtml(data.schedule).toLowerCase().includes(term) ||
                           stripHtml(data.room).toLowerCase().includes(term) ||
                           stripHtml(data.instructor).toLowerCase().includes(term) ||
                           stripHtml(data.status).toLowerCase().includes(term);
                });
            }
        });
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>

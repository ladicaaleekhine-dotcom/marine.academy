<?php
/**
 * Class Roster
 * Read-only list of enrolled students for a specific section.
 * Verifies section ownership before displaying data.
 */

require_once '../includes/auth_check.php';
checkRole(['teacher', 'admin']);

require_once '../config/database.php';

$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'];
$section = null;
$students = [];

if ($sectionId > 0) {
    try {
        // Fetch section with ownership check built into the query for teachers
        if ($userRole === 'teacher') {
            $secStmt = $pdo->prepare("
                SELECT s.id, s.schedule, s.room, s.capacity, s.teacher_id,
                       COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                       COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                       COALESCE(c.units, 0) AS units
                FROM sections s
                LEFT JOIN courses c ON s.course_id = c.id
                WHERE s.id = :section_id
                  AND (
                      s.teacher_id = :teacher_id_a
                      OR EXISTS (
                          SELECT 1 FROM section_subjects ss
                          WHERE ss.section_id = s.id AND ss.instructor_id = :teacher_id_b
                      )
                  )
                LIMIT 1
            ");
            $secStmt->execute([
                'section_id'   => $sectionId,
                'teacher_id_a' => $userId,
                'teacher_id_b' => $userId,
            ]);
        } else {
            $secStmt = $pdo->prepare("
                SELECT s.id, s.schedule, s.room, s.capacity, s.teacher_id,
                       COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                       COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                       COALESCE(c.units, 0) AS units,
                       u.username AS teacher_username
                FROM sections s
                LEFT JOIN courses c ON s.course_id = c.id
                LEFT JOIN users u ON s.teacher_id = u.id
                WHERE s.id = :section_id
                LIMIT 1
            ");
            $secStmt->execute(['section_id' => $sectionId]);
        }

        $section = $secStmt->fetch();

        if ($section) {
            $stuStmt = $pdo->prepare("
                SELECT st.first_name, st.last_name, st.contact_number,
                       u.email, u.username
                FROM enrollments e
                JOIN students st ON e.student_id = st.id
                JOIN users u ON st.user_id = u.id
                WHERE e.section_id = :section_id
                  AND e.status = 'enrolled'
                ORDER BY st.last_name ASC, st.first_name ASC
            ");
            $stuStmt->execute(['section_id' => $sectionId]);
            $students = $stuStmt->fetchAll();
        }

    } catch (\PDOException $e) {
        error_log("Fetch class roster failed: " . $e->getMessage());
        $section = null;
        $students = [];
    }
}

// Fetch available sections for selector/switcher
$availableSections = [];
try {
    if ($userRole === 'teacher') {
        $secListStmt = $pdo->prepare("
            SELECT s.id, s.section_name, s.schedule, s.room,
                   COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                   COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                   (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id AND e.status = 'enrolled') AS enrolled_count
            FROM sections s
            LEFT JOIN courses c ON s.course_id = c.id
            WHERE s.teacher_id = :tid_a
               OR EXISTS (
                   SELECT 1 FROM section_subjects ss
                   WHERE ss.section_id = s.id AND ss.instructor_id = :tid_b
               )
            ORDER BY course_code ASC, s.section_name ASC
        ");
        $secListStmt->execute(['tid_a' => $userId, 'tid_b' => $userId]);
    } else {
        $secListStmt = $pdo->query("
            SELECT s.id, s.section_name, s.schedule, s.room,
                   COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                   COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                   (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id AND e.status = 'enrolled') AS enrolled_count
            FROM sections s
            LEFT JOIN courses c ON s.course_id = c.id
            ORDER BY course_code ASC, s.section_name ASC
        ");
    }
    $availableSections = $secListStmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch available sections for class list failed: " . $e->getMessage());
}

// Teacher attempted to access a section not assigned to them
if ($sectionId > 0 && !$section && $userRole === 'teacher') {
    $_SESSION['flash_error'] = "Access denied: This section is not assigned to you.";
    header("Location: my_classes");
    exit;
}

$page_title = "Class Roster";
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Class Roster</h3>
        <p class="text-muted small m-0">View enrolled students for an assigned class section.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if ($sectionId > 0 && !empty($availableSections)): ?>
            <form method="get" action="class_list" class="d-inline-flex align-items-center gap-2">
                <label for="switchSection" class="visually-hidden">Switch Section</label>
                <select name="section_id" id="switchSection" class="form-select form-select-sm" onchange="this.form.submit()" style="max-width: 260px;">
                    <?php foreach ($availableSections as $secOpt): ?>
                        <option value="<?php echo (int)$secOpt['id']; ?>" <?php echo (int)$secOpt['id'] === $sectionId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($secOpt['course_code'] . ' (' . $secOpt['section_name'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <a href="my_classes" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-arrow-left"></i> Back to My Classes
        </a>
    </div>
</div>

<?php if ($sectionId === 0): ?>
    <div class="card card-premium shadow-sm">
        <div class="card-body card-body-premium py-5 px-3 px-md-5">
            <div class="text-center mx-auto" style="max-width: 580px;">
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-brand-primary rounded-circle" style="width: 64px; height: 64px; font-size: 1.75rem;">
                        <i class="bi bi-list-stars"></i>
                    </span>
                </div>
                <h4 class="text-darker fw-bold mb-2">Select a Class Section</h4>
                <p class="text-muted mb-4">Choose an assigned section from the list below to view its enrolled student roster and details.</p>
                
                <?php if (!empty($availableSections)): ?>
                    <form method="get" action="class_list" class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                        <label for="sectionSelector" class="visually-hidden">Choose Section</label>
                        <select name="section_id" id="sectionSelector" class="form-select form-select-lg" required style="font-size: 0.95rem;">
                            <option value="">-- Choose a Section --</option>
                            <?php foreach ($availableSections as $secOpt): ?>
                                <option value="<?php echo (int)$secOpt['id']; ?>">
                                    <?php echo htmlspecialchars($secOpt['course_code'] . ' (' . $secOpt['section_name'] . ') — ' . $secOpt['course_name'] . ' [' . (int)$secOpt['enrolled_count'] . ' Enrolled]'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-brand-primary btn-lg text-nowrap px-4" style="font-size: 0.95rem;">
                            <i class="bi bi-arrow-right-circle me-1"></i> View Roster
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info d-inline-flex align-items-center gap-2 text-start">
                        <i class="bi bi-info-circle fs-5"></i>
                        <div>No assigned sections found for your faculty account.</div>
                    </div>
                    <div class="mt-3">
                        <a href="my_classes" class="btn btn-outline-secondary btn-sm">Go to My Classes</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php elseif (!$section): ?>
    <div class="card card-premium shadow-sm">
        <div class="card-body card-body-premium text-center py-5">
            <i class="bi bi-exclamation-triangle fs-1 text-warning d-block mb-3 opacity-75"></i>
            <h5 class="text-darker">Section Not Found</h5>
            <p class="text-muted mb-0">No section exists for ID #<?php echo $sectionId; ?>.</p>
        </div>
    </div>

<?php else: ?>
    <!-- Section Summary -->
    <div class="card card-premium shadow-sm mb-4 border-start border-4" style="border-color: var(--brand-primary) !important;">
        <div class="card-body card-body-premium">
            <div class="row g-3 align-items-center">
                <div class="col-12 col-md-8">
                    <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">Course</div>
                    <h5 class="m-0 fw-bold text-darker">
                        <?php echo htmlspecialchars($section['course_code'] . ' — ' . $section['course_name']); ?>
                    </h5>
                    <div class="text-muted small mt-1">
                        <i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($section['schedule']); ?>
                        &middot;
                        <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($section['room'] ?: 'TBA'); ?>
                        &middot;
                        <?php echo (int)$section['units']; ?> units
                    </div>
                </div>
                <div class="col-12 col-md-4 text-md-end">
                    <span class="badge bg-secondary-subtle text-brand-primary border border-secondary px-2.5 py-1 text-uppercase fw-semibold" style="font-size: 0.7rem;">
                        <?php echo count($students); ?> Enrolled
                    </span>
                    <?php if ($userRole === 'admin' && !empty($section['teacher_username'])): ?>
                        <div class="text-muted small mt-2">
                            Instructor: <strong><?php echo htmlspecialchars($section['teacher_username']); ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Roster Table -->
    <div class="card card-premium shadow-sm">
        <div class="card-header card-header-premium d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <h5 class="card-title m-0 fw-semibold text-navy-alt">
                <i class="bi bi-people me-1"></i> Enrolled Students
            </h5>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <label class="visually-hidden" for="classRosterSearch">Search students</label>
                <div class="input-group input-group-sm" style="min-width: 260px;">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="search" id="classRosterSearch" class="form-control" placeholder="Search student or email..." aria-label="Search students">
                </div>
            </div>
        </div>

        <div class="card-body card-body-premium p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle m-0" id="classRosterTable" style="width: 100%;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" tabulator-field="row_num" style="width: 70px;">#</th>
                            <th tabulator-field="student" style="width: 35%;">Student Name</th>
                            <th tabulator-field="email" style="width: 30%;">Email</th>
                            <th class="pe-4" tabulator-field="contact_number" style="width: 25%;">Contact Number</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($students)): ?>
                            <?php foreach ($students as $i => $st): ?>
                                <tr>
                                    <td class="ps-4 text-muted"><?php echo $i + 1; ?></td>
                                    <td>
                                        <div class="fw-semibold text-darker">
                                            <?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name']); ?>
                                        </div>
                                        <span class="text-muted small" style="font-size: 0.75rem;">
                                            @<?php echo htmlspecialchars($st['username']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="mailto:<?php echo htmlspecialchars($st['email']); ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($st['email']); ?>
                                        </a>
                                    </td>
                                    <td class="pe-4">
                                        <?php if ($st['contact_number']): ?>
                                            <span class="fw-medium"><?php echo htmlspecialchars($st['contact_number']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
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
<?php endif; ?>

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
    var classRosterTableEl = document.getElementById('classRosterTable');
    if (classRosterTableEl) {
        function stripHtml(html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            return tmp.textContent || tmp.innerText || '';
        }

        var classRosterTable = new Tabulator("#classRosterTable", {
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
            placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-person-x fs-1 d-block mb-2 text-muted-light'></i>No students are currently enrolled in this section.</div>",
            columns: [
                { title: "#", field: "row_num", width: 70, hozAlign: "center", sorter: "number", formatter: "html" },
                { title: "Student Name", field: "student", minWidth: 200, formatter: "html" },
                { title: "Email", field: "email", minWidth: 180, formatter: "html" },
                { title: "Contact Number", field: "contact_number", minWidth: 140, formatter: "html" }
            ]
        });

        var searchInput = document.getElementById('classRosterSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var term = this.value.trim().toLowerCase();
                if (!term) {
                    classRosterTable.clearFilter();
                } else {
                    classRosterTable.setFilter(function (data) {
                        return stripHtml(data.student).toLowerCase().includes(term) ||
                               stripHtml(data.email).toLowerCase().includes(term) ||
                               stripHtml(data.contact_number).toLowerCase().includes(term);
                    });
                }
            });
        }
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>

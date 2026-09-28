<?php
/**
 * My Assigned Classes
 * Read-only list of sections assigned to the logged-in instructor.
 */

require_once '../includes/auth_check.php';
checkRole(['teacher', 'admin']);

require_once '../config/database.php';

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'];

try {
    if ($userRole === 'teacher') {
        $stmt = $pdo->prepare("
            SELECT s.id, s.schedule, s.room, s.capacity,
                   COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                   COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                   COALESCE(c.units, 0) AS units,
                   (s.teacher_id = :teacher_id_a) AS is_lead,
                   (SELECT COUNT(*) FROM enrollments e
                    WHERE e.section_id = s.id AND e.status = 'enrolled') AS enrolled_count
            FROM sections s
            LEFT JOIN courses c ON s.course_id = c.id
            WHERE s.teacher_id = :teacher_id_b
               OR EXISTS (
                   SELECT 1 FROM section_subjects ss
                   WHERE ss.section_id = s.id AND ss.instructor_id = :teacher_id_c
               )
            ORDER BY course_code ASC, s.schedule ASC
        ");
        $stmt->execute([
            'teacher_id_a' => $userId,
            'teacher_id_b' => $userId,
            'teacher_id_c' => $userId
        ]);
    } else {
        // Admin oversight: view all sections with assigned instructor
        $stmt = $pdo->query("
            SELECT s.id, s.schedule, s.room, s.capacity,
                   COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                   COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                   COALESCE(c.units, 0) AS units,
                   1 AS is_lead,
                   u.username AS teacher_username,
                   (SELECT COUNT(*) FROM enrollments e
                    WHERE e.section_id = s.id AND e.status = 'enrolled') AS enrolled_count
            FROM sections s
            LEFT JOIN courses c ON s.course_id = c.id
            LEFT JOIN users u ON s.teacher_id = u.id
            ORDER BY course_code ASC, s.schedule ASC
        ");
    }
    $sections = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch teacher sections failed: " . $e->getMessage());
    $sections = [];
}

$page_title = "My Classes";
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">My Assigned Classes</h3>
        <p class="text-muted small m-0">
            <?php if ($userRole === 'teacher'): ?>
                View your scheduled sections, room assignments, and enrolled student counts.
            <?php else: ?>
                Overview of all class sections and their assigned instructors.
            <?php endif; ?>
        </p>
    </div>
    <span class="badge bg-secondary-subtle text-brand-primary border border-secondary px-2.5 py-1 text-uppercase fw-semibold" style="font-size: 0.7rem;">
        <?php echo count($sections); ?> Section<?php echo count($sections) !== 1 ? 's' : ''; ?>
    </span>
</div>

<div class="card card-premium shadow-sm">
    <div class="card-header card-header-premium d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <h5 class="card-title m-0 fw-semibold text-navy-alt">
            <i class="bi bi-door-open me-1"></i>
            <?php echo $userRole === 'teacher' ? 'Assigned Sections' : 'All Sections'; ?>
        </h5>
        <div class="d-flex align-items-center gap-2 ms-auto">
            <label class="visually-hidden" for="myClassesSearch">Search sections</label>
            <div class="input-group input-group-sm" style="min-width: 260px;">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="search" id="myClassesSearch" class="form-control" placeholder="Search sections or courses..." aria-label="Search sections">
            </div>
        </div>
    </div>

    <div class="card-body card-body-premium p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle m-0" id="myClassesTable" style="width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" tabulator-field="course" style="width: 30%;">Course</th>
                        <th tabulator-field="schedule" style="width: 25%;">Schedule</th>
                        <th tabulator-field="room" style="width: 15%;">Room</th>
                        <?php if ($userRole === 'admin'): ?>
                            <th tabulator-field="instructor" style="width: 15%;">Instructor</th>
                        <?php endif; ?>
                        <th tabulator-field="enrolled" style="width: 10%;">Enrolled</th>
                        <th class="pe-4 text-end" tabulator-field="actions" style="width: 15%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sections)): ?>
                        <?php foreach ($sections as $sec): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($sec['course_code']); ?></div>
                                    <div class="fw-semibold text-darker"><?php echo htmlspecialchars($sec['course_name']); ?></div>
                                    <span class="text-muted small d-inline-flex flex-wrap align-items-center gap-1" style="font-size: 0.75rem;">
                                        <span class="text-nowrap"><?php echo (int)$sec['units']; ?> units &middot; Section #<?php echo (int)$sec['id']; ?></span>
                                        <?php if ($userRole === 'teacher'): ?>
                                            <span class="text-nowrap d-inline-flex align-items-center gap-1">&middot; <span class="badge <?php echo !empty($sec['is_lead']) ? 'bg-primary-subtle text-primary border' : 'bg-info-subtle text-info border'; ?>" style="font-size: 0.65rem;">
                                                <?php echo !empty($sec['is_lead']) ? 'Section Lead' : 'Subject Instructor'; ?>
                                            </span></span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-semibold text-darker"><?php echo htmlspecialchars($sec['schedule']); ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-navy border px-2.5 py-1">
                                        <?php echo htmlspecialchars($sec['room'] ?: 'TBA'); ?>
                                    </span>
                                </td>
                                <?php if ($userRole === 'admin'): ?>
                                    <td>
                                        <span class="text-muted small">
                                            <?php echo htmlspecialchars($sec['teacher_username'] ?? 'Unassigned'); ?>
                                        </span>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <?php
                                    $enrolled = (int)$sec['enrolled_count'];
                                    $capacity = (int)$sec['capacity'];
                                    $fillClass = ($enrolled >= $capacity) ? 'text-danger' : 'text-success';
                                    ?>
                                    <span class="fw-bold <?php echo $fillClass; ?>"><?php echo $enrolled; ?></span>
                                    <span class="text-muted small">/ <?php echo $capacity; ?></span>
                                </td>
                                <td class="pe-4 text-end">
                                    <a href="class_list?section_id=<?php echo (int)$sec['id']; ?>"
                                       class="btn btn-sm btn-outline-secondary" title="View class roster">
                                        <i class="bi bi-people"></i> Roster
                                    </a>
                                    <?php if ($userRole === 'teacher'): ?>
                                        <?php if (!empty($sec['is_lead'])): ?>
                                            <a href="attendance?section_id=<?php echo (int)$sec['id']; ?>" class="btn btn-sm btn-outline-secondary">Attendance</a>
                                        <?php endif; ?>
                                        <a href="gradebook?section_id=<?php echo (int)$sec['id']; ?>" class="btn btn-sm btn-brand-primary">Grades</a>
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

    var myClassesTable = new Tabulator("#myClassesTable", {
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
        placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-door-closed fs-1 d-block mb-2 text-muted-light'></i>No class sections found.</div>",
        columns: [
            { title: "Course", field: "course", minWidth: 200, formatter: "html" },
            { title: "Schedule", field: "schedule", minWidth: 140, formatter: "html" },
            { title: "Room", field: "room", width: 120, formatter: "html" },
            <?php if ($userRole === 'admin'): ?>
                { title: "Instructor", field: "instructor", width: 140, formatter: "html" },
            <?php endif; ?>
            { 
                title: "Enrolled", 
                field: "enrolled", 
                width: 110, 
                hozAlign: "center", 
                formatter: "html",
                sorter: function(a, b) {
                    var aNum = parseInt(stripHtml(a).split('/')[0]) || 0;
                    var bNum = parseInt(stripHtml(b).split('/')[0]) || 0;
                    return aNum - bNum;
                }
            },
            { title: "Actions", field: "actions", width: 160, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
        ]
    });

    var searchInput = document.getElementById('myClassesSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var term = this.value.trim().toLowerCase();
            if (!term) {
                myClassesTable.clearFilter();
            } else {
                myClassesTable.setFilter(function (data) {
                    return stripHtml(data.course).toLowerCase().includes(term) ||
                           stripHtml(data.schedule).toLowerCase().includes(term) ||
                           stripHtml(data.room).toLowerCase().includes(term)
                           <?php if ($userRole === 'admin'): ?>
                               || stripHtml(data.instructor || '').toLowerCase().includes(term)
                           <?php endif; ?>
                           ;
                });
            }
        });
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>

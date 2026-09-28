<?php
require_once '../includes/auth_check.php';
checkRole(['teacher']);
require_once '../config/database.php';

$teacher = null;
$sections = [];
$error = null;
try {
    $stmt = $pdo->prepare("SELECT id, username, email, role, is_active, created_at FROM users WHERE id = :user_id AND role = 'teacher' LIMIT 1");
    $stmt->execute(['user_id' => (int)$_SESSION['user_id']]);
    $teacher = $stmt->fetch();
    if ($teacher) {
        $sectionStmt = $pdo->prepare("SELECT s.id, s.schedule, s.room, s.capacity, COALESCE(c.course_code, s.section_name, 'Section') AS course_code, COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name, (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id AND e.status IN ('approved','paid','enrolled')) AS enrolled_count FROM sections s LEFT JOIN courses c ON c.id = s.course_id WHERE s.teacher_id = :teacher_id ORDER BY course_code");

        $sectionStmt->execute(['teacher_id' => (int)$teacher['id']]);
        $sections = $sectionStmt->fetchAll();
    }
} catch (PDOException $e) {
    error_log('Teacher profile fetch failed: ' . $e->getMessage());
    $error = 'Profile information is temporarily unavailable.';
}

$page_title = 'My Profile';
require_once '../includes/header.php';
?>
<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h3 class="m-0 text-navy-alt">My Profile</h3><p class="text-muted small m-0">View your teacher account and assigned classes.</p></div><a href="dashboard" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Dashboard</a></div>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div><?php elseif (!$teacher): ?><div class="card card-premium shadow-sm"><div class="card-body card-body-premium text-center py-5"><i class="bi bi-person-x fs-1 text-muted d-block mb-3"></i><h5>Teacher account not found</h5></div></div><?php else: ?>
<div class="row g-4 mb-4">
    <div class="col-12 col-md-4"><div class="card card-premium card-stat stat-primary shadow-sm h-100"><div class="card-body card-body-premium d-flex align-items-center justify-content-between"><div><span class="text-muted small uppercase fw-bold">Username</span><h4 class="m-0 fw-bold text-navy-alt mt-1"><?php echo htmlspecialchars($teacher['username']); ?></h4></div><div class="stat-icon bg-primary-soft"><i class="bi bi-person-badge"></i></div></div></div></div>
    <div class="col-12 col-md-4"><div class="card card-premium card-stat stat-success shadow-sm h-100"><div class="card-body card-body-premium d-flex align-items-center justify-content-between"><div><span class="text-muted small uppercase fw-bold">Assigned Classes</span><h3 class="m-0 fw-bold text-success mt-1"><?php echo count($sections); ?></h3></div><div class="stat-icon bg-success-soft"><i class="bi bi-easel2"></i></div></div></div></div>
    <div class="col-12 col-md-4"><div class="card card-premium card-stat stat-warning shadow-sm h-100"><div class="card-body card-body-premium d-flex align-items-center justify-content-between"><div><span class="text-muted small uppercase fw-bold">Account Status</span><h5 class="m-0 fw-bold text-warning mt-1"><?php echo (int)$teacher['is_active'] === 1 ? 'Active' : 'Inactive'; ?></h5></div><div class="stat-icon bg-warning-soft"><i class="bi bi-shield-check"></i></div></div></div></div>
</div>
<div class="row g-4">
    <div class="col-12 col-lg-5"><div class="card card-premium shadow-sm h-100"><div class="card-header card-header-premium"><h5 class="m-0 fw-semibold text-navy-alt"><i class="bi bi-person-vcard me-2"></i>Account Information</h5></div><div class="card-body card-body-premium"><dl class="row mb-0"><dt class="col-sm-5 text-muted">Username</dt><dd class="col-sm-7"><?php echo htmlspecialchars($teacher['username']); ?></dd><dt class="col-sm-5 text-muted">Email</dt><dd class="col-sm-7 text-break"><?php echo htmlspecialchars($teacher['email']); ?></dd><dt class="col-sm-5 text-muted">Role</dt><dd class="col-sm-7"><span class="badge bg-primary-subtle text-primary">Teacher</span></dd><dt class="col-sm-5 text-muted">Created</dt><dd class="col-sm-7"><?php echo date('M j, Y', strtotime($teacher['created_at'])); ?></dd></dl></div></div></div>
    <div class="col-12 col-lg-7"><div class="card card-premium shadow-sm h-100"><div class="card-header card-header-premium"><h5 class="m-0 fw-semibold text-navy-alt"><i class="bi bi-journal-bookmark me-2"></i>Assigned Sections</h5></div><div class="card-body card-body-premium p-0"><?php if (!$sections): ?><div class="text-center py-5 px-3"><i class="bi bi-calendar-x fs-1 text-muted d-block mb-3"></i><h5>No assigned sections</h5><p class="text-muted mb-0">The registrar has not assigned a section to your account yet.</p></div><?php else: ?><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th class="ps-4">Course</th><th>Schedule</th><th>Room</th><th class="text-end pe-4">Class Size</th></tr></thead><tbody><?php foreach ($sections as $section): ?><tr><td class="ps-4"><strong><?php echo htmlspecialchars($section['course_code']); ?></strong><small class="d-block text-muted"><?php echo htmlspecialchars($section['course_name']); ?></small></td><td><?php echo htmlspecialchars($section['schedule']); ?></td><td><?php echo htmlspecialchars($section['room'] ?: 'TBA'); ?></td><td class="text-end pe-4"><?php echo (int)$section['enrolled_count']; ?> / <?php echo (int)$section['capacity']; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div></div>
</div>
<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>

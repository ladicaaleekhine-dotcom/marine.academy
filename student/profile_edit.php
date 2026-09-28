<?php
require_once '../includes/auth_check.php';
checkRole(['student']);
require_once '../config/database.php';

$profile = null;
try {
    $stmt = $pdo->prepare('SELECT s.*, u.username, u.email FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => (int)$_SESSION['user_id']]);
    $profile = $stmt->fetch();
} catch (PDOException $e) {
    error_log('Student editable profile fetch failed: ' . $e->getMessage());
}

$page_title = 'Edit Profile';
require_once '../includes/header.php';
?>
<div class="mb-4"><h3 class="m-0 text-navy-alt">Edit Profile</h3><p class="text-muted small m-0">Update contact and guardian information. Admission and academic records remain registrar-controlled.</p></div>
<?php if (!$profile): ?><div class="alert alert-warning">Student profile not found.</div><?php else: ?>
<form action="../actions/student_actions" method="POST" class="card card-premium shadow-sm needs-validation" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="update_profile">
    <div class="card-body card-body-premium"><div class="row g-3">
        <div class="col-md-6"><label class="form-label">Username</label><input class="form-control" value="<?php echo htmlspecialchars($profile['username']); ?>" disabled></div>
        <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" value="<?php echo htmlspecialchars($profile['email']); ?>" disabled></div>
        <div class="col-md-6"><label class="form-label">Contact Number</label><input class="form-control" name="contact_number" value="<?php echo htmlspecialchars($profile['contact_number'] ?? ''); ?>" pattern="09[0-9]{9}" maxlength="11" required></div>
        <div class="col-md-6"><label class="form-label">Street Address</label><input class="form-control" name="address_street" value="<?php echo htmlspecialchars($profile['address_street'] ?? ''); ?>"></div>
        <div class="col-md-6"><label class="form-label">Barangay</label><input class="form-control" name="address_barangay" value="<?php echo htmlspecialchars($profile['address_barangay'] ?? ''); ?>"></div>
        <div class="col-md-4"><label class="form-label">City / Municipality</label><input class="form-control" name="address_city" value="<?php echo htmlspecialchars($profile['address_city'] ?? ''); ?>" required></div>
        <div class="col-md-4"><label class="form-label">Province</label><input class="form-control" name="address_province" value="<?php echo htmlspecialchars($profile['address_province'] ?? ''); ?>" required></div>
        <div class="col-md-4"><label class="form-label">ZIP Code</label><input class="form-control" name="address_zip_code" value="<?php echo htmlspecialchars($profile['address_zip_code'] ?? ''); ?>" maxlength="10"></div>
        <div class="col-md-6"><label class="form-label">Guardian Name</label><input class="form-control" name="guardian_name" value="<?php echo htmlspecialchars($profile['guardian_name'] ?? ''); ?>" required></div>
        <div class="col-md-6"><label class="form-label">Guardian Relationship</label><input class="form-control" name="guardian_relationship" value="<?php echo htmlspecialchars($profile['guardian_relationship'] ?? ''); ?>" required></div>
        <div class="col-md-6"><label class="form-label">Guardian Contact</label><input class="form-control" name="guardian_contact_number" value="<?php echo htmlspecialchars($profile['guardian_contact_number'] ?? ''); ?>" pattern="09[0-9]{9}" maxlength="11"></div>
        <div class="col-md-6"><label class="form-label">Religion</label><input class="form-control" name="religion" value="<?php echo htmlspecialchars($profile['religion'] ?? ''); ?>"></div>
        <div class="col-12"><label class="form-label">Guardian Address</label><textarea class="form-control" name="guardian_address" rows="2" required><?php echo htmlspecialchars($profile['guardian_address'] ?? ''); ?></textarea></div>
    </div></div><div class="card-footer bg-white d-flex justify-content-between"><a href="my_profile" class="btn btn-outline-secondary">Cancel</a><button class="btn btn-brand-primary"><i class="bi bi-save me-1"></i>Save Changes</button></div>
</form>
<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>
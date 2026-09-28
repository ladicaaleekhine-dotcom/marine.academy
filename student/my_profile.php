<?php
require_once '../includes/auth_check.php';
checkRole(['student']);
require_once '../config/database.php';

$profile = null;
$error = null;
try {
    $stmt = $pdo->prepare("SELECT s.*, u.username, u.email, u.is_active FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = :user_id LIMIT 1");
    $stmt->execute(['user_id' => (int)$_SESSION['user_id']]);
    $profile = $stmt->fetch();
} catch (PDOException $e) {
    error_log('Student profile fetch failed: ' . $e->getMessage());
    $error = 'Profile information is temporarily unavailable.';
}

function profileValue(array $profile, string $key): string {
    $value = $profile[$key] ?? null;
    return htmlspecialchars(($value === null || $value === '') ? '—' : (string)$value, ENT_QUOTES, 'UTF-8');
}

$page_title = 'My Profile';
require_once '../includes/header.php';
?>
<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><h3 class="m-0 text-navy-alt">My Profile</h3><p class="text-muted small m-0">View the personal and academic information linked to your account.</p></div>
    <a href="profile_edit" class="btn btn-brand-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit Contact Details</a>
    <a href="dashboard" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Dashboard</a>
</div>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div>
<?php elseif (!$profile): ?>
    <div class="card card-premium shadow-sm"><div class="card-body card-body-premium text-center py-5"><i class="bi bi-person-x fs-1 text-muted d-block mb-3"></i><h5>Profile not found</h5><p class="text-muted mb-0">Please contact the registrar if your account is not linked to a student profile.</p></div></div>
<?php else: ?>
<div class="row g-4">
    <div class="col-12 col-xl-4"><div class="card card-premium shadow-sm h-100"><div class="card-body card-body-premium text-center">
        <div class="rounded-circle bg-primary-soft text-navy-alt d-inline-flex align-items-center justify-content-center mb-3" style="width:82px;height:82px;font-size:2rem"><i class="bi bi-person-fill"></i></div>
        <h4 class="text-navy-alt mb-1"><?php echo profileValue($profile, 'first_name') . ' ' . profileValue($profile, 'last_name'); ?></h4>
        <p class="text-muted mb-3">@<?php echo profileValue($profile, 'username'); ?></p>
        <div class="d-flex flex-column gap-2 align-items-center mb-3">
            <span class="badge bg-info-subtle text-info px-3 py-2"><?php echo htmlspecialchars('Admission: ' . ucwords(str_replace('_', ' ', $profile['admission_status'] ?? $profile['application_status'] ?? 'draft'))); ?></span>
            <span class="badge bg-success-subtle text-success px-3 py-2"><?php echo htmlspecialchars('Enrollment: ' . ucwords(str_replace('_', ' ', $profile['enrollment_status'] ?? 'pending'))); ?></span>
        </div>
        <hr><div class="text-start small"><div class="d-flex justify-content-between mb-2"><span class="text-muted">Student ID</span><strong>#<?php echo (int)$profile['id']; ?></strong></div><div class="d-flex justify-content-between"><span class="text-muted">Account</span><strong><?php echo (int)$profile['is_active'] === 1 ? 'Active' : 'Inactive'; ?></strong></div></div>
    </div></div></div>
    <div class="col-12 col-xl-8">
        <div class="card card-premium shadow-sm mb-4"><div class="card-header card-header-premium"><h5 class="m-0 fw-semibold text-navy-alt"><i class="bi bi-person-vcard me-2"></i>Personal Information</h5></div><div class="card-body card-body-premium"><div class="row g-3">
            <div class="col-md-4"><span class="text-muted small d-block">First Name</span><strong><?php echo profileValue($profile, 'first_name'); ?></strong></div><div class="col-md-4"><span class="text-muted small d-block">Middle Name</span><strong><?php echo profileValue($profile, 'middle_name'); ?></strong></div><div class="col-md-4"><span class="text-muted small d-block">Last Name</span><strong><?php echo profileValue($profile, 'last_name'); ?></strong></div><div class="col-md-4"><span class="text-muted small d-block">Birthdate</span><strong><?php echo profileValue($profile, 'birthdate'); ?></strong></div><div class="col-md-4"><span class="text-muted small d-block">Gender</span><strong><?php echo profileValue($profile, 'gender'); ?></strong></div><div class="col-md-4"><span class="text-muted small d-block">Civil Status</span><strong><?php echo profileValue($profile, 'civil_status'); ?></strong></div><div class="col-md-6"><span class="text-muted small d-block">Nationality</span><strong><?php echo profileValue($profile, 'nationality'); ?></strong></div><div class="col-md-6"><span class="text-muted small d-block">Religion</span><strong><?php echo profileValue($profile, 'religion'); ?></strong></div>
        </div></div></div>
        <div class="card card-premium shadow-sm mb-4"><div class="card-header card-header-premium"><h5 class="m-0 fw-semibold text-navy-alt"><i class="bi bi-geo-alt me-2"></i>Contact and Address</h5></div><div class="card-body card-body-premium"><div class="row g-3"><div class="col-md-6"><span class="text-muted small d-block">Email</span><strong><?php echo profileValue($profile, 'email'); ?></strong></div><div class="col-md-6"><span class="text-muted small d-block">Contact Number</span><strong><?php echo profileValue($profile, 'contact_number'); ?></strong></div><?php $composedAddress = implode(', ', array_filter([$profile['address_street'] ?? '', $profile['address_barangay'] ?? '', $profile['address_city'] ?? '', $profile['address_province'] ?? '', $profile['address_zip_code'] ?? ''])); ?><div class="col-12"><span class="text-muted small d-block">Address</span><strong><?php echo htmlspecialchars($composedAddress ?: '—', ENT_QUOTES, 'UTF-8'); ?></strong></div><div class="col-md-6"><span class="text-muted small d-block">Guardian</span><strong><?php echo profileValue($profile, 'guardian_name'); ?></strong></div><div class="col-md-6"><span class="text-muted small d-block">Guardian Contact</span><strong><?php echo profileValue($profile, 'guardian_contact_number'); ?></strong></div></div></div></div>
        <div class="card card-premium shadow-sm"><div class="card-header card-header-premium"><h5 class="m-0 fw-semibold text-navy-alt"><i class="bi bi-mortarboard me-2"></i>Academic Information</h5></div><div class="card-body card-body-premium"><div class="row g-3"><div class="col-md-6"><span class="text-muted small d-block">Program</span><strong><?php echo profileValue($profile, 'program_applying_for'); ?></strong></div><div class="col-md-3"><span class="text-muted small d-block">Applicant Type</span><strong><?php echo profileValue($profile, 'applicant_type'); ?></strong></div><div class="col-md-3"><span class="text-muted small d-block">Year Level</span><strong><?php echo profileValue($profile, 'year_level'); ?></strong></div><div class="col-md-6"><span class="text-muted small d-block">Senior High School</span><strong><?php echo profileValue($profile, 'shs_name'); ?></strong></div><div class="col-md-3"><span class="text-muted small d-block">Track/Strand</span><strong><?php echo profileValue($profile, 'shs_track_strand'); ?></strong></div><div class="col-md-3"><span class="text-muted small d-block">General Average</span><strong><?php echo $profile['general_average'] !== null ? number_format((float)$profile['general_average'], 2) : '—'; ?></strong></div></div></div></div>
    </div>
</div>
<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>

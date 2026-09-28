<?php
/**
 * Record Payment Portal
 * Search and select a student, then record a payment with a unique OR number.
 */

require_once '../includes/auth_check.php';
checkRole(['cashier', 'admin']);

require_once '../config/database.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$applicationStatus = isset($_GET['application_status']) ? trim($_GET['application_status']) : '';
$yearLevel = isset($_GET['year_level']) ? trim($_GET['year_level']) : '';
$programFilter = isset($_GET['program']) ? trim($_GET['program']) : '';
$paymentStatus = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : '';
$enrollmentStatus = isset($_GET['enrollment_status']) ? trim($_GET['enrollment_status']) : '';
$balanceFilter = isset($_GET['balance_filter']) ? trim($_GET['balance_filter']) : '';
$referenceNumberLookup = isset($_GET['reference_number']) ? trim((string)$_GET['reference_number']) : '';
$selectedStudentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$students = [];
$selectedStudent = null;
$paymentMethods = [];
$assessmentLookup = null;
$assessmentLookupError = null;
$assessmentItems = [];

try {
    $paymentMethods = $pdo->query("SELECT method_code, method_name, requires_reference, description FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, method_name")->fetchAll();
} catch (\PDOException $e) {
    error_log('Payment methods fetch failed: ' . $e->getMessage());
    $paymentMethods = [
        ['method_code' => 'cash', 'method_name' => 'Cash', 'requires_reference' => 0, 'description' => 'Cash payment'],
        ['method_code' => 'bank_transfer', 'method_name' => 'Bank Transfer', 'requires_reference' => 1, 'description' => 'Online or bank transfer'],
        ['method_code' => 'check', 'method_name' => 'Check', 'requires_reference' => 1, 'description' => 'Check payment'],
    ];
}

// Search and filter students by name, username, email, and status metadata.
if ($search !== '' || $applicationStatus !== '' || $yearLevel !== '' || $programFilter !== '' || $paymentStatus !== '' || $enrollmentStatus !== '' || $balanceFilter !== '') {
    try {
        $conditions = ['1=1'];
        $params = [];

        if ($search !== '') {
            $conditions[] = "(
                s.first_name LIKE :term1
                OR s.last_name LIKE :term2
                OR CONCAT(s.first_name, ' ', s.last_name) LIKE :term3
                OR u.username LIKE :term4
                OR u.email LIKE :term5
            )";
            $searchLike = '%' . $search . '%';
            $params['term1'] = $searchLike;
            $params['term2'] = $searchLike;
            $params['term3'] = $searchLike;
            $params['term4'] = $searchLike;
            $params['term5'] = $searchLike;
        }

        if ($applicationStatus !== '') {
            $conditions[] = 's.application_status = :application_status';
            $params['application_status'] = $applicationStatus;
        }

        if ($yearLevel !== '') {
            $conditions[] = 's.year_level = :year_level';
            $params['year_level'] = $yearLevel;
        }

        if ($programFilter !== '') {
            $conditions[] = 's.program_applying_for LIKE :program';
            $params['program'] = '%' . $programFilter . '%';
        }

        if ($paymentStatus !== '') {
            $conditions[] = 's.payment_status = :payment_status';
            $params['payment_status'] = $paymentStatus;
        }

        if ($enrollmentStatus !== '') {
            $conditions[] = 's.enrollment_status = :enrollment_status';
            $params['enrollment_status'] = $enrollmentStatus;
        }

        if ($balanceFilter === 'has_balance') {
            $conditions[] = 's.outstanding_balance > 0';
        } elseif ($balanceFilter === 'no_balance') {
            $conditions[] = 's.outstanding_balance <= 0';
        }

        $stmt = $pdo->prepare("
            SELECT s.id, s.first_name, s.last_name, s.application_status, s.enrollment_status, s.payment_status,
                   s.outstanding_balance, s.year_level, s.program_applying_for, s.contact_number,
                   u.username, u.email
            FROM students s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY s.last_name ASC, s.first_name ASC
            LIMIT 25
        ");

        $stmt->execute($params);
        $students = $stmt->fetchAll();
    } catch (\PDOException $e) {
        error_log("Student search failed: " . $e->getMessage());
        $students = [];
    }
}

// Load selected student details
if ($selectedStudentId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.first_name, s.last_name, s.application_status, s.enrollment_status, s.contact_number,
                   u.username, u.email
            FROM students s
            JOIN users u ON s.user_id = u.id
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $selectedStudentId]);
        $selectedStudent = $stmt->fetch();
    } catch (\PDOException $e) {
        error_log("Fetch selected student failed: " . $e->getMessage());
    }
}

if ($referenceNumberLookup !== '') {
    try {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.reference_number, a.total_amount, a.discount_amount, a.status, s.id AS student_id,
                    s.first_name, s.last_name, s.enrollment_status, s.program_applying_for, s.year_level,
                    t.school_year, t.semester, t.payment_requirement_percent
             FROM assessments a
             JOIN students s ON s.id = a.student_id
             JOIN academic_terms t ON t.id = a.academic_term_id
             WHERE a.reference_number = :reference_number
             LIMIT 1"
        );
        $stmt->execute(['reference_number' => $referenceNumberLookup]);
        $assessmentLookup = $stmt->fetch();

        if ($assessmentLookup) {
            $itemStmt = $pdo->prepare('SELECT description, quantity, unit_amount, amount FROM assessment_items WHERE assessment_id = :assessment_id ORDER BY id');
            $itemStmt->execute(['assessment_id' => (int)$assessmentLookup['id']]);
            $assessmentItems = $itemStmt->fetchAll();
            $allowedLookupStatuses = ['walk_in_ready', 'section_chosen', 'approved', 'paid', 'enrolled'];
            if (!in_array($assessmentLookup['enrollment_status'], $allowedLookupStatuses, true)) {
                $assessmentLookupError = 'This student is not yet at the payment stage (current status: ' . htmlspecialchars($assessmentLookup['enrollment_status']) . ').';
            }
            if (($assessmentLookup['status'] ?? 'open') === 'paid') {
                $assessmentLookupError = 'This assessment has already been marked paid and cannot be processed again.';
            }
            $selectedStudentId = (int)($assessmentLookup['student_id'] ?? 0);
            $selectedStudent = $selectedStudent ?? ['id' => $selectedStudentId, 'first_name' => $assessmentLookup['first_name'], 'last_name' => $assessmentLookup['last_name'], 'enrollment_status' => $assessmentLookup['enrollment_status']];
        } else {
            $assessmentLookupError = 'No assessment was found for that reference number.';
        }
    } catch (\PDOException $e) {
        error_log('Assessment lookup by reference failed: ' . $e->getMessage());
        $assessmentLookupError = 'Assessment lookup failed. Please try again.';
    }
}

$page_title = "Record Payment";
require_once '../includes/header.php';

function statusBadgeClass(string $status): string {
    switch ($status) {
        case 'paid':
        case 'enrolled':
            return 'bg-success-subtle text-success';
        case 'approved':
            return 'bg-warning-subtle text-warning';
        case 'pending':
            return 'bg-secondary-subtle text-muted';
        case 'rejected':
            return 'bg-danger-subtle text-danger';
        default:
            return 'bg-light text-dark';
    }
}
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Record Payment</h3>
        <p class="text-muted small m-0">Search for a student, select their record, and issue an official receipt (OR).</p>
    </div>
    <a href="payment_history" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
        <i class="bi bi-clock-history"></i> Payment History
    </a>
</div>

<div class="row g-4">
    <div class="col-12">
        <div class="card card-premium shadow-sm">
            <div class="card-header card-header-premium">
                <h5 class="card-title m-0 fw-semibold text-navy-alt">
                    <i class="bi bi-hash me-1"></i> Assessment Reference Lookup
                </h5>
            </div>
            <div class="card-body card-body-premium">
                <form method="GET" action="payments" class="row g-2 align-items-end">
                    <div class="col-md-10">
                        <label for="reference_number" class="form-label small mb-1">Assessment Reference</label>
                        <input type="text" name="reference_number" id="reference_number" class="form-control"
                               placeholder="e.g. AS-20260901-6B4B4154"
                               value="<?php echo htmlspecialchars($referenceNumberLookup); ?>" autocomplete="off">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-brand-primary">Lookup</button>
                    </div>
                </form>
                <?php if ($assessmentLookupError): ?>
                    <div class="alert alert-warning mt-3 mb-0"><?php echo htmlspecialchars($assessmentLookupError); ?></div>
                <?php elseif ($assessmentLookup): ?>
                    <div class="alert alert-info mt-3 mb-0">
                        <strong><?php echo htmlspecialchars($assessmentLookup['reference_number']); ?></strong> matched
                        <?php echo htmlspecialchars($assessmentLookup['first_name'] . ' ' . $assessmentLookup['last_name']); ?>
                        for <?php echo htmlspecialchars($assessmentLookup['school_year'] . ' / ' . ucfirst($assessmentLookup['semester'])); ?>.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Student Search Panel -->
    <div class="col-12 <?php echo $selectedStudent ? 'col-lg-5' : 'col-lg-12'; ?>">
        <div class="card card-premium shadow-sm">
            <div class="card-header card-header-premium">
                <h5 class="card-title m-0 fw-semibold text-navy-alt">
                    <i class="bi bi-search me-1"></i> Find Student
                </h5>
            </div>
            <div class="card-body card-body-premium">
                <form method="GET" action="payments" class="mb-3">
                    <?php if ($selectedStudentId): ?>
                        <input type="hidden" name="student_id" value="<?php echo $selectedStudentId; ?>">
                    <?php endif; ?>
                    <div class="input-group mb-3">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" id="search" class="form-control"
                               placeholder="Search by name, username, or email..."
                               value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                        <button type="submit" class="btn btn-brand-primary px-4">Search</button>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Application Status</label>
                            <select name="application_status" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="draft" <?php echo $applicationStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="pending" <?php echo $applicationStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="under_review" <?php echo $applicationStatus === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                                <option value="approved" <?php echo $applicationStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $applicationStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="needs_revision" <?php echo $applicationStatus === 'needs_revision' ? 'selected' : ''; ?>>Needs Revision</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small mb-1">Year Level</label>
                            <select name="year_level" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="1st Year" <?php echo $yearLevel === '1st Year' ? 'selected' : ''; ?>>1st Year</option>
                                <option value="2nd Year" <?php echo $yearLevel === '2nd Year' ? 'selected' : ''; ?>>2nd Year</option>
                                <option value="3rd Year" <?php echo $yearLevel === '3rd Year' ? 'selected' : ''; ?>>3rd Year</option>
                                <option value="4th Year" <?php echo $yearLevel === '4th Year' ? 'selected' : ''; ?>>4th Year</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small mb-1">Program</label>
                            <select name="program" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="BSMarE" <?php echo $programFilter === 'BSMarE' ? 'selected' : ''; ?>>BSMarE</option>
                                <option value="BSMT" <?php echo $programFilter === 'BSMT' ? 'selected' : ''; ?>>BSMT</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small mb-1">Payment Status</label>
                            <select name="payment_status" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="unpaid" <?php echo $paymentStatus === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                <option value="partially_paid" <?php echo $paymentStatus === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                                <option value="fully_paid" <?php echo $paymentStatus === 'fully_paid' ? 'selected' : ''; ?>>Fully Paid</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small mb-1">Enrollment Status</label>
                            <select name="enrollment_status" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="draft" <?php echo $enrollmentStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="pending" <?php echo $enrollmentStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="needs_revision" <?php echo $enrollmentStatus === 'needs_revision' ? 'selected' : ''; ?>>Needs Revision</option>
                                <option value="approved" <?php echo $enrollmentStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="paid" <?php echo $enrollmentStatus === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="enrolled" <?php echo $enrollmentStatus === 'enrolled' ? 'selected' : ''; ?>>Enrolled</option>
                                <option value="rejected" <?php echo $enrollmentStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small mb-1">Outstanding Balance</label>
                            <select name="balance_filter" class="form-select form-select-sm">
                                <option value="">All</option>
                                <option value="has_balance" <?php echo $balanceFilter === 'has_balance' ? 'selected' : ''; ?>>Has Balance</option>
                                <option value="no_balance" <?php echo $balanceFilter === 'no_balance' ? 'selected' : ''; ?>>No Balance</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-brand-primary btn-sm">Apply Filters</button>
                        <a href="payments" class="btn btn-outline-secondary btn-sm">Clear</a>
                    </div>
                </form>

                <?php if ($search !== '' || $applicationStatus !== '' || $yearLevel !== '' || $programFilter !== '' || $paymentStatus !== '' || $enrollmentStatus !== '' || $balanceFilter !== ''): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle m-0" id="studentResultsTable" style="width: 100%;">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4" tabulator-field="student">Student</th>
                                    <th tabulator-field="program_year" style="width: 160px;">Program / Year</th>
                                    <th tabulator-field="status" style="width: 150px;">Status</th>
                                    <th class="pe-4 text-end" tabulator-field="action" style="width: 110px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($students)): ?>
                                    <?php foreach ($students as $st): ?>
                                        <tr class="<?php echo ($selectedStudentId === (int)$st['id']) ? 'table-active' : ''; ?>">
                                            <td class="ps-4 student-name-cell">
                                                <div class="fw-semibold text-darker">
                                                    <?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name']); ?>
                                                </div>
                                                <span class="text-muted small">
                                                    <?php echo htmlspecialchars($st['username'] ?? ''); ?>
                                                    <?php if (!empty($st['email'])): ?>
                                                        &middot; <?php echo htmlspecialchars($st['email']); ?>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="small fw-medium text-darker"><?php echo htmlspecialchars($st['program_applying_for'] ?: 'N/A'); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($st['year_level'] ?: 'N/A'); ?></div>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo statusBadgeClass($st['enrollment_status']); ?> border px-2 py-1 text-uppercase" style="font-size: 0.65rem;">
                                                    <?php echo htmlspecialchars($st['enrollment_status']); ?>
                                                </span>
                                                <div class="small text-muted mt-1">
                                                    <?php echo htmlspecialchars($st['payment_status'] ?: 'unpaid'); ?>
                                                    <?php if ((float)($st['outstanding_balance'] ?? 0) > 0): ?>
                                                        &middot; ₱<?php echo number_format((float)$st['outstanding_balance'], 2); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="pe-4 text-end">
                                                <a href="payments?student_id=<?php echo (int)$st['id']; ?>&search=<?php echo urlencode($search); ?>&application_status=<?php echo urlencode($applicationStatus); ?>&year_level=<?php echo urlencode($yearLevel); ?>&program=<?php echo urlencode($programFilter); ?>&payment_status=<?php echo urlencode($paymentStatus); ?>&enrollment_status=<?php echo urlencode($enrollmentStatus); ?>&balance_filter=<?php echo urlencode($balanceFilter); ?>"
                                                   class="btn btn-sm <?php echo ($selectedStudentId === (int)$st['id']) ? 'btn-brand-primary' : 'btn-outline-secondary'; ?>">
                                                    <?php echo ($selectedStudentId === (int)$st['id']) ? 'Selected' : 'Select'; ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-search fs-1 d-block mb-2 opacity-50"></i>
                        <p class="mb-0">Use the search bar or filters above to find a student.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Payment Form Panel -->
    <?php if ($selectedStudent): ?>
    <div class="col-12 col-lg-7">
        <div class="card card-premium shadow-sm border-start border-4" style="border-color: var(--brand-primary) !important;">
            <div class="card-header card-header-premium d-flex justify-content-between align-items-center">
                <h5 class="card-title m-0 fw-semibold text-navy-alt">
                    <i class="bi bi-credit-card me-1"></i> Payment Details
                </h5>
                <span class="badge bg-light text-navy border px-2 py-1">
                    ID #<?php echo (int)$selectedStudent['id']; ?>
                </span>
            </div>
            <div class="card-body card-body-premium">
                <!-- Selected Student Summary -->
                <div class="rounded-3 p-3 mb-4" style="background: var(--surface-tint);">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <div class="text-muted small text-uppercase fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">Payor</div>
                            <h5 class="m-0 fw-bold text-darker">
                                <?php echo htmlspecialchars($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']); ?>
                            </h5>
                            <div class="text-muted small mt-1">
                                <?php echo htmlspecialchars($selectedStudent['email']); ?>
                                <?php if ($selectedStudent['contact_number']): ?>
                                    &middot; <?php echo htmlspecialchars($selectedStudent['contact_number']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="badge <?php echo statusBadgeClass($selectedStudent['enrollment_status']); ?> border px-2.5 py-1 text-uppercase fw-semibold" style="font-size: 0.7rem;">
                            <?php echo htmlspecialchars($selectedStudent['enrollment_status']); ?>
                        </span>
                    </div>
                    <div class="mt-3">
                        <a href="assessment?student_id=<?php echo (int)$selectedStudent['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-calculator me-1"></i>View or Generate Assessment</a>
                    </div>
                    <?php if ($selectedStudent['application_status'] === 'approved' && $selectedStudent['enrollment_status'] === 'approved'): ?>
                        <div class="alert alert-info border-0 small py-2 px-3 mt-3 mb-0 d-flex align-items-center gap-2">
                            <i class="bi bi-info-circle-fill"></i>
                            Recording payment will update this student's status to <strong>Paid</strong>.
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($referenceNumberLookup !== ''): ?>
                    <form action="../actions/payment_actions" method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                        <input type="hidden" name="action" value="cashier_process_assessment_payment">
                        <input type="hidden" name="reference_number" value="<?php echo htmlspecialchars($referenceNumberLookup); ?>">
                        <input type="hidden" name="student_id" value="<?php echo (int)$selectedStudent['id']; ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="ref_amount" class="form-label fw-medium">Assessment Amount (₱) <span class="text-danger">*</span></label>
                                <input type="number" name="amount" id="ref_amount" class="form-control" value="<?php echo number_format((float)($assessmentLookup['total_amount'] ?? 0), 2, '.', ''); ?>" readonly>
                            </div>

                            <div class="col-md-6">
                                <label for="ref_or_number" class="form-label fw-medium">Official Receipt (OR) No. <span class="text-danger">*</span></label>
                                <input type="text" name="or_number" id="ref_or_number" class="form-control text-uppercase" placeholder="e.g. OR-2025-0002" maxlength="50" required autocomplete="off">
                            </div>

                            <div class="col-md-6">
                                <label for="ref_payment_method" class="form-label fw-medium">Payment Method <span class="text-danger">*</span></label>
                                <select name="payment_method" id="ref_payment_method" class="form-select" required>
                                    <option value="">Select method</option>
                                    <?php foreach ($paymentMethods as $method): ?>
                                        <option value="<?php echo htmlspecialchars($method['method_code']); ?>"><?php echo htmlspecialchars($method['method_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="ref_payment_reference" class="form-label fw-medium">Reference / Transaction Code</label>
                                <input type="text" name="payment_reference" id="ref_payment_reference" class="form-control text-uppercase" maxlength="100" autocomplete="off">
                            </div>

                            <div class="col-md-6">
                                <label for="ref_bank_name" class="form-label fw-medium">Bank / E-wallet</label>
                                <input type="text" name="bank_name" id="ref_bank_name" class="form-control" maxlength="100" autocomplete="off">
                            </div>

                            <div class="col-md-6">
                                <label for="ref_check_number" class="form-label fw-medium">Check Number</label>
                                <input type="text" name="check_number" id="ref_check_number" class="form-control text-uppercase" maxlength="50" autocomplete="off">
                            </div>

                            <div class="col-12">
                                <label for="ref_notes" class="form-label fw-medium">Notes <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" name="notes" id="ref_notes" class="form-control" maxlength="255">
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4 pt-2 border-top">
                            <button type="submit" class="btn btn-brand-primary px-4 fw-semibold">
                                <i class="bi bi-check-circle me-1"></i> Process Assessment Payment
                            </button>
                            <a href="payments" class="btn btn-outline-secondary px-3">Cancel</a>
                        </div>
                    </form>
                <?php else: ?>
                    <form action="../actions/payment_actions" method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="student_id" value="<?php echo (int)$selectedStudent['id']; ?>">

                        <div class="row g-3">
                        <div class="col-md-6">
                            <label for="amount" class="form-label fw-medium">Amount (₱) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <?php
                                    $envMax = getenv('PAYMENT_MAX');
                                    $paymentMaxAttr = is_numeric($envMax) ? ' max="' . number_format((float)$envMax, 2, '.', '') . '"' : '';
                                ?>
                                <input type="number" name="amount" id="amount" class="form-control"
                                       min="0.01" step="0.01" placeholder="0.00" required<?php echo $paymentMaxAttr; ?>>
                                <div class="invalid-feedback">Please enter a valid payment amount.</div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="or_number" class="form-label fw-medium">Official Receipt (OR) No. <span class="text-danger">*</span></label>
                            <input type="text" name="or_number" id="or_number" class="form-control text-uppercase"
                                   placeholder="e.g. OR-2025-0002" maxlength="50" required autocomplete="off">
                            <div class="invalid-feedback">OR number is required and must be unique.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="payment_method" class="form-label fw-medium">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" id="payment_method" class="form-select" required>
                                <option value="">Select method</option>
                                <?php foreach ($paymentMethods as $method): ?>
                                    <option value="<?php echo htmlspecialchars($method['method_code']); ?>"><?php echo htmlspecialchars($method['method_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="payment_reference" class="form-label fw-medium">Reference / Transaction Code</label>
                            <input type="text" name="payment_reference" id="payment_reference" class="form-control text-uppercase"
                                   placeholder="e.g. GCASH-12345, REF-2025-001" maxlength="100" autocomplete="off">
                        </div>

                        <div class="col-md-6">
                            <label for="bank_name" class="form-label fw-medium">Bank / E-wallet</label>
                            <input type="text" name="bank_name" id="bank_name" class="form-control"
                                   placeholder="BDO, BPI, GCash, Maya" maxlength="100" autocomplete="off">
                        </div>

                        <div class="col-md-6">
                            <label for="check_number" class="form-label fw-medium">Check Number</label>
                            <input type="text" name="check_number" id="check_number" class="form-control text-uppercase"
                                   placeholder="If payment by check" maxlength="50" autocomplete="off">
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label fw-medium">Notes <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="notes" id="notes" class="form-control"
                                   placeholder="e.g. Tuition down payment, miscellaneous fees..." maxlength="255">
                        </div>
                    </div>

                        <div class="d-flex gap-2 mt-4 pt-2 border-top">
                            <button type="submit" class="btn btn-brand-primary px-4 fw-semibold">
                                <i class="bi bi-check-circle me-1"></i> Record Payment
                            </button>
                            <a href="payments" class="btn btn-outline-secondary px-3">Cancel</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
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
document.addEventListener('DOMContentLoaded', function() {
    var studentResultsTableEl = document.getElementById('studentResultsTable');
    if (studentResultsTableEl) {
        var studentResultsTable = new Tabulator("#studentResultsTable", {
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
            placeholder: "<div class='text-center py-4 text-muted'><i class='bi bi-person-x fs-4 d-block mb-2 text-muted-light'></i>No students found for the current search and filters.</div>",
            columns: [
                { title: "Student", field: "student", minWidth: 160, formatter: "html" },
                { title: "Program / Year", field: "program_year", width: 160, formatter: "html" },
                { title: "Status", field: "status", width: 150, formatter: "html" },
                { title: "Action", field: "action", width: 110, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
            ]
        });
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>

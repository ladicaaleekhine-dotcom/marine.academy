<?php
require_once '../includes/auth_check.php';
checkRole(['student']);

require_once '../config/database.php';
require_once '../includes/academic_terms.php';
require_once '../includes/assessments.php';

$userId = (int)$_SESSION['user_id'];
$student = null;
$assessment = null;
$assessmentItems = [];
$paymentMethods = getAllowedPaymentMethods();
$error = null;

try {
    $studentStmt = $pdo->prepare("SELECT * FROM students WHERE user_id = :user_id LIMIT 1");
    $studentStmt->execute(['user_id' => $userId]);
    $student = $studentStmt->fetch();

    if ($student) {
        $_SESSION['student_id'] = (int)$student['id'];
        $termId = (int)($student['academic_term_id'] ?? 0);
        if ($termId <= 0) {
            $activeTerm = getActiveAcademicTerm($pdo);
            $termId = $activeTerm ? (int)$activeTerm['id'] : 0;
        }

        if ($termId > 0) {
            $assessmentId = getOrCreateAssessment($pdo, (int)$student['id'], $termId);
            $assessment = getAssessmentTotals($pdo, $assessmentId);
            $assessment['id'] = $assessmentId;
            $itemStmt = $pdo->prepare('SELECT description, quantity, unit_amount, amount FROM assessment_items WHERE assessment_id = :id ORDER BY id');
            $itemStmt->execute(['id' => $assessmentId]);
            $assessmentItems = $itemStmt->fetchAll();
        }
    }
} catch (Throwable $e) {
    error_log('Student payment page failed: ' . $e->getMessage());
    $error = 'Payment information is temporarily unavailable.';
}

$programLabel = !empty($student['program_applying_for']) ? $student['program_applying_for'] : (!empty($student['program_code']) ? $student['program_code'] : 'BSMT');
$rawYearLevel = trim((string)($student['year_level'] ?? ''));
$yearLevel = ($rawYearLevel !== '' && strtolower($rawYearLevel) !== 'year level') ? $rawYearLevel : '';
$grossAssessment = (float)($assessment['total_amount'] ?? 0) + (float)($assessment['discount_amount'] ?? 0);
$minimumDownpayment = 0.0;
if (!empty($student['academic_term_id'])) {
    $termStmt = $pdo->prepare('SELECT downpayment_percentage, minimum_downpayment FROM academic_terms WHERE id = :id LIMIT 1');
    $termStmt->execute(['id' => (int)$student['academic_term_id']]);
    $term = $termStmt->fetch();
    if ($term) {
        $minimumDownpayment = max((float)($term['minimum_downpayment'] ?? 0.00), ((float)($term['downpayment_percentage'] ?? 30.00) / 100) * (float)($assessment['total_amount'] ?? 0.00));
    }
}

$page_title = 'Payment Center';
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Payment Center</h3>
        <p class="text-muted small m-0">Review your current assessment, remaining balance, and payment method options.</p>
    </div>
    <a href="dashboard" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php elseif (!$student): ?>
    <div class="alert alert-warning">Your student profile was not found. Please contact the registrar.</div>
<?php else: ?>
    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <div class="card card-premium shadow-sm">
                <div class="card-header card-header-premium">
                    <h5 class="m-0 fw-semibold text-navy-alt"><i class="bi bi-calculator me-1"></i> Assessment Breakdown</h5>
                </div>
                <div class="card-body card-body-premium p-0">
                    <?php if ($assessment): ?>
                        <div class="p-3 border-bottom bg-light-subtle">
                            <div class="fw-semibold text-darker"><?php echo htmlspecialchars($programLabel); ?><?php if ($yearLevel !== ''): ?> &mdash; <?php echo htmlspecialchars($yearLevel); ?><?php endif; ?></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-borderless align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Fee Component</th>
                                        <th class="pe-4 text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assessmentItems as $item): ?>
                                        <tr>
                                            <td class="ps-4"><?php echo htmlspecialchars($item['description']); ?></td>
                                            <td class="pe-4 text-end fw-medium">₱<?php echo number_format((float)$item['amount'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="border-top">
                                        <td class="ps-4 fw-bold text-darker">Gross Assessment</td>
                                        <td class="pe-4 text-end fw-bold">₱<?php echo number_format($grossAssessment, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4 text-muted">Discount</td>
                                        <td class="pe-4 text-end fw-semibold text-success">-₱<?php echo number_format((float)($assessment['discount_amount'] ?? 0), 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4 fw-bold text-darker">Net Assessment</td>
                                        <td class="pe-4 text-end fw-bold text-darker">₱<?php echo number_format((float)($assessment['total_amount'] ?? 0), 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4 text-muted">Minimum Downpayment</td>
                                        <td class="pe-4 text-end fw-semibold text-primary">₱<?php echo number_format($minimumDownpayment, 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4 text-muted">Total Paid</td>
                                        <td class="pe-4 text-end fw-semibold text-success">₱<?php echo number_format((float)($assessment['validated_paid'] ?? 0), 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4 text-muted">Remaining Balance</td>
                                        <td class="pe-4 text-end fw-semibold text-danger">₱<?php echo number_format((float)($assessment['balance'] ?? 0), 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4 text-muted">Payment Status</td>
                                        <td class="pe-4 text-end"><span class="badge bg-<?php echo ((float)($assessment['validated_paid'] ?? 0) >= (float)($assessment['total_amount'] ?? 0)) ? 'success' : 'warning'; ?>-subtle text-<?php echo ((float)($assessment['validated_paid'] ?? 0) >= (float)($assessment['total_amount'] ?? 0)) ? 'success' : 'warning'; ?> px-2 py-1"><?php echo htmlspecialchars(getAssessmentPaymentStatus((float)($assessment['validated_paid'] ?? 0), (float)($assessment['total_amount'] ?? 0))); ?></span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="p-4 text-muted">No assessment summary is available yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card card-premium shadow-sm">
                <div class="card-header card-header-premium">
                    <h5 class="m-0 fw-semibold text-navy-alt"><i class="bi bi-wallet2 me-1"></i> Payment Input</h5>
                </div>
                <div class="card-body card-body-premium">
                    <form action="../actions/payment_actions" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                        <input type="hidden" name="action" value="record_student_payment">
                        <div class="mb-3">
                            <label class="form-label fw-medium">Amount to Pay</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium">Payment Method</label>
                            <select name="payment_method" class="form-select" required>
                                <option value="">Select method</option>
                                <?php foreach ($paymentMethods as $code => $label): ?>
                                    <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium">Reference / Detail</label>
                            <input type="text" name="payment_reference" class="form-control" maxlength="100" placeholder="Optional reference">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium">Notes</label>
                            <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Optional notes">
                        </div>
                        <button type="submit" class="btn btn-brand-primary w-100">Submit Payment</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>

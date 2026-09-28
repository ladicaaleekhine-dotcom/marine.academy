<?php
require_once '../includes/auth_check.php';
checkRole(['enrollee']);

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
        $enrollmentStatus  = $student['enrollment_status'] ?? '';
        // Payment is allowed only AFTER the registrar has validated the section (walk_in_ready or later)
        $registrarValidated = in_array($enrollmentStatus, ['walk_in_ready', 'paid', 'enrolled'], true);
        // Whether the student has picked a section at all (section_chosen or later)
        $hasSelectedSection = in_array($enrollmentStatus, ['section_chosen', 'walk_in_ready', 'paid', 'enrolled'], true);
        if (!$hasSelectedSection) {
            $secStmt = $pdo->prepare("SELECT 1 FROM enrollments WHERE student_id = :sid AND status != 'dropped' LIMIT 1");
            $secStmt->execute(['sid' => (int)$student['id']]);
            if ($secStmt->fetchColumn()) {
                $hasSelectedSection = true;
            }
        }

        $termId = (int)($student['academic_term_id'] ?? 0);
        if ($termId <= 0) {
            $activeTerm = getActiveAcademicTerm($pdo);
            $termId = $activeTerm ? (int)$activeTerm['id'] : 0;
        }

        // Only load the assessment if the registrar has already validated the walk-in
        if ($termId > 0 && $registrarValidated) {
            $assessmentId = getOrCreateAssessment($pdo, (int)$student['id'], $termId);
            $assessment = getAssessmentTotals($pdo, $assessmentId);
            $assessment['id'] = $assessmentId;
            $itemStmt = $pdo->prepare('SELECT description, quantity, unit_amount, amount FROM assessment_items WHERE assessment_id = :id ORDER BY id');
            $itemStmt->execute(['id' => $assessmentId]);
            $assessmentItems = $itemStmt->fetchAll();
        }
    }
} catch (Throwable $e) {
    error_log('Enrollee payment page failed: ' . $e->getMessage());
    $error = 'Payment information is temporarily unavailable.';
}

$programLabel = $student['program_applying_for'] ?? 'Program';
$yearLevel = $student['year_level'] ?? 'Year Level';
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

$page_title = 'Payment Details';
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Payment Details</h3>
        <p class="text-muted small m-0">Review your current assessment, remaining balance, and preferred payment method.</p>
    </div>
    <a href="dashboard" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php elseif (!$student): ?>
    <div class="alert alert-warning">Your applicant profile was not found. Please complete your application first.</div>
<?php elseif (!in_array($student['application_status'] ?? '', ['approved', 'eligible_to_enroll', 'paid', 'enrolled'], true)): ?>
    <div class="card card-premium shadow-sm p-4 p-md-5 text-center mb-4">
        <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle" style="width: 72px; height: 72px; background: rgba(217, 154, 29, 0.12); color: #d99a1d;">
            <i class="bi bi-hourglass-split display-6"></i>
        </div>
        <h4 class="fw-bold text-navy-alt mb-2">Application Pending Approval</h4>
        <p class="text-muted mx-auto mb-4" style="max-width: 600px;">
            Your admission credentials and applicant profile are currently under administrative review by the Registrar's Office. Tuition assessment and payment channels will be unlocked once your application status is approved.
        </p>

        <div class="row g-3 justify-content-center text-start mb-4 mx-auto" style="max-width: 760px;">
            <div class="col-12 col-md-6">
                <div class="p-3 rounded-3 border bg-light-subtle h-100 d-flex gap-3 align-items-start">
                    <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 28px; height: 28px; font-size: 0.8rem; font-weight: bold;">
                        <i class="bi bi-check-lg"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark small">1. Application & Documents</div>
                        <div class="text-muted" style="font-size: 0.78rem;">Profile and academic uploads submitted for review.</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="p-3 rounded-3 border border-warning-subtle bg-warning-subtle bg-opacity-25 h-100 d-flex gap-3 align-items-start">
                    <div class="rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center flex-shrink-0" style="width: 28px; height: 28px; font-size: 0.8rem; font-weight: bold;">
                        2
                    </div>
                    <div>
                        <div class="fw-bold text-dark small">2. Registrar Auditing (In Progress)</div>
                        <div class="text-muted" style="font-size: 0.78rem;">Admissions board audits your profile credentials.</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="p-3 rounded-3 border bg-light-subtle h-100 d-flex gap-3 align-items-start opacity-75">
                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 28px; height: 28px; font-size: 0.8rem; font-weight: bold;">
                        3
                    </div>
                    <div>
                        <div class="fw-bold text-muted small">3. Section Selection</div>
                        <div class="text-muted" style="font-size: 0.78rem;">Choose class schedules and confirm subjects.</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="p-3 rounded-3 border bg-light-subtle h-100 d-flex gap-3 align-items-start opacity-75">
                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 28px; height: 28px; font-size: 0.8rem; font-weight: bold;">
                        4
                    </div>
                    <div>
                        <div class="fw-bold text-muted small">4. Tuition Payment</div>
                        <div class="text-muted" style="font-size: 0.78rem;">Finalize assessment and submit enrollment fees.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <a href="dashboard" class="btn btn-primary px-4 py-2" style="background: var(--brand-primary); border-color: var(--brand-primary);">
                <i class="bi bi-speedometer2 me-1"></i> Return to Applicant Desk
            </a>
            <a href="apply" class="btn btn-outline-secondary px-4 py-2">
                <i class="bi bi-file-earmark-person me-1"></i> View Submitted Application
            </a>
        </div>
    </div>
<?php elseif (empty($hasSelectedSection)): ?>
    <div class="card card-premium shadow-sm p-4 p-md-5 text-center mb-4">
        <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle" style="width: 72px; height: 72px; background: rgba(14, 165, 233, 0.12); color: #0284c7;">
            <i class="bi bi-grid-3x3-gap display-6"></i>
        </div>
        <h4 class="fw-bold text-navy-alt mb-2">Section Selection Required</h4>
        <p class="text-muted mx-auto mb-4" style="max-width: 600px;">
            Your admission application has been approved! Before viewing your tuition assessment and fee breakdown, please select your preferred class section first.
        </p>
        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <a href="sections" class="btn btn-primary px-4 py-2" style="background: #00c98e; border-color: #00c98e; color: #fff; font-weight: 600;">
                <i class="bi bi-grid-3x3-gap-fill me-1"></i> Choose Section Now
            </a>
            <a href="dashboard" class="btn btn-outline-secondary px-4 py-2">
                <i class="bi bi-speedometer2 me-1"></i> Return to Dashboard
            </a>
        </div>
    </div>
<?php elseif (!$registrarValidated): ?>
    <!-- Section chosen but registrar has NOT yet finalized the walk-in — block payment -->
    <div class="card card-premium shadow-sm p-4 p-md-5 text-center mb-4" style="border: 2px solid #fde68a;">
        <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle" style="width: 72px; height: 72px; background: rgba(234, 179, 8, 0.12); color: #ca8a04;">
            <i class="bi bi-person-check display-6"></i>
        </div>
        <h4 class="fw-bold text-navy-alt mb-2">Awaiting Registrar Section Validation</h4>
        <p class="text-muted mx-auto mb-4" style="max-width: 640px;">
            You have already selected your section. However, <strong>payment is only available after the Registrar's Office validates and finalizes your subject list during your walk-in visit.</strong>
            Please proceed to the campus and submit your physical documents. The registrar will confirm your subjects and unlock the payment step.
        </p>
        <div class="alert alert-warning border-warning mx-auto text-start mb-4 rounded-3" style="max-width:560px; background:#fffbeb;">
            <div class="fw-bold mb-1"><i class="bi bi-info-circle me-2"></i>What happens next?</div>
            <ol class="mb-0 ps-3 text-muted small">
                <li>Walk in to the Registrar's Office with your physical document copies.</li>
                <li>The registrar reviews and finalizes your subject list.</li>
                <li>Once validated, the payment form will be unlocked here automatically.</li>
                <li>Proceed to the Cashier to settle your tuition fees.</li>
            </ol>
        </div>
        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <a href="my_section" class="btn btn-primary px-4 py-2" style="background: var(--brand-primary); border-color: var(--brand-primary);">
                <i class="bi bi-calendar2-check me-1"></i> View My Section
            </a>
            <a href="dashboard" class="btn btn-outline-secondary px-4 py-2">
                <i class="bi bi-speedometer2 me-1"></i> Return to Dashboard
            </a>
        </div>
    </div>
<?php else: ?>
    <?php if (!empty($assessment['is_finalized'])): ?>
        <div class="alert alert-success border-success d-flex align-items-center gap-3 mb-4 rounded-3 shadow-sm" style="background:#f0fdf4;">
            <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:38px;height:38px;">
                <i class="bi bi-patch-check-fill fs-5"></i>
            </div>
            <div class="flex-fill">
                <div class="fw-bold text-success" style="font-size:0.95rem;">Tuition Assessment Finalized by Registrar</div>
                <div class="text-muted small">
                    Your official enrollment tuition has been verified and confirmed at <strong>₱<?php echo number_format((float)$assessment['total_amount'], 2); ?></strong>.
                    <?php if (!empty($assessment['finalization_notes'])): ?>
                        <br><span class="text-dark fw-medium">Registrar Notes:</span> <?php echo htmlspecialchars($assessment['finalization_notes']); ?>
                    <?php endif; ?>
                </div>
            </div>
            <span class="badge bg-success text-white px-3 py-1.5 fw-bold">Official Finalized</span>
        </div>
    <?php elseif ($assessment): ?>
        <div class="alert alert-warning border-warning d-flex align-items-center gap-3 mb-4 rounded-3 shadow-sm" style="background:#fffbeb;">
            <div class="rounded-circle bg-warning text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:38px;height:38px;">
                <i class="bi bi-clock-history fs-5"></i>
            </div>
            <div class="flex-fill">
                <div class="fw-bold text-dark" style="font-size:0.95rem;">Tuition Assessment Pending Registrar Finalization</div>
                <div class="text-muted small">
                    The amounts shown below represent an initial automated estimate. Your final billable tuition will be verified and finalized by the Registrar during your walk-in submission.
                </div>
            </div>
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-3 py-1.5 fw-bold">Pending Review</span>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <div class="card card-premium shadow-sm">
                <div class="card-header card-header-premium">
                    <h5 class="m-0 fw-semibold text-navy-alt"><i class="bi bi-calculator me-1"></i> Fee Breakdown</h5>
                </div>
                <div class="card-body card-body-premium p-0">
                    <?php if ($assessment): ?>
                        <div class="p-3 border-bottom bg-light-subtle">
                            <div class="fw-semibold text-darker"><?php echo htmlspecialchars($programLabel); ?> — <?php echo htmlspecialchars($yearLevel); ?></div>
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
                                        <td class="ps-4 fw-bold text-darker">Calculated Fee Basis</td>
                                        <td class="pe-4 text-end fw-bold">₱<?php echo number_format((float)($assessment['calculated_amount'] ?? $grossAssessment), 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4 text-muted">Discount Applied</td>
                                        <td class="pe-4 text-end fw-semibold text-success">-₱<?php echo number_format((float)($assessment['discount_amount'] ?? 0), 2); ?></td>
                                    </tr>
                                    <tr class="table-light">
                                        <td class="ps-4 fw-bold text-darker">
                                            <?php echo !empty($assessment['is_finalized']) ? 'Finalized Tuition' : 'Net Assessment (Estimated)'; ?>
                                            <?php if (!empty($assessment['is_finalized'])): ?>
                                                <span class="badge bg-success text-white ms-1" style="font-size:0.65rem;">Finalized</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark ms-1" style="font-size:0.65rem;">Estimated</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="pe-4 text-end fw-bold text-darker fs-6">₱<?php echo number_format((float)($assessment['total_amount'] ?? 0), 2); ?></td>
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

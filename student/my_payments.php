<?php
/**
 * Student Payment History
 * Displays payment records belonging only to the authenticated student.
 */

require_once '../includes/auth_check.php';
checkRole(['student']);

require_once '../config/database.php';
require_once '../includes/assessments.php';

$studentId = isset($_SESSION['student_id']) ? (int)$_SESSION['student_id'] : 0;
$payments = [];
$totalPaid = 0.00;
$paymentError = null;
$assessment = null;

try {
    // Recover the profile ID if it is not available in the current session.
    if ($studentId <= 0) {
        $profileStmt = $pdo->prepare("SELECT id FROM students WHERE user_id = :user_id LIMIT 1");
        $profileStmt->execute(['user_id' => (int)$_SESSION['user_id']]);
        $studentId = (int)($profileStmt->fetchColumn() ?: 0);
        if ($studentId > 0) {
            $_SESSION['student_id'] = $studentId;
        }
    }

    if ($studentId > 0) {
        $assessmentStmt = $pdo->prepare("SELECT id FROM assessments WHERE student_id = :student_id AND academic_term_id = (SELECT academic_term_id FROM students WHERE id = :student_id_term LIMIT 1) AND status != 'cancelled' LIMIT 1");
        $assessmentStmt->execute(['student_id' => $studentId, 'student_id_term' => $studentId]);
        $assessmentId = (int)($assessmentStmt->fetchColumn() ?: 0);
        if ($assessmentId > 0) {
            $assessment = getAssessmentTotals($pdo, $assessmentId);
            $assessment['id'] = $assessmentId;
        }
            $paymentStmt = $pdo->prepare("\n            SELECT p.id, p.amount, p.or_number, p.payment_date, p.or_status, p.notes, p.created_at,\n                   u.username AS cashier_username\n            FROM payments p\n            LEFT JOIN users u ON u.id = p.cashier_id\n            WHERE p.student_id = :student_id\n            ORDER BY p.payment_date DESC, p.id DESC\n        ");
        $paymentStmt->execute(['student_id' => $studentId]);
        $payments = $paymentStmt->fetchAll();

        $validatedPaidTotal = 0.00;
        $pendingPaidTotal = 0.00;
        foreach ($payments as $payment) {
            if (($payment['or_status'] ?? '') === 'validated') {
                $validatedPaidTotal += (float)$payment['amount'];
            } elseif (($payment['or_status'] ?? '') === 'pending') {
                $pendingPaidTotal += (float)$payment['amount'];
            }
        }
        $totalPaid = $validatedPaidTotal;
    }
} catch (\PDOException $e) {
    error_log('Student payment history failed: ' . $e->getMessage());
    $paymentError = 'Payment history is temporarily unavailable. Please try again later.';
}

$page_title = 'My Payments';
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">My Payments</h3>
        <p class="text-muted small m-0">Review your recorded payment history and official receipt details.</p>
    </div>
    <a href="dashboard" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
    </a>
</div>

<?php if ($paymentError): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?php echo htmlspecialchars($paymentError); ?></span>
    </div>
<?php elseif ($studentId <= 0): ?>
    <div class="card card-premium shadow-sm">
        <div class="card-body card-body-premium text-center py-5">
            <i class="bi bi-person-x fs-1 text-muted d-block mb-3 opacity-50"></i>
            <h5 class="text-darker">Student profile not found</h5>
            <p class="text-muted mb-0">Your account is not currently linked to a student profile. Please contact the registrar.</p>
        </div>
    </div>
<?php else: ?>
    <div class="row g-4 mb-4">
        <div class="col-12 col-md-6">
            <div class="card card-premium card-stat stat-success shadow-sm h-100">
                <div class="card-body card-body-premium d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small uppercase fw-bold">Total Paid (Validated)</span>
                        <h3 class="m-0 fw-bold text-success mt-1">₱<?php echo number_format($totalPaid, 2); ?></h3>
                        <?php if ($pendingPaidTotal > 0): ?>
                            <small class="text-warning-emphasis d-block mt-1"><i class="bi bi-clock-history me-1"></i>₱<?php echo number_format($pendingPaidTotal, 2); ?> pending verification</small>
                        <?php endif; ?>
                    </div>
                    <div class="stat-icon bg-success-soft"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card card-premium card-stat stat-primary shadow-sm h-100">
                <div class="card-body card-body-premium d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small uppercase fw-bold">Payment Records</span>
                        <h3 class="m-0 fw-bold text-navy-alt mt-1"><?php echo count($payments); ?></h3>
                    </div>
                    <div class="stat-icon bg-primary-soft"><i class="bi bi-receipt"></i></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($assessment): ?>
    <div class="card card-premium shadow-sm mb-4"><div class="card-body card-body-premium d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div><span class="text-muted small text-uppercase fw-bold">Current Assessment Balance</span><h3 class="m-0 text-danger">₱<?php echo number_format((float)$assessment['balance'], 2); ?></h3><small class="text-muted">Assessed: ₱<?php echo number_format((float)$assessment['total_amount'], 2); ?> · Validated paid: ₱<?php echo number_format((float)$assessment['validated_paid'], 2); ?></small></div>
        <div class="d-flex gap-2"><a href="../cashier/assessment?id=<?php echo (int)$assessment['id']; ?>" class="btn btn-outline-primary"><i class="bi bi-file-earmark-text me-1"></i>View Assessment</a><a href="../cashier/assessment?id=<?php echo (int)$assessment['id']; ?>&download=1" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i>Download Slip</a></div>
    </div></div>
    <?php endif; ?>

    <div class="card card-premium shadow-sm">
        <div class="card-header card-header-premium d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title m-0 fw-semibold text-navy-alt">Payment History</h5>
            <div class="d-flex align-items-center gap-2">
                <div class="input-group input-group-sm" style="width: 220px;">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="myPaymentsSearch" class="form-control form-control-sm border-start-0 ps-0" placeholder="Filter payments...">
                </div>
                <span class="badge bg-primary-subtle text-primary">Student ID #<?php echo $studentId; ?></span>
            </div>
        </div>
        <div class="card-body card-body-premium p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="myPaymentsTable" style="width: 100%;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" tabulator-field="payment_date" style="width: 130px;">Date</th>
                            <th tabulator-field="or_number" style="width: 140px;">OR Number</th>
                            <th tabulator-field="particulars">Particulars</th>
                            <th tabulator-field="cashier" style="width: 130px;">Cashier</th>
                            <th tabulator-field="status" style="width: 120px;">OR Status</th>
                            <th class="text-end pe-4" tabulator-field="amount" style="width: 140px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($payments)): ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td class="ps-4 text-nowrap">
                                        <?php echo htmlspecialchars(date('M j, Y', strtotime($payment['payment_date']))); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border font-monospace fw-bold"><?php echo htmlspecialchars($payment['or_number']); ?></span>
                                    </td>
                                    <td>
                                        <?php echo $payment['notes'] !== null && $payment['notes'] !== ''
                                            ? htmlspecialchars($payment['notes'])
                                            : '<span class="text-muted">No notes</span>'; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['cashier_username'] ?? 'Cashier'); ?></td>
                                    <td><span class="badge bg-<?php echo ($payment['or_status'] ?? '') === 'validated' ? 'success' : 'warning'; ?>-subtle text-<?php echo ($payment['or_status'] ?? '') === 'validated' ? 'success' : 'warning'; ?>"><?php echo htmlspecialchars(ucfirst($payment['or_status'] ?? 'pending')); ?></span></td>
                                    <td class="text-end pe-4"><div class="fw-bold text-success">₱<?php echo number_format((float)$payment['amount'], 2); ?></div><a href="../cashier/receipts?id=<?php echo (int)$payment['id']; ?>" class="small">Receipt</a></td>
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
    var myPaymentsTableEl = document.getElementById('myPaymentsTable');
    if (myPaymentsTableEl) {
        function stripHtml(html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            return tmp.textContent || tmp.innerText || '';
        }

        var myPaymentsTable = new Tabulator("#myPaymentsTable", {
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
            placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-wallet2 fs-1 d-block mb-3 opacity-50'></i><h5 class='text-darker'>No payments recorded</h5><p class='text-muted mb-0'>Your payment records will appear here after the cashier records a payment.</p></div>",
            columns: [
                { title: "Date", field: "payment_date", width: 130, formatter: "html" },
                { title: "OR Number", field: "or_number", width: 140, formatter: "html" },
                { title: "Particulars", field: "particulars", minWidth: 160, formatter: "html" },
                { title: "Cashier", field: "cashier", width: 130, formatter: "html" },
                { title: "OR Status", field: "status", width: 120, formatter: "html" },
                { 
                    title: "Amount", 
                    field: "amount", 
                    width: 140, 
                    hozAlign: "right", 
                    formatter: "html",
                    sorter: function(a, b) {
                        var aNum = parseFloat(stripHtml(a).replace(/[^0-9.-]+/g, '')) || 0;
                        var bNum = parseFloat(stripHtml(b).replace(/[^0-9.-]+/g, '')) || 0;
                        return aNum - bNum;
                    }
                }
            ]
        });

        var searchInput = document.getElementById('myPaymentsSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var term = this.value.trim().toLowerCase();
                if (!term) {
                    myPaymentsTable.clearFilter();
                } else {
                    myPaymentsTable.setFilter(function (data) {
                        return stripHtml(data.payment_date).toLowerCase().includes(term) ||
                               stripHtml(data.or_number).toLowerCase().includes(term) ||
                               stripHtml(data.particulars).toLowerCase().includes(term) ||
                               stripHtml(data.cashier).toLowerCase().includes(term) ||
                               stripHtml(data.status).toLowerCase().includes(term);
                    });
                }
            });
        }
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>

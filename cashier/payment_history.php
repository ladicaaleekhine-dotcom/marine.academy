<?php
/**
 * Payment History
 * Searchable table of all recorded payments, filterable by student name or OR number.
 */

require_once '../includes/auth_check.php';
checkRole(['cashier', 'admin']);

require_once '../config/database.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$query = "
    SELECT p.id, p.amount, p.payment_method, p.payment_reference, p.bank_name, p.check_number, p.or_number, p.payment_date, p.issue_date, p.or_status, p.notes, p.created_at,
           s.first_name, s.last_name,
           u.username AS cashier_username
    FROM payments p
    JOIN students s ON p.student_id = s.id
    LEFT JOIN users u ON p.cashier_id = u.id
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $query .= " AND (
        s.first_name LIKE :term1
        OR s.last_name LIKE :term2
        OR CONCAT(s.first_name, ' ', s.last_name) LIKE :term3
        OR p.or_number LIKE :term4
    )";
    $searchLike = '%' . $search . '%';
    $params['term1'] = $searchLike;
    $params['term2'] = $searchLike;
    $params['term3'] = $searchLike;
    $params['term4'] = $searchLike;
}

$query .= " ORDER BY p.payment_date DESC, p.id DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $payments = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch payment history failed: " . $e->getMessage());
    $payments = [];
}

$totalAmount = array_sum(array_column($payments, 'amount'));

$page_title = "Payment History";
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Payment History</h3>
        <p class="text-muted small m-0">Browse and search all recorded payments by student name or OR number.</p>
    </div>
    <a href="payments" class="btn btn-brand-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
        <i class="bi bi-plus-lg"></i> Record Payment
    </a>
</div>

<!-- Search Filter Panel -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-3.5 bg-white">
        <form method="GET" action="payment_history" class="row g-2 align-items-end">
            <div class="col-12 col-md-8 col-lg-6">
                <label for="search" class="form-label small fw-bold text-muted mb-1">Search Records</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" id="search" class="form-control"
                           placeholder="Filter student name, OR number, method..."
                           value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                </div>
            </div>
            <div class="col-12 col-md-4 col-lg-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-brand-primary flex-grow-1 py-1.5 fw-semibold">
                    <i class="bi bi-filter"></i> Search
                </button>
                <a href="payment_history" class="btn btn-sm btn-outline-secondary py-1.5 px-3">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Payments Table -->
<div class="card card-premium shadow-sm">
    <div class="card-header card-header-premium d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="card-title m-0 fw-semibold text-navy-alt">All Payments</h5>
        <div class="d-flex gap-2 align-items-center">
            <?php if ($search !== ''): ?>
                <span class="badge bg-light text-muted border px-2 py-1 small">
                    Filtered results
                </span>
            <?php endif; ?>
            <span class="badge bg-secondary-subtle text-brand-primary border border-secondary px-2.5 py-1 text-uppercase fw-semibold" style="font-size: 0.7rem;">
                <?php echo count($payments); ?> Record<?php echo count($payments) !== 1 ? 's' : ''; ?>
                <?php if (!empty($payments)): ?>
                    &middot; ₱<?php echo number_format($totalAmount, 2); ?>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <div class="card-body card-body-premium p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle m-0" id="paymentHistoryTable" style="width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" tabulator-field="payment_date" style="width: 140px;">Date</th>
                        <th tabulator-field="or_number" style="width: 140px;">OR Number</th>
                        <th tabulator-field="student">Student</th>
                        <th tabulator-field="amount" style="width: 130px;">Amount</th>
                        <th tabulator-field="method" style="width: 140px;">Method</th>
                        <th tabulator-field="or_status" style="width: 120px;">OR Status</th>
                        <th tabulator-field="cashier" style="width: 120px;">Cashier</th>
                        <th tabulator-field="notes">Notes</th>
                        <th class="pe-4 text-end" tabulator-field="actions" style="width: 110px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($payments)): ?>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-semibold text-darker"><?php echo date('M j, Y', strtotime($p['payment_date'])); ?></div>
                                    <span class="text-muted small" style="font-size: 0.75rem;">#<?php echo (int)$p['id']; ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-navy border px-2.5 py-1 font-monospace fw-bold">
                                        <?php echo htmlspecialchars($p['or_number']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-darker">
                                        <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-bold text-success">₱<?php echo number_format((float)$p['amount'], 2); ?></span>
                                </td>
                                <td>
                                    <span class="text-darker"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($p['payment_method'] ?? 'cash')))); ?></span>
                                    <?php if (!empty($p['payment_reference'])): ?>
                                        <small class="text-muted d-block font-monospace"><?php echo htmlspecialchars($p['payment_reference']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-status <?php echo $p['or_status'] === 'validated' ? 'status-approved' : ($p['or_status'] === 'voided' ? 'status-rejected' : 'status-pending'); ?>"><?php echo htmlspecialchars(ucfirst($p['or_status'])); ?></span>
                                </td>
                                <td>
                                    <span class="text-muted small"><?php echo !empty($p['cashier_username']) ? htmlspecialchars($p['cashier_username']) : '—'; ?></span>
                                </td>
                                <td>
                                    <span class="text-muted small">
                                        <?php echo $p['notes'] ? htmlspecialchars($p['notes']) : '—'; ?>
                                    </span>
                                </td>
                                <td class="pe-4 text-end">
                                     <?php if ($p['or_status'] === 'pending'): ?><form action="../actions/payment_actions" method="POST" class="d-inline"><input type="hidden" name="action" value="validate"><input type="hidden" name="payment_id" value="<?php echo (int)$p['id']; ?>"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>"><button class="btn btn-sm btn-outline-success" title="Validate OR" aria-label="Validate OR"><i class="bi bi-check-circle"></i></button></form><?php endif; ?>
                                    <a href="receipts?id=<?php echo (int)$p['id']; ?>"
                                       class="btn btn-sm btn-outline-secondary" title="View receipt" aria-label="View receipt">
                                        <i class="bi bi-printer"></i>
                                    </a>
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
document.addEventListener('DOMContentLoaded', function() {
    function stripHtml(html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        return tmp.textContent || tmp.innerText || '';
    }

    var paymentHistoryTable = new Tabulator("#paymentHistoryTable", {
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
        placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-receipt fs-1 d-block mb-2 text-muted-light'></i>No payment records found.</div>",
        columns: [
            { title: "Date", field: "payment_date", width: 140, formatter: "html" },
            { title: "OR Number", field: "or_number", width: 140, formatter: "html" },
            { title: "Student", field: "student", minWidth: 160, formatter: "html" },
            { 
                title: "Amount", 
                field: "amount", 
                width: 130, 
                formatter: "html",
                sorter: function(a, b) {
                    var aNum = parseFloat(stripHtml(a).replace(/[^0-9.-]+/g, '')) || 0;
                    var bNum = parseFloat(stripHtml(b).replace(/[^0-9.-]+/g, '')) || 0;
                    return aNum - bNum;
                }
            },
            { title: "Method", field: "method", width: 140, formatter: "html" },
            { title: "OR Status", field: "or_status", width: 120, formatter: "html" },
            { title: "Cashier", field: "cashier", width: 120, formatter: "html" },
            { title: "Notes", field: "notes", minWidth: 120, formatter: "html" },
            { title: "Actions", field: "actions", width: 110, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
        ]
    });

    var searchInput = document.getElementById('search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var term = this.value.trim().toLowerCase();
            if (!term) {
                paymentHistoryTable.clearFilter();
            } else {
                paymentHistoryTable.setFilter(function(data) {
                    return stripHtml(data.student).toLowerCase().includes(term) ||
                           stripHtml(data.or_number).toLowerCase().includes(term) ||
                           stripHtml(data.method).toLowerCase().includes(term) ||
                           stripHtml(data.or_status).toLowerCase().includes(term) ||
                           stripHtml(data.notes).toLowerCase().includes(term);
                });
            }
        });
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>


<?php
/**
 * Cashier - Enrollment Queue & Progress Monitor
 * Shows every enrollee with enrollment stage, section, subjects, tuition, and payment status.
 */
require_once '../includes/auth_check.php';
checkRole(['cashier', 'admin']);
require_once '../config/database.php';
require_once '../includes/assessments.php';

$filterStatus  = isset($_GET['status'])  ? trim($_GET['status'])  : '';
$filterPayment = isset($_GET['payment']) ? trim($_GET['payment']) : '';
$filterProgram = isset($_GET['program']) ? trim($_GET['program']) : '';
$filterSearch  = isset($_GET['q'])       ? trim($_GET['q'])       : '';

function enrollStatusMeta(string $status): array {
    $map = [
        'draft'          => ['label' => 'Draft',           'badge' => 'secondary', 'icon' => 'pencil'],
        'pending'        => ['label' => 'App. Submitted',  'badge' => 'info',      'icon' => 'send'],
        'under_review'   => ['label' => 'Under Review',    'badge' => 'primary',   'icon' => 'eye'],
        'needs_revision' => ['label' => 'Needs Revision',  'badge' => 'warning',   'icon' => 'exclamation-triangle'],
        'approved'       => ['label' => 'Approved',        'badge' => 'success',   'icon' => 'check-circle'],
        'paid'           => ['label' => 'Admission Paid',  'badge' => 'success',   'icon' => 'cash-coin'],
        'section_chosen' => ['label' => 'Section Chosen',  'badge' => 'primary',   'icon' => 'bookmark-check'],
        'walk_in_ready'  => ['label' => 'Walk-in Ready',   'badge' => 'warning',   'icon' => 'person-walking'],
        'enrolled'       => ['label' => 'Enrolled',        'badge' => 'success',   'icon' => 'mortarboard'],
        'rejected'       => ['label' => 'Rejected',        'badge' => 'danger',    'icon' => 'x-circle'],
    ];
    return $map[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'badge' => 'secondary', 'icon' => 'question-circle'];
}

$conditions = ["s.enrollment_status NOT IN ('draft')"];
$params = [];

if ($filterStatus !== '') {
    $conditions[] = 's.enrollment_status = :status';
    $params['status'] = $filterStatus;
}
if ($filterPayment !== '') {
    $conditions[] = 's.payment_status = :pstatus';
    $params['pstatus'] = $filterPayment;
}
if ($filterProgram !== '') {
    $conditions[] = '(s.program_applying_for = :prog OR s.program_code = :prog2)';
    $params['prog']  = $filterProgram;
    $params['prog2'] = $filterProgram;
}
if ($filterSearch !== '') {
    $conditions[] = "(CONCAT(s.first_name,' ',s.last_name) LIKE :q1 OR u.username LIKE :q2 OR u.email LIKE :q3)";
    $like = '%' . $filterSearch . '%';
    $params['q1'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
}

$enrollees  = [];
$queryError = null;

try {
    $whereClause = implode(' AND ', $conditions);
    $sql = "
        SELECT
            s.id AS student_id, s.first_name, s.last_name,
            s.enrollment_status, s.application_status,
            s.payment_status, s.outstanding_balance,
            s.program_applying_for, s.program_code, s.year_level, s.academic_term_id,
            u.email, u.username,

            sc.section_name, sc.schedule, sc.room,

            a.id AS assessment_id, a.reference_number,
            a.total_amount AS assessed_total, a.finalized_amount,
            a.is_finalized AS tuition_finalized, a.status AS assessment_status,

            COALESCE(SUM(CASE WHEN p.or_status = 'validated' THEN pa.amount ELSE 0 END), 0) AS paid_amount,

            (SELECT COUNT(*) FROM student_selected_subjects sss
             WHERE sss.student_id = s.id AND sss.is_finalized = 1) AS finalized_subjects,
            (SELECT COALESCE(SUM(sss2.units),0) FROM student_selected_subjects sss2
             WHERE sss2.student_id = s.id AND sss2.is_finalized = 1) AS finalized_units

        FROM students s
        JOIN users u ON u.id = s.user_id

        LEFT JOIN (
            SELECT en.student_id, sec.section_name, sec.schedule, sec.room
            FROM enrollments en
            JOIN sections sec ON sec.id = en.section_id
            WHERE en.id = (
                SELECT MAX(en2.id) FROM enrollments en2 WHERE en2.student_id = en.student_id
            )
            UNION
            SELECT sr.student_id, sec2.section_name, sec2.schedule, sec2.room
            FROM section_reservations sr
            JOIN sections sec2 ON sec2.id = sr.section_id
            WHERE sr.status = 'active'
              AND sr.id = (
                SELECT MAX(sr2.id) FROM section_reservations sr2 
                WHERE sr2.student_id = sr.student_id AND sr2.status = 'active'
              )
              AND sr.student_id NOT IN (SELECT en3.student_id FROM enrollments en3)
        ) sc ON sc.student_id = s.id

        LEFT JOIN assessments a
            ON a.student_id = s.id
            AND a.academic_term_id = s.academic_term_id
            AND a.status != 'cancelled'

        LEFT JOIN assessment_items ai ON ai.assessment_id = a.id
        LEFT JOIN payment_allocations pa ON pa.assessment_item_id = ai.id
        LEFT JOIN payments p ON p.id = pa.payment_id

        WHERE {$whereClause}

        GROUP BY
            s.id, s.first_name, s.last_name, s.enrollment_status, s.application_status,
            s.payment_status, s.outstanding_balance, s.program_applying_for, s.program_code,
            s.year_level, s.academic_term_id, u.email, u.username,
            sc.section_name, sc.schedule, sc.room,
            a.id, a.reference_number, a.total_amount, a.finalized_amount,
            a.is_finalized, a.status

        ORDER BY
            FIELD(s.enrollment_status,'walk_in_ready','section_chosen','approved','paid','enrolled',
                  'pending','under_review','needs_revision','rejected','draft'),
            s.last_name ASC, s.first_name ASC
        LIMIT 200
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $enrollees = $stmt->fetchAll();
} catch (Throwable $ex) {
    error_log('Cashier enrollment queue: ' . $ex->getMessage());
    $queryError = 'Unable to load enrollment queue: ' . $ex->getMessage();
}

// Summary Counts
$walkInCount  = 0; $enrolledCount = 0; $approvedCount = 0; $admPaidCount = 0;
try {
    $cntRows = $pdo->query("
        SELECT enrollment_status, COUNT(*) AS cnt FROM students
        WHERE enrollment_status NOT IN ('draft')
        GROUP BY enrollment_status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    $walkInCount   = (int)($cntRows['walk_in_ready'] ?? 0);
    $enrolledCount = (int)($cntRows['enrolled']      ?? 0);
    $approvedCount = (int)($cntRows['approved']      ?? 0);
    $admPaidCount  = (int)($cntRows['paid']           ?? 0);
} catch (Throwable $ex) {}

$page_title = 'Enrollment Queue';
require_once '../includes/header.php';
?>
<style>
.eq-stat-pill{border-radius:10px;padding:.55rem 1rem;text-align:center;background:#fff;border:1px solid #e8edf4;min-width:110px;}
.eq-stat-pill .pv{font-size:1.4rem;font-weight:800;line-height:1;}
.eq-stat-pill .pl{font-size:.67rem;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-top:.12rem;}
.eq-filter-bar{background:#fff;border:1px solid #e8edf4;border-radius:12px;padding:14px 18px;margin-bottom:1.25rem;}
.eq-bar-wrap{background:#e2e8f0;border-radius:999px;height:5px;overflow:hidden;margin:.15rem auto 0;width:56px;}
.eq-bar-fill{height:100%;border-radius:999px;}
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <div class="page-eyebrow"><i class="bi bi-people me-1"></i> Cashier</div>
        <h1 class="m-0 fw-bold text-navy-alt" style="font-size:1.45rem;">Enrollment Queue &amp; Payment Progress</h1>
        <p class="text-muted small m-0 mt-1">Real-time view of every enrollee — section, subjects, finalized tuition, and payment status.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="dashboard" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
        <a href="payments" class="btn btn-brand-primary btn-sm"><i class="bi bi-credit-card me-1"></i> Record Payment</a>
    </div>
</div>

<!-- Summary Pills -->
<div class="d-flex gap-3 flex-wrap mb-4">
    <a href="enrollment_queue?status=walk_in_ready" class="eq-stat-pill text-decoration-none">
        <div class="pv text-warning"><?php echo $walkInCount; ?></div>
        <div class="pl"><i class="bi bi-person-walking me-1"></i>Walk-in Ready</div>
    </a>
    <a href="enrollment_queue?status=enrolled" class="eq-stat-pill text-decoration-none">
        <div class="pv text-success"><?php echo $enrolledCount; ?></div>
        <div class="pl"><i class="bi bi-mortarboard me-1"></i>Enrolled</div>
    </a>
    <a href="enrollment_queue?status=approved" class="eq-stat-pill text-decoration-none">
        <div class="pv text-primary"><?php echo $approvedCount; ?></div>
        <div class="pl"><i class="bi bi-check-circle me-1"></i>Approved</div>
    </a>
    <a href="enrollment_queue?status=paid" class="eq-stat-pill text-decoration-none">
        <div class="pv text-info"><?php echo $admPaidCount; ?></div>
        <div class="pl"><i class="bi bi-cash-coin me-1"></i>Adm. Paid</div>
    </a>
</div>

<!-- Filter Bar -->
<div class="eq-filter-bar">
    <form method="GET" action="enrollment_queue" class="row g-2 align-items-end">
        <div class="col-sm-4 col-md-3">
            <label class="form-label small fw-semibold mb-1">Search</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" name="q" class="form-control" placeholder="Name, username, email…"
                       value="<?php echo htmlspecialchars($filterSearch); ?>" autocomplete="off">
            </div>
        </div>
        <div class="col-sm-4 col-md-2">
            <label class="form-label small fw-semibold mb-1">Stage</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All Stages</option>
                <option value="approved"       <?php echo $filterStatus === 'approved'       ? 'selected' : ''; ?>>Approved</option>
                <option value="paid"           <?php echo $filterStatus === 'paid'           ? 'selected' : ''; ?>>Admission Paid</option>
                <option value="section_chosen" <?php echo $filterStatus === 'section_chosen' ? 'selected' : ''; ?>>Section Chosen</option>
                <option value="walk_in_ready"  <?php echo $filterStatus === 'walk_in_ready'  ? 'selected' : ''; ?>>Walk-in Ready</option>
                <option value="enrolled"       <?php echo $filterStatus === 'enrolled'       ? 'selected' : ''; ?>>Enrolled</option>
                <option value="pending"        <?php echo $filterStatus === 'pending'        ? 'selected' : ''; ?>>Pending</option>
                <option value="under_review"   <?php echo $filterStatus === 'under_review'   ? 'selected' : ''; ?>>Under Review</option>
                <option value="needs_revision" <?php echo $filterStatus === 'needs_revision' ? 'selected' : ''; ?>>Needs Revision</option>
            </select>
        </div>
        <div class="col-sm-4 col-md-2">
            <label class="form-label small fw-semibold mb-1">Payment</label>
            <select name="payment" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="unpaid"         <?php echo $filterPayment === 'unpaid'         ? 'selected' : ''; ?>>Unpaid</option>
                <option value="partially_paid" <?php echo $filterPayment === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                <option value="fully_paid"     <?php echo $filterPayment === 'fully_paid'     ? 'selected' : ''; ?>>Fully Paid</option>
            </select>
        </div>
        <div class="col-sm-4 col-md-2">
            <label class="form-label small fw-semibold mb-1">Program</label>
            <select name="program" class="form-select form-select-sm">
                <option value="">All Programs</option>
                <option value="BSMT"   <?php echo $filterProgram === 'BSMT'   ? 'selected' : ''; ?>>BSMT</option>
                <option value="BSMarE" <?php echo $filterProgram === 'BSMarE' ? 'selected' : ''; ?>>BSMarE</option>
            </select>
        </div>
        <div class="col-sm-4 col-md-3 d-flex gap-2 align-items-end">
            <button type="submit" class="btn btn-brand-primary btn-sm px-3">Filter</button>
            <a href="enrollment_queue" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
    </form>
</div>

<?php if ($queryError): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($queryError); ?></div>
<?php elseif (empty($enrollees)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-person-x fs-1 d-block mb-2 opacity-40"></i>
        <p class="fw-semibold mb-0">No enrollees match the current filters.</p>
        <p class="small"><a href="enrollment_queue">Clear all filters</a> to see all students.</p>
    </div>
<?php else: ?>
<div class="card shadow-sm border-0 mb-4" style="border-radius:14px;overflow:hidden;">
    <div class="card-header bg-light border-0 d-flex justify-content-between align-items-center py-3 px-4">
        <div class="fw-bold text-navy-alt">
            <i class="bi bi-list-check me-1"></i> Enrollment Queue
            <span class="badge bg-secondary-subtle text-secondary border ms-2"><?php echo count($enrollees); ?> students</span>
        </div>
        <div class="text-muted small">Walk-in Ready sorted first &middot; then by surname</div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0" style="font-size:.82rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="width:20%;">Student</th>
                        <th style="width:11%;">Program / Year</th>
                        <th style="width:14%;">Stage</th>
                        <th style="width:14%;">Section</th>
                        <th style="width:9%;" class="text-center">Subjects</th>
                        <th style="width:13%;" class="text-end">Tuition</th>
                        <th style="width:10%;" class="text-center">Payment</th>
                        <th style="width:9%;" class="pe-4 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($enrollees as $e): ?>
                    <?php
                        $meta       = enrollStatusMeta($e['enrollment_status']);
                        $isWalkIn   = ($e['enrollment_status'] === 'walk_in_ready');
                        $isEnrolled = ($e['enrollment_status'] === 'enrolled');
                        $paidAmt     = (float)($e['paid_amount']    ?? 0);
                        $totalAmt    = (float)($e['finalized_amount'] ?: ($e['assessed_total'] ?? 0));
                        $balance     = max(0, round($totalAmt - $paidAmt, 2));
                        $payPct      = ($totalAmt > 0) ? min(100, round($paidAmt / $totalAmt * 100)) : 0;
                        $payStatus   = $e['payment_status'] ?? 'unpaid';
                        $payBadge    = match($payStatus) {
                            'fully_paid'     => 'success',
                            'partially_paid' => 'warning',
                            default          => 'secondary',
                        };
                        $barColor   = $payPct >= 100 ? '#10b981' : ($payPct > 0 ? '#f59e0b' : '#e2e8f0');
                        $tuitionFin = !empty($e['tuition_finalized']);
                        $subjCount  = (int)($e['finalized_subjects'] ?? 0);
                        $subjUnits  = (float)($e['finalized_units']  ?? 0);
                        $studentName = htmlspecialchars(trim($e['first_name'] . ' ' . $e['last_name']));
                    ?>
                    <tr <?php if ($isWalkIn) echo 'style="background:rgba(251,191,36,.07);"'; elseif ($isEnrolled) echo 'style="background:rgba(16,185,129,.05);"'; ?>>
                        <td class="ps-4">
                            <div class="fw-semibold"><?php echo $studentName; ?></div>
                            <div class="text-muted" style="font-size:.69rem;"><?php echo htmlspecialchars($e['username'] ?? ''); ?></div>
                            <?php if (!empty($e['reference_number'])): ?>
                                <div style="font-size:.63rem;color:#94a3b8;font-family:monospace;"><?php echo htmlspecialchars($e['reference_number']); ?></div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="fw-semibold"><?php echo htmlspecialchars($e['program_applying_for'] ?: $e['program_code'] ?: '—'); ?></div>
                            <div class="text-muted" style="font-size:.69rem;"><?php echo htmlspecialchars($e['year_level'] ?: '—'); ?></div>
                        </td>

                        <td>
                            <span class="badge bg-<?php echo $meta['badge']; ?>-subtle text-<?php echo $meta['badge']; ?> border border-<?php echo $meta['badge']; ?>-subtle px-2 py-1 fw-semibold d-inline-flex align-items-center gap-1" style="font-size:.67rem;">
                                <i class="bi bi-<?php echo $meta['icon']; ?>"></i>
                                <?php echo htmlspecialchars($meta['label']); ?>
                            </span>
                            <?php if ($isWalkIn): ?>
                                <div style="font-size:.64rem;color:#d97706;font-weight:700;margin-top:.15rem;">
                                    <i class="bi bi-lightning-charge-fill me-1"></i>Ready for cashier
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($e['section_name'])): ?>
                                <div class="fw-semibold"><?php echo htmlspecialchars($e['section_name']); ?></div>
                                <?php if (!empty($e['schedule'])): ?>
                                    <div class="text-muted" style="font-size:.67rem;"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($e['schedule']); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-center">
                            <?php if ($subjCount > 0): ?>
                                <div class="fw-bold"><?php echo $subjCount; ?></div>
                                <div class="text-muted" style="font-size:.67rem;"><?php echo number_format($subjUnits, 1); ?> units</div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-end">
                            <?php if ($totalAmt > 0): ?>
                                <div class="fw-bold">&#8369;<?php echo number_format($totalAmt, 2); ?></div>
                                <?php if ($tuitionFin): ?>
                                    <span style="font-size:.61rem;" class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-patch-check-fill me-1"></i>Finalized</span>
                                <?php else: ?>
                                    <span style="font-size:.61rem;color:#d97706;font-weight:700;"><i class="bi bi-clock me-1"></i>Pending</span>
                                <?php endif; ?>
                                <?php if ($balance > 0): ?>
                                    <div class="text-danger" style="font-size:.67rem;">Bal: &#8369;<?php echo number_format($balance, 2); ?></div>
                                <?php else: ?>
                                    <div class="text-success" style="font-size:.67rem;"><i class="bi bi-check-lg"></i> Full</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:.72rem;">Not assessed</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-center">
                            <span class="badge bg-<?php echo $payBadge; ?>-subtle text-<?php echo $payBadge; ?> border border-<?php echo $payBadge; ?>-subtle px-2 py-1" style="font-size:.64rem;">
                                <?php echo ucfirst(str_replace('_', ' ', $payStatus)); ?>
                            </span>
                            <?php if ($totalAmt > 0): ?>
                                <div class="eq-bar-wrap">
                                    <div class="eq-bar-fill" style="width:<?php echo $payPct; ?>%;background:<?php echo $barColor; ?>;"></div>
                                </div>
                                <div style="font-size:.59rem;color:#64748b;"><?php echo $payPct; ?>%</div>
                            <?php endif; ?>
                        </td>

                        <td class="pe-4 text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <?php if ($e['assessment_id']): ?>
                                    <a href="payments?student_id=<?php echo (int)$e['student_id']; ?>"
                                       class="btn btn-sm <?php echo $isWalkIn ? 'btn-warning fw-bold' : 'btn-outline-secondary'; ?> px-2 py-1"
                                       title="Record Payment">
                                        <i class="bi bi-credit-card"></i><?php echo $isWalkIn ? ' Pay' : ''; ?>
                                    </a>
                                    <a href="assessment?student_id=<?php echo (int)$e['student_id']; ?>"
                                       class="btn btn-sm btn-outline-primary px-2 py-1" title="View Assessment">
                                        <i class="bi bi-receipt"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="payments?student_id=<?php echo (int)$e['student_id']; ?>"
                                       class="btn btn-sm btn-outline-secondary px-2 py-1" title="Generate Assessment">
                                        <i class="bi bi-calculator"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="d-flex gap-3 flex-wrap align-items-center small text-muted mb-4">
    <span class="fw-semibold">Legend:</span>
    <span><span class="badge bg-warning-subtle text-warning border border-warning-subtle">Walk-in Ready</span> — Registrar finalized subjects &amp; tuition; student physically present to pay</span>
    <span><span class="badge bg-success-subtle text-success border border-success-subtle">Enrolled</span> — Payment validated; enrollment complete</span>
    <span><strong><i class="bi bi-credit-card text-warning"></i> Pay</strong> — Highlighted button for cashier action</span>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>

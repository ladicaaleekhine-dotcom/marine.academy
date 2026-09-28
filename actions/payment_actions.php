<?php
/**
 * Payment Actions Processor
 * Records cashier payments, validates unique OR numbers, and updates student enrollment status.
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/academic_terms.php';
require_once __DIR__ . '/../includes/assessments.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/user_roles.php';

function processCashierAssessmentPayment(PDO $pdo, int $cashierId, string $referenceNumber, string $methodCode, string $orNumber, array $options = []): array
{
    $referenceNumber = trim($referenceNumber);
    $methodCode = strtolower(trim($methodCode));
    $orNumber = trim($orNumber);

    if ($cashierId <= 0) {
        throw new RuntimeException('A valid cashier account is required to process payment.');
    }

    if ($referenceNumber === '') {
        throw new RuntimeException('Assessment reference number is required.');
    }

    if ($orNumber === '') {
        throw new RuntimeException('Official receipt number is required.');
    }

    if (!in_array($methodCode, array_keys(getAllowedPaymentMethods()), true)) {
        throw new RuntimeException('Invalid payment method selected.');
    }

    $assessmentStmt = $pdo->prepare(
        "SELECT a.id AS assessment_id, a.reference_number, a.total_amount, a.discount_amount, a.status,
                s.id AS student_id, s.first_name, s.last_name, s.enrollment_status, s.payment_status,
                s.academic_term_id
         FROM assessments a
         JOIN students s ON s.id = a.student_id
         WHERE a.reference_number = :reference_number
         LIMIT 1"
    );
    $assessmentStmt->execute(['reference_number' => $referenceNumber]);
    $assessment = $assessmentStmt->fetch();

    if (!$assessment) {
        throw new RuntimeException('Assessment reference number not found.');
    }

    $payableStatuses = ['walk_in_ready', 'section_chosen', 'approved', 'paid', 'enrolled'];
    if (!in_array($assessment['enrollment_status'], $payableStatuses, true)) {
        throw new RuntimeException('This student is not yet at the payment stage (current status: ' . htmlspecialchars($assessment['enrollment_status']) . ').');
    }

    if (($assessment['status'] ?? 'open') === 'paid') {
        throw new RuntimeException('This assessment has already been marked paid and cannot be processed again.');
    }

    $assessmentId = (int)$assessment['assessment_id'];
    $totalDue = round((float)($assessment['total_amount'] ?? 0.00), 2);
    if ($totalDue <= 0) {
        throw new RuntimeException('This assessment has no amount due to process.');
    }

    $enteredAmount = isset($options['amount']) ? (float)$options['amount'] : $totalDue;
    if ($enteredAmount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }

    if (abs(round($enteredAmount, 2) - $totalDue) > 0.01) {
        throw new RuntimeException('Cashier payment must match the full assessment due amount of ₱' . number_format($totalDue, 2) . '.');
    }

    $existingOrCheck = $pdo->prepare('SELECT id FROM payments WHERE or_number = :or_number LIMIT 1');
    $existingOrCheck->execute(['or_number' => $orNumber]);
    if ($existingOrCheck->fetch()) {
        throw new RuntimeException("Official receipt number '{$orNumber}' is already registered.");
    }

    $studentId = (int)$assessment['student_id'];
    $paymentDate = date('Y-m-d');
    $notes = isset($options['notes']) ? trim((string)$options['notes']) : 'Cashier assessment payment';
    $paymentReference = isset($options['payment_reference']) ? trim((string)$options['payment_reference']) : null;
    $bankName = isset($options['bank_name']) ? trim((string)$options['bank_name']) : null;
    $checkNumber = isset($options['check_number']) ? trim((string)$options['check_number']) : null;

    $alreadyInTransaction = $pdo->inTransaction();
    if (!$alreadyInTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $insertStmt = $pdo->prepare(
            "INSERT INTO payments (student_id, academic_term_id, amount, payment_method, payment_reference, bank_name, check_number, or_number, payment_date, issue_date, cashier_id, notes, or_status, validated_at, validated_by)
             VALUES (:student_id, :academic_term_id, :amount, :payment_method, :payment_reference, :bank_name, :check_number, :or_number, :payment_date, :issue_date, :cashier_id, :notes, 'validated', CURRENT_TIMESTAMP, :validated_by)"
        );
        $insertStmt->execute([
            'student_id' => $studentId,
            'academic_term_id' => (int)$assessment['academic_term_id'],
            'amount' => round($enteredAmount, 2),
            'payment_method' => $methodCode,
            'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
            'bank_name' => $bankName !== '' ? $bankName : null,
            'check_number' => $checkNumber !== '' ? $checkNumber : null,
            'or_number' => $orNumber,
            'payment_date' => $paymentDate,
            'issue_date' => $paymentDate,
            'cashier_id' => $cashierId,
            'notes' => $notes !== '' ? $notes : 'Cashier assessment payment',
            'validated_by' => $cashierId,
        ]);
        $paymentId = (int)$pdo->lastInsertId();
        allocatePayment($pdo, $paymentId, $assessmentId, $enteredAmount);
        updateStudentPaymentSummary($pdo, $studentId);
        $assessmentUpdateStmt = $pdo->prepare('UPDATE assessments SET status = :status WHERE id = :id');
        $assessmentUpdateStmt->execute(['status' => 'paid', 'id' => $assessmentId]);
        
        $studentUpdateStmt = $pdo->prepare("
            UPDATE students 
            SET enrollment_status = CASE 
                    WHEN enrollment_status IN ('walk_in_ready', 'section_chosen', 'approved') THEN 'paid' 
                    ELSE enrollment_status 
                END,
                admission_status = 'admitted',
                payment_status = 'fully_paid', 
                outstanding_balance = 0 
            WHERE id = :id
        ");
        $studentUpdateStmt->execute(['id' => $studentId]);

        $studentUserStmt = $pdo->prepare('SELECT s.user_id, u.role FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = :id LIMIT 1');
        $studentUserStmt->execute(['id' => $studentId]);
        $studentUser = $studentUserStmt->fetch();
        $studentUserId = (int)($studentUser['user_id'] ?? 0);

        if ($studentUser && $studentUser['role'] === 'enrollee') {
            promoteUserToStudent($pdo, $studentUserId);
        }

        $registrarStmt = $pdo->query("SELECT id FROM users WHERE role IN ('registrar', 'admin') AND is_active = 1");
        foreach ($registrarStmt->fetchAll(PDO::FETCH_COLUMN) as $registrarUserId) {
            createNotification(
                $pdo,
                (int)$registrarUserId,
                'Payment received',
                'Student has completed payment and is ready for final registrar enrollment confirmation.',
                'info'
            );
        }

        if ($studentUserId > 0) {
            createNotification($pdo, $studentUserId, 'Payment processed', 'Your assessment ' . $referenceNumber . ' has been processed and marked as paid. Official receipt OR #' . $orNumber . ' generated.', 'success');
        }
        if (!$alreadyInTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$alreadyInTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'assessment_id' => $assessmentId,
        'student_id' => $studentId,
        'payment_id' => $paymentId,
        'reference_number' => $referenceNumber,
        'amount' => round($enteredAmount, 2),
        'or_number' => $orNumber,
        'method' => $methodCode,
    ];
}

if (PHP_SAPI !== 'cli') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $_SESSION['flash_error'] = "Invalid request method.";
        header("Location: ../cashier/payments");
        exit;
    }

    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_error'] = "Security validation failed. Please try again.";
        header("Location: ../cashier/payments");
        exit;
    }
}

$action = trim($_POST['action'] ?? '');

if ($action !== '') {
    switch ($action) {
    case 'record_student_payment':
        checkRole(['student', 'enrollee']);

        $userRole = $_SESSION['role'] ?? 'student';
        $paymentPage = ($userRole === 'enrollee') ? '../enrollee/payment' : '../student/payment';
        $dashboardPage = ($userRole === 'enrollee') ? '../enrollee/dashboard' : '../student/dashboard';

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;
        $methodCode = strtolower(trim((string)($_POST['payment_method'] ?? '')));
        $notes = trim((string)($_POST['notes'] ?? 'Student payment record'));
        $reference = trim((string)($_POST['payment_reference'] ?? ''));

        if ($userId <= 0) {
            $_SESSION['flash_error'] = 'Your session is no longer valid. Please log in again.';
            header('Location: ../auth/login');
            exit;
        }

        $studentStmt = $pdo->prepare("SELECT * FROM students WHERE user_id = :user_id LIMIT 1");
        $studentStmt->execute(['user_id' => $userId]);
        $student = $studentStmt->fetch();
        if (!$student) {
            $_SESSION['flash_error'] = 'Your student profile was not found.';
            header('Location: ' . $dashboardPage);
            exit;
        }

        // SECURITY GATE: Payment is only allowed AFTER the registrar has validated the
        // walk-in and finalized the subject list. 'section_chosen' means the enrollee
        // picked a section online but the registrar has NOT yet reviewed it.
        $payableEnrollmentStatuses = ['walk_in_ready', 'approved', 'paid', 'enrolled'];
        if (!in_array($student['enrollment_status'] ?? '', $payableEnrollmentStatuses, true)) {
            $_SESSION['flash_error'] = 'Payment is not yet available. Please visit the Registrar\'s Office to have your section and subjects validated before proceeding to payment.';
            header('Location: ' . $paymentPage);
            exit;
        }

        $termId = (int)($student['academic_term_id'] ?? 0);
        if ($termId <= 0) {
            $activeTerm = getActiveAcademicTerm($pdo);
            if (!$activeTerm) {
                $_SESSION['flash_error'] = 'There is no active academic term configured for payment.';
                header('Location: ' . $paymentPage);
                exit;
            }
            $termId = (int)$activeTerm['id'];
        }

        $validMethods = array_keys(getAllowedPaymentMethods());
        if (!in_array($methodCode, $validMethods, true)) {
            $_SESSION['flash_error'] = 'Invalid payment method selected.';
            header('Location: ' . $paymentPage);
            exit;
        }

        if ($amount <= 0) {
            $_SESSION['flash_error'] = 'Payment amount must be greater than zero.';
            header('Location: ' . $paymentPage);
            exit;
        }

        try {
            $assessmentId = getOrCreateAssessment($pdo, (int)$student['id'], $termId);
            $totals = getAssessmentTotals($pdo, $assessmentId);
            $netAssessment = (float)($totals['total_amount'] ?? 0.00);
            $paidAmount = (float)($totals['validated_paid'] ?? 0.00);
            $remainingBalance = max(0.00, $netAssessment - $paidAmount);

            $termStmt = $pdo->prepare('SELECT downpayment_percentage, minimum_downpayment FROM academic_terms WHERE id = :id LIMIT 1');
            $termStmt->execute(['id' => $termId]);
            $term = $termStmt->fetch();
            $minimumDownpayment = 0.00;
            if ($term) {
                $minimumDownpayment = max(
                    (float)($term['minimum_downpayment'] ?? 0.00),
                    ((float)($term['downpayment_percentage'] ?? 30.00) / 100) * $netAssessment
                );
            }

            if ($paidAmount <= 0 && $amount < $minimumDownpayment) {
                $_SESSION['flash_error'] = 'The payment amount is below the minimum downpayment of ₱' . number_format($minimumDownpayment, 2) . '.';
                header('Location: ' . $paymentPage);
                exit;
            }

            if ($amount > $remainingBalance) {
                $_SESSION['flash_error'] = 'Payment exceeds the remaining balance of ₱' . number_format($remainingBalance, 2) . '.';
                header('Location: ' . $paymentPage);
                exit;
            }

            $maxAttempts = 5;
            $attempt = 0;
            $inserted = false;

            while (!$inserted && $attempt < $maxAttempts) {
                $attempt++;
                $orNumber = 'ST-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));

                try {
                    $pdo->beginTransaction();
                    $insertStmt = $pdo->prepare(
                        "INSERT INTO payments (student_id, academic_term_id, amount, payment_method, payment_reference, bank_name, check_number, or_number, payment_date, issue_date, cashier_id, notes, or_status)
                         VALUES (:student_id, :academic_term_id, :amount, :payment_method, :payment_reference, NULL, NULL, :or_number, CURDATE(), CURDATE(), :cashier_id, :notes, 'pending')"
                    );
                    $insertStmt->execute([
                        'student_id' => (int)$student['id'],
                        'academic_term_id' => $termId,
                        'amount' => round($amount, 2),
                        'payment_method' => $methodCode,
                        'payment_reference' => $reference !== '' ? $reference : null,
                        'or_number' => $orNumber,
                        // Student-submitted payments should not be recorded as made by a cashier.
                        // Leave `cashier_id` NULL so only an authorized cashier fills this on validation.
                        'cashier_id' => null,
                        'notes' => $notes !== '' ? $notes : 'Student payment record',
                    ]);

                    $paymentId = (int)$pdo->lastInsertId();
                    allocatePayment($pdo, $paymentId, $assessmentId, $amount);
                    updateStudentPaymentSummary($pdo, (int)$student['id']);
                    $pdo->commit();
                    $inserted = true;
                } catch (\PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $isDuplicateOr = ($e->getCode() === '23000' || ($e->errorInfo[1] ?? 0) === 1062);
                    if ($isDuplicateOr && $attempt < $maxAttempts) {
                        continue;
                    }
                    throw $e;
                }
            }

            $_SESSION['flash_success'] = 'Payment of ₱' . number_format($amount, 2) . ' recorded successfully. OR #: ' . $orNumber . '. Awaiting cashier validation.';
            header('Location: ' . $paymentPage);
            exit;
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
            header('Location: ' . $paymentPage);
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Student payment recording failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
            header('Location: ' . $paymentPage);
            exit;
        }
        break;

    case 'cashier_process_assessment_payment':
        checkRole(['cashier', 'admin']);
        $referenceNumber = trim((string)($_POST['reference_number'] ?? ''));
        $methodCode = strtolower(trim((string)($_POST['payment_method'] ?? '')));
        $orNumber = trim((string)($_POST['or_number'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
        $bankName = trim((string)($_POST['bank_name'] ?? ''));
        $checkNumber = trim((string)($_POST['check_number'] ?? ''));

        try {
            $result = processCashierAssessmentPayment($pdo, (int)($_SESSION['user_id'] ?? 0), $referenceNumber, $methodCode, $orNumber, [
                'notes' => $notes,
                'payment_reference' => $paymentReference,
                'bank_name' => $bankName,
                'check_number' => $checkNumber,
            ]);
            $_SESSION['flash_success'] = 'Assessment ' . $referenceNumber . ' was processed successfully. Payment receipt OR #' . htmlspecialchars($orNumber, ENT_QUOTES, 'UTF-8') . ' recorded and student marked as paid.';
            header('Location: ../cashier/payments?reference_number=' . urlencode($referenceNumber));
            exit;
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            header('Location: ../cashier/payments?reference_number=' . urlencode($referenceNumber));
            exit;
        } catch (\Throwable $e) {
            error_log('Cashier assessment payment failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
            header('Location: ../cashier/payments?reference_number=' . urlencode($referenceNumber));
            exit;
        }
        break;

    case 'generate_assessment':
        checkRole(['cashier', 'admin']);
        $studentId = (int)($_POST['student_id'] ?? 0);
        try {
            $studentStmt = $pdo->prepare('SELECT academic_term_id FROM students WHERE id = :id LIMIT 1');
            $studentStmt->execute(['id' => $studentId]);
            $student = $studentStmt->fetch();
            if (!$student || empty($student['academic_term_id'])) {
                throw new \RuntimeException('Student term record was not found.');
            }
            $pdo->beginTransaction();
            $assessmentId = getOrCreateAssessment($pdo, $studentId, (int)$student['academic_term_id']);
            $pdo->commit();
            $_SESSION['flash_success'] = 'Assessment generated successfully.';
            header("Location: ../cashier/assessment?id={$assessmentId}");
            exit;
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Assessment generation failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }
        break;

    case 'create':
        checkRole(['cashier', 'admin']);
        $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        $amount = isset($_POST['amount']) ? trim($_POST['amount']) : '';
        $orNumber = isset($_POST['or_number']) ? trim($_POST['or_number']) : '';
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        $paymentMethod = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';
        $paymentReference = isset($_POST['payment_reference']) ? trim($_POST['payment_reference']) : '';
        $bankName = isset($_POST['bank_name']) ? trim($_POST['bank_name']) : '';
        $checkNumber = isset($_POST['check_number']) ? trim($_POST['check_number']) : '';
        $cashierId = (int)$_SESSION['user_id'];

        if (empty($studentId) || $amount === '' || empty($orNumber) || $paymentMethod === '') {
            $_SESSION['flash_error'] = "Student, amount, payment method, and OR number are required.";
            header("Location: ../cashier/payments" . ($studentId ? "?student_id={$studentId}" : ''));
            exit;
        }

        if (!is_numeric($amount) || (float)$amount <= 0) {
            $_SESSION['flash_error'] = "Amount must be a positive number.";
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }

        $amount = round((float)$amount, 2);

        $requiresReference = in_array($paymentMethod, ['bank_transfer', 'online_transfer', 'gcash', 'paymaya', 'credit_card', 'check'], true);
        if ($requiresReference && $paymentReference === '') {
            $_SESSION['flash_error'] = 'This payment method requires a reference number or transaction code.';
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }

        if ($paymentMethod === 'check' && $checkNumber === '') {
            $_SESSION['flash_error'] = 'Please enter the check number for this payment.';
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }

        $envMax = getenv('PAYMENT_MAX');
        $paymentMax = is_numeric($envMax) ? (float)$envMax : 1000000.00;
        if ($amount > $paymentMax) {
            $_SESSION['flash_error'] = "Amount exceeds allowed maximum of ₱" . number_format($paymentMax, 2) . ".";
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }

        if (strlen($orNumber) > 50 || strlen($orNumber) < 1) {
            $_SESSION['flash_error'] = "OR number is required and must not exceed 50 characters.";
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }

        try {
            $studentStmt = $pdo->prepare("SELECT s.id, s.user_id, s.academic_term_id, s.application_status, s.enrollment_status, s.first_name, s.last_name, u.role FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = :id LIMIT 1");
            $studentStmt->execute(['id' => $studentId]);
            $student = $studentStmt->fetch();

            if (!$student) {
                $_SESSION['flash_error'] = "Selected student was not found.";
                header("Location: ../cashier/payments");
                exit;
            }

            if (empty($student['academic_term_id'])) {
                throw new \RuntimeException('The selected account is not linked to an academic term.');
            }

            $pdo->beginTransaction();
            $assessmentId = getOrCreateAssessment($pdo, $studentId, (int)$student['academic_term_id']);
            $totals = getAssessmentTotals($pdo, $assessmentId);
            $balance = round((float)($totals['balance'] ?? 0.00), 2);
            if ($amount > $balance) {
                $pdo->rollBack();
                $_SESSION['flash_error'] = "Amount exceeds outstanding assessment balance of ₱" . number_format($balance, 2) . ".";
                header("Location: ../cashier/payments?student_id={$studentId}");
                exit;
            }

            $paymentDate = date('Y-m-d');
            $insertStmt = $pdo->prepare("
                INSERT INTO payments (student_id, academic_term_id, amount, payment_method, payment_reference, bank_name, check_number, or_number, payment_date, issue_date, cashier_id, notes, or_status, validated_at, validated_by)
                VALUES (:student_id, :academic_term_id, :amount, :payment_method, :payment_reference, :bank_name, :check_number, :or_number, :payment_date, :issue_date, :cashier_id, :notes, 'validated', CURRENT_TIMESTAMP, :validated_by)
            ");

            try {
                $insertStmt->execute([
                    'student_id' => $studentId,
                    'academic_term_id' => (int)$student['academic_term_id'],
                    'amount' => $amount,
                    'payment_method' => $paymentMethod,
                    'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
                    'bank_name' => $bankName !== '' ? $bankName : null,
                    'check_number' => $checkNumber !== '' ? $checkNumber : null,
                    'or_number' => $orNumber,
                    'payment_date' => $paymentDate,
                    'issue_date' => $paymentDate,
                    'cashier_id' => $cashierId,
                    'notes' => $notes !== '' ? $notes : null,
                    'validated_by' => $cashierId,
                ]);
            } catch (\PDOException $duplicateException) {
                $pdo->rollBack();
                if (($duplicateException->errorInfo[1] ?? 0) == 1062) {
                    $_SESSION['flash_error'] = "OR number '{$orNumber}' is already registered. Please use a unique official receipt number.";
                    header("Location: ../cashier/payments?student_id={$studentId}");
                    exit;
                }
                throw $duplicateException;
            }

            $paymentId = (int)$pdo->lastInsertId();
            allocatePayment($pdo, $paymentId, $assessmentId, $amount);
            updateStudentPaymentSummary($pdo, $studentId);

            $totals = getAssessmentTotals($pdo, $assessmentId);
            $assessmentStatus = (float)$totals['validated_paid'] >= (float)$totals['total_amount'] ? 'paid' : 'open';
            $pdo->prepare("UPDATE assessments SET status = :status WHERE id = :id")->execute(['status' => $assessmentStatus, 'id' => $assessmentId]);

            // Update student enrollment & payment status
            if (in_array($student['enrollment_status'], ['approved', 'section_chosen', 'walk_in_ready', 'paid'], true)) {
                $remainingBal = max(0.00, round((float)($totals['balance'] ?? 0), 2));
                $newPayStatus = $remainingBal <= 0 ? 'fully_paid' : 'partially_paid';
                $pdo->prepare("
                    UPDATE students 
                    SET admission_status = 'admitted', 
                        enrollment_status = 'paid', 
                        payment_status = :pstatus,
                        outstanding_balance = :balance
                    WHERE id = :id
                ")->execute([
                    'pstatus' => $newPayStatus,
                    'balance' => $remainingBal,
                    'id' => $studentId
                ]);

                if ($student['role'] === 'enrollee') {
                    promoteUserToStudent($pdo, (int)$student['user_id']);
                }
            }

            $pdo->commit();

            $studentName = trim($student['first_name'] . ' ' . $student['last_name']);
            $_SESSION['flash_success'] = "Payment of ₱" . number_format($amount, 2) . " recorded and validated for {$studentName}. OR #{$orNumber}.";
            header("Location: ../cashier/receipts?id={$paymentId}");
            exit;
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Record payment failed: " . $e->getMessage());

            if ($e->getCode() == 23000) {
                $_SESSION['flash_error'] = "OR number '{$orNumber}' is already registered. Please use a unique official receipt number.";
            } else {
                $_SESSION['flash_error'] = "Failed to record payment: " . $e->getMessage();
            }
            header("Location: ../cashier/payments?student_id={$studentId}");
            exit;
        }
        break;

    case 'validate':
        if (defined('TEST_INSTRUMENT') && TEST_INSTRUMENT === true) {
            // Instrumentation for CLI verifiers: indicate we entered the validate action
            echo "INSTR|validate_entry" . PHP_EOL;
        }
        checkRole(['cashier', 'admin']);
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $paymentStmt = $pdo->prepare(
                "SELECT p.id, p.student_id, p.academic_term_id, p.or_status, s.user_id, s.application_status, s.enrollment_status, s.academic_term_id AS student_term_id, u.role,
                        a.id AS assessment_id, a.total_amount, t.payment_requirement_percent
                 FROM payments p
                 JOIN students s ON s.id = p.student_id
                 JOIN users u ON u.id = s.user_id
                 LEFT JOIN assessments a ON a.student_id = s.id AND a.academic_term_id = p.academic_term_id
                 JOIN academic_terms t ON t.id = p.academic_term_id
                 WHERE p.id = :id
                 LIMIT 1 FOR UPDATE"
            );
            $paymentStmt->execute(['id' => $paymentId]);
            $payment = $paymentStmt->fetch();
            if (!$payment || $payment['or_status'] !== 'pending') {
                throw new \RuntimeException('Only pending OR records can be validated.');
            }

            if (empty($payment['assessment_id'])) {
                $assessmentId = getOrCreateAssessment($pdo, (int)$payment['student_id'], (int)$payment['academic_term_id']);
                $payment['assessment_id'] = $assessmentId;
                $stmt = $pdo->prepare("SELECT total_amount FROM assessments WHERE id = :id");
                $stmt->execute(['id' => $assessmentId]);
                $payment['total_amount'] = (float)($stmt->fetchColumn() ?? 0);
            }

            $requiredAmount = round((float)$payment['total_amount'] * ((float)$payment['payment_requirement_percent'] / 100), 2);
            $validateStmt = $pdo->prepare("UPDATE payments SET or_status = 'validated', validated_at = CURRENT_TIMESTAMP, validated_by = :validated_by WHERE id = :id AND or_status = 'pending'");
            $validateStmt->execute(['validated_by' => (int)$_SESSION['user_id'], 'id' => $paymentId]);
            $totals = getAssessmentTotals($pdo, (int)$payment['assessment_id']);
            $assessmentStatus = (float)$totals['validated_paid'] >= (float)$totals['total_amount'] ? 'paid' : 'open';
            $assessmentStmt = $pdo->prepare("UPDATE assessments SET status = :status WHERE id = :id");
            $assessmentStmt->execute(['status' => $assessmentStatus, 'id' => (int)$payment['assessment_id']]);

            if ($payment['application_status'] === 'approved' 
                && in_array($payment['enrollment_status'], ['approved', 'section_chosen', 'walk_in_ready', 'paid'], true)
                && (float)$totals['validated_paid'] >= $requiredAmount) {
                $statusStmt = $pdo->prepare("UPDATE students SET admission_status = 'admitted', enrollment_status = 'paid', payment_status = 'fully_paid', outstanding_balance = GREATEST(0, outstanding_balance - :paid_amt) WHERE id = :id");
                $statusStmt->execute(['paid_amt' => (float)$totals['validated_paid'], 'id' => (int)$payment['student_id']]);
                if ($payment['role'] === 'enrollee') {
                    $promoted = promoteUserToStudent($pdo, (int)$payment['user_id']);
                    if (!$promoted) {
                        throw new \RuntimeException('The account could not be activated as a student.');
                    }
                } elseif ($payment['role'] === 'student') {
                    // Already a student (e.g. continuing student paying term assessment) — skip promotion
                } else {
                    throw new \RuntimeException('The account could not be activated as a student.');
                }
                createNotification($pdo, (int)$payment['user_id'], 'Payment validated', 'Your payment and official receipt were validated. Student access is now active.', 'success');
            } elseif ($payment['application_status'] === 'approved' && (float)$totals['validated_paid'] < $requiredAmount) {
                $statusStmt = $pdo->prepare("UPDATE students SET admission_status = 'approved', payment_status = 'partially_paid', outstanding_balance = GREATEST(0, outstanding_balance - :paid_amt) WHERE id = :id");
                $statusStmt->execute(['paid_amt' => (float)$totals['validated_paid'], 'id' => (int)$payment['student_id']]);
                // Do not write student admission state into the cashier/admin session.
                createNotification($pdo, (int)$payment['user_id'], 'Payment validated', 'Your payment and official receipt were validated. Additional payment is still required before student access can be activated.', 'info');
            }

            $pdo->commit();
            $_SESSION['flash_success'] = 'Official receipt validated successfully.';
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Payment validation failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
        }
        header('Location: ../cashier/payment_history');
        exit;
        break;

    default:
        $_SESSION['flash_error'] = "Invalid payment action specified.";
        header("Location: ../cashier/payments");
        exit;
    }
}

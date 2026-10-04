<?php
// ================================================================
// DormEase – Payment Actions Handler
// Add, Edit, Delete payments. Only Owner/Caretaker may write.
// The 'amount' column always stores the computed total of all fees.
// ================================================================
require_once __DIR__ . '/auth.php';
requireRole(['Dormitory Owner', 'Caretaker']);

$action = $_POST['action'] ?? '';

// ── Helper: compute total from four fee components ─────────────
function computeTotal(float $rent, float $wifi, float $water, float $elec): float {
    return round($rent + $wifi + $water + $elec, 2);
}

switch ($action) {

    // ── ADD PAYMENT ────────────────────────────────────────────
    case 'add':
        $tenant_id     = (int)($_POST['tenant_id']      ?? 0);
        $payment_for   = trim($_POST['payment_for']     ?? '');
        $rent_fee      = (float)($_POST['rent_fee']     ?? 0);
        $wifi_fee      = (float)($_POST['wifi_fee']     ?? 0);
        $water_fee     = (float)($_POST['water_fee']    ?? 0);
        $electric_fee  = (float)($_POST['electric_fee'] ?? 0);
        $payment_method= trim($_POST['payment_method']  ?? 'Cash');
        $reference_no  = trim($_POST['reference_no']    ?? '');
        $payment_date  = trim($_POST['payment_date']    ?? '') ?: null;
        $status        = trim($_POST['status']          ?? 'Paid');
        $remarks       = trim($_POST['remarks']         ?? '');

        if ($tenant_id <= 0 || empty($payment_for)) {
            $_SESSION['error'] = 'Tenant and billing period are required.';
            header('Location: ../payments.php'); exit();
        }

        $validMethods  = ['Cash','GCash','Bank Transfer','Other'];
        $validStatuses = ['Paid','Pending','Overdue'];
        if (!in_array($payment_method, $validMethods))  $payment_method = 'Cash';
        if (!in_array($status, $validStatuses))          $status = 'Paid';

        $amount = computeTotal($rent_fee, $wifi_fee, $water_fee, $electric_fee);

        // Prevent duplicate payment for the same tenant + billing period
        $dup = $conn->prepare("SELECT id FROM payments WHERE tenant_id = ? AND payment_for = ? LIMIT 1");
        $dup->bind_param("is", $tenant_id, $payment_for);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            $_SESSION['error'] = "A payment record for \"{$payment_for}\" already exists for this tenant.";
            header('Location: ../payments.php'); exit();
        }
        $dup->close();

        $stmt = $conn->prepare("
            INSERT INTO payments
                (tenant_id, amount, rent_fee, wifi_fee, water_fee, electric_fee,
                 payment_date, payment_for, payment_method, reference_no, status, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("idddddssssss",
            $tenant_id, $amount, $rent_fee, $wifi_fee, $water_fee, $electric_fee,
            $payment_date, $payment_for, $payment_method, $reference_no, $status, $remarks
        );

        if ($stmt->execute()) {
            $_SESSION['success'] = "Payment of ₱" . number_format($amount, 2) . " for \"{$payment_for}\" recorded.";
        } else {
            $_SESSION['error'] = 'Failed to record payment: ' . $conn->error;
        }
        $stmt->close();
        header('Location: ../payments.php'); exit();

    // ── EDIT PAYMENT ───────────────────────────────────────────
    case 'edit':
        $id            = (int)($_POST['payment_id']     ?? 0);
        $payment_for   = trim($_POST['payment_for']     ?? '');
        $rent_fee      = (float)($_POST['rent_fee']     ?? 0);
        $wifi_fee      = (float)($_POST['wifi_fee']     ?? 0);
        $water_fee     = (float)($_POST['water_fee']    ?? 0);
        $electric_fee  = (float)($_POST['electric_fee'] ?? 0);
        $payment_method= trim($_POST['payment_method']  ?? 'Cash');
        $reference_no  = trim($_POST['reference_no']    ?? '');
        $payment_date  = trim($_POST['payment_date']    ?? '') ?: null;
        $status        = trim($_POST['status']          ?? 'Paid');
        $remarks       = trim($_POST['remarks']         ?? '');

        if ($id <= 0 || empty($payment_for)) {
            $_SESSION['error'] = 'Invalid payment data.';
            header('Location: ../payments.php'); exit();
        }

        $validMethods  = ['Cash','GCash','Bank Transfer','Other'];
        $validStatuses = ['Paid','Pending','Overdue'];
        if (!in_array($payment_method, $validMethods))  $payment_method = 'Cash';
        if (!in_array($status, $validStatuses))          $status = 'Paid';

        $amount = computeTotal($rent_fee, $wifi_fee, $water_fee, $electric_fee);

        $stmt = $conn->prepare("
            UPDATE payments
            SET amount = ?, rent_fee = ?, wifi_fee = ?, water_fee = ?,
                electric_fee = ?, payment_date = ?, payment_for = ?,
                payment_method = ?, reference_no = ?, status = ?, remarks = ?
            WHERE id = ?
        ");
        $stmt->bind_param("dddddsssssi",
            $amount, $rent_fee, $wifi_fee, $water_fee, $electric_fee,
            $payment_date, $payment_for, $payment_method, $reference_no, $status, $remarks,
            $id
        );

        if ($stmt->execute()) {
            $_SESSION['success'] = "Payment #{$id} updated (₱" . number_format($amount, 2) . ").";
        } else {
            $_SESSION['error'] = 'Failed to update payment: ' . $conn->error;
        }
        $stmt->close();
        header('Location: ../payments.php'); exit();

    // ── DELETE PAYMENT ─────────────────────────────────────────
    case 'delete':
        $id = (int)($_POST['payment_id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid payment ID.';
            header('Location: ../payments.php'); exit();
        }

        $fetch = $conn->prepare("SELECT payment_for, amount FROM payments WHERE id = ?");
        $fetch->bind_param("i", $id);
        $fetch->execute();
        $rec = $fetch->get_result()->fetch_assoc();
        $fetch->close();

        if (!$rec) {
            $_SESSION['error'] = 'Payment record not found.';
            header('Location: ../payments.php'); exit();
        }

        $stmt = $conn->prepare("DELETE FROM payments WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Payment #{$id} for \"{$rec['payment_for']}\" deleted.";
        } else {
            $_SESSION['error'] = 'Failed to delete payment: ' . $conn->error;
        }
        $stmt->close();
        header('Location: ../payments.php'); exit();

    default:
        header('Location: ../payments.php'); exit();
}
?>

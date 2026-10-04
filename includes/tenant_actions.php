<?php
// ================================================================
// DormEase – Tenant Actions Handler
// Handles Add, Edit, Delete tenant CRUD operations via POST.
// Automatically syncs room occupancy status after every mutation.
// ================================================================
require_once __DIR__ . '/auth.php';
requireRole(['Dormitory Owner', 'Caretaker']);

$action = $_POST['action'] ?? '';

// ─────────────────────────────────────────────────────────────────
// HELPER: Sync a room's status based on its current active tenants.
//   ≥1 Active tenants  → Occupied
//   0 Active tenants   → Available  (never overrides Maintenance)
// ─────────────────────────────────────────────────────────────────
function syncRoomStatus(int $roomId): void {
    global $conn;
    if ($roomId <= 0) return;

    $s = $conn->prepare("SELECT COUNT(*) AS cnt FROM tenants WHERE room_id = ? AND status = 'Active'");
    $s->bind_param("i", $roomId);
    $s->execute();
    $active = (int)$s->get_result()->fetch_assoc()['cnt'];
    $s->close();

    if ($active > 0) {
        $u = $conn->prepare("UPDATE rooms SET status = 'Occupied' WHERE id = ?");
        $u->bind_param("i", $roomId);
        $u->execute();
        $u->close();
    } else {
        $u = $conn->prepare("UPDATE rooms SET status = 'Available' WHERE id = ? AND status = 'Occupied'");
        $u->bind_param("i", $roomId);
        $u->execute();
        $u->close();
    }
}

// ─────────────────────────────────────────────────────────────────
// HELPER: Return true if a room still has space for one more
//         Active tenant (current active < capacity).
// ─────────────────────────────────────────────────────────────────
function roomHasCapacity(int $roomId): bool {
    global $conn;
    $s = $conn->prepare("
        SELECT r.capacity,
               (SELECT COUNT(*) FROM tenants t WHERE t.room_id = r.id AND t.status = 'Active') AS active_count
        FROM rooms r WHERE r.id = ?
    ");
    $s->bind_param("i", $roomId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$row) return false;
    return (int)$row['active_count'] < (int)$row['capacity'];
}

// =================================================================
switch ($action) {

    // ── ADD TENANT ─────────────────────────────────────────────
    case 'add':
        $user_id      = (int)($_POST['user_id']      ?? 0);
        $room_id      = (int)($_POST['room_id']      ?? 0);
        $monthly_rent = (float)($_POST['monthly_rent'] ?? 0);
        $move_in      = trim($_POST['move_in_date']  ?? '');
        $move_out     = trim($_POST['move_out_date'] ?? '') ?: null;
        $ec_name      = trim($_POST['emergency_contact_name']  ?? '');
        $ec_phone     = trim($_POST['emergency_contact_phone'] ?? '');
        $status       = $_POST['status'] ?? 'Pending';

        // Validate required fields
        if ($user_id <= 0 || $room_id <= 0 || $move_in === '') {
            $_SESSION['error'] = 'Tenant user, room, and move-in date are required.';
            header('Location: ../tenants.php'); exit();
        }
        if (!in_array($status, ['Active','Inactive','Pending'])) $status = 'Pending';

        // Block duplicate active assignment for the same user
        $dup = $conn->prepare("SELECT id FROM tenants WHERE user_id = ? AND status = 'Active' LIMIT 1");
        $dup->bind_param("i", $user_id);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            $_SESSION['error'] = 'This user already has an active room assignment.';
            header('Location: ../tenants.php'); exit();
        }
        $dup->close();

        // Capacity check (only applies when status = Active)
        if ($status === 'Active' && !roomHasCapacity($room_id)) {
            $_SESSION['error'] = 'The selected room is already at full capacity.';
            header('Location: ../tenants.php'); exit();
        }

        $stmt = $conn->prepare("
            INSERT INTO tenants
                (user_id, room_id, monthly_rent, move_in_date, move_out_date,
                 emergency_contact_name, emergency_contact_phone, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iidsssss",
            $user_id, $room_id, $monthly_rent,
            $move_in, $move_out, $ec_name, $ec_phone, $status
        );

        if ($stmt->execute()) {
            syncRoomStatus($room_id);
            $_SESSION['success'] = 'Tenant added successfully and room status updated.';
        } else {
            $_SESSION['error'] = 'Failed to add tenant: ' . $conn->error;
        }
        $stmt->close();
        header('Location: ../tenants.php'); exit();

    // ── EDIT TENANT ────────────────────────────────────────────
    case 'edit':
        $id           = (int)($_POST['tenant_id']    ?? 0);
        $room_id      = (int)($_POST['room_id']      ?? 0);
        $monthly_rent = (float)($_POST['monthly_rent'] ?? 0);
        $move_in      = trim($_POST['move_in_date']  ?? '');
        $move_out     = trim($_POST['move_out_date'] ?? '') ?: null;
        $ec_name      = trim($_POST['emergency_contact_name']  ?? '');
        $ec_phone     = trim($_POST['emergency_contact_phone'] ?? '');
        $status       = $_POST['status'] ?? 'Active';

        if ($id <= 0 || $room_id <= 0 || $move_in === '') {
            $_SESSION['error'] = 'Invalid tenant data.';
            header('Location: ../tenants.php'); exit();
        }
        if (!in_array($status, ['Active','Inactive','Pending'])) $status = 'Active';

        // Fetch old room_id before the update (needed for dual sync)
        $old = $conn->prepare("SELECT room_id FROM tenants WHERE id = ?");
        $old->bind_param("i", $id);
        $old->execute();
        $oldRow    = $old->get_result()->fetch_assoc();
        $old->close();
        $oldRoomId = (int)($oldRow['room_id'] ?? 0);

        // Capacity guard: only check if switching to a new room with Active status
        if ($room_id !== $oldRoomId && $status === 'Active' && !roomHasCapacity($room_id)) {
            $_SESSION['error'] = 'The selected room is already at full capacity.';
            header('Location: ../tenants.php'); exit();
        }

        $stmt = $conn->prepare("
            UPDATE tenants
            SET room_id = ?, monthly_rent = ?, move_in_date = ?,
                move_out_date = ?, emergency_contact_name = ?,
                emergency_contact_phone = ?, status = ?
            WHERE id = ?
        ");
        $stmt->bind_param("idsssssi",
            $room_id, $monthly_rent, $move_in,
            $move_out, $ec_name, $ec_phone, $status, $id
        );

        if ($stmt->execute()) {
            syncRoomStatus($oldRoomId);           // free old room if needed
            if ($room_id !== $oldRoomId) {
                syncRoomStatus($room_id);         // mark new room occupied if needed
            }
            $_SESSION['success'] = 'Tenant updated and room status synced.';
        } else {
            $_SESSION['error'] = 'Failed to update tenant: ' . $conn->error;
        }
        $stmt->close();
        header('Location: ../tenants.php'); exit();

    // ── DELETE TENANT ──────────────────────────────────────────
    case 'delete':
        $id = (int)($_POST['tenant_id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid tenant ID.';
            header('Location: ../tenants.php'); exit();
        }

        // Fetch name and room before deleting
        $fetch = $conn->prepare("
            SELECT t.room_id, u.full_name
            FROM tenants t JOIN users u ON t.user_id = u.id
            WHERE t.id = ?
        ");
        $fetch->bind_param("i", $id);
        $fetch->execute();
        $rec = $fetch->get_result()->fetch_assoc();
        $fetch->close();

        if (!$rec) {
            $_SESSION['error'] = 'Tenant not found.';
            header('Location: ../tenants.php'); exit();
        }

        // Block deletion if payment records exist (preserve financial history)
        $pc = $conn->prepare("SELECT COUNT(*) AS cnt FROM payments WHERE tenant_id = ?");
        $pc->bind_param("i", $id);
        $pc->execute();
        $payCount = (int)$pc->get_result()->fetch_assoc()['cnt'];
        $pc->close();

        if ($payCount > 0) {
            $_SESSION['error'] = "Cannot delete \"{$rec['full_name']}\" — {$payCount} payment record(s) exist. Mark as Inactive instead.";
            header('Location: ../tenants.php'); exit();
        }

        $roomId = (int)$rec['room_id'];
        $stmt   = $conn->prepare("DELETE FROM tenants WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            syncRoomStatus($roomId);
            $_SESSION['success'] = "\"{$rec['full_name']}\" removed and room status updated.";
        } else {
            $_SESSION['error'] = 'Failed to delete tenant: ' . $conn->error;
        }
        $stmt->close();
        header('Location: ../tenants.php'); exit();

    default:
        header('Location: ../tenants.php'); exit();
}
?>

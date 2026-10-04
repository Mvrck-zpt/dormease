<?php
// ================================================================
// DormEase – Room Actions Handler
// All Add / Edit / Delete operations for rooms go through here.
// Called by rooms.php via POST form submissions.
// ================================================================
require_once __DIR__ . '/auth.php';
requireRole(['Dormitory Owner', 'Caretaker']);

$action = $_POST['action'] ?? '';

// ── Helper: auto-sync room status based on active tenant count ──
// A room is 'Occupied' when it has ≥1 Active tenant; otherwise it
// keeps whatever the admin set (Available / Under Maintenance).
function syncRoomStatus(int $roomId): void {
    global $conn;

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM tenants
        WHERE room_id = ? AND status = 'Active'
    ");
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    if ($count > 0) {
        $upd = $conn->prepare("UPDATE rooms SET status = 'Occupied' WHERE id = ?");
        $upd->bind_param("i", $roomId);
        $upd->execute();
        $upd->close();
    } else {
        // Only revert to Available if it is currently Occupied
        // (don't override Under Maintenance set by admin)
        $upd = $conn->prepare("
            UPDATE rooms SET status = 'Available'
            WHERE id = ? AND status = 'Occupied'
        ");
        $upd->bind_param("i", $roomId);
        $upd->execute();
        $upd->close();
    }
}

switch ($action) {

    // ── ADD ROOM ───────────────────────────────────────────────
    case 'add':
        $room_number  = trim($_POST['room_number']  ?? '');
        $floor        = (int)($_POST['floor']        ?? 1);
        $room_type    = $_POST['room_type']          ?? 'Single';
        $capacity     = (int)($_POST['capacity']     ?? 1);
        $monthly_rate = (float)($_POST['monthly_rate'] ?? 0);
        $status       = $_POST['status']             ?? 'Available';
        $description  = trim($_POST['description']   ?? '');

        // Validate
        if (empty($room_number)) {
            $_SESSION['error'] = 'Room number is required.';
            header('Location: rooms.php');
            exit();
        }

        // Valid enum values
        $validTypes    = ['Single', 'Double', 'Triple', 'Quad'];
        $validStatuses = ['Available', 'Occupied', 'Under Maintenance'];
        if (!in_array($room_type, $validTypes))    { $room_type = 'Single'; }
        if (!in_array($status, $validStatuses))    { $status    = 'Available'; }

        $stmt = $conn->prepare("
            INSERT INTO rooms (room_number, floor, room_type, capacity, monthly_rate, status, description)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
       $stmt->bind_param("sisidss",
            $room_number, $floor, $room_type,
            $capacity, $monthly_rate, $status, $description
        );

        if ($stmt->execute()) {
            $_SESSION['success'] = "Room {$room_number} added successfully.";
        } else {
            // Duplicate room_number triggers unique key violation
            $errNo = $conn->errno;
            $_SESSION['error'] = ($errNo === 1062)
                ? "Room number '{$room_number}' already exists."
                : 'Failed to add room. Please try again.';
        }
        $stmt->close();
        header('Location: rooms.php');
        exit();

    // ── EDIT ROOM ──────────────────────────────────────────────
    case 'edit':
        $id           = (int)($_POST['room_id']      ?? 0);
        $room_number  = trim($_POST['room_number']   ?? '');
        $floor        = (int)($_POST['floor']        ?? 1);
        $room_type    = $_POST['room_type']           ?? 'Single';
        $capacity     = (int)($_POST['capacity']     ?? 1);
        $monthly_rate = (float)($_POST['monthly_rate'] ?? 0);
        $status       = $_POST['status']              ?? 'Available';
        $description  = trim($_POST['description']   ?? '');

        if ($id <= 0 || empty($room_number)) {
            $_SESSION['error'] = 'Invalid room data.';
            header('Location: rooms.php');
            exit();
        }

        $validTypes    = ['Single', 'Double', 'Triple', 'Quad'];
        $validStatuses = ['Available', 'Occupied', 'Under Maintenance'];
        if (!in_array($room_type, $validTypes))  { $room_type = 'Single'; }
        if (!in_array($status, $validStatuses))  { $status    = 'Available'; }

        $stmt = $conn->prepare("
            UPDATE rooms
            SET room_number = ?, floor = ?, room_type = ?,
                capacity = ?, monthly_rate = ?, status = ?, description = ?
            WHERE id = ?
        ");
        $stmt->bind_param("sisidssi",
            $room_number, $floor, $room_type,
            $capacity, $monthly_rate, $status, $description, $id
        );

        if ($stmt->execute()) {
            // After manual status change, re-sync based on active tenants
            syncRoomStatus($id);
            $_SESSION['success'] = "Room {$room_number} updated successfully.";
        } else { 
            $errNo = $conn->errno;
            $_SESSION['error'] = ($errNo === 1062)
                ? "Room number '{$room_number}' already exists."
                : 'Failed to update room. Please try again.';
        }
        $stmt->close();
        header('Location: rooms.php');
        exit();

    // ── DELETE ROOM ────────────────────────────────────────────
    case 'delete':
        $id = (int)($_POST['room_id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid room ID.';
            header('Location: rooms.php');
            exit();
        }

        // Block deletion if the room has ANY tenant record (active or past).
        // The database itself enforces this with a foreign key (ON DELETE RESTRICT),
        // so we check for all tenants here, not just active ones, to avoid a crash.
        $check = $conn->prepare("
            SELECT COUNT(*) AS cnt FROM tenants WHERE room_id = ?
        ");
        $check->bind_param("i", $id);
        $check->execute();
        $linkedTenants = (int)$check->get_result()->fetch_assoc()['cnt'];
        $check->close();

        if ($linkedTenants > 0) {
            $_SESSION['error'] = 'Cannot delete this room because it has tenant history (current or past) linked to it. '
                . 'Rooms with any tenant records cannot be deleted, to keep payment and occupancy history intact.';
            header('Location: rooms.php');
            exit();
        }

        // Fetch room number for the success message
        $fetch = $conn->prepare("SELECT room_number FROM rooms WHERE id = ?");
        $fetch->bind_param("i", $id);
        $fetch->execute();
        $roomData = $fetch->get_result()->fetch_assoc();
        $fetch->close();

        $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->bind_param("i", $id);

        try {
            if ($stmt->execute()) {
                $_SESSION['success'] = "Room {$roomData['room_number']} deleted successfully.";
            } else {
                $_SESSION['error'] = 'Failed to delete room. Please try again.';
            }
        } catch (\mysqli_sql_exception $ex) {
            // Safety net: if a foreign key or other DB constraint blocks this
            // delete for a reason the check above didn't anticipate, show a
            // friendly message instead of a fatal error page.
            $_SESSION['error'] = 'Cannot delete this room because other records '
                . '(such as payments or tenant history) are still linked to it.';
        }
        $stmt->close();
        header('Location: rooms.php');
        exit();

    default:
        header('Location: rooms.php');
        exit();
}
?>

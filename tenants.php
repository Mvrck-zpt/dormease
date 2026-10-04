<?php
require_once 'includes/auth.php';
requireRole(['Dormitory Owner', 'Caretaker']);
$user = getCurrentUser();
$page = getCurrentPage();

/* ------------------------------------------------------------------
   DATABASE CONNECTION (Laragon defaults). Change here if yours differ.
------------------------------------------------------------------- */
$DB_HOST = 'localhost';
$DB_NAME = 'dormease_db';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

/* ---------- Helpers ---------- */
function enumValues(PDO $pdo, $table, $col) {
    $r = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch();
    preg_match_all("/'([^']*)'/", $r['Type'], $m);
    return $m[1];
}
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Emergency contact column names (auto-detected: first = name, second = phone)
$ecCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM tenants") as $c) {
    if (strpos($c['Field'], 'emergency') === 0) $ecCols[] = $c['Field'];
}
$ecName  = $ecCols[0] ?? null;
$ecPhone = $ecCols[1] ?? null;

$statuses = enumValues($pdo, 'tenants', 'status');   // e.g. Active, Inactive, Pending...
$inactiveStatus = null;
foreach ($statuses as $s) { if (stripos($s, 'inact') === 0) $inactiveStatus = $s; }
if (!$inactiveStatus) $inactiveStatus = end($statuses);

// Keep room status in sync with active tenants
function syncRooms(PDO $pdo) {
    try {
        $pdo->exec("UPDATE rooms r SET r.status='Occupied'
                    WHERE r.status='Available'
                    AND EXISTS (SELECT 1 FROM tenants t WHERE t.room_id=r.id AND t.status='Active')");
        $pdo->exec("UPDATE rooms r SET r.status='Available'
                    WHERE r.status='Occupied'
                    AND NOT EXISTS (SELECT 1 FROM tenants t WHERE t.room_id=r.id AND t.status='Active')");
    } catch (PDOException $e) { /* room status values differ; ignore */ }
}

/* ---------- Handle form actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['type' => 'success', 'msg' => 'Done.'];
    try {
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
            throw new Exception('Invalid form token. Please reload the page and try again.');
        }
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            $full_name = trim($_POST['full_name'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $phone     = trim($_POST['phone'] ?? '');
            $room_id   = (int)($_POST['room_id'] ?? 0);
            $rent      = (float)($_POST['monthly_rent'] ?? 0);
            $move_in   = $_POST['move_in_date'] ?? '';
            $move_out  = ($_POST['move_out_date'] ?? '') !== '' ? $_POST['move_out_date'] : null;
            $status    = $_POST['status'] ?? 'Pending';
            $ec1       = trim($_POST['ec_name'] ?? '');
            $ec2       = trim($_POST['ec_phone'] ?? '');

            if ($full_name === '' || $email === '' || $room_id <= 0 || $move_in === '') {
                throw new Exception('Full name, email, room and move-in date are required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address.');
            if (!in_array($status, $statuses, true)) throw new Exception('Invalid status.');

            $pdo->beginTransaction();

            if ($action === 'add') {
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                if ($username === '' || strlen($password) < 6) {
                    throw new Exception('Username is required and password must be at least 6 characters.');
                }
                $st = $pdo->prepare("INSERT INTO users (username, password, full_name, email, phone, role, is_active)
                                     VALUES (?,?,?,?,?,'Tenant',1)");
                $st->execute([$username, password_hash($password, PASSWORD_DEFAULT), $full_name, $email, $phone]);
                $uid = (int)$pdo->lastInsertId();

                $sql = "INSERT INTO tenants (user_id, room_id, monthly_rent, move_in_date, move_out_date, status"
                     . ($ecName ? ", `$ecName`" : "") . ($ecPhone ? ", `$ecPhone`" : "")
                     . ") VALUES (?,?,?,?,?,?" . ($ecName ? ",?" : "") . ($ecPhone ? ",?" : "") . ")";
                $params = [$uid, $room_id, $rent, $move_in, $move_out, $status];
                if ($ecName)  $params[] = $ec1;
                if ($ecPhone) $params[] = $ec2;
                $pdo->prepare($sql)->execute($params);
                $flash['msg'] = 'Tenant added successfully.';
            } else {
                $tid = (int)($_POST['id'] ?? 0);
                $row = $pdo->prepare("SELECT user_id FROM tenants WHERE id=?");
                $row->execute([$tid]);
                $uid = $row->fetchColumn();
                if (!$uid) throw new Exception('Tenant not found.');

                $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?")
                    ->execute([$full_name, $email, $phone, $uid]);
                if (!empty($_POST['password'])) {
                    if (strlen($_POST['password']) < 6) throw new Exception('New password must be at least 6 characters.');
                    $pdo->prepare("UPDATE users SET password=? WHERE id=?")
                        ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $uid]);
                }

                $sql = "UPDATE tenants SET room_id=?, monthly_rent=?, move_in_date=?, move_out_date=?, status=?"
                     . ($ecName ? ", `$ecName`=?" : "") . ($ecPhone ? ", `$ecPhone`=?" : "") . " WHERE id=?";
                $params = [$room_id, $rent, $move_in, $move_out, $status];
                if ($ecName)  $params[] = $ec1;
                if ($ecPhone) $params[] = $ec2;
                $params[] = $tid;
                $pdo->prepare($sql)->execute($params);
                $flash['msg'] = 'Tenant updated successfully.';
            }
            $pdo->commit();
            syncRooms($pdo);

        } elseif ($action === 'checkout') {
            $tid = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE tenants SET status=?, move_out_date=COALESCE(move_out_date, CURDATE()) WHERE id=?")
                ->execute([$inactiveStatus, $tid]);
            syncRooms($pdo);
            $flash['msg'] = 'Tenant checked out.';

        } elseif ($action === 'delete') {
            if (($_SESSION['role'] ?? '') !== 'Dormitory Owner') {
                throw new Exception('Only the Dormitory Owner can delete tenants.');
            }
            $tid = (int)($_POST['id'] ?? 0);
            $row = $pdo->prepare("SELECT user_id FROM tenants WHERE id=?");
            $row->execute([$tid]);
            $uid = $row->fetchColumn();
            if (!$uid) throw new Exception('Tenant not found.');
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]);
            $pdo->prepare("DELETE FROM users WHERE id=? AND role='Tenant'")->execute([$uid]);
            $pdo->commit();
            syncRooms($pdo);
            $flash['msg'] = 'Tenant deleted.';
        }
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['type' => 'error', 'msg' => ($ex->getCode() == 23000)
            ? 'That username or email already exists, or this tenant has linked records (e.g. payments). Use Check-out instead of Delete.'
            : 'Database error: ' . $ex->getMessage()];
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['type' => 'error', 'msg' => $ex->getMessage()];
    }
    $_SESSION['flash'] = $flash;
    header('Location: tenants.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- Load data ---------- */
$ecSelect = ($ecName ? ", t.`$ecName` AS ec_name" : ", '' AS ec_name")
          . ($ecPhone ? ", t.`$ecPhone` AS ec_phone" : ", '' AS ec_phone");
$tenants = $pdo->query("SELECT t.id, t.user_id, t.room_id, t.monthly_rent, t.move_in_date, t.move_out_date, t.status
        $ecSelect, u.full_name, u.username, u.email, u.phone, r.room_number, r.room_type
        FROM tenants t
        JOIN users u ON u.id = t.user_id
        LEFT JOIN rooms r ON r.id = t.room_id
        ORDER BY t.id DESC")->fetchAll();
$rooms = $pdo->query("SELECT id, room_number, room_type, monthly_rate, status FROM rooms ORDER BY room_number")->fetchAll();

$total = count($tenants);
$cnt = array_fill_keys($statuses, 0);
foreach ($tenants as $t) { if (isset($cnt[$t['status']])) $cnt[$t['status']]++; }
$activeCnt   = $cnt['Active'] ?? 0;
$pendingCnt  = $cnt['Pending'] ?? 0;
$inactiveCnt = $cnt[$inactiveStatus] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Tenants</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .tn-toolbar{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
        .tn-search{padding:10px 14px;border:1px solid #d5dbe5;border-radius:8px;min-width:240px;font-size:14px}
        .tn-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:0;border-radius:8px;background:#1a73e8;color:#fff;font-size:14px;font-weight:600;cursor:pointer}
        .tn-btn:hover{background:#1558b8}
        .tn-btn.gray{background:#e8ecf3;color:#333}
        .tn-btn.gray:hover{background:#d8deea}
        .tn-icon{border:0;background:#eef2f9;color:#1a73e8;width:34px;height:34px;border-radius:8px;cursor:pointer;margin-right:4px}
        .tn-icon.warn{color:#e69500;background:#fff6e2}
        .tn-icon.del{color:#d93025;background:#fdecea}
        .tn-table-wrap{overflow-x:auto}
        .tn-table{width:100%;border-collapse:collapse;font-size:14px}
        .tn-table th{text-align:left;padding:12px 16px;background:#f5f7fb;color:#5a6577;font-size:12px;text-transform:uppercase;letter-spacing:.5px}
        .tn-table td{padding:14px 16px;border-top:1px solid #edf0f5;vertical-align:middle}
        .tn-table small{color:#7a8496}
        .tn-badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:#e8ecf3;color:#555}
        .tn-badge.Active{background:#e3f6e8;color:#1e8e3e}
        .tn-badge.Pending{background:#fff4d6;color:#b06f00}
        .tn-flash{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px}
        .tn-flash.success{background:#e3f6e8;color:#1e6b34}
        .tn-flash.error{background:#fdecea;color:#a1261b}
        .tn-modal{display:none;position:fixed;inset:0;background:rgba(10,20,40,.55);z-index:1000;align-items:center;justify-content:center;padding:16px}
        .tn-modal.open{display:flex}
        .tn-dialog{background:#fff;border-radius:14px;width:100%;max-width:680px;max-height:92vh;overflow:auto;padding:24px}
        .tn-dialog h3{margin:0 0 16px}
        .tn-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .tn-grid .full{grid-column:1/-1}
        .tn-field label{display:block;font-size:13px;font-weight:600;margin-bottom:5px}
        .tn-field input,.tn-field select{width:100%;padding:10px 12px;border:1px solid #d5dbe5;border-radius:8px;font-size:14px;box-sizing:border-box}
        .tn-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
        .tn-hint{font-size:12px;color:#7a8496;margin-top:4px}
        @media(max-width:600px){.tn-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="app-layout">
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <div class="sidebar-brand-icon"><i class="fas fa-building"></i></div>
                <div>
                    <h2><?php echo APP_NAME; ?></h2>
                    <span><?php echo APP_TAGLINE; ?></span>
                </div>
            </div>
            <nav class="sidebar-nav">
                <div class="sidebar-section">
                    <div class="sidebar-section-title">Main Menu</div>
                    <a href="dashboard.php" class="sidebar-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>">
                        <span class="icon"><i class="fas fa-th-large"></i></span> Dashboard
                    </a>
                    <a href="rooms.php" class="sidebar-link <?php echo $page === 'rooms' ? 'active' : ''; ?>">
                        <span class="icon"><i class="fas fa-door-open"></i></span> Rooms
                    </a>
                    <a href="tenants.php" class="sidebar-link <?php echo $page === 'tenants' ? 'active' : ''; ?>">
                        <span class="icon"><i class="fas fa-users"></i></span> Tenants
                    </a>
                    <a href="payments.php" class="sidebar-link <?php echo $page === 'payments' ? 'active' : ''; ?>">
                        <span class="icon"><i class="fas fa-money-bill-wave"></i></span> Payments
                    </a>
                </div>
                <div class="sidebar-section">
                    <div class="sidebar-section-title">Other</div>
                    <a href="reports.php" class="sidebar-link <?php echo $page === 'reports' ? 'active' : ''; ?>">
                        <span class="icon"><i class="fas fa-chart-bar"></i></span> Reports
                    </a>
                    <a href="profile.php" class="sidebar-link <?php echo $page === 'profile' ? 'active' : ''; ?>">
                        <span class="icon"><i class="fas fa-user-cog"></i></span> Profile
                    </a>
                    <a href="logout.php" class="sidebar-link">
                        <span class="icon"><i class="fas fa-sign-out-alt"></i></span> Logout
                    </a>
                </div>
            </nav>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-avatar"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)); ?></div>
                    <div class="sidebar-user-info">
                        <h4><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></h4>
                        <p><?php echo htmlspecialchars($_SESSION['role'] ?? 'Administrator'); ?></p>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="top-header">
                <div class="header-left">
                    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
                    <div class="page-title">
                        <h1>Tenants Management</h1>
                        <p>View and manage dormitory tenants</p>
                    </div>
                </div>
                <div class="header-right">
                    <button class="header-icon-btn"><i class="fas fa-bell"></i><span class="notification-badge"></span></button>
                    <div class="header-avatar"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)); ?></div>
                </div>
            </header>

            <div class="page-content">
                <?php if ($flash): ?>
                    <div class="tn-flash <?php echo e($flash['type']); ?>"><?php echo e($flash['msg']); ?></div>
                <?php endif; ?>

                <!-- Tenant Stats (real data) -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                        <div class="stat-info"><h3><?php echo $total; ?></h3><p>Total Tenants</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                        <div class="stat-info"><h3><?php echo $activeCnt; ?></h3><p>Active Tenants</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-user-clock"></i></div>
                        <div class="stat-info"><h3><?php echo $pendingCnt; ?></h3><p>Pending Check-in</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="fas fa-user-times"></i></div>
                        <div class="stat-info"><h3><?php echo $inactiveCnt; ?></h3><p>Checked Out</p></div>
                    </div>
                </div>

                <!-- Tenants Table -->
                <div class="card">
                    <div class="card-header">
                        <div class="tn-toolbar" style="width:100%">
                            <h2><i class="fas fa-users"></i> &nbsp;All Tenants</h2>
                            <div style="display:flex;gap:10px;flex-wrap:wrap">
                                <input type="text" id="tnSearch" class="tn-search" placeholder="Search name, room, email...">
                                <button class="tn-btn" onclick="openAdd()"><i class="fas fa-plus"></i> Add Tenant</button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body" style="padding:0">
                        <div class="tn-table-wrap">
                        <table class="tn-table" id="tnTable">
                            <thead>
                                <tr>
                                    <th>Tenant</th><th>Room</th><th>Monthly Rent</th>
                                    <th>Move-in</th><th>Status</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$tenants): ?>
                                <tr><td colspan="6" style="text-align:center;padding:40px;color:#7a8496">No tenants yet. Click "Add Tenant" to create one.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($tenants as $t): ?>
                                <tr>
                                    <td><strong><?php echo e($t['full_name']); ?></strong><br><small><?php echo e($t['email']); ?></small></td>
                                    <td><strong><?php echo e($t['room_number'] ? 'Room ' . $t['room_number'] : '—'); ?></strong><br><small><?php echo e($t['room_type']); ?></small></td>
                                    <td>₱<?php echo number_format((float)$t['monthly_rent'], 2); ?></td>
                                    <td><?php echo $t['move_in_date'] ? date('M d, Y', strtotime($t['move_in_date'])) : '—'; ?></td>
                                    <td><span class="tn-badge <?php echo e($t['status']); ?>"><?php echo e($t['status']); ?></span></td>
                                    <td>
                                        <button class="tn-icon" title="Edit" onclick='openEdit(<?php echo json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'><i class="fas fa-pen"></i></button>
                                        <?php if ($t['status'] !== $inactiveStatus): ?>
                                        <button class="tn-icon warn" title="Check out" onclick="postAction('checkout', <?php echo (int)$t['id']; ?>, 'Check out this tenant?')"><i class="fas fa-right-from-bracket"></i></button>
                                        <?php endif; ?>
                                        <?php if (($_SESSION['role'] ?? '') === 'Dormitory Owner'): ?>
                                        <button class="tn-icon del" title="Delete" onclick="postAction('delete', <?php echo (int)$t['id']; ?>, 'Permanently delete this tenant and their login account?')"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add / Edit modal -->
    <div class="tn-modal" id="tnModal">
        <div class="tn-dialog">
            <h3 id="tnTitle">Add Tenant</h3>
            <form method="post" action="tenants.php" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo e($_SESSION['csrf']); ?>">
                <input type="hidden" name="action" id="fAction" value="add">
                <input type="hidden" name="id" id="fId" value="">
                <div class="tn-grid">
                    <div class="tn-field full"><label>Full name *</label><input type="text" name="full_name" id="fName" required></div>
                    <div class="tn-field"><label>Email *</label><input type="email" name="email" id="fEmail" required></div>
                    <div class="tn-field"><label>Phone</label><input type="text" name="phone" id="fPhone"></div>
                    <div class="tn-field" id="userBox"><label>Username *</label><input type="text" name="username" id="fUser"></div>
                    <div class="tn-field">
                        <label id="pwLabel">Password *</label>
                        <input type="password" name="password" id="fPass" minlength="6">
                        <div class="tn-hint" id="pwHint"></div>
                    </div>
                    <div class="tn-field">
                        <label>Room *</label>
                        <select name="room_id" id="fRoom" required onchange="fillRate()">
                            <option value="">Select room</option>
                            <?php foreach ($rooms as $r): ?>
                                <option value="<?php echo (int)$r['id']; ?>" data-rate="<?php echo e($r['monthly_rate']); ?>">
                                    Room <?php echo e($r['room_number']); ?> (<?php echo e($r['room_type']); ?>) - <?php echo e($r['status']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tn-field"><label>Monthly rent (₱) *</label><input type="number" step="0.01" min="0" name="monthly_rent" id="fRent" required></div>
                    <div class="tn-field"><label>Move-in date *</label><input type="date" name="move_in_date" id="fIn" required></div>
                    <div class="tn-field"><label>Move-out date</label><input type="date" name="move_out_date" id="fOut"></div>
                    <div class="tn-field"><label>Emergency contact</label><input type="text" name="ec_name" id="fEc1"></div>
                    <div class="tn-field"><label>Emergency phone</label><input type="text" name="ec_phone" id="fEc2"></div>
                    <div class="tn-field full">
                        <label>Status</label>
                        <select name="status" id="fStatus">
                            <?php foreach ($statuses as $s): ?>
                                <option value="<?php echo e($s); ?>"><?php echo e($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="tn-actions">
                    <button type="button" class="tn-btn gray" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="tn-btn"><i class="fas fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hidden form for check-out / delete -->
    <form method="post" action="tenants.php" id="actionForm" style="display:none">
        <input type="hidden" name="csrf" value="<?php echo e($_SESSION['csrf']); ?>">
        <input type="hidden" name="action" id="aAction">
        <input type="hidden" name="id" id="aId">
    </form>

    <script src="assets/js/script.js"></script>
    <script>
        const modal = document.getElementById('tnModal');
        const $ = id => document.getElementById(id);

        function openAdd() {
            $('tnTitle').textContent = 'Add Tenant';
            $('fAction').value = 'add'; $('fId').value = '';
            ['fName','fEmail','fPhone','fUser','fPass','fRent','fOut','fEc1','fEc2'].forEach(i => $(i).value = '');
            $('fRoom').value = ''; $('fIn').value = new Date().toISOString().slice(0,10);
            $('fStatus').value = 'Pending';
            $('userBox').style.display = ''; $('fUser').required = true;
            $('fPass').required = true; $('pwLabel').textContent = 'Password *';
            $('pwHint').textContent = 'At least 6 characters. The tenant uses this to log in.';
            modal.classList.add('open');
        }
        function openEdit(t) {
            $('tnTitle').textContent = 'Edit Tenant';
            $('fAction').value = 'edit'; $('fId').value = t.id;
            $('fName').value = t.full_name || ''; $('fEmail').value = t.email || '';
            $('fPhone').value = t.phone || ''; $('fPass').value = '';
            $('fRoom').value = t.room_id || ''; $('fRent').value = t.monthly_rent || '';
            $('fIn').value = t.move_in_date || ''; $('fOut').value = t.move_out_date || '';
            $('fEc1').value = t.ec_name || ''; $('fEc2').value = t.ec_phone || '';
            $('fStatus').value = t.status;
            $('userBox').style.display = 'none'; $('fUser').required = false;
            $('fPass').required = false; $('pwLabel').textContent = 'New password';
            $('pwHint').textContent = 'Leave blank to keep the current password.';
            modal.classList.add('open');
        }
        function closeModal() { modal.classList.remove('open'); }
        function fillRate() {
            const o = $('fRoom').selectedOptions[0];
            if (o && o.dataset.rate && !$('fRent').value) $('fRent').value = o.dataset.rate;
        }
        function postAction(action, id, msg) {
            if (!confirm(msg)) return;
            $('aAction').value = action; $('aId').value = id;
            $('actionForm').submit();
        }
        modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

        // Live search
        $('tnSearch').addEventListener('input', function () {
            const q = this.value.toLowerCase();
            document.querySelectorAll('#tnTable tbody tr').forEach(tr => {
                tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    </script>
</body>
</html>

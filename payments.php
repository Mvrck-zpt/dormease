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
function findEnum(array $vals, $prefix, $fallback) {
    foreach ($vals as $v) { if (stripos($v, $prefix) === 0) return $v; }
    return $fallback;
}
function money($v) { return number_format((float)$v, 2); }

// Only Owner and Caretaker may edit fee amounts
$canEditFees = in_array($_SESSION['role'] ?? '', ['Dormitory Owner', 'Caretaker'], true);

$methods  = enumValues($pdo, 'payments', 'payment_method');
$statuses = enumValues($pdo, 'payments', 'status');
$stPaid    = findEnum($statuses, 'Paid', 'Paid');
$stPending = findEnum($statuses, 'Pend', 'Pending');
$stOverdue = findEnum($statuses, 'Over', 'Overdue');

/* ---------- Handle form actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['type' => 'success', 'msg' => 'Done.'];
    try {
        if (!$canEditFees) throw new Exception('You do not have permission to modify payments.');
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
            throw new Exception('Invalid form token. Please reload the page and try again.');
        }
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            $tenant_id = (int)($_POST['tenant_id'] ?? 0);
            $rent      = round((float)($_POST['rent_fee'] ?? 0), 2);
            $wifi      = round((float)($_POST['wifi_fee'] ?? 0), 2);
            $water     = round((float)($_POST['water_fee'] ?? 0), 2);
            $electric  = round((float)($_POST['electric_fee'] ?? 0), 2);
            $month     = $_POST['pf_month'] ?? '';                 // YYYY-MM
            $pdate     = ($_POST['payment_date'] ?? '') !== '' ? $_POST['payment_date'] : null;
            $method    = $_POST['method'] ?? '';
            $ref       = trim($_POST['reference_no'] ?? '');
            $status    = $_POST['status'] ?? $stPending;
            $remarks   = trim($_POST['remarks'] ?? '');

            if ($rent < 0 || $wifi < 0 || $water < 0 || $electric < 0) {
                throw new Exception('Fee amounts cannot be negative.');
            }
            $amount = round($rent + $wifi + $water + $electric, 2);

            if ($tenant_id <= 0 || $amount <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
                throw new Exception('Tenant, at least one fee amount, and the month covered are required.');
            }
            if (!in_array($method, $methods, true)) throw new Exception('Invalid payment method.');
            if (!in_array($status, $statuses, true)) throw new Exception('Invalid status.');
            if ($status === $stPaid && $pdate === null) $pdate = date('Y-m-d');
            $payment_for = date('F Y', strtotime($month . '-01'));

            if ($action === 'add') {
                $pdo->prepare("INSERT INTO payments
                    (tenant_id, amount, rent_fee, wifi_fee, water_fee, electric_fee, payment_date, payment_for, payment_method, reference_no, status, remarks)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$tenant_id, $amount, $rent, $wifi, $water, $electric, $pdate, $payment_for, $method, $ref ?: null, $status, $remarks ?: null]);
                $flash['msg'] = 'Payment recorded.';
            } else {
                $pid = (int)($_POST['id'] ?? 0);
                $pdo->prepare("UPDATE payments SET
                    tenant_id=?, amount=?, rent_fee=?, wifi_fee=?, water_fee=?, electric_fee=?,
                    payment_date=?, payment_for=?, payment_method=?, reference_no=?, status=?, remarks=?
                    WHERE id=?")
                    ->execute([$tenant_id, $amount, $rent, $wifi, $water, $electric, $pdate, $payment_for, $method, $ref ?: null, $status, $remarks ?: null, $pid]);
                $flash['msg'] = 'Payment updated.';
            }

        } elseif ($action === 'markpaid') {
            $pid = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE payments SET status=?, payment_date=COALESCE(payment_date, CURDATE()) WHERE id=?")
                ->execute([$stPaid, $pid]);
            $flash['msg'] = 'Payment marked as paid.';

        } elseif ($action === 'delete') {
            if (($_SESSION['role'] ?? '') !== 'Dormitory Owner') {
                throw new Exception('Only the Dormitory Owner can delete payments.');
            }
            $pdo->prepare("DELETE FROM payments WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            $flash['msg'] = 'Payment deleted.';
        }
    } catch (PDOException $ex) {
        $flash = ['type' => 'error', 'msg' => 'Database error: ' . $ex->getMessage()];
    } catch (Exception $ex) {
        $flash = ['type' => 'error', 'msg' => $ex->getMessage()];
    }
    $_SESSION['flash'] = $flash;
    header('Location: payments.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- Load data ---------- */
$payments = $pdo->query("SELECT p.id, p.tenant_id, p.amount, p.rent_fee, p.wifi_fee, p.water_fee, p.electric_fee,
        p.payment_date, p.payment_for, p.payment_method AS method, p.reference_no, p.status, p.remarks,
        u.full_name, r.room_number
        FROM payments p
        JOIN tenants t ON t.id = p.tenant_id
        JOIN users u ON u.id = t.user_id
        LEFT JOIN rooms r ON r.id = t.room_id
        ORDER BY COALESCE(p.payment_date, DATE(p.created_at)) DESC, p.id DESC")->fetchAll();

foreach ($payments as &$p) {
    $ts = strtotime('1 ' . $p['payment_for']);
    $p['pf_month'] = $ts ? date('Y-m', $ts) : '';
}
unset($p);

$tenants = $pdo->query("SELECT t.id, t.monthly_rent, t.status, u.full_name, r.room_number
        FROM tenants t JOIN users u ON u.id=t.user_id LEFT JOIN rooms r ON r.id=t.room_id
        ORDER BY (t.status='Active') DESC, u.full_name")->fetchAll();

$totalCollected = 0; $cPaid = 0; $cPending = 0; $cOverdue = 0;
foreach ($payments as $p) {
    if ($p['status'] === $stPaid)    { $cPaid++; $totalCollected += (float)$p['amount']; }
    if ($p['status'] === $stPending) $cPending++;
    if ($p['status'] === $stOverdue) $cOverdue++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Payments</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .tn-toolbar{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
        .tn-search,.tn-filter{padding:10px 14px;border:1px solid #d5dbe5;border-radius:8px;font-size:14px;background:#fff}
        .tn-search{min-width:220px}
        .tn-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:0;border-radius:8px;background:#1a73e8;color:#fff;font-size:14px;font-weight:600;cursor:pointer}
        .tn-btn:hover{background:#1558b8}
        .tn-btn.gray{background:#e8ecf3;color:#333}
        .tn-btn.gray:hover{background:#d8deea}
        .tn-icon{border:0;background:#eef2f9;color:#1a73e8;width:34px;height:34px;border-radius:8px;cursor:pointer;margin-right:4px}
        .tn-icon.ok{color:#1e8e3e;background:#e3f6e8}
        .tn-icon.del{color:#d93025;background:#fdecea}
        .tn-table-wrap{overflow-x:auto}
        .tn-table{width:100%;border-collapse:collapse;font-size:14px}
        .tn-table th{text-align:left;padding:12px 16px;background:#f5f7fb;color:#5a6577;font-size:12px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
        .tn-table td{padding:14px 16px;border-top:1px solid #edf0f5;vertical-align:middle}
        .tn-table small{color:#7a8496}
        .fee-breakdown{font-size:11px;color:#8892a3;line-height:1.5;margin-top:2px}
        .tn-badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:#e8ecf3;color:#555}
        .tn-badge.paid{background:#e3f6e8;color:#1e8e3e}
        .tn-badge.pend{background:#fff4d6;color:#b06f00}
        .tn-badge.over{background:#fdecea;color:#d93025}
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
        .tn-field input,.tn-field select,.tn-field textarea{width:100%;padding:10px 12px;border:1px solid #d5dbe5;border-radius:8px;font-size:14px;box-sizing:border-box;font-family:inherit}
        .fee-section{background:#f7f9fc;border:1px solid #e5e9f0;border-radius:10px;padding:14px;grid-column:1/-1}
        .fee-section-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#5a6577;margin-bottom:10px}
        .fee-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
        .fee-row .tn-field label{font-weight:500;font-size:12px;color:#5a6577}
        .fee-total{display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding-top:12px;border-top:1px dashed #d5dbe5;font-weight:700;font-size:15px}
        .fee-total span.val{color:#1a73e8;font-size:18px}
        .tn-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
        .tn-hint{font-size:12px;color:#7a8496;margin-top:4px}
        @media(max-width:600px){.tn-grid{grid-template-columns:1fr}.fee-row{grid-template-columns:1fr 1fr}}
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
                        <h1>Payments Management</h1>
                        <p>Track and manage tenant payments</p>
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

                <!-- Payment Stats (real data) -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-peso-sign"></i></div>
                        <div class="stat-info"><h3>₱<?php echo money($totalCollected); ?></h3><p>Total Collections</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-check-double"></i></div>
                        <div class="stat-info"><h3><?php echo $cPaid; ?></h3><p>Paid</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                        <div class="stat-info"><h3><?php echo $cPending; ?></h3><p>Pending</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="fas fa-exclamation-circle"></i></div>
                        <div class="stat-info"><h3><?php echo $cOverdue; ?></h3><p>Overdue</p></div>
                    </div>
                </div>

                <!-- Payments Table -->
                <div class="card">
                    <div class="card-header">
                        <div class="tn-toolbar" style="width:100%">
                            <h2><i class="fas fa-money-bill-wave"></i> &nbsp;All Payments</h2>
                            <div style="display:flex;gap:10px;flex-wrap:wrap">
                                <input type="text" id="pmSearch" class="tn-search" placeholder="Search tenant, room, month...">
                                <select id="pmFilter" class="tn-filter">
                                    <option value="">All statuses</option>
                                    <?php foreach ($statuses as $s): ?><option value="<?php echo e($s); ?>"><?php echo e($s); ?></option><?php endforeach; ?>
                                </select>
                                <?php if ($canEditFees): ?>
                                <button class="tn-btn" onclick="openAdd()"><i class="fas fa-plus"></i> Record Payment</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-body" style="padding:0">
                        <div class="tn-table-wrap">
                        <table class="tn-table" id="pmTable">
                            <thead>
                                <tr>
                                    <th>Tenant</th><th>Amount</th><th>Period</th><th>Date Paid</th>
                                    <th>Method</th><th>Reference</th><th>Status</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$payments): ?>
                                <tr><td colspan="8" style="text-align:center;padding:40px;color:#7a8496">No payments yet. Click "Record Payment" to add one.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($payments as $p):
                                $cls = $p['status'] === $stPaid ? 'paid' : ($p['status'] === $stOverdue ? 'over' : 'pend'); ?>
                                <tr data-status="<?php echo e($p['status']); ?>">
                                    <td><strong><?php echo e($p['full_name']); ?></strong><br><small><?php echo e($p['room_number'] ? 'Room ' . $p['room_number'] : ''); ?></small></td>
                                    <td>
                                        <strong>₱<?php echo money($p['amount']); ?></strong>
                                        <div class="fee-breakdown">
                                            Rent ₱<?php echo money($p['rent_fee']); ?> · WiFi ₱<?php echo money($p['wifi_fee']); ?><br>
                                            Water ₱<?php echo money($p['water_fee']); ?> · Elec ₱<?php echo money($p['electric_fee']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo e($p['payment_for']); ?></td>
                                    <td><?php echo $p['payment_date'] ? date('M d, Y', strtotime($p['payment_date'])) : '—'; ?></td>
                                    <td><?php echo e($p['method']); ?></td>
                                    <td><?php echo e($p['reference_no'] ?: '—'); ?></td>
                                    <td><span class="tn-badge <?php echo $cls; ?>"><?php echo e($p['status']); ?></span></td>
                                    <td style="white-space:nowrap">
                                        <?php if ($canEditFees): ?>
                                        <button class="tn-icon" title="Edit" onclick='openEdit(<?php echo json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'><i class="fas fa-pen"></i></button>
                                        <?php if ($p['status'] !== $stPaid): ?>
                                        <button class="tn-icon ok" title="Mark as paid" onclick="postAction('markpaid', <?php echo (int)$p['id']; ?>, 'Mark this payment as paid today?')"><i class="fas fa-check"></i></button>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (($_SESSION['role'] ?? '') === 'Dormitory Owner'): ?>
                                        <button class="tn-icon del" title="Delete" onclick="postAction('delete', <?php echo (int)$p['id']; ?>, 'Permanently delete this payment record?')"><i class="fas fa-trash"></i></button>
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

    <?php if ($canEditFees): ?>
    <!-- Add / Edit modal -->
    <div class="tn-modal" id="pmModal">
        <div class="tn-dialog">
            <h3 id="pmTitle">Record Payment</h3>
            <form method="post" action="payments.php" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo e($_SESSION['csrf']); ?>">
                <input type="hidden" name="action" id="fAction" value="add">
                <input type="hidden" name="id" id="fId" value="">
                <div class="tn-grid">
                    <div class="tn-field full">
                        <label>Tenant *</label>
                        <select name="tenant_id" id="fTenant" required onchange="fillRent()">
                            <option value="">Select tenant</option>
                            <?php foreach ($tenants as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" data-rent="<?php echo e($t['monthly_rent']); ?>">
                                    <?php echo e($t['full_name']); ?><?php echo $t['room_number'] ? ' - Room ' . e($t['room_number']) : ''; ?><?php echo $t['status'] !== 'Active' ? ' (' . e($t['status']) . ')' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fee-section">
                        <div class="fee-section-title">Charges breakdown</div>
                        <div class="fee-row">
                            <div class="tn-field"><label>Rent (₱)</label><input type="number" step="0.01" min="0" name="rent_fee" id="fRent" value="0" oninput="calcTotal()"></div>
                            <div class="tn-field"><label>WiFi (₱)</label><input type="number" step="0.01" min="0" name="wifi_fee" id="fWifi" value="0" oninput="calcTotal()"></div>
                            <div class="tn-field"><label>Water (₱)</label><input type="number" step="0.01" min="0" name="water_fee" id="fWater" value="0" oninput="calcTotal()"></div>
                            <div class="tn-field"><label>Electric (₱)</label><input type="number" step="0.01" min="0" name="electric_fee" id="fElectric" value="0" oninput="calcTotal()"></div>
                        </div>
                        <div class="fee-total"><span>Total</span><span class="val" id="fTotalDisplay">₱0.00</span></div>
                    </div>

                    <div class="tn-field"><label>Month covered *</label><input type="month" name="pf_month" id="fMonth" required></div>
                    <div class="tn-field">
                        <label>Payment method *</label>
                        <select name="method" id="fMethod" required>
                            <?php foreach ($methods as $m): ?><option value="<?php echo e($m); ?>"><?php echo e($m); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tn-field"><label>Reference no.</label><input type="text" name="reference_no" id="fRef" maxlength="50" placeholder="GCash / bank ref"></div>
                    <div class="tn-field">
                        <label>Status</label>
                        <select name="status" id="fStatus">
                            <?php foreach ($statuses as $s): ?><option value="<?php echo e($s); ?>"><?php echo e($s); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tn-field full">
                        <label>Date paid</label>
                        <input type="date" name="payment_date" id="fDate">
                        <div class="tn-hint">Left blank for unpaid. Auto-set to today when Paid.</div>
                    </div>
                    <div class="tn-field full"><label>Remarks</label><textarea name="remarks" id="fRemarks" rows="2"></textarea></div>
                </div>
                <div class="tn-actions">
                    <button type="button" class="tn-btn gray" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="tn-btn"><i class="fas fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hidden form for mark-paid / delete -->
    <form method="post" action="payments.php" id="actionForm" style="display:none">
        <input type="hidden" name="csrf" value="<?php echo e($_SESSION['csrf']); ?>">
        <input type="hidden" name="action" id="aAction">
        <input type="hidden" name="id" id="aId">
    </form>
    <?php endif; ?>

    <script src="assets/js/script.js"></script>
    <script>
        const modal = document.getElementById('pmModal');
        const $ = id => document.getElementById(id);
        const PAID = <?php echo json_encode($stPaid); ?>;

        function calcTotal() {
            const v = id => parseFloat($(id).value) || 0;
            const total = v('fRent') + v('fWifi') + v('fWater') + v('fElectric');
            $('fTotalDisplay').textContent = '₱' + total.toFixed(2);
        }

        function openAdd() {
            $('pmTitle').textContent = 'Record Payment';
            $('fAction').value = 'add'; $('fId').value = '';
            $('fTenant').value = ''; $('fRef').value = '';
            $('fRemarks').value = ''; $('fDate').value = '';
            $('fRent').value = 0; $('fWifi').value = 0; $('fWater').value = 0; $('fElectric').value = 0;
            $('fMonth').value = new Date().toISOString().slice(0,7);
            $('fStatus').value = PAID; $('fDate').value = new Date().toISOString().slice(0,10);
            $('fMethod').selectedIndex = 0;
            calcTotal();
            modal.classList.add('open');
        }
        function openEdit(p) {
            $('pmTitle').textContent = 'Edit Payment';
            $('fAction').value = 'edit'; $('fId').value = p.id;
            $('fTenant').value = p.tenant_id;
            $('fRent').value = p.rent_fee; $('fWifi').value = p.wifi_fee;
            $('fWater').value = p.water_fee; $('fElectric').value = p.electric_fee;
            $('fMonth').value = p.pf_month || ''; $('fMethod').value = p.method;
            $('fRef').value = p.reference_no || ''; $('fStatus').value = p.status;
            $('fDate').value = p.payment_date || ''; $('fRemarks').value = p.remarks || '';
            calcTotal();
            modal.classList.add('open');
        }
        function closeModal() { modal.classList.remove('open'); }
        function fillRent() {
            const o = $('fTenant').selectedOptions[0];
            if (o && o.dataset.rent && parseFloat($('fRent').value) === 0) {
                $('fRent').value = o.dataset.rent;
                calcTotal();
            }
        }
        function postAction(action, id, msg) {
            if (!confirm(msg)) return;
            $('aAction').value = action; $('aId').value = id;
            $('actionForm').submit();
        }
        if (modal) {
            modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
            document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
        }

        // Search + status filter
        function applyFilter() {
            const q = $('pmSearch').value.toLowerCase(), s = $('pmFilter').value;
            document.querySelectorAll('#pmTable tbody tr[data-status]').forEach(tr => {
                const okText = tr.textContent.toLowerCase().includes(q);
                const okStatus = !s || tr.dataset.status === s;
                tr.style.display = (okText && okStatus) ? '' : 'none';
            });
        }
        $('pmSearch').addEventListener('input', applyFilter);
        $('pmFilter').addEventListener('change', applyFilter);
    </script>
</body>
</html>

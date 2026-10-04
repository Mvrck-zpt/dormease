<?php
require_once 'includes/auth.php';
requireRole(['Dormitory Owner', 'Caretaker']);
$user = getCurrentUser();
$page = getCurrentPage();

/* ---------- Database (same Laragon defaults as payments.php) ---------- */
$DB_HOST = 'localhost'; $DB_NAME = 'dormease_db'; $DB_USER = 'root'; $DB_PASS = '';
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v) { return number_format((float)$v, 2); }
function periodTs($pf) { $t = strtotime('1 ' . $pf); return $t ?: null; }

/* ---------- Report registry ---------- */
$reports = [
    'revenue'     => ['Revenue Report',     'fa-file-invoice-dollar', 'Monthly and yearly revenue breakdown with charts and summaries'],
    'occupancy'   => ['Occupancy Report',   'fa-bed',                 'Room occupancy rates, availability, and utilization stats'],
    'tenants'     => ['Tenant Report',      'fa-users',               'Tenant list with rooms, rent, move-in/out dates, and status'],
    'overdue'     => ['Overdue Payments',   'fa-exclamation-triangle','Overdue payments with aging analysis and remarks'],
    'maintenance' => ['Maintenance Report', 'fa-tools',               'Rooms currently under maintenance and the rent on hold'],
    'summary'     => ['Summary Report',     'fa-chart-pie',           'Overview of dormitory operations and key figures'],
];
$r = $_GET['r'] ?? '';
if (!isset($reports[$r])) $r = '';

$year    = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$tstatus = $_GET['tstatus'] ?? '';
if (!in_array($tstatus, ['Active', 'Inactive', 'Pending'], true)) $tstatus = '';

$stats = [];      // [label, value, color, icon]
$headers = []; $rows = []; $footer = [];
$chart = [];      // [label => value] for revenue bars
$note = '';

if ($r === 'revenue') {
    $m = [];
    for ($i = 1; $i <= 12; $i++) $m[$i] = ['paid'=>0,'rent'=>0,'wifi'=>0,'water'=>0,'elec'=>0,'total'=>0,'unpaid'=>0];
    $q = $pdo->query("SELECT amount, rent_fee, wifi_fee, water_fee, electric_fee, status, payment_for FROM payments")->fetchAll();
    foreach ($q as $p) {
        $ts = periodTs($p['payment_for']);
        if (!$ts || (int)date('Y', $ts) !== $year) continue;
        $k = (int)date('n', $ts);
        if ($p['status'] === 'Paid') {
            $m[$k]['paid']++;
            $m[$k]['rent']  += $p['rent_fee'];
            $m[$k]['wifi']  += $p['wifi_fee'];
            $m[$k]['water'] += $p['water_fee'];
            $m[$k]['elec']  += $p['electric_fee'];
            $m[$k]['total'] += $p['amount'];
        } else {
            $m[$k]['unpaid'] += $p['amount'];
        }
    }
    $headers = ['Month','Paid','Rent (₱)','Wi-Fi (₱)','Water (₱)','Electric (₱)','Not itemized (₱)','Collected (₱)','Uncollected (₱)'];
    $tot = ['paid'=>0,'rent'=>0,'wifi'=>0,'water'=>0,'elec'=>0,'other'=>0,'total'=>0,'unpaid'=>0];
    $best = ['', 0]; $active = 0;
    foreach ($m as $k => $d) {
        $other = $d['total'] - ($d['rent'] + $d['wifi'] + $d['water'] + $d['elec']);
        $name = date('F', mktime(0, 0, 0, $k, 1));
        $rows[] = [$name, $d['paid'], money($d['rent']), money($d['wifi']), money($d['water']), money($d['elec']),
                   money($other), money($d['total']), money($d['unpaid'])];
        $chart[date('M', mktime(0, 0, 0, $k, 1))] = $d['total'];
        foreach (['paid','rent','wifi','water','elec','total','unpaid'] as $f) $tot[$f] += $d[$f];
        $tot['other'] += $other;
        if ($d['total'] > $best[1]) $best = [$name, $d['total']];
        if ($d['total'] > 0) $active++;
    }
    $footer = ['Total', $tot['paid'], money($tot['rent']), money($tot['wifi']), money($tot['water']),
               money($tot['elec']), money($tot['other']), money($tot['total']), money($tot['unpaid'])];
    $stats = [
        ['Collected in ' . $year, '₱' . money($tot['total']), 'blue', 'fa-peso-sign'],
        ['Uncollected (pending + overdue)', '₱' . money($tot['unpaid']), 'red', 'fa-exclamation-circle'],
        ['Average per active month', '₱' . money($active ? $tot['total'] / $active : 0), 'green', 'fa-chart-line'],
        ['Best month', $best[1] > 0 ? $best[0] : '—', 'orange', 'fa-trophy'],
    ];
}

if ($r === 'occupancy') {
    $q = $pdo->query("SELECT r.room_number, r.floor, r.room_type, r.capacity, r.monthly_rate, r.status,
        (SELECT COUNT(*) FROM tenants t WHERE t.room_id = r.id AND t.status = 'Active') AS occ
        FROM rooms r ORDER BY r.floor, r.room_number")->fetchAll();
    $headers = ['Room','Floor','Type','Capacity','Tenants','Bed occupancy','Monthly rate (₱)','Status'];
    $cap = 0; $occ = 0; $cnt = ['Available'=>0,'Occupied'=>0,'Under Maintenance'=>0];
    foreach ($q as $x) {
        $pct = $x['capacity'] > 0 ? round($x['occ'] / $x['capacity'] * 100) : 0;
        $rows[] = [$x['room_number'], $x['floor'], $x['room_type'], $x['capacity'], $x['occ'], $pct . '%', money($x['monthly_rate']), $x['status']];
        $cap += $x['capacity']; $occ += $x['occ'];
        if (isset($cnt[$x['status']])) $cnt[$x['status']]++;
    }
    $footer = ['Total', '', '', $cap, $occ, ($cap ? round($occ / $cap * 100) : 0) . '%', '', ''];
    $stats = [
        ['Total rooms', count($q), 'blue', 'fa-door-open'],
        ['Occupied rooms', $cnt['Occupied'], 'green', 'fa-bed'],
        ['Available rooms', $cnt['Available'], 'orange', 'fa-door-closed'],
        ['Under maintenance', $cnt['Under Maintenance'], 'red', 'fa-tools'],
    ];
}

if ($r === 'tenants') {
    $sql = "SELECT u.full_name, u.phone, u.email, r.room_number, t.monthly_rent, t.move_in_date, t.move_out_date, t.status
            FROM tenants t JOIN users u ON u.id = t.user_id LEFT JOIN rooms r ON r.id = t.room_id";
    $args = [];
    if ($tstatus !== '') { $sql .= " WHERE t.status = ?"; $args[] = $tstatus; }
    $sql .= " ORDER BY (t.status = 'Active') DESC, u.full_name";
    $st = $pdo->prepare($sql); $st->execute($args); $q = $st->fetchAll();
    $headers = ['Tenant','Room','Phone','Email','Monthly rent (₱)','Move-in','Move-out','Status'];
    $rent = 0; $act = 0;
    foreach ($q as $x) {
        $rows[] = [$x['full_name'], $x['room_number'] ?? '—', $x['phone'] ?? '—', $x['email'],
                   money($x['monthly_rent']), date('M d, Y', strtotime($x['move_in_date'])),
                   $x['move_out_date'] ? date('M d, Y', strtotime($x['move_out_date'])) : '—', $x['status']];
        if ($x['status'] === 'Active') { $act++; $rent += $x['monthly_rent']; }
    }
    $stats = [
        ['Tenants listed', count($q), 'blue', 'fa-users'],
        ['Active', $act, 'green', 'fa-user-check'],
        ['Not active', count($q) - $act, 'orange', 'fa-user-clock'],
        ['Monthly rent (active)', '₱' . money($rent), 'blue', 'fa-peso-sign'],
    ];
}

if ($r === 'overdue') {
    $q = $pdo->query("SELECT p.amount, p.payment_for, p.remarks, u.full_name, r.room_number
        FROM payments p JOIN tenants t ON t.id = p.tenant_id JOIN users u ON u.id = t.user_id
        LEFT JOIN rooms r ON r.id = t.room_id WHERE p.status = 'Overdue' ORDER BY p.id")->fetchAll();
    $headers = ['Tenant','Room','Period','Amount (₱)','Days since period start','Aging','Remarks'];
    $total = 0; $old = 0; $who = [];
    foreach ($q as $x) {
        $ts = periodTs($x['payment_for']);
        $days = $ts ? max(0, (int)floor((time() - $ts) / 86400)) : 0;
        $age = $days <= 30 ? '0–30 days' : ($days <= 60 ? '31–60 days' : '61+ days');
        $rows[] = [$x['full_name'], $x['room_number'] ?? '—', $x['payment_for'], money($x['amount']), $days, $age, $x['remarks'] ?? ''];
        $total += $x['amount']; if ($days > 60) $old++; $who[$x['full_name']] = 1;
    }
    $footer = ['Total', '', '', money($total), '', '', ''];
    $stats = [
        ['Overdue payments', count($q), 'red', 'fa-exclamation-circle'],
        ['Total overdue', '₱' . money($total), 'red', 'fa-peso-sign'],
        ['Tenants affected', count($who), 'orange', 'fa-user-clock'],
        ['Older than 60 days', $old, 'orange', 'fa-hourglass-end'],
    ];
    $note = 'Days are counted from the first day of the month the payment covers.';
}

if ($r === 'maintenance') {
    $q = $pdo->query("SELECT room_number, floor, room_type, monthly_rate, description FROM rooms
        WHERE status = 'Under Maintenance' ORDER BY floor, room_number")->fetchAll();
    $headers = ['Room','Floor','Type','Monthly rate (₱)','Notes'];
    $hold = 0;
    foreach ($q as $x) { $rows[] = [$x['room_number'], $x['floor'], $x['room_type'], money($x['monthly_rate']), $x['description'] ?? '']; $hold += $x['monthly_rate']; }
    $footer = ['Total', '', '', money($hold), ''];
    $stats = [
        ['Rooms under maintenance', count($q), 'orange', 'fa-tools'],
        ['Monthly rent on hold', '₱' . money($hold), 'red', 'fa-peso-sign'],
    ];
    $note = 'DormEase does not yet store maintenance requests or repair costs, so this report only lists rooms marked "Under Maintenance". Cost tracking needs a new maintenance table.';
}

if ($r === 'summary') {
    $roomsT = (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
    $byStatus = $pdo->query("SELECT status, COUNT(*) c FROM rooms GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $capacity = (int)$pdo->query("SELECT COALESCE(SUM(capacity),0) FROM rooms")->fetchColumn();
    $actT = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'Active'")->fetchColumn();
    $rentActive = (float)$pdo->query("SELECT COALESCE(SUM(monthly_rent),0) FROM tenants WHERE status = 'Active'")->fetchColumn();
    $paid = 0; $pend = 0; $over = 0; $thisMonth = 0; $curLabel = date('F Y');
    foreach ($pdo->query("SELECT amount, status, payment_for FROM payments")->fetchAll() as $p) {
        $ts = periodTs($p['payment_for']);
        if (!$ts || (int)date('Y', $ts) !== (int)date('Y')) continue;
        if ($p['status'] === 'Paid') { $paid += $p['amount']; if ($p['payment_for'] === $curLabel) $thisMonth += $p['amount']; }
        elseif ($p['status'] === 'Pending') $pend += $p['amount'];
        elseif ($p['status'] === 'Overdue') $over += $p['amount'];
    }
    $billed = $paid + $pend + $over;
    $occRate = $capacity ? round($actT / $capacity * 100) : 0;
    $colRate = $billed ? round($paid / $billed * 100) : 0;
    $headers = ['Measure', 'Value'];
    $rows = [
        ['Total rooms', $roomsT],
        ['Occupied rooms', $byStatus['Occupied'] ?? 0],
        ['Available rooms', $byStatus['Available'] ?? 0],
        ['Rooms under maintenance', $byStatus['Under Maintenance'] ?? 0],
        ['Bed occupancy (active tenants / total capacity)', $occRate . '%'],
        ['Active tenants', $actT],
        ['Expected monthly rent from active tenants (₱)', money($rentActive)],
        ['Collected in ' . date('Y') . ' (₱)', money($paid)],
        ['Collected for ' . $curLabel . ' (₱)', money($thisMonth)],
        ['Pending in ' . date('Y') . ' (₱)', money($pend)],
        ['Overdue in ' . date('Y') . ' (₱)', money($over)],
        ['Collection rate in ' . date('Y'), $colRate . '%'],
    ];
    $stats = [
        ['Bed occupancy', $occRate . '%', 'blue', 'fa-bed'],
        ['Active tenants', $actT, 'green', 'fa-users'],
        ['Collected this year', '₱' . money($paid), 'blue', 'fa-peso-sign'],
        ['Overdue this year', '₱' . money($over), 'red', 'fa-exclamation-circle'],
    ];
}

/* ---------- CSV export (before any HTML output) ---------- */
if ($r !== '' && isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dormease_' . $r . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    if ($footer) fputcsv($out, $footer);
    fclose($out);
    exit;
}

function badgeClass($v) {
    $v = strtolower((string)$v);
    if (in_array($v, ['active', 'occupied'], true)) return 'paid';
    if (in_array($v, ['pending', 'available', 'inactive'], true)) return 'pend';
    if (in_array($v, ['overdue', 'under maintenance'], true)) return 'over';
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Reports</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        a.report-card{display:block;text-decoration:none;color:inherit;cursor:pointer}
        .tn-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:0;border-radius:8px;background:#1a73e8;color:#fff;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none}
        .tn-btn:hover{background:#1558b8}
        .tn-btn.gray{background:#e8ecf3;color:#333}
        .tn-btn.gray:hover{background:#d8deea}
        .tn-filter{padding:9px 14px;border:1px solid #d5dbe5;border-radius:8px;font-size:14px;background:#fff}
        .rp-bar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:20px}
        .rp-bar form,.rp-bar .rp-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .tn-table-wrap{overflow-x:auto}
        .tn-table{width:100%;border-collapse:collapse;font-size:14px}
        .tn-table th{text-align:left;padding:12px 16px;background:#f5f7fb;color:#5a6577;font-size:12px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
        .tn-table td{padding:12px 16px;border-top:1px solid #edf0f5;vertical-align:middle}
        .tn-table tfoot td{font-weight:700;background:#f5f7fb}
        .tn-badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:#e8ecf3;color:#555}
        .tn-badge.paid{background:#e3f6e8;color:#1e8e3e}
        .tn-badge.pend{background:#fff4d6;color:#b06f00}
        .tn-badge.over{background:#fdecea;color:#d93025}
        .rp-note{font-size:13px;color:#7a8496;margin-top:14px}
        .rp-chart{display:flex;align-items:flex-end;gap:10px;height:170px;padding:16px 8px 0}
        .rp-col{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;font-size:11px;color:#5a6577}
        .rp-bar-fill{width:100%;max-width:44px;background:#1a73e8;border-radius:6px 6px 0 0;min-height:2px}
        .rp-title{margin:0 0 4px;font-size:16px}
        .print-only{display:none}
        @media print{
            .sidebar,.top-header,.no-print,.sidebar-overlay{display:none!important}
            .main-content{margin-left:0!important;width:100%!important}
            .print-only{display:block;margin-bottom:12px}
            body{background:#fff}
        }
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
                        <h1><?php echo $r ? e($reports[$r][0]) : 'Reports'; ?></h1>
                        <p><?php echo $r ? e($reports[$r][2]) : 'Generate and view dormitory reports'; ?></p>
                    </div>
                </div>
                <div class="header-right">
                    <button class="header-icon-btn"><i class="fas fa-bell"></i><span class="notification-badge"></span></button>
                    <div class="header-avatar"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)); ?></div>
                </div>
            </header>

            <div class="page-content">
<?php if ($r === ''): ?>
                <div class="report-cards">
<?php foreach ($reports as $key => $rep): ?>
                    <a class="report-card" href="reports.php?r=<?php echo $key; ?>">
                        <div class="icon"><i class="fas <?php echo $rep[1]; ?>"></i></div>
                        <h3><?php echo e($rep[0]); ?></h3>
                        <p><?php echo e($rep[2]); ?></p>
                    </a>
<?php endforeach; ?>
                </div>
<?php else: ?>
                <div class="print-only">
                    <h2><?php echo APP_NAME; ?> – <?php echo e($reports[$r][0]); ?></h2>
                    <p>Generated <?php echo date('F j, Y g:i A'); ?><?php echo $r === 'revenue' ? ' · Year ' . $year : ''; ?></p>
                </div>

                <div class="rp-bar no-print">
                    <form method="get">
                        <input type="hidden" name="r" value="<?php echo e($r); ?>">
                        <a class="tn-btn gray" href="reports.php"><i class="fas fa-arrow-left"></i> All reports</a>
<?php if ($r === 'revenue'): ?>
                        <select class="tn-filter" name="year" onchange="this.form.submit()">
<?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
<?php endfor; ?>
                        </select>
<?php elseif ($r === 'tenants'): ?>
                        <select class="tn-filter" name="tstatus" onchange="this.form.submit()">
                            <option value="">All statuses</option>
<?php foreach (['Active', 'Inactive', 'Pending'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $tstatus === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
<?php endforeach; ?>
                        </select>
<?php endif; ?>
                    </form>
                    <div class="rp-actions">
                        <a class="tn-btn gray" href="<?php echo e('reports.php?' . http_build_query(array_merge($_GET, ['export' => 1]))); ?>"><i class="fas fa-file-csv"></i> Export CSV</a>
                        <button class="tn-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                    </div>
                </div>

                <div class="stats-grid">
<?php foreach ($stats as $s): ?>
                    <div class="stat-card">
                        <div class="stat-icon <?php echo $s[2]; ?>"><i class="fas <?php echo $s[3]; ?>"></i></div>
                        <div class="stat-info"><h3><?php echo e($s[1]); ?></h3><p><?php echo e($s[0]); ?></p></div>
                    </div>
<?php endforeach; ?>
                </div>

<?php if ($chart): $max = max($chart) ?: 1; ?>
                <div class="card" style="margin-bottom:20px">
                    <div class="card-body">
                        <h3 class="rp-title">Collected per month, <?php echo $year; ?></h3>
                        <div class="rp-chart">
<?php foreach ($chart as $lbl => $val): ?>
                            <div class="rp-col" title="₱<?php echo money($val); ?>">
                                <div class="rp-bar-fill" style="height:<?php echo round($val / $max * 85); ?>%"></div>
                                <span style="margin-top:6px"><?php echo $lbl; ?></span>
                            </div>
<?php endforeach; ?>
                        </div>
                    </div>
                </div>
<?php endif; ?>

                <div class="card">
                    <div class="card-body" style="padding:0">
                        <div class="tn-table-wrap">
                            <table class="tn-table">
                                <thead><tr>
<?php foreach ($headers as $h): ?>
                                    <th><?php echo e($h); ?></th>
<?php endforeach; ?>
                                </tr></thead>
                                <tbody>
<?php if (!$rows): ?>
                                    <tr><td colspan="<?php echo count($headers); ?>" style="text-align:center;padding:40px;color:#7a8496">Nothing to show for this report right now.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $row): ?>
                                    <tr>
<?php foreach ($row as $i => $cell): ?>
<?php if ($headers[$i] === 'Status' && $cell !== ''): ?>
                                        <td><span class="tn-badge <?php echo badgeClass($cell); ?>"><?php echo e($cell); ?></span></td>
<?php else: ?>
                                        <td><?php echo e($cell); ?></td>
<?php endif; ?>
<?php endforeach; ?>
                                    </tr>
<?php endforeach; ?>
                                </tbody>
<?php if ($footer && $rows): ?>
                                <tfoot><tr>
<?php foreach ($footer as $cell): ?>
                                    <td><?php echo e($cell); ?></td>
<?php endforeach; ?>
                                </tr></tfoot>
<?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
<?php if ($note): ?>
                <p class="rp-note"><?php echo e($note); ?></p>
<?php endif; ?>
<?php endif; ?>
            </div>
        </main>
    </div>

    <script src="assets/js/script.js"></script>
</body>
</html>

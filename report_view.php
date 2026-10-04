<?php
// ================================================================
// DormEase – Report Viewer
// Single page that renders any of the 6 report types.
// All data from real DB queries. Supports Print + CSV export.
// Access restricted to Dormitory Owner and Caretaker.
// ================================================================
require_once 'includes/auth.php';
requireRole(['Dormitory Owner', 'Caretaker']);

$user = getCurrentUser();
$page = 'reports';

// ── Validate report type ───────────────────────────────────────
$validTypes = ['revenue','occupancy','tenant','overdue','maintenance','summary'];
$type       = in_array($_GET['type'] ?? '', $validTypes) ? $_GET['type'] : 'summary';

// ── Filters ────────────────────────────────────────────────────
$filterYear   = (int)($_GET['year']   ?? date('Y'));
$filterStatus = trim($_GET['status']  ?? '');      // for tenant report

// ── Allowed years for the Revenue year-filter (last 5 years) ──
$currentYear = (int)date('Y');
$yearRange   = range($currentYear, $currentYear - 4);

// ── CSV export: output before any HTML ────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    $safeType = ucfirst($type);
    header("Content-Disposition: attachment; filename=\"DormEase_{$safeType}_Report_" . date('Ymd') . ".csv\"");
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
}

// ================================================================
// DATA QUERIES  (run for every request — both HTML and CSV)
// ================================================================

// ── 1. REVENUE ─────────────────────────────────────────────────
if ($type === 'revenue') {
    // Summary stats for the selected year
    $stmtRevYear = $conn->prepare("
        SELECT
            COALESCE(SUM(amount),0)                                AS total_collected,
            COALESCE(SUM(CASE WHEN status='Paid'    THEN amount END),0) AS paid,
            COALESCE(SUM(CASE WHEN status='Pending' THEN amount END),0) AS pending,
            COALESCE(SUM(CASE WHEN status='Overdue' THEN amount END),0) AS overdue,
            COUNT(*) AS total_records
        FROM payments
        WHERE YEAR(payment_date) = ? OR (status != 'Paid' AND YEAR(created_at) = ?)
    ");
    $stmtRevYear->bind_param("ii", $filterYear, $filterYear);
    $stmtRevYear->execute();
    $revStats = $stmtRevYear->get_result()->fetch_assoc();
    $stmtRevYear->close();

    // Monthly breakdown for the selected year
    $stmtMonthly = $conn->prepare("
        SELECT
            MONTH(payment_date)  AS month_num,
            MONTHNAME(payment_date) AS month_name,
            COUNT(*)             AS txn_count,
            COALESCE(SUM(CASE WHEN status='Paid' THEN amount END),0)    AS paid,
            COALESCE(SUM(CASE WHEN status='Pending' THEN amount END),0) AS pending,
            COALESCE(SUM(CASE WHEN status='Overdue' THEN amount END),0) AS overdue,
            COALESCE(SUM(rent_fee),0)     AS rent_total,
            COALESCE(SUM(wifi_fee),0)     AS wifi_total,
            COALESCE(SUM(water_fee),0)    AS water_total,
            COALESCE(SUM(electric_fee),0) AS electric_total
        FROM payments
        WHERE YEAR(payment_date) = ?
        GROUP BY MONTH(payment_date), MONTHNAME(payment_date)
        ORDER BY MONTH(payment_date)
    ");
    $stmtMonthly->bind_param("i", $filterYear);
    $stmtMonthly->execute();
    $monthlyRows = $stmtMonthly->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtMonthly->close();

    // Detailed payment rows
    $stmtRevDetail = $conn->prepare("
        SELECT
            p.payment_for, p.payment_date, p.payment_method,
            p.rent_fee, p.wifi_fee, p.water_fee, p.electric_fee, p.amount,
            p.status, p.reference_no,
            u.full_name, r.room_number
        FROM payments p
        JOIN tenants t ON p.tenant_id = t.id
        JOIN users   u ON t.user_id   = u.id
        JOIN rooms   r ON t.room_id   = r.id
        WHERE YEAR(p.payment_date) = ? OR (p.status != 'Paid' AND YEAR(p.created_at) = ?)
        ORDER BY p.payment_date DESC, p.created_at DESC
    ");
    $stmtRevDetail->bind_param("ii", $filterYear, $filterYear);
    $stmtRevDetail->execute();
    $revDetail = $stmtRevDetail->get_result();

    if (isset($out)) {
        fputcsv($out, ['Revenue Report – Year ' . $filterYear]);
        fputcsv($out, []);
        fputcsv($out, ['Tenant','Room','Period','Rent','WiFi','Water','Electric','Total','Method','Reference','Date Paid','Status']);
        while ($row = $revDetail->fetch_assoc()) {
            fputcsv($out, [
                $row['full_name'], 'Room '.$row['room_number'], $row['payment_for'],
                $row['rent_fee'], $row['wifi_fee'], $row['water_fee'], $row['electric_fee'],
                $row['amount'], $row['payment_method'], $row['reference_no'] ?? '',
                $row['payment_date'] ?? '', $row['status'],
            ]);
        }
        fclose($out); exit();
    }
}

// ── 2. OCCUPANCY ───────────────────────────────────────────────
if ($type === 'occupancy') {
    $stmtOccStats = $conn->query("
        SELECT
            COUNT(*) AS total,
            SUM(status='Available') AS available,
            SUM(status='Occupied')  AS occupied,
            SUM(status='Under Maintenance') AS maintenance
        FROM rooms
    ");
    $occStats = $stmtOccStats->fetch_assoc();

    $stmtOccDetail = $conn->query("
        SELECT r.*,
               (SELECT COUNT(*) FROM tenants t WHERE t.room_id=r.id AND t.status='Active') AS active_tenants,
               (SELECT GROUP_CONCAT(u.full_name SEPARATOR ', ')
                FROM tenants t JOIN users u ON t.user_id=u.id
                WHERE t.room_id=r.id AND t.status='Active') AS tenant_names
        FROM rooms r
        ORDER BY r.floor, r.room_number
    ");

    if (isset($out)) {
        fputcsv($out, ['Occupancy Report – ' . date('F j, Y')]);
        fputcsv($out, []);
        fputcsv($out, ['Room No.','Floor','Type','Capacity','Monthly Rate','Active Tenants','Tenants','Status']);
        while ($row = $stmtOccDetail->fetch_assoc()) {
            fputcsv($out, [
                'Room '.$row['room_number'], 'Floor '.$row['floor'],
                $row['room_type'], $row['capacity'],
                $row['monthly_rate'], $row['active_tenants'],
                $row['tenant_names'] ?? '—', $row['status'],
            ]);
        }
        fclose($out); exit();
    }
}

// ── 3. TENANT ──────────────────────────────────────────────────
if ($type === 'tenant') {
    $validTenantStatuses = ['Active','Pending','Inactive'];
    if (!in_array($filterStatus, $validTenantStatuses)) $filterStatus = '';

    $tenantSQL = "
        SELECT
            u.full_name, u.email, u.phone,
            r.room_number, r.floor, r.room_type,
            t.monthly_rent, t.move_in_date, t.move_out_date,
            t.status, t.emergency_contact_name, t.emergency_contact_phone
        FROM tenants t
        JOIN users u ON t.user_id = u.id
        JOIN rooms r ON t.room_id = r.id
    ";
    $tenantParams = []; $tenantTypes = '';
    if ($filterStatus !== '') {
        $tenantSQL   .= " WHERE t.status = ?";
        $tenantParams = [$filterStatus];
        $tenantTypes  = 's';
    }
    $tenantSQL .= " ORDER BY t.status, u.full_name";

    $stmtTenants = $conn->prepare($tenantSQL);
    if (!empty($tenantParams)) $stmtTenants->bind_param($tenantTypes, ...$tenantParams);
    $stmtTenants->execute();
    $tenantRows = $stmtTenants->get_result();

    // Stats
    $tenantStats = $conn->query("
        SELECT
            COUNT(*) AS total,
            SUM(status='Active')   AS active,
            SUM(status='Pending')  AS pending,
            SUM(status='Inactive') AS inactive
        FROM tenants
    ")->fetch_assoc();

    if (isset($out)) {
        $label = $filterStatus ?: 'All';
        fputcsv($out, ["Tenant Report – Status: {$label}"]);
        fputcsv($out, []);
        fputcsv($out, ['Full Name','Email','Phone','Room','Floor','Type','Monthly Rent','Move-in','Move-out','Status','EC Name','EC Phone']);
        while ($row = $tenantRows->fetch_assoc()) {
            fputcsv($out, [
                $row['full_name'], $row['email'], $row['phone'] ?? '',
                'Room '.$row['room_number'], 'Floor '.$row['floor'], $row['room_type'],
                $row['monthly_rent'], $row['move_in_date'], $row['move_out_date'] ?? '',
                $row['status'], $row['emergency_contact_name'] ?? '', $row['emergency_contact_phone'] ?? '',
            ]);
        }
        fclose($out); exit();
    }
}

// ── 4. OVERDUE ─────────────────────────────────────────────────
if ($type === 'overdue') {
    $stmtOverdue = $conn->query("
        SELECT
            u.full_name, u.email, u.phone,
            r.room_number, r.floor,
            p.payment_for, p.amount, p.payment_method,
            p.status, p.created_at,
            DATEDIFF(CURRENT_DATE(), p.payment_date) AS days_overdue
        FROM payments p
        JOIN tenants t ON p.tenant_id = t.id
        JOIN users   u ON t.user_id   = u.id
        JOIN rooms   r ON t.room_id   = r.id
        WHERE p.status IN ('Overdue','Pending')
        ORDER BY p.status DESC, p.created_at ASC
    ");

    $overdueStats = $conn->query("
        SELECT
            SUM(status='Overdue') AS overdue_count,
            SUM(status='Pending') AS pending_count,
            COALESCE(SUM(CASE WHEN status IN ('Overdue','Pending') THEN amount END),0) AS total_unpaid
        FROM payments
    ")->fetch_assoc();

    if (isset($out)) {
        fputcsv($out, ['Overdue Payments Report – ' . date('F j, Y')]);
        fputcsv($out, []);
        fputcsv($out, ['Tenant','Email','Phone','Room','Period','Amount','Status','Days Overdue']);
        while ($row = $stmtOverdue->fetch_assoc()) {
            fputcsv($out, [
                $row['full_name'], $row['email'], $row['phone'] ?? '',
                'Room '.$row['room_number'], $row['payment_for'],
                $row['amount'], $row['status'], max(0, (int)$row['days_overdue']),
            ]);
        }
        fclose($out); exit();
    }
}

// ── 5. MAINTENANCE ─────────────────────────────────────────────
if ($type === 'maintenance') {
    $stmtMaint = $conn->query("
        SELECT r.*,
               (SELECT COUNT(*) FROM tenants t WHERE t.room_id=r.id AND t.status='Active') AS active_tenants
        FROM rooms r
        WHERE r.status = 'Under Maintenance'
        ORDER BY r.floor, r.room_number
    ");

    $maintCount = (int)$conn->query("SELECT COUNT(*) n FROM rooms WHERE status='Under Maintenance'")->fetch_assoc()['n'];

    if (isset($out)) {
        fputcsv($out, ['Maintenance Report – ' . date('F j, Y')]);
        fputcsv($out, []);
        fputcsv($out, ['Room No.','Floor','Type','Capacity','Monthly Rate','Active Tenants','Description']);
        while ($row = $stmtMaint->fetch_assoc()) {
            fputcsv($out, [
                'Room '.$row['room_number'], 'Floor '.$row['floor'],
                $row['room_type'], $row['capacity'], $row['monthly_rate'],
                $row['active_tenants'], $row['description'] ?? '',
            ]);
        }
        fclose($out); exit();
    }
}

// ── 6. SUMMARY ─────────────────────────────────────────────────
if ($type === 'summary') {
    $summaryData = [
        'rooms'     => $conn->query("SELECT COUNT(*) n,SUM(status='Available') avail,SUM(status='Occupied') occ,SUM(status='Under Maintenance') maint FROM rooms")->fetch_assoc(),
        'tenants'   => $conn->query("SELECT COUNT(*) n,SUM(status='Active') active,SUM(status='Pending') pending,SUM(status='Inactive') inactive FROM tenants")->fetch_assoc(),
        'payments'  => $conn->query("SELECT COUNT(*) n,COALESCE(SUM(CASE WHEN status='Paid' THEN amount END),0) paid_amt,SUM(status='Paid') paid_cnt,SUM(status='Pending') pending_cnt,SUM(status='Overdue') overdue_cnt FROM payments")->fetch_assoc(),
        'thisMonth' => $conn->query("SELECT COALESCE(SUM(CASE WHEN status='Paid' THEN amount END),0) collected FROM payments WHERE MONTH(payment_date)=MONTH(CURRENT_DATE()) AND YEAR(payment_date)=YEAR(CURRENT_DATE())")->fetch_assoc(),
    ];

    // Recent 10 payments
    $stmtSumPay = $conn->query("
        SELECT p.payment_for, p.amount, p.status, u.full_name, r.room_number
        FROM payments p
        JOIN tenants t ON p.tenant_id=t.id
        JOIN users u ON t.user_id=u.id
        JOIN rooms r ON t.room_id=r.id
        ORDER BY p.created_at DESC LIMIT 10
    ");

    if (isset($out)) {
        fputcsv($out, ['Summary Report – ' . date('F j, Y')]);
        fputcsv($out, []);
        fputcsv($out, ['Metric','Value']);
        fputcsv($out, ['Total Rooms', $summaryData['rooms']['n']]);
        fputcsv($out, ['Available Rooms', $summaryData['rooms']['avail']]);
        fputcsv($out, ['Occupied Rooms', $summaryData['rooms']['occ']]);
        fputcsv($out, ['Under Maintenance', $summaryData['rooms']['maint']]);
        fputcsv($out, []);
        fputcsv($out, ['Total Tenants', $summaryData['tenants']['n']]);
        fputcsv($out, ['Active Tenants', $summaryData['tenants']['active']]);
        fputcsv($out, ['Pending Tenants', $summaryData['tenants']['pending']]);
        fputcsv($out, ['Inactive Tenants', $summaryData['tenants']['inactive']]);
        fputcsv($out, []);
        fputcsv($out, ['Total Payments Recorded', $summaryData['payments']['n']]);
        fputcsv($out, ['Total Collected (all time)', $summaryData['payments']['paid_amt']]);
        fputcsv($out, ['Paid Payments', $summaryData['payments']['paid_cnt']]);
        fputcsv($out, ['Pending Payments', $summaryData['payments']['pending_cnt']]);
        fputcsv($out, ['Overdue Payments', $summaryData['payments']['overdue_cnt']]);
        fputcsv($out, ['This Month Collections', $summaryData['thisMonth']['collected']]);
        fclose($out); exit();
    }
}

// ── Report metadata ────────────────────────────────────────────
$reportMeta = [
    'revenue'     => ['title'=>'Revenue Report',       'icon'=>'fa-file-invoice-dollar', 'color'=>'green'],
    'occupancy'   => ['title'=>'Occupancy Report',     'icon'=>'fa-bed',                 'color'=>'blue'],
    'tenant'      => ['title'=>'Tenant Report',        'icon'=>'fa-users',               'color'=>'purple'],
    'overdue'     => ['title'=>'Overdue Payments',     'icon'=>'fa-exclamation-triangle','color'=>'red'],
    'maintenance' => ['title'=>'Maintenance Report',   'icon'=>'fa-tools',               'color'=>'orange'],
    'summary'     => ['title'=>'Summary Report',       'icon'=>'fa-chart-pie',           'color'=>'blue'],
];
$meta = $reportMeta[$type];

// Build CSV export URL preserving current filters
$csvUrl  = 'report_view.php?type=' . $type . '&export=csv';
if ($type === 'revenue') $csvUrl .= '&year=' . $filterYear;
if ($type === 'tenant' && $filterStatus) $csvUrl .= '&status=' . urlencode($filterStatus);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> – <?php echo $meta['title']; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* ── Report toolbar ─────────────────────────────────── */
        .report-toolbar {
            display: flex; align-items: center; flex-wrap: wrap;
            gap: 10px; margin-bottom: 20px;
        }
        .report-toolbar .back-link {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: .85rem; color: var(--text-muted);
            padding: 7px 14px; border-radius: var(--radius-sm);
            background: #fff; border: 1px solid var(--border);
            transition: var(--transition);
            text-decoration: none;
        }
        .report-toolbar .back-link:hover { border-color: var(--primary); color: var(--primary); }
        .toolbar-right { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }

        /* ── Report header banner ────────────────────────────── */
        .report-banner {
            background: #fff; border-radius: var(--radius);
            box-shadow: var(--shadow); padding: 20px 24px;
            display: flex; align-items: center; gap: 16px;
            margin-bottom: 20px; border-left: 5px solid var(--primary);
        }
        .report-banner.color-green  { border-color: var(--success); }
        .report-banner.color-red    { border-color: var(--danger); }
        .report-banner.color-orange { border-color: var(--warning); }
        .report-banner.color-purple { border-color: #7c3aed; }

        .report-banner .rb-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }
        .rb-icon.color-blue   { background: var(--primary-light); color: var(--primary); }
        .rb-icon.color-green  { background: #e6f4ea; color: var(--success); }
        .rb-icon.color-red    { background: #fce8e6; color: var(--danger); }
        .rb-icon.color-orange { background: #fef7e0; color: #b58a00; }
        .rb-icon.color-purple { background: #ede9fe; color: #7c3aed; }

        .report-banner h2 { font-size: 1.1rem; font-weight: 700; }
        .report-banner p  { font-size: .82rem; color: var(--text-muted); }

        /* ── Filter bar ─────────────────────────────────────── */
        .filter-bar {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            padding: 12px 20px; background: #fafbfc;
            border-bottom: 1px solid var(--border);
        }
        .filter-bar label { font-size: .82rem; font-weight: 600; }
        .filter-bar select, .filter-bar input {
            padding: 7px 12px; border: 2px solid var(--border);
            border-radius: var(--radius-sm); font-size: .85rem;
            background: #fff; cursor: pointer;
        }
        .filter-bar select:focus, .filter-bar input:focus {
            outline: none; border-color: var(--primary);
        }

        /* ── Summary stat mini-cards ─────────────────────────── */
        .summary-stats {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr));
            gap: 14px; margin-bottom: 20px;
        }
        .sum-stat {
            background: #fff; border-radius: var(--radius);
            box-shadow: var(--shadow); padding: 16px 18px;
            border-top: 3px solid var(--primary);
        }
        .sum-stat.green  { border-color: var(--success); }
        .sum-stat.red    { border-color: var(--danger); }
        .sum-stat.orange { border-color: var(--warning); }
        .sum-stat.purple { border-color: #7c3aed; }
        .sum-stat .val {
            font-size: 1.4rem; font-weight: 800;
            color: var(--text-dark); line-height: 1;
        }
        .sum-stat .lbl {
            font-size: .72rem; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: .8px;
            margin-top: 5px; font-weight: 600;
        }

        /* ── Monthly bar chart (CSS-only) ────────────────────── */
        .bar-chart {
            display: flex; align-items: flex-end; gap: 8px;
            height: 120px; padding: 0 4px; margin-bottom: 8px;
        }
        .bar-col {
            flex: 1; display: flex; flex-direction: column;
            align-items: center; gap: 4px; height: 100%;
            justify-content: flex-end;
        }
        .bar-fill {
            width: 100%; border-radius: 4px 4px 0 0;
            background: var(--primary); transition: height .4s ease;
            min-height: 3px;
        }
        .bar-label { font-size: .65rem; color: var(--text-muted); white-space: nowrap; }
        .bar-value { font-size: .62rem; color: var(--text-muted); }

        /* ── Occupancy bar ───────────────────────────────────── */
        .occ-bar-wrap {
            height: 8px; border-radius: 4px;
            background: var(--border); overflow: hidden;
        }
        .occ-bar-fill {
            height: 100%; border-radius: 4px;
            background: var(--primary); transition: width .4s ease;
        }

        /* ── Buttons ────────────────────────────────────────── */
        .btn-sm { padding: 7px 16px; font-size: .8rem; }
        .btn-outline {
            background: #fff; border: 1.5px solid var(--border);
            color: var(--text-dark); border-radius: var(--radius-sm);
            display: inline-flex; align-items: center; gap: 6px;
            transition: var(--transition); cursor: pointer;
            text-decoration: none; font-family: inherit;
        }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn-green  { background: var(--success); color: #fff; border: none; }
        .btn-green:hover  { background: #2d9247; }

        /* ── Section title ───────────────────────────────────── */
        .section-title {
            font-size: .75rem; text-transform: uppercase;
            letter-spacing: 1.2px; color: var(--text-muted);
            font-weight: 700; margin: 20px 0 10px;
            padding-bottom: 6px; border-bottom: 1px solid var(--border);
        }

        /* ── Badge helpers ───────────────────────────────────── */
        .aging-chip {
            font-size: .72rem; font-weight: 700; padding: 2px 8px;
            border-radius: 10px; white-space: nowrap;
        }
        .aging-low    { background: #fef7e0; color: #b58a00; }
        .aging-medium { background: #fce8e6; color: var(--danger); }
        .aging-high   { background: #b71c1c; color: #fff; }

        /* ── Print styles ────────────────────────────────────── */
        @media print {
            .no-print, .sidebar, .top-header, .report-toolbar,
            .filter-bar form, .toolbar-right { display: none !important; }
            .app-layout { display: block; }
            .main-content { margin-left: 0 !important; }
            .page-content { padding: 0 !important; }
            .card { box-shadow: none !important; border: 1px solid #ccc; }
            .print-header {
                display: flex !important; align-items: center; justify-content: space-between;
                margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #1a73e8;
            }
            .print-header h2 { font-size: 1.2rem; }
            .print-header p  { font-size: .8rem; color: #666; }
            @page { size: A4; margin: 18mm 14mm; }
            table { font-size: .78rem; }
        }
        .print-header { display: none; } /* hidden on screen */

        @media (max-width: 768px) {
            .summary-stats { grid-template-columns: repeat(2,1fr); }
        }
    </style>
</head>
<body>
<div class="app-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ═══ SIDEBAR ═══ -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-brand-icon"><i class="fas fa-building"></i></div>
            <div><h2><?php echo APP_NAME; ?></h2><span><?php echo APP_TAGLINE; ?></span></div>
        </div>
        <nav class="sidebar-nav">
            <div class="sidebar-section">
                <div class="sidebar-section-title">Main Menu</div>
                <a href="dashboard.php" class="sidebar-link"><span class="icon"><i class="fas fa-th-large"></i></span> Dashboard</a>
                <a href="rooms.php"     class="sidebar-link"><span class="icon"><i class="fas fa-door-open"></i></span> Rooms</a>
                <a href="tenants.php"   class="sidebar-link"><span class="icon"><i class="fas fa-users"></i></span> Tenants</a>
                <a href="payments.php"  class="sidebar-link"><span class="icon"><i class="fas fa-money-bill-wave"></i></span> Payments</a>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-section-title">Other</div>
                <a href="reports.php"   class="sidebar-link active"><span class="icon"><i class="fas fa-chart-bar"></i></span> Reports</a>
                <a href="profile.php"   class="sidebar-link"><span class="icon"><i class="fas fa-user-cog"></i></span> Profile</a>
                <a href="logout.php"    class="sidebar-link"><span class="icon"><i class="fas fa-sign-out-alt"></i></span> Logout</a>
            </div>
        </nav>
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-avatar"><?php echo strtoupper(substr($user['full_name']??'A',0,1)); ?></div>
                <div class="sidebar-user-info">
                    <h4><?php echo htmlspecialchars($user['full_name']??''); ?></h4>
                    <p><?php echo htmlspecialchars($user['role']??''); ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- ═══ MAIN ═══ -->
    <main class="main-content">
        <header class="top-header no-print">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
                <div class="page-title">
                    <h1><?php echo $meta['title']; ?></h1>
                    <p>Generated <?php echo date('F j, Y \a\t g:i A'); ?></p>
                </div>
            </div>
            <div class="header-right">
                <button class="header-icon-btn"><i class="fas fa-bell"></i></button>
                <div class="header-avatar"><?php echo strtoupper(substr($user['full_name']??'A',0,1)); ?></div>
            </div>
        </header>

        <div class="page-content">

            <!-- Print header (shows only on print) -->
            <div class="print-header">
                <div>
                    <h2><?php echo APP_NAME; ?> – <?php echo $meta['title']; ?></h2>
                    <p>Generated by <?php echo htmlspecialchars($user['full_name']??''); ?> · <?php echo date('F j, Y, g:i A'); ?></p>
                </div>
                <div style="text-align:right;font-size:.8rem;color:#666;">
                    <?php echo APP_TAGLINE; ?><br>Confidential
                </div>
            </div>

            <!-- ── Toolbar ─────────────────────────────── -->
            <div class="report-toolbar no-print">
                <a href="reports.php" class="back-link">
                    <i class="fas fa-chevron-left"></i> All Reports
                </a>

                <!-- Filters inline -->
                <?php if ($type === 'revenue'): ?>
                <form method="GET" style="display:flex;align-items:center;gap:8px;">
                    <input type="hidden" name="type" value="revenue">
                    <label style="font-size:.82rem;font-weight:600;">Year:</label>
                    <select name="year" onchange="this.form.submit()"
                            style="padding:7px 12px;border:2px solid var(--border);border-radius:var(--radius-sm);font-size:.85rem;background:#fff;">
                        <?php foreach ($yearRange as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo $yr===$filterYear?'selected':''; ?>>
                                <?php echo $yr; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>

                <?php if ($type === 'tenant'): ?>
                <form method="GET" style="display:flex;align-items:center;gap:8px;">
                    <input type="hidden" name="type" value="tenant">
                    <label style="font-size:.82rem;font-weight:600;">Status:</label>
                    <select name="status" onchange="this.form.submit()"
                            style="padding:7px 12px;border:2px solid var(--border);border-radius:var(--radius-sm);font-size:.85rem;background:#fff;">
                        <option value="">All</option>
                        <?php foreach(['Active','Pending','Inactive'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $filterStatus===$s?'selected':''; ?>>
                                <?php echo $s; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>

                <div class="toolbar-right">
                    <a href="<?php echo $csvUrl; ?>" class="btn btn-outline btn-sm">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                    <button onclick="window.print()" class="btn btn-primary btn-sm">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <!-- ── Report Banner ───────────────────────── -->
            <div class="report-banner color-<?php echo $meta['color']; ?>" style="margin-bottom:20px;">
                <div class="rb-icon color-<?php echo $meta['color']; ?>">
                    <i class="fas <?php echo $meta['icon']; ?>"></i>
                </div>
                <div>
                    <h2><?php echo $meta['title']; ?></h2>
                    <p>
                        <?php if ($type==='revenue'): ?>Year: <?php echo $filterYear; ?>
                        <?php elseif ($type==='tenant'): ?>Status filter: <?php echo $filterStatus ?: 'All'; ?>
                        <?php else: ?>As of <?php echo date('F j, Y'); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>


<!-- ══════════════════════════════════════════════════════════════
     REVENUE REPORT
══════════════════════════════════════════════════════════════ -->
<?php if ($type === 'revenue'): ?>

            <div class="summary-stats">
                <div class="sum-stat green">
                    <div class="val">₱<?php echo number_format($revStats['paid'],2); ?></div>
                    <div class="lbl">Total Collected</div>
                </div>
                <div class="sum-stat orange">
                    <div class="val">₱<?php echo number_format($revStats['pending'],2); ?></div>
                    <div class="lbl">Pending</div>
                </div>
                <div class="sum-stat red">
                    <div class="val">₱<?php echo number_format($revStats['overdue'],2); ?></div>
                    <div class="lbl">Overdue</div>
                </div>
                <div class="sum-stat">
                    <div class="val"><?php echo $revStats['total_records']; ?></div>
                    <div class="lbl">Total Records</div>
                </div>
            </div>

            <!-- Monthly bar chart -->
            <?php if (!empty($monthlyRows)): ?>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header"><h2><i class="fas fa-chart-bar"></i> &nbsp;Monthly Collections – <?php echo $filterYear; ?></h2></div>
                <div class="card-body">
                    <?php
                    $maxPaid = max(array_column($monthlyRows,'paid') ?: [1]);
                    ?>
                    <div class="bar-chart">
                        <?php foreach ($monthlyRows as $m):
                            $pct = $maxPaid > 0 ? round($m['paid'] / $maxPaid * 100) : 0;
                        ?>
                            <div class="bar-col">
                                <div class="bar-value">₱<?php echo number_format($m['paid']/1000,1); ?>k</div>
                                <div class="bar-fill" style="height:<?php echo max(3,$pct); ?>%;"></div>
                                <div class="bar-label"><?php echo substr($m['month_name'],0,3); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Monthly breakdown table -->
                    <div class="table-responsive" style="margin-top:16px;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Month</th><th>Transactions</th>
                                    <th>Room Rent</th><th>WiFi</th><th>Water</th><th>Electric</th>
                                    <th>Collected</th><th>Pending</th><th>Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($monthlyRows as $m): ?>
                                <tr>
                                    <td style="font-weight:600;"><?php echo $m['month_name']; ?></td>
                                    <td><?php echo $m['txn_count']; ?></td>
                                    <td>₱<?php echo number_format($m['rent_total'],2); ?></td>
                                    <td>₱<?php echo number_format($m['wifi_total'],2); ?></td>
                                    <td>₱<?php echo number_format($m['water_total'],2); ?></td>
                                    <td>₱<?php echo number_format($m['electric_total'],2); ?></td>
                                    <td style="color:var(--success);font-weight:700;">₱<?php echo number_format($m['paid'],2); ?></td>
                                    <td style="color:var(--warning);">₱<?php echo number_format($m['pending'],2); ?></td>
                                    <td style="color:var(--danger);">₱<?php echo number_format($m['overdue'],2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Detailed transactions -->
            <div class="card">
                <div class="card-header"><h2><i class="fas fa-list"></i> &nbsp;All Transactions – <?php echo $filterYear; ?></h2></div>
                <div class="card-body" style="padding:0;">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th><th>Tenant</th><th>Room</th><th>Period</th>
                                    <th>Rent</th><th>WiFi</th><th>Water</th><th>Electric</th>
                                    <th>Total</th><th>Method</th><th>Date Paid</th><th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $rowNum=1;
                            while ($r = $revDetail->fetch_assoc()):
                                $sb = match($r['status']) { 'Paid'=>'badge-paid','Pending'=>'badge-pending','Overdue'=>'badge-overdue',default=>'badge-pending' };
                            ?>
                                <tr>
                                    <td style="color:var(--text-muted);font-size:.78rem;"><?php echo $rowNum++; ?></td>
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($r['full_name']); ?></td>
                                    <td>Room <?php echo htmlspecialchars($r['room_number']); ?></td>
                                    <td><?php echo htmlspecialchars($r['payment_for']); ?></td>
                                    <td>₱<?php echo number_format($r['rent_fee'],2); ?></td>
                                    <td>₱<?php echo number_format($r['wifi_fee'],2); ?></td>
                                    <td>₱<?php echo number_format($r['water_fee'],2); ?></td>
                                    <td>₱<?php echo number_format($r['electric_fee'],2); ?></td>
                                    <td style="font-weight:800;">₱<?php echo number_format($r['amount'],2); ?></td>
                                    <td style="font-size:.8rem;"><?php echo htmlspecialchars($r['payment_method']); ?></td>
                                    <td style="font-size:.82rem;"><?php echo $r['payment_date'] ? date('M d, Y',strtotime($r['payment_date'])) : '—'; ?></td>
                                    <td><span class="badge <?php echo $sb; ?>"><?php echo $r['status']; ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

<?php endif; // revenue ?>


<!-- ══════════════════════════════════════════════════════════════
     OCCUPANCY REPORT
══════════════════════════════════════════════════════════════ -->
<?php if ($type === 'occupancy'):
    $occPct = $occStats['total'] > 0 ? round($occStats['occupied'] / $occStats['total'] * 100) : 0;
?>
            <div class="summary-stats">
                <div class="sum-stat"><div class="val"><?php echo $occStats['total']; ?></div><div class="lbl">Total Rooms</div></div>
                <div class="sum-stat green"><div class="val"><?php echo $occStats['available']; ?></div><div class="lbl">Available</div></div>
                <div class="sum-stat orange"><div class="val"><?php echo $occStats['occupied']; ?></div><div class="lbl">Occupied</div></div>
                <div class="sum-stat red"><div class="val"><?php echo $occStats['maintenance']; ?></div><div class="lbl">Maintenance</div></div>
                <div class="sum-stat purple">
                    <div class="val"><?php echo $occPct; ?>%</div>
                    <div class="lbl">Occupancy Rate</div>
                </div>
            </div>

            <!-- Occupancy rate visual -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-body">
                    <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:6px;">
                        <span style="font-weight:700;">Overall Occupancy Rate</span>
                        <span><?php echo $occStats['occupied']; ?>/<?php echo $occStats['total']; ?> rooms (<?php echo $occPct; ?>%)</span>
                    </div>
                    <div class="occ-bar-wrap" style="height:14px;border-radius:8px;">
                        <div class="occ-bar-fill" style="width:<?php echo $occPct; ?>%;background:<?php echo $occPct>=80?'var(--success)':($occPct>=50?'var(--primary)':'var(--warning)'); ?>;border-radius:8px;"></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2><i class="fas fa-bed"></i> &nbsp;Room-by-Room Occupancy</h2></div>
                <div class="card-body" style="padding:0;">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>#</th><th>Room</th><th>Floor</th><th>Type</th><th>Capacity</th><th>Active Tenants</th><th>Occupancy</th><th>Monthly Rate</th><th>Status</th><th>Current Tenants</th></tr>
                            </thead>
                            <tbody>
                            <?php
                            $rowNum=1;
                            while ($r = $stmtOccDetail->fetch_assoc()):
                                $used = (int)$r['active_tenants'];
                                $cap  = (int)$r['capacity'];
                                $pct  = $cap > 0 ? round($used/$cap*100) : 0;
                                $sb = match($r['status']) { 'Available'=>'badge-available','Occupied'=>'badge-occupied','Under Maintenance'=>'badge-maintenance',default=>'badge-pending' };
                            ?>
                                <tr>
                                    <td style="color:var(--text-muted);font-size:.78rem;"><?php echo $rowNum++; ?></td>
                                    <td style="font-weight:700;">Room <?php echo htmlspecialchars($r['room_number']); ?></td>
                                    <td>Floor <?php echo $r['floor']; ?></td>
                                    <td><?php echo htmlspecialchars($r['room_type']); ?></td>
                                    <td><?php echo $cap; ?> pax</td>
                                    <td><?php echo $used; ?></td>
                                    <td style="min-width:100px;">
                                        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:3px;"><?php echo $pct; ?>%</div>
                                        <div class="occ-bar-wrap"><div class="occ-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $pct>=100?'var(--danger)':($pct>=60?'var(--warning)':'var(--primary)'); ?>;"></div></div>
                                    </td>
                                    <td style="font-weight:700;">₱<?php echo number_format($r['monthly_rate'],2); ?></td>
                                    <td><span class="badge <?php echo $sb; ?>"><?php echo $r['status']; ?></span></td>
                                    <td style="font-size:.8rem;color:var(--text-muted);"><?php echo $r['tenant_names'] ? htmlspecialchars($r['tenant_names']) : '—'; ?></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
<?php endif; // occupancy ?>


<!-- ══════════════════════════════════════════════════════════════
     TENANT REPORT
══════════════════════════════════════════════════════════════ -->
<?php if ($type === 'tenant'): ?>
            <div class="summary-stats">
                <div class="sum-stat"><div class="val"><?php echo $tenantStats['total']; ?></div><div class="lbl">Total Tenants</div></div>
                <div class="sum-stat green"><div class="val"><?php echo $tenantStats['active']; ?></div><div class="lbl">Active</div></div>
                <div class="sum-stat orange"><div class="val"><?php echo $tenantStats['pending']; ?></div><div class="lbl">Pending</div></div>
                <div class="sum-stat red"><div class="val"><?php echo $tenantStats['inactive']; ?></div><div class="lbl">Inactive</div></div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-users"></i> &nbsp;Tenant List <?php echo $filterStatus ? "– {$filterStatus}" : '– All'; ?></h2>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>#</th><th>Name</th><th>Contact</th><th>Room</th><th>Floor/Type</th><th>Monthly Rent</th><th>Move-in</th><th>Move-out</th><th>Status</th><th>Emergency Contact</th></tr>
                            </thead>
                            <tbody>
                            <?php
                            $rowNum=1;
                            while ($r = $tenantRows->fetch_assoc()):
                                $sb = match($r['status']) { 'Active'=>'badge-paid','Pending'=>'badge-pending','Inactive'=>'badge-maintenance',default=>'badge-pending' };
                            ?>
                                <tr>
                                    <td style="color:var(--text-muted);font-size:.78rem;"><?php echo $rowNum++; ?></td>
                                    <td>
                                        <div style="font-weight:600;"><?php echo htmlspecialchars($r['full_name']); ?></div>
                                        <div style="font-size:.72rem;color:var(--text-muted);"><?php echo htmlspecialchars($r['email']); ?></div>
                                    </td>
                                    <td style="font-size:.82rem;"><?php echo htmlspecialchars($r['phone'] ?? '—'); ?></td>
                                    <td style="font-weight:700;">Room <?php echo htmlspecialchars($r['room_number']); ?></td>
                                    <td style="font-size:.82rem;">Floor <?php echo $r['floor']; ?> · <?php echo $r['room_type']; ?></td>
                                    <td style="font-weight:700;">₱<?php echo number_format($r['monthly_rent'],2); ?></td>
                                    <td style="font-size:.82rem;"><?php echo date('M d, Y',strtotime($r['move_in_date'])); ?></td>
                                    <td style="font-size:.82rem;"><?php echo $r['move_out_date'] ? date('M d, Y',strtotime($r['move_out_date'])) : '—'; ?></td>
                                    <td><span class="badge <?php echo $sb; ?>"><?php echo $r['status']; ?></span></td>
                                    <td style="font-size:.78rem;">
                                        <?php if ($r['emergency_contact_name']): ?>
                                            <?php echo htmlspecialchars($r['emergency_contact_name']); ?><br>
                                            <span style="color:var(--text-muted);"><?php echo htmlspecialchars($r['emergency_contact_phone'] ?? ''); ?></span>
                                        <?php else: echo '—'; endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
<?php endif; // tenant ?>


<!-- ══════════════════════════════════════════════════════════════
     OVERDUE PAYMENTS REPORT
══════════════════════════════════════════════════════════════ -->
<?php if ($type === 'overdue'): ?>
            <div class="summary-stats">
                <div class="sum-stat red"><div class="val"><?php echo $overdueStats['overdue_count']; ?></div><div class="lbl">Overdue</div></div>
                <div class="sum-stat orange"><div class="val"><?php echo $overdueStats['pending_count']; ?></div><div class="lbl">Pending</div></div>
                <div class="sum-stat red">
                    <div class="val">₱<?php echo number_format($overdueStats['total_unpaid'],2); ?></div>
                    <div class="lbl">Total Unpaid</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2><i class="fas fa-exclamation-triangle"></i> &nbsp;Overdue & Pending Payments</h2></div>
                <div class="card-body" style="padding:0;">
                    <?php if ($stmtOverdue->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>#</th><th>Tenant</th><th>Contact</th><th>Room</th><th>Period</th><th>Amount</th><th>Status</th><th>Aging</th></tr>
                            </thead>
                            <tbody>
                            <?php
                            $rowNum=1;
                            while ($r = $stmtOverdue->fetch_assoc()):
                                $days = max(0,(int)$r['days_overdue']);
                                $sb = $r['status']==='Overdue' ? 'badge-overdue' : 'badge-pending';
                                $agingClass = $days > 60 ? 'aging-high' : ($days > 30 ? 'aging-medium' : 'aging-low');
                                $agingLabel = $days > 0 ? "{$days} days" : 'Not due yet';
                            ?>
                                <tr>
                                    <td style="color:var(--text-muted);font-size:.78rem;"><?php echo $rowNum++; ?></td>
                                    <td>
                                        <div style="font-weight:600;"><?php echo htmlspecialchars($r['full_name']); ?></div>
                                        <div style="font-size:.72rem;color:var(--text-muted);"><?php echo htmlspecialchars($r['email']); ?></div>
                                    </td>
                                    <td style="font-size:.82rem;"><?php echo htmlspecialchars($r['phone'] ?? '—'); ?></td>
                                    <td style="font-weight:700;">Room <?php echo htmlspecialchars($r['room_number']); ?>, Floor <?php echo $r['floor']; ?></td>
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($r['payment_for']); ?></td>
                                    <td style="font-weight:800;color:var(--danger);">₱<?php echo number_format($r['amount'],2); ?></td>
                                    <td><span class="badge <?php echo $sb; ?>"><?php echo $r['status']; ?></span></td>
                                    <td><span class="aging-chip <?php echo $agingClass; ?>"><?php echo $agingLabel; ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div class="empty-state" style="padding:50px 20px;">
                            <div class="icon"><i class="fas fa-check-circle" style="color:var(--success);"></i></div>
                            <h3>No overdue or pending payments!</h3>
                            <p>All tenants are up to date with their payments.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
<?php endif; // overdue ?>


<!-- ══════════════════════════════════════════════════════════════
     MAINTENANCE REPORT
══════════════════════════════════════════════════════════════ -->
<?php if ($type === 'maintenance'): ?>
            <div class="summary-stats">
                <div class="sum-stat orange">
                    <div class="val"><?php echo $maintCount; ?></div>
                    <div class="lbl">Rooms Under Maintenance</div>
                </div>
                <div class="sum-stat red">
                    <div class="val">₱<?php echo number_format(
                        (float)$conn->query("SELECT COALESCE(SUM(monthly_rate),0) t FROM rooms WHERE status='Under Maintenance'")->fetch_assoc()['t']
                    ,2); ?></div>
                    <div class="lbl">Lost Revenue / Month</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2><i class="fas fa-tools"></i> &nbsp;Rooms Under Maintenance</h2></div>
                <div class="card-body" style="padding:0;">
                    <?php if ($stmtMaint->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>#</th><th>Room</th><th>Floor</th><th>Type</th><th>Capacity</th><th>Monthly Rate</th><th>Active Tenants</th><th>Description / Notes</th></tr>
                            </thead>
                            <tbody>
                            <?php $rowNum=1; while ($r = $stmtMaint->fetch_assoc()): ?>
                                <tr>
                                    <td style="color:var(--text-muted);font-size:.78rem;"><?php echo $rowNum++; ?></td>
                                    <td style="font-weight:700;">Room <?php echo htmlspecialchars($r['room_number']); ?></td>
                                    <td>Floor <?php echo $r['floor']; ?></td>
                                    <td><?php echo htmlspecialchars($r['room_type']); ?></td>
                                    <td><?php echo $r['capacity']; ?> pax</td>
                                    <td style="font-weight:700;">₱<?php echo number_format($r['monthly_rate'],2); ?></td>
                                    <td>
                                        <?php if ($r['active_tenants'] > 0): ?>
                                            <span class="badge badge-pending"><?php echo $r['active_tenants']; ?> tenant(s)</span>
                                        <?php else: echo '—'; endif; ?>
                                    </td>
                                    <td style="font-size:.82rem;color:var(--text-muted);">
                                        <?php echo $r['description'] ? htmlspecialchars($r['description']) : '—'; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div class="empty-state" style="padding:50px 20px;">
                            <div class="icon"><i class="fas fa-check-circle" style="color:var(--success);"></i></div>
                            <h3>No rooms under maintenance</h3>
                            <p>All rooms are available or occupied.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
<?php endif; // maintenance ?>


<!-- ══════════════════════════════════════════════════════════════
     SUMMARY REPORT
══════════════════════════════════════════════════════════════ -->
<?php if ($type === 'summary'):
    $rooms    = $summaryData['rooms'];
    $tenants  = $summaryData['tenants'];
    $payments = $summaryData['payments'];
    $thisM    = $summaryData['thisMonth'];
    $occRate  = $rooms['total'] > 0 ? round($rooms['occ']/$rooms['total']*100) : 0;
?>
            <!-- KPI grid -->
            <div class="summary-stats" style="grid-template-columns:repeat(auto-fill,minmax(140px,1fr));">
                <!-- Rooms -->
                <div class="sum-stat">        <div class="val"><?php echo $rooms['total']; ?></div>   <div class="lbl">Total Rooms</div></div>
                <div class="sum-stat green">  <div class="val"><?php echo $rooms['avail']; ?></div>   <div class="lbl">Available</div></div>
                <div class="sum-stat orange"> <div class="val"><?php echo $rooms['occ']; ?></div>     <div class="lbl">Occupied</div></div>
                <div class="sum-stat red">    <div class="val"><?php echo $rooms['maint']; ?></div>   <div class="lbl">Maintenance</div></div>
                <!-- Tenants -->
                <div class="sum-stat">        <div class="val"><?php echo $tenants['n']; ?></div>     <div class="lbl">Total Tenants</div></div>
                <div class="sum-stat green">  <div class="val"><?php echo $tenants['active']; ?></div><div class="lbl">Active</div></div>
                <div class="sum-stat orange"> <div class="val"><?php echo $tenants['pending']; ?></div><div class="lbl">Pending</div></div>
                <!-- Revenue -->
                <div class="sum-stat green">
                    <div class="val" style="font-size:1.1rem;">₱<?php echo number_format($payments['paid_amt'],0); ?></div>
                    <div class="lbl">All-time Collected</div>
                </div>
                <div class="sum-stat green">
                    <div class="val" style="font-size:1.1rem;">₱<?php echo number_format($thisM['collected'],0); ?></div>
                    <div class="lbl">This Month</div>
                </div>
                <!-- Occupancy rate -->
                <div class="sum-stat purple">
                    <div class="val"><?php echo $occRate; ?>%</div>
                    <div class="lbl">Occupancy Rate</div>
                </div>
                <div class="sum-stat orange"><div class="val"><?php echo $payments['pending_cnt']; ?></div><div class="lbl">Pending Payments</div></div>
                <div class="sum-stat red">   <div class="val"><?php echo $payments['overdue_cnt']; ?></div><div class="lbl">Overdue Payments</div></div>
            </div>

            <!-- Occupancy bar -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-body">
                    <div style="display:flex;justify-content:space-between;font-size:.84rem;margin-bottom:8px;">
                        <span style="font-weight:700;">Overall Occupancy</span>
                        <span><?php echo $rooms['occ']; ?>/<?php echo $rooms['total']; ?> rooms – <?php echo $occRate; ?>%</span>
                    </div>
                    <div class="occ-bar-wrap" style="height:14px;border-radius:8px;">
                        <div class="occ-bar-fill" style="width:<?php echo $occRate; ?>%;background:<?php echo $occRate>=80?'var(--success)':($occRate>=50?'var(--primary)':'var(--warning)'); ?>;border-radius:8px;"></div>
                    </div>
                </div>
            </div>

            <!-- Recent Payments -->
            <div class="card">
                <div class="card-header"><h2><i class="fas fa-list"></i> &nbsp;Recent Payments</h2></div>
                <div class="card-body" style="padding:0;">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Tenant</th><th>Room</th><th>Period</th><th>Total</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                            <?php while ($r = $stmtSumPay->fetch_assoc()):
                                $sb = match($r['status']) { 'Paid'=>'badge-paid','Pending'=>'badge-pending','Overdue'=>'badge-overdue',default=>'badge-pending' };
                            ?>
                                <tr>
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($r['full_name']); ?></td>
                                    <td>Room <?php echo htmlspecialchars($r['room_number']); ?></td>
                                    <td><?php echo htmlspecialchars($r['payment_for']); ?></td>
                                    <td style="font-weight:800;">₱<?php echo number_format($r['amount'],2); ?></td>
                                    <td><span class="badge <?php echo $sb; ?>"><?php echo $r['status']; ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
<?php endif; // summary ?>

        </div><!-- /page-content -->
    </main>
</div><!-- /app-layout -->
<script src="assets/js/script.js"></script>
</body>
</html>

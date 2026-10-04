<?php
require_once 'includes/auth.php';
requireRole(['Dormitory Owner', 'Caretaker']);

$user = getCurrentUser();
$page = getCurrentPage();

// ── Stats Queries ─────────────────────────────────────────────
// Total Rooms
$stmtTotal = $conn->query("SELECT COUNT(*) AS total FROM rooms");
$totalRooms = $stmtTotal->fetch_assoc()['total'] ?? 0;

// Occupied Rooms
$stmtOccupied = $conn->query("SELECT COUNT(*) AS total FROM rooms WHERE status = 'Occupied'");
$occupiedRooms = $stmtOccupied->fetch_assoc()['total'] ?? 0;

// Vacant Rooms (Available)
$stmtVacant = $conn->query("SELECT COUNT(*) AS total FROM rooms WHERE status = 'Available'");
$vacantRooms = $stmtVacant->fetch_assoc()['total'] ?? 0;

// Total Active Tenants
$stmtTenants = $conn->query("SELECT COUNT(*) AS total FROM tenants WHERE status = 'Active'");
$totalTenants = $stmtTenants->fetch_assoc()['total'] ?? 0;

// Monthly Payments — sum of paid payments for the current calendar month
$stmtPayments = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM payments
    WHERE status = 'Paid'
      AND MONTH(payment_date) = MONTH(CURRENT_DATE())
      AND YEAR(payment_date)  = YEAR(CURRENT_DATE())
");
$stmtPayments->execute();
$monthlyRevenue = $stmtPayments->get_result()->fetch_assoc()['total'] ?? 0;
$stmtPayments->close();

// Overdue / Pending counts
$stmtOverdue = $conn->query("SELECT COUNT(*) AS total FROM payments WHERE status = 'Overdue'");
$overdueCount = $stmtOverdue->fetch_assoc()['total'] ?? 0;

$stmtPending = $conn->query("SELECT COUNT(*) AS total FROM payments WHERE status = 'Pending'");
$pendingCount = $stmtPending->fetch_assoc()['total'] ?? 0;

// ── Recent Tenants (latest 7 by move_in_date) ─────────────────
$stmtRecentTenants = $conn->query("
    SELECT
        u.full_name,
        u.email,
        r.room_number,
        r.room_type,
        t.move_in_date,
        t.status
    FROM tenants t
    JOIN users u ON t.user_id  = u.id
    JOIN rooms r ON t.room_id  = r.id
    ORDER BY t.created_at DESC
    LIMIT 7
");

// ── Recent Payments (latest 7) ────────────────────────────────
$stmtRecentPayments = $conn->query("
    SELECT
        u.full_name,
        r.room_number,
        p.amount,
        p.payment_for,
        p.payment_method,
        p.status,
        p.payment_date
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    JOIN users  u ON t.user_id    = u.id
    JOIN rooms  r ON t.room_id    = r.id
    ORDER BY p.created_at DESC
    LIMIT 7
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="app-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ── Sidebar ─────────────────────────────────────── -->
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
                <div class="sidebar-avatar">
                    <?php echo strtoupper(substr($user['full_name'] ?? 'A', 0, 1)); ?>
                </div>
                <div class="sidebar-user-info">
                    <h4><?php echo htmlspecialchars($user['full_name'] ?? 'Admin'); ?></h4>
                    <p><?php echo htmlspecialchars($user['role'] ?? ''); ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- ── Main Content ────────────────────────────────── -->
    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
                <div class="page-title">
                    <h1><span id="timeGreeting">Good Day</span>, <?php echo htmlspecialchars($user['full_name'] ?? 'Admin'); ?>!</h1>
                    <p id="currentDateTime"></p>
                </div>
            </div>
            <div class="header-right">
                <button class="header-icon-btn" data-tooltip="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if ($overdueCount > 0): ?>
                        <span class="notification-badge"></span>
                    <?php endif; ?>
                </button>
                <button class="header-icon-btn" data-tooltip="Settings">
                    <i class="fas fa-cog"></i>
                </button>
                <div class="header-avatar">
                    <?php echo strtoupper(substr($user['full_name'] ?? 'A', 0, 1)); ?>
                </div>
            </div>
        </header>

        <div class="page-content">

            <!-- ── Stats Cards ──────────────────────────── -->
            <div class="stats-grid">

                <!-- Total Rooms -->
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-building"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $totalRooms; ?></h3>
                        <p>Total Rooms</p>
                    </div>
                </div>

                <!-- Occupied Rooms -->
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-door-closed"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $occupiedRooms; ?></h3>
                        <p>Occupied Rooms</p>
                    </div>
                </div>

                <!-- Vacant Rooms -->
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-door-open"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $vacantRooms; ?></h3>
                        <p>Vacant Rooms</p>
                    </div>
                </div>

                <!-- Total Active Tenants -->
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $totalTenants; ?></h3>
                        <p>Active Tenants</p>
                    </div>
                </div>

                <!-- Monthly Payments collected this month -->
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-peso-sign"></i></div>
                    <div class="stat-info">
                        <h3>₱<?php echo number_format($monthlyRevenue, 2); ?></h3>
                        <p>Monthly Collections</p>
                        <span class="stat-trend up">
                            <i class="fas fa-calendar-check"></i>
                            <?php echo date('F Y'); ?>
                        </span>
                    </div>
                </div>

                <!-- Pending + Overdue -->
                <div class="stat-card">
                    <div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $pendingCount + $overdueCount; ?></h3>
                        <p>Unpaid Payments</p>
                        <span class="stat-trend down">
                            <i class="fas fa-clock"></i>
                            <?php echo $overdueCount; ?> overdue
                        </span>
                    </div>
                </div>

            </div><!-- /stats-grid -->

            <!-- ── Tables Grid ──────────────────────────── -->
            <div class="content-grid">

                <!-- Recent Tenants -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-users"></i> &nbsp;Recent Tenants</h2>
                        <a href="tenants.php"
                           class="btn"
                           style="padding:8px 16px;font-size:0.8rem;background:var(--primary-light);color:var(--primary);">
                            View All
                        </a>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if ($stmtRecentTenants && $stmtRecentTenants->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Room</th>
                                        <th>Move-in</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php while ($row = $stmtRecentTenants->fetch_assoc()):
                                    $badgeClass = match($row['status']) {
                                        'Active'   => 'badge-paid',
                                        'Pending'  => 'badge-pending',
                                        'Inactive' => 'badge-maintenance',
                                        default    => 'badge-pending',
                                    };
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600;"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                            <div style="font-size:0.75rem;color:var(--text-muted);"><?php echo htmlspecialchars($row['email']); ?></div>
                                        </td>
                                        <td>
                                            <strong>Room <?php echo htmlspecialchars($row['room_number']); ?></strong>
                                            <div style="font-size:0.75rem;color:var(--text-muted);"><?php echo htmlspecialchars($row['room_type']); ?></div>
                                        </td>
                                        <td style="font-size:0.85rem;">
                                            <?php echo date('M d, Y', strtotime($row['move_in_date'])); ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="empty-state" style="padding:40px 20px;">
                                <div class="icon"><i class="fas fa-users"></i></div>
                                <h3>No tenants yet</h3>
                                <p>Tenant records will appear here once added.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Payments -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-money-bill-wave"></i> &nbsp;Recent Payments</h2>
                        <a href="payments.php"
                           class="btn"
                           style="padding:8px 16px;font-size:0.8rem;background:var(--primary-light);color:var(--primary);">
                            View All
                        </a>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if ($stmtRecentPayments && $stmtRecentPayments->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Tenant</th>
                                        <th>Amount</th>
                                        <th>Period</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php while ($row = $stmtRecentPayments->fetch_assoc()):
                                    $badgeClass = match($row['status']) {
                                        'Paid'    => 'badge-paid',
                                        'Pending' => 'badge-pending',
                                        'Overdue' => 'badge-overdue',
                                        default   => 'badge-pending',
                                    };
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600;"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                            <div style="font-size:0.75rem;color:var(--text-muted);">Room <?php echo htmlspecialchars($row['room_number']); ?></div>
                                        </td>
                                        <td style="font-weight:700;color:var(--text-dark);">
                                            ₱<?php echo number_format($row['amount'], 2); ?>
                                        </td>
                                        <td style="font-size:0.82rem;">
                                            <?php echo htmlspecialchars($row['payment_for']); ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="empty-state" style="padding:40px 20px;">
                                <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                                <h3>No payments yet</h3>
                                <p>Payment records will appear here once added.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div><!-- /content-grid -->
        </div><!-- /page-content -->
    </main>
</div><!-- /app-layout -->

<script src="assets/js/script.js"></script>
</body>
</html>

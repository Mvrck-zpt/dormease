<?php
require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();
$page = getCurrentPage();
$role = $_SESSION['role'] ?? '';
$isAdmin = in_array($role, ['Dormitory Owner', 'Caretaker']);

// ── Tenant-specific queries (only run when the user is a Tenant) ──
$tenantRecord  = null;
$tenantPayments = null;

if (!$isAdmin) {
    // Fetch this tenant's active assignment (room + rent)
    $stmt = $conn->prepare("
        SELECT
            t.id          AS tenant_id,
            t.monthly_rent,
            t.move_in_date,
            t.move_out_date,
            t.status      AS tenancy_status,
            t.emergency_contact_name,
            t.emergency_contact_phone,
            r.room_number,
            r.floor,
            r.room_type,
            r.capacity,
            r.status      AS room_status
        FROM tenants t
        JOIN rooms r ON t.room_id = r.id
        WHERE t.user_id = ?
        ORDER BY t.created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $tenantRecord = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Fetch this tenant's payment history (most recent first)
    if ($tenantRecord) {
        $stmtPay = $conn->prepare("
            SELECT
                payment_for,
                amount,
                payment_date,
                payment_method,
                reference_no,
                status,
                remarks
            FROM payments
            WHERE tenant_id = ?
            ORDER BY created_at DESC
        ");
        $stmtPay->bind_param("i", $tenantRecord['tenant_id']);
        $stmtPay->execute();
        $tenantPayments = $stmtPay->get_result();
        $stmtPay->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Profile</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* ── Extra styles for the tenant profile view ── */
        .room-info-card {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0;
        }
        .room-stat {
            padding: 20px 24px;
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            text-align: center;
        }
        .room-stat:last-child { border-right: none; }
        .room-stat .icon {
            font-size: 1.5rem;
            margin-bottom: 6px;
        }
        .room-stat h4 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        .room-stat p {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .no-room-notice {
            padding: 40px 24px;
            text-align: center;
            color: var(--text-muted);
        }
        .no-room-notice .icon { font-size: 2.5rem; opacity: 0.35; margin-bottom: 12px; }
        .payment-method-icon { font-size: 0.8rem; color: var(--text-muted); }
    </style>
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
            <?php if ($isAdmin): ?>
                <!-- Admin sidebar -->
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
            <?php else: ?>
                <!-- Tenant sidebar — only My Profile & Logout -->
                <div class="sidebar-section">
                    <div class="sidebar-section-title">My Account</div>
                    <a href="profile.php" class="sidebar-link <?php echo $page === 'profile' ? 'active' : ''; ?>">
                        <span class="icon"><i class="fas fa-user"></i></span> My Profile
                    </a>
                    <a href="logout.php" class="sidebar-link">
                        <span class="icon"><i class="fas fa-sign-out-alt"></i></span> Logout
                    </a>
                </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-avatar"><?php echo strtoupper(substr($user['full_name'] ?? 'A', 0, 1)); ?></div>
                <div class="sidebar-user-info">
                    <h4><?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?></h4>
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
                    <h1>My Profile</h1>
                    <p>
                        <?php echo $isAdmin
                            ? 'View your account information'
                            : 'Your room assignment and payment history'; ?>
                    </p>
                </div>
            </div>
            <div class="header-right">
                <button class="header-icon-btn"><i class="fas fa-bell"></i></button>
                <div class="header-avatar"><?php echo strtoupper(substr($user['full_name'] ?? 'A', 0, 1)); ?></div>
            </div>
        </header>

        <div class="page-content">

            <!-- ── Account Info Card (all roles) ──────── -->
            <div class="card" style="margin-bottom:24px;">
                <div class="card-header">
                    <h2><i class="fas fa-user-circle"></i> &nbsp;Account Information</h2>
                </div>
                <div class="card-body">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <?php echo strtoupper(substr($user['full_name'] ?? 'A', 0, 1)); ?>
                        </div>
                        <div class="profile-info">
                            <h2><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></h2>
                            <p><i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars($user['role'] ?? ''); ?></p>
                            <p>
                                <i class="fas fa-circle" style="color:var(--success);font-size:0.55rem;"></i>
                                Online
                            </p>
                        </div>
                    </div>

                    <div class="profile-details">
                        <div class="detail-item">
                            <label>Full Name</label>
                            <span><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Username</label>
                            <span><?php echo htmlspecialchars($user['username'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Role</label>
                            <span><?php echo htmlspecialchars($user['role'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Email</label>
                            <span><?php echo htmlspecialchars($user['email'] ?? ''); ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Phone</label>
                            <span><?php echo htmlspecialchars($user['phone'] ?? 'Not set'); ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Member Since</label>
                            <span><?php echo isset($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : 'N/A'; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$isAdmin): ?>
            <!-- ── Tenant-Only Section ─────────────────── -->

                <!-- Room Assignment Card -->
                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header">
                        <h2><i class="fas fa-door-open"></i> &nbsp;Room Assignment</h2>
                        <?php if ($tenantRecord): ?>
                            <span class="badge <?php echo $tenantRecord['tenancy_status'] === 'Active' ? 'badge-available' : 'badge-pending'; ?>">
                                <?php echo htmlspecialchars($tenantRecord['tenancy_status']); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($tenantRecord): ?>
                        <!-- Room stat grid -->
                        <div class="room-info-card">
                            <div class="room-stat">
                                <div class="icon" style="color:var(--primary);"><i class="fas fa-door-open"></i></div>
                                <h4>Room <?php echo htmlspecialchars($tenantRecord['room_number']); ?></h4>
                                <p>Room Number</p>
                            </div>
                            <div class="room-stat">
                                <div class="icon" style="color:var(--primary);"><i class="fas fa-layer-group"></i></div>
                                <h4>Floor <?php echo htmlspecialchars($tenantRecord['floor']); ?></h4>
                                <p>Floor Level</p>
                            </div>
                            <div class="room-stat">
                                <div class="icon" style="color:var(--success);"><i class="fas fa-bed"></i></div>
                                <h4><?php echo htmlspecialchars($tenantRecord['room_type']); ?></h4>
                                <p>Room Type</p>
                            </div>
                            <div class="room-stat">
                                <div class="icon" style="color:var(--success);"><i class="fas fa-peso-sign"></i></div>
                                <h4>₱<?php echo number_format($tenantRecord['monthly_rent'], 2); ?></h4>
                                <p>Monthly Rent</p>
                            </div>
                            <div class="room-stat">
                                <div class="icon" style="color:var(--info);"><i class="fas fa-calendar-check"></i></div>
                                <h4><?php echo date('M d, Y', strtotime($tenantRecord['move_in_date'])); ?></h4>
                                <p>Move-in Date</p>
                            </div>
                            <div class="room-stat" style="border-right:none;">
                                <div class="icon" style="color:var(--text-muted);"><i class="fas fa-users"></i></div>
                                <h4><?php echo htmlspecialchars($tenantRecord['capacity']); ?></h4>
                                <p>Room Capacity</p>
                            </div>
                        </div>

                        <!-- Emergency contact -->
                        <?php if (!empty($tenantRecord['emergency_contact_name'])): ?>
                        <div style="padding:16px 24px;border-top:1px solid var(--border);background:#fafbfc;display:flex;align-items:center;gap:12px;">
                            <i class="fas fa-phone-alt" style="color:var(--danger);"></i>
                            <div>
                                <div style="font-size:0.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;font-weight:600;">Emergency Contact</div>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($tenantRecord['emergency_contact_name']); ?></div>
                                <div style="font-size:0.85rem;color:var(--text-muted);"><?php echo htmlspecialchars($tenantRecord['emergency_contact_phone'] ?? ''); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="no-room-notice">
                            <div class="icon"><i class="fas fa-door-open"></i></div>
                            <h3>No room assigned yet</h3>
                            <p>Please contact the dormitory administrator.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Payment History Card -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-money-bill-wave"></i> &nbsp;My Payment History</h2>
                    </div>
                    <?php if ($tenantPayments && $tenantPayments->num_rows > 0): ?>
                        <div class="card-body" style="padding:0;">
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Period</th>
                                            <th>Amount</th>
                                            <th>Date Paid</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php while ($pay = $tenantPayments->fetch_assoc()):
                                        $payBadge = match($pay['status']) {
                                            'Paid'    => 'badge-paid',
                                            'Pending' => 'badge-pending',
                                            'Overdue' => 'badge-overdue',
                                            default   => 'badge-pending',
                                        };
                                        $methodIcon = match($pay['payment_method']) {
                                            'GCash'         => 'fa-mobile-alt',
                                            'Bank Transfer' => 'fa-university',
                                            'Cash'          => 'fa-money-bill',
                                            default         => 'fa-credit-card',
                                        };
                                    ?>
                                        <tr>
                                            <td style="font-weight:600;"><?php echo htmlspecialchars($pay['payment_for']); ?></td>
                                            <td style="font-weight:700;">₱<?php echo number_format($pay['amount'], 2); ?></td>
                                            <td style="font-size:0.85rem;">
                                                <?php echo $pay['payment_date']
                                                    ? date('M d, Y', strtotime($pay['payment_date']))
                                                    : '<span style="color:var(--text-muted);">—</span>'; ?>
                                            </td>
                                            <td>
                                                <i class="fas <?php echo $methodIcon; ?> payment-method-icon"></i>
                                                <?php echo htmlspecialchars($pay['payment_method']); ?>
                                            </td>
                                            <td style="font-size:0.8rem;color:var(--text-muted);">
                                                <?php echo $pay['reference_no'] ? htmlspecialchars($pay['reference_no']) : '—'; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $payBadge; ?>">
                                                    <?php echo htmlspecialchars($pay['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php elseif ($tenantRecord): ?>
                        <div class="empty-state" style="padding:40px 20px;">
                            <div class="icon"><i class="fas fa-receipt"></i></div>
                            <h3>No payment records yet</h3>
                            <p>Your payment history will appear here once the first payment is recorded.</p>
                        </div>
                    <?php else: ?>
                        <div class="empty-state" style="padding:40px 20px;">
                            <div class="icon"><i class="fas fa-receipt"></i></div>
                            <h3>No payments found</h3>
                            <p>You have no room assignment or payment records.</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; // end tenant-only section ?>

        </div><!-- /page-content -->
    </main>
</div><!-- /app-layout -->

<script src="assets/js/script.js"></script>
</body>
</html>

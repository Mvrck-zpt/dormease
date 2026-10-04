<?php
// ================================================================
// DormEase – Rooms Management
// ================================================================
require_once 'includes/auth.php';
requireRole(['Dormitory Owner', 'Caretaker']);

// Route POST actions to the dedicated handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'includes/room_actions.php';
    exit(); // room_actions.php redirects, so this is a safety net
}

$user = getCurrentUser();
$page = getCurrentPage();

// ── Flash messages ────────────────────────────────────────────
$successMsg = $_SESSION['success'] ?? '';
$errorMsg   = $_SESSION['error']   ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ── Search & Filter ───────────────────────────────────────────
$search     = trim($_GET['search']      ?? '');
$filterType = trim($_GET['room_type']   ?? '');
$filterStatus = trim($_GET['status']    ?? '');

// ── Stats Queries ─────────────────────────────────────────────
$statTotal      = $conn->query("SELECT COUNT(*) AS n FROM rooms")->fetch_assoc()['n'];
$statAvailable  = $conn->query("SELECT COUNT(*) AS n FROM rooms WHERE status='Available'")->fetch_assoc()['n'];
$statOccupied   = $conn->query("SELECT COUNT(*) AS n FROM rooms WHERE status='Occupied'")->fetch_assoc()['n'];
$statMaintenance= $conn->query("SELECT COUNT(*) AS n FROM rooms WHERE status='Under Maintenance'")->fetch_assoc()['n'];

// ── Room Listing Query (with search & filters) ────────────────
// Also fetches current active tenant count per room
$sql = "
    SELECT
        r.*,
        (SELECT COUNT(*) FROM tenants t
         WHERE t.room_id = r.id AND t.status = 'Active') AS active_tenants
    FROM rooms r
    WHERE 1=1
";
$params = [];
$types  = '';

if ($search !== '') {
    $sql    .= " AND (r.room_number LIKE ? OR r.description LIKE ?)";
    $like    = "%{$search}%";
    $params  = array_merge($params, [$like, $like]);
    $types  .= 'ss';
}
if ($filterType !== '') {
    $validTypes = ['Single','Double','Triple','Quad'];
    if (in_array($filterType, $validTypes)) {
        $sql    .= " AND r.room_type = ?";
        $params  = array_merge($params, [$filterType]);
        $types  .= 's';
    }
}
if ($filterStatus !== '') {
    $validStatuses = ['Available','Occupied','Under Maintenance'];
    if (in_array($filterStatus, $validStatuses)) {
        $sql    .= " AND r.status = ?";
        $params  = array_merge($params, [$filterStatus]);
        $types  .= 's';
    }
}
$sql .= " ORDER BY r.floor ASC, r.room_number ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rooms = $stmt->get_result();
$stmt->close();

// ── For Edit modal: fetch single room if ?edit_id= is set ─────
$editRoom = null;
if (isset($_GET['edit_id'])) {
    $eid = (int)$_GET['edit_id'];
    $es  = $conn->prepare("SELECT * FROM rooms WHERE id = ?");
    $es->bind_param("i", $eid);
    $es->execute();
    $editRoom = $es->get_result()->fetch_assoc();
    $es->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Rooms</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* ── Modal ─────────────────────────────────────────── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.open { display: flex; }

        .modal {
            background: #fff;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalIn .25s ease;
        }
        @keyframes modalIn {
            from { opacity:0; transform:translateY(-20px) scale(.97); }
            to   { opacity:1; transform:translateY(0)     scale(1);   }
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
        }
        .modal-header h3 { font-size:1.05rem; font-weight:700; }
        .modal-close {
            width:32px; height:32px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            background:var(--bg-body); color:var(--text-muted);
            font-size:.95rem; cursor:pointer; border:none;
            transition: var(--transition);
        }
        .modal-close:hover { background:var(--danger); color:#fff; }
        .modal-body { padding: 24px; }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            display: flex; gap:10px; justify-content:flex-end;
        }

        /* ── Form elements ─────────────────────────────────── */
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .form-group { margin-bottom:16px; }
        .form-group label {
            display:block; font-size:.82rem; font-weight:600;
            color:var(--text-dark); margin-bottom:5px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width:100%; padding:10px 14px;
            border:2px solid var(--border); border-radius:var(--radius-sm);
            font-size:.9rem; font-family:inherit; color:var(--text-dark);
            background:#fafbfc; transition:var(--transition);
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline:none; border-color:var(--primary);
            background:#fff; box-shadow:0 0 0 3px rgba(26,115,232,.12);
        }
        .form-group textarea { resize:vertical; min-height:70px; }

        /* ── Buttons ───────────────────────────────────────── */
        .btn-sm { padding:7px 16px; font-size:.8rem; }
        .btn-success { background:var(--success); color:#fff; }
        .btn-success:hover { background:#2d9247; }
        .btn-danger  { background:var(--danger);  color:#fff; }
        .btn-danger:hover  { background:#c62828; }
        .btn-secondary { background:var(--bg-body); color:var(--text-dark); border:1px solid var(--border); }
        .btn-secondary:hover { background:var(--border); }
        .btn-warning { background:var(--warning); color:var(--text-dark); }
        .btn-warning:hover { background:#e8a700; }
        .btn-icon {
            width:34px; height:34px; border-radius:var(--radius-sm);
            display:inline-flex; align-items:center; justify-content:center;
            font-size:.85rem; transition:var(--transition);
        }
        .btn-icon.edit   { background:#e8f0fe; color:var(--primary); }
        .btn-icon.edit:hover  { background:var(--primary); color:#fff; }
        .btn-icon.delete { background:#fce8e6; color:var(--danger); }
        .btn-icon.delete:hover { background:var(--danger); color:#fff; }

        /* ── Search bar ────────────────────────────────────── */
        .search-bar {
            display:flex; gap:10px; flex-wrap:wrap;
            align-items:center;
            padding:16px 20px;
            border-bottom:1px solid var(--border);
            background:#fafbfc;
        }
        .search-input-wrap { position:relative; flex:1; min-width:200px; }
        .search-input-wrap i {
            position:absolute; left:12px; top:50%; transform:translateY(-50%);
            color:var(--text-muted); font-size:.9rem;
        }
        .search-input-wrap input {
            width:100%; padding:9px 14px 9px 36px;
            border:2px solid var(--border); border-radius:var(--radius-sm);
            font-size:.88rem; background:#fff;
            transition:var(--transition);
        }
        .search-input-wrap input:focus {
            outline:none; border-color:var(--primary);
            box-shadow:0 0 0 3px rgba(26,115,232,.1);
        }
        .filter-select {
            padding:9px 12px; border:2px solid var(--border);
            border-radius:var(--radius-sm); font-size:.87rem;
            background:#fff; cursor:pointer; transition:var(--transition);
        }
        .filter-select:focus { outline:none; border-color:var(--primary); }

        /* ── Room type pill ────────────────────────────────── */
        .type-pill {
            display:inline-flex; align-items:center; gap:4px;
            padding:3px 10px; border-radius:20px;
            font-size:.75rem; font-weight:600;
        }
        .type-single { background:#e8f0fe; color:#1a73e8; }
        .type-double { background:#e6f4ea; color:#2d9247; }
        .type-triple { background:#fef7e0; color:#b58a00; }
        .type-quad   { background:#fce8e6; color:#ea4335; }

        /* ── Status badge override widths ──────────────────── */
        .badge { white-space: nowrap; }

        /* ── Confirm modal ─────────────────────────────────── */
        .confirm-modal { max-width:420px; }
        .confirm-modal .modal-body { text-align:center; padding:32px 24px; }
        .confirm-modal .icon-warn {
            width:64px; height:64px; border-radius:50%;
            background:#fce8e6; color:var(--danger);
            display:flex; align-items:center; justify-content:center;
            font-size:1.8rem; margin:0 auto 16px;
        }
        .confirm-modal h4 { font-size:1.1rem; margin-bottom:6px; }
        .confirm-modal p  { color:var(--text-muted); font-size:.88rem; }

        /* ── Occupancy bar ─────────────────────────────────── */
        .occupancy-bar {
            height:6px; border-radius:4px;
            background:var(--border); overflow:hidden;
            margin-top:4px;
        }
        .occupancy-bar-fill {
            height:100%; border-radius:4px;
            background:var(--primary); transition:width .4s ease;
        }

        /* ── Responsive table ──────────────────────────────── */
        @media(max-width:768px) {
            .form-row { grid-template-columns:1fr; }
            .search-bar { flex-direction:column; align-items:stretch; }
        }
    </style>
</head>
<body>
<div class="app-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ═══════════ SIDEBAR ═══════════ -->
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
                <a href="dashboard.php" class="sidebar-link <?php echo $page==='dashboard'?'active':''; ?>">
                    <span class="icon"><i class="fas fa-th-large"></i></span> Dashboard
                </a>
                <a href="rooms.php" class="sidebar-link <?php echo $page==='rooms'?'active':''; ?>">
                    <span class="icon"><i class="fas fa-door-open"></i></span> Rooms
                </a>
                <a href="tenants.php" class="sidebar-link <?php echo $page==='tenants'?'active':''; ?>">
                    <span class="icon"><i class="fas fa-users"></i></span> Tenants
                </a>
                <a href="payments.php" class="sidebar-link <?php echo $page==='payments'?'active':''; ?>">
                    <span class="icon"><i class="fas fa-money-bill-wave"></i></span> Payments
                </a>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-section-title">Other</div>
                <a href="reports.php" class="sidebar-link <?php echo $page==='reports'?'active':''; ?>">
                    <span class="icon"><i class="fas fa-chart-bar"></i></span> Reports
                </a>
                <a href="profile.php" class="sidebar-link <?php echo $page==='profile'?'active':''; ?>">
                    <span class="icon"><i class="fas fa-user-cog"></i></span> Profile
                </a>
                <a href="logout.php" class="sidebar-link">
                    <span class="icon"><i class="fas fa-sign-out-alt"></i></span> Logout
                </a>
            </div>
        </nav>
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-avatar"><?php echo strtoupper(substr($user['full_name']??'A',0,1)); ?></div>
                <div class="sidebar-user-info">
                    <h4><?php echo htmlspecialchars($user['full_name']??'Admin'); ?></h4>
                    <p><?php echo htmlspecialchars($user['role']??''); ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- ═══════════ MAIN CONTENT ═══════════ -->
    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
                <div class="page-title">
                    <h1>Rooms Management</h1>
                    <p>Add, edit, and manage dormitory rooms</p>
                </div>
            </div>
            <div class="header-right">
                <button class="btn btn-primary btn-sm" id="btnOpenAdd">
                    <i class="fas fa-plus"></i> Add Room
                </button>
                <button class="header-icon-btn"><i class="fas fa-bell"></i></button>
                <div class="header-avatar"><?php echo strtoupper(substr($user['full_name']??'A',0,1)); ?></div>
            </div>
        </header>

        <div class="page-content">

            <!-- Flash Messages -->
            <?php if ($successMsg): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMsg); ?></div>
            <?php endif; ?>
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
            <?php endif; ?>

            <!-- ── Stats Cards ──────────────────────────── -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-building"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $statTotal; ?></h3>
                        <p>Total Rooms</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-door-open"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $statAvailable; ?></h3>
                        <p>Available</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-door-closed"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $statOccupied; ?></h3>
                        <p>Occupied</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon red"><i class="fas fa-tools"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $statMaintenance; ?></h3>
                        <p>Maintenance</p>
                    </div>
                </div>
            </div>

            <!-- ── Room Table Card ──────────────────────── -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-door-open"></i> &nbsp;All Rooms</h2>
                    <span style="font-size:.82rem;color:var(--text-muted);">
                        <?php echo $rooms->num_rows; ?> result<?php echo $rooms->num_rows !== 1 ? 's' : ''; ?>
                    </span>
                </div>

                <!-- Search & Filter Bar -->
                <form method="GET" action="rooms.php" class="search-bar">
                    <div class="search-input-wrap">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search room number or description…"
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <select name="room_type" class="filter-select">
                        <option value="">All Types</option>
                        <?php foreach(['Single','Double','Triple','Quad'] as $t): ?>
                            <option value="<?php echo $t; ?>" <?php echo $filterType===$t?'selected':''; ?>><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" class="filter-select">
                        <option value="">All Statuses</option>
                        <?php foreach(['Available','Occupied','Under Maintenance'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $filterStatus===$s?'selected':''; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <?php if ($search || $filterType || $filterStatus): ?>
                        <a href="rooms.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>

                <!-- Table -->
                <div class="card-body" style="padding:0;">
                    <?php if ($rooms->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Room No.</th>
                                    <th>Floor</th>
                                    <th>Type</th>
                                    <th>Capacity</th>
                                    <th>Monthly Rate</th>
                                    <th>Occupancy</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $rowNum = 1;
                            while ($room = $rooms->fetch_assoc()):
                                $activeTenants = (int)$room['active_tenants'];
                                $capacity      = (int)$room['capacity'];
                                $occupancyPct  = $capacity > 0 ? round($activeTenants / $capacity * 100) : 0;

                                $statusBadge = match($room['status']) {
                                    'Available'        => 'badge-available',
                                    'Occupied'         => 'badge-occupied',
                                    'Under Maintenance'=> 'badge-maintenance',
                                    default            => 'badge-pending',
                                };
                                $typePill = match($room['room_type']) {
                                    'Single' => 'type-single',
                                    'Double' => 'type-double',
                                    'Triple' => 'type-triple',
                                    'Quad'   => 'type-quad',
                                    default  => 'type-single',
                                };

                                // Build query string for edit link preserving filters
                                $editUrl = 'rooms.php?edit_id=' . $room['id'];
                                if ($search)       $editUrl .= '&search='    . urlencode($search);
                                if ($filterType)   $editUrl .= '&room_type=' . urlencode($filterType);
                                if ($filterStatus) $editUrl .= '&status='    . urlencode($filterStatus);
                            ?>
                                <tr>
                                    <td style="color:var(--text-muted);font-size:.82rem;"><?php echo $rowNum++; ?></td>
                                    <td>
                                        <strong style="font-size:1rem;">
                                            Room <?php echo htmlspecialchars($room['room_number']); ?>
                                        </strong>
                                        <?php if (!empty($room['description'])): ?>
                                            <div style="font-size:.75rem;color:var(--text-muted);margin-top:2px;">
                                                <?php echo htmlspecialchars(mb_strimwidth($room['description'], 0, 45, '…')); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>Floor <?php echo $room['floor']; ?></td>
                                    <td>
                                        <span class="type-pill <?php echo $typePill; ?>">
                                            <?php echo htmlspecialchars($room['room_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $capacity; ?> pax</td>
                                    <td style="font-weight:700;">
                                        ₱<?php echo number_format($room['monthly_rate'], 2); ?>
                                    </td>
                                    <td style="min-width:100px;">
                                        <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:3px;">
                                            <?php echo $activeTenants; ?>/<?php echo $capacity; ?> tenants
                                        </div>
                                        <div class="occupancy-bar">
                                            <div class="occupancy-bar-fill"
                                                 style="width:<?php echo $occupancyPct; ?>%;
                                                        background:<?php echo $occupancyPct >= 100 ? 'var(--danger)' : ($occupancyPct >= 60 ? 'var(--warning)' : 'var(--primary)'); ?>;">
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $statusBadge; ?>">
                                            <?php echo htmlspecialchars($room['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:6px;">
                                            <!-- Edit button opens modal via URL param -->
                                            <a href="<?php echo $editUrl; ?>#editModal"
                                               class="btn-icon edit"
                                               title="Edit Room">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            <!-- Delete triggers confirm modal -->
                                            <button type="button"
                                                    class="btn-icon delete btn-delete"
                                                    title="Delete Room"
                                                    data-id="<?php echo $room['id']; ?>"
                                                    data-number="<?php echo htmlspecialchars($room['room_number']); ?>"
                                                    data-tenants="<?php echo $activeTenants; ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div class="empty-state" style="padding:60px 20px;">
                            <div class="icon"><i class="fas fa-door-open"></i></div>
                            <h3><?php echo ($search || $filterType || $filterStatus) ? 'No rooms match your search' : 'No rooms added yet'; ?></h3>
                            <p><?php echo ($search || $filterType || $filterStatus)
                                ? 'Try adjusting your filters.'
                                : 'Click "Add Room" to get started.'; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div><!-- /card-body -->
            </div><!-- /card -->
        </div><!-- /page-content -->
    </main>
</div><!-- /app-layout -->


<!-- ═══════════ ADD ROOM MODAL ═══════════ -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle" style="color:var(--primary);margin-right:8px;"></i>Add New Room</h3>
            <button class="modal-close" data-close="addModal"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="rooms.php">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Room Number <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="room_number" placeholder="e.g. 101" required maxlength="10">
                    </div>
                    <div class="form-group">
                        <label>Floor <span style="color:var(--danger)">*</span></label>
                        <input type="number" name="floor" value="1" min="1" max="20" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Room Type</label>
                        <select name="room_type">
                            <option value="Single">Single (1 pax)</option>
                            <option value="Double">Double (2 pax)</option>
                            <option value="Triple">Triple (3 pax)</option>
                            <option value="Quad">Quad (4 pax)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Capacity (pax)</label>
                        <input type="number" name="capacity" value="1" min="1" max="20" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Monthly Rate (₱)</label>
                        <input type="number" name="monthly_rate" value="3500" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Initial Status</label>
                        <select name="status">
                            <option value="Available">Available</option>
                            <option value="Under Maintenance">Under Maintenance</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Description (optional)</label>
                    <textarea name="description" placeholder="Room notes, amenities, etc."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-close="addModal">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="fas fa-save"></i> Save Room
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ═══════════ EDIT ROOM MODAL ═══════════ -->
<div class="modal-overlay <?php echo $editRoom ? 'open' : ''; ?>" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-pen" style="color:var(--primary);margin-right:8px;"></i>Edit Room</h3>
            <button class="modal-close" data-close="editModal" id="editModalClose"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="rooms.php">
            <input type="hidden" name="action"  value="edit">
            <input type="hidden" name="room_id" id="editRoomId"
                   value="<?php echo $editRoom ? $editRoom['id'] : ''; ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Room Number <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="room_number" id="editRoomNumber"
                               value="<?php echo htmlspecialchars($editRoom['room_number'] ?? ''); ?>"
                               maxlength="10" required>
                    </div>
                    <div class="form-group">
                        <label>Floor</label>
                        <input type="number" name="floor" id="editFloor"
                               value="<?php echo $editRoom['floor'] ?? 1; ?>" min="1" max="20" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Room Type</label>
                        <select name="room_type" id="editRoomType">
                            <?php foreach(['Single','Double','Triple','Quad'] as $t): ?>
                                <option value="<?php echo $t; ?>"
                                    <?php echo ($editRoom['room_type'] ?? '') === $t ? 'selected' : ''; ?>>
                                    <?php echo $t; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Capacity (pax)</label>
                        <input type="number" name="capacity" id="editCapacity"
                               value="<?php echo $editRoom['capacity'] ?? 1; ?>" min="1" max="20" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Monthly Rate (₱)</label>
                        <input type="number" name="monthly_rate" id="editMonthlyRate"
                               value="<?php echo $editRoom['monthly_rate'] ?? 0; ?>" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="editStatus">
                            <?php foreach(['Available','Occupied','Under Maintenance'] as $s): ?>
                                <option value="<?php echo $s; ?>"
                                    <?php echo ($editRoom['status'] ?? '') === $s ? 'selected' : ''; ?>>
                                    <?php echo $s; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="editDescription"><?php echo htmlspecialchars($editRoom['description'] ?? ''); ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-close="editModal" id="editCancelBtn">Cancel</button>
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="fas fa-save"></i> Update Room
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ═══════════ DELETE CONFIRM MODAL ═══════════ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal confirm-modal">
        <div class="modal-header" style="border-bottom:none;padding-bottom:0;">
            <span></span>
            <button class="modal-close" data-close="deleteModal"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="rooms.php" id="deleteForm">
            <input type="hidden" name="action"  value="delete">
            <input type="hidden" name="room_id" id="deleteRoomId">
            <div class="modal-body">
                <div class="icon-warn"><i class="fas fa-trash-alt"></i></div>
                <h4>Delete Room <span id="deleteRoomLabel"></span>?</h4>
                <p>This action is permanent and cannot be undone.<br>
                   All payment history linked to this room may be affected.</p>
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button type="button" class="btn btn-secondary" data-close="deleteModal">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Yes, Delete
                </button>
            </div>
        </form>
    </div>
</div>


<script src="assets/js/script.js"></script>
<script>
(function () {
    // ── Modal helpers ─────────────────────────────────────────
    function openModal(id)  { document.getElementById(id).classList.add('open'); }
    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
        // Remove edit_id from URL without reload when closing edit modal
        if (id === 'editModal') {
            const url = new URL(window.location);
            url.searchParams.delete('edit_id');
            window.history.replaceState({}, '', url);
        }
    }

    // Close buttons
    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', () => closeModal(btn.dataset.close));
    });
    // Click outside modal to close
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) closeModal(overlay.id);
        });
    });
    // Escape key
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(m => closeModal(m.id));
        }
    });

    // ── Add Room button ───────────────────────────────────────
    const btnAdd = document.getElementById('btnOpenAdd');
    if (btnAdd) btnAdd.addEventListener('click', () => openModal('addModal'));

    // ── Delete buttons ────────────────────────────────────────
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', () => {
            const tenants = parseInt(btn.dataset.tenants, 10);
            if (tenants > 0) {
                alert(`Cannot delete Room ${btn.dataset.number} — it has ${tenants} active tenant(s). Please remove or reassign them first.`);
                return;
            }
            document.getElementById('deleteRoomId').value    = btn.dataset.id;
            document.getElementById('deleteRoomLabel').textContent = btn.dataset.number;
            openModal('deleteModal');
        });
    });

    // ── Auto-set capacity when room type changes (Add modal) ──
    const roomTypeSelect = document.querySelector('[name="room_type"]:not(#editRoomType)');
    const capacityInput  = document.querySelector('[name="capacity"]:not(#editCapacity)');
    const typeCapMap = { Single:1, Double:2, Triple:3, Quad:4 };
    if (roomTypeSelect && capacityInput) {
        roomTypeSelect.addEventListener('change', () => {
            const cap = typeCapMap[roomTypeSelect.value];
            if (cap) capacityInput.value = cap;
        });
    }

    // ── Auto-set capacity for Edit modal too ──────────────────
    const editTypeSelect = document.getElementById('editRoomType');
    const editCapInput   = document.getElementById('editCapacity');
    if (editTypeSelect && editCapInput) {
        editTypeSelect.addEventListener('change', () => {
            const cap = typeCapMap[editTypeSelect.value];
            if (cap) editCapInput.value = cap;
        });
    }

    // ── Edit modal: opened via ?edit_id= URL param ────────────
    // If edit_id is in the URL, the PHP already set class="open" on the overlay.
    // The cancel button should redirect back, stripping edit_id.
    const editCancelBtn = document.getElementById('editCancelBtn');
    if (editCancelBtn) {
        editCancelBtn.addEventListener('click', () => {
            closeModal('editModal');
        });
    }
})();
</script>
</body>
</html>

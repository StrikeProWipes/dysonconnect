<?php
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';

requireLogin();
requireAdmin();

// ── DASHBOARD STATS ──────────────────────────────────────────

// Total bookings (all time)
$row = $conn->query("SELECT COUNT(*) AS n FROM bookings")->fetch_assoc();
$totalBookings = (int) $row['n'];

// Today's revenue from paid bookings
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(p.amount_paid), 0) AS rev
    FROM   payments p
    JOIN   bookings b ON b.booking_id = p.booking_id
    WHERE  DATE(p.payment_date) = CURDATE()
      AND  p.payment_status = 'Paid'
");
$stmt->execute();
$todayRevenue = (float) $stmt->get_result()->fetch_assoc()['rev'];
$stmt->close();

// Available (active) buses
$row = $conn->query("SELECT COUNT(*) AS n FROM buses WHERE status = 'active'")->fetch_assoc();
$activeBuses = (int) $row['n'];

// Cancelled bookings
$row = $conn->query("SELECT COUNT(*) AS n FROM bookings WHERE booking_status = 'Cancelled'")->fetch_assoc();
$cancelledBookings = (int) $row['n'];

// Pending refunds
$row = $conn->query("SELECT COUNT(*) AS n FROM bookings WHERE refund_status = 'Pending'")->fetch_assoc();
$pendingRefunds = (int) $row['n'];

// Total revenue all time
$row = $conn->query("SELECT COALESCE(SUM(amount_paid),0) AS rev FROM payments WHERE payment_status='Paid'")->fetch_assoc();
$totalRevenue = (float) $row['rev'];

// ── RECENT BOOKINGS (last 10) ────────────────────────────────
$recentBookings = $conn->query("
    SELECT
        b.booking_id,
        b.booking_reference,
        b.booking_date,
        b.total_amount,
        b.booking_status,
        b.refund_status,
        u.full_name   AS customer_name,
        r.origin,
        r.destination,
        s.departure_date,
        s.departure_time
    FROM   bookings b
    JOIN   users     u  ON u.user_id     = b.user_id
    JOIN   schedules s  ON s.schedule_id = b.schedule_id
    JOIN   routes    r  ON r.route_id    = s.route_id
    ORDER  BY b.booking_date DESC
    LIMIT  10
")->fetch_all(MYSQLI_ASSOC);

// ── POPULAR ROUTES (by booking count) ───────────────────────
$popularRoutes = $conn->query("
    SELECT
        r.origin,
        r.destination,
        r.base_price,
        COUNT(b.booking_id) AS booking_count
    FROM   routes r
    JOIN   schedules s ON s.route_id = r.route_id
    JOIN   bookings  b ON b.schedule_id = s.schedule_id
    WHERE  b.booking_status != 'Cancelled'
    GROUP  BY r.route_id
    ORDER  BY booking_count DESC
    LIMIT  5
")->fetch_all(MYSQLI_ASSOC);

// ── ALERT QUERIES ────────────────────────────────────────────
// Low seat availability — schedules with < 5 seats remaining
$lowSeatAlerts = $conn->query("
    SELECT s.schedule_id, r.origin, r.destination, s.departure_date,
           s.departure_time, s.available_seats
    FROM   schedules s
    JOIN   routes r ON r.route_id = s.route_id
    WHERE  s.status = 'scheduled' AND s.departure_date >= CURDATE()
      AND  s.available_seats > 0 AND s.available_seats < 6
    ORDER  BY s.departure_date ASC
    LIMIT  5
")->fetch_all(MYSQLI_ASSOC);

// Inactive buses count
$inactiveBusCount = (int)$conn->query(
    "SELECT COUNT(*) AS n FROM buses WHERE status IN ('inactive','maintenance')"
)->fetch_assoc()['n'];

$pageTitle    = 'Admin Dashboard';
$adminPage    = 'dashboard';
require_once 'admin_header.php';
?>

<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">Dashboard</h1>
        <p class="admin-page-sub">Overview of DysonConnect bookings and operations.</p>
    </div>
    <div class="admin-header-actions">
        <a href="<?= BASE_URL ?>admin/bookings.php" class="btn-admin-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            All Bookings
        </a>
    </div>
</div>

<!-- STAT CARDS -->
<div class="admin-stat-grid">

    <div class="admin-stat-card">
        <div class="asc-icon asc-icon--blue">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div class="asc-body">
            <span class="asc-num"><?= number_format($totalBookings) ?></span>
            <span class="asc-label">Total Bookings</span>
        </div>
    </div>

    <div class="admin-stat-card">
        <div class="asc-icon asc-icon--green">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div class="asc-body">
            <span class="asc-num"><?= formatCurrency($todayRevenue) ?></span>
            <span class="asc-label">Today's Revenue</span>
        </div>
        <div class="asc-sub">All time: <?= formatCurrency($totalRevenue) ?></div>
    </div>

    <div class="admin-stat-card">
        <div class="asc-icon asc-icon--orange">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
        </div>
        <div class="asc-body">
            <span class="asc-num"><?= number_format($activeBuses) ?></span>
            <span class="asc-label">Active Buses</span>
        </div>
    </div>

    <div class="admin-stat-card">
        <div class="asc-icon asc-icon--red">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="asc-body">
            <span class="asc-num"><?= number_format($cancelledBookings) ?></span>
            <span class="asc-label">Cancelled Bookings</span>
        </div>
        <?php if ($pendingRefunds > 0): ?>
        <div class="asc-sub asc-sub--warn"><?= $pendingRefunds ?> refund<?= $pendingRefunds > 1 ? 's' : '' ?> pending</div>
        <?php endif; ?>
    </div>

</div>

<!-- ALERT CARDS — only show if there are alerts -->
<?php if ($pendingRefunds > 0 || !empty($lowSeatAlerts) || $inactiveBusCount > 0): ?>
<div class="admin-alert-row">
    <?php if ($pendingRefunds > 0): ?>
    <div class="admin-alert-card admin-alert-card--warn">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>
            <strong><?= $pendingRefunds ?> refund<?= $pendingRefunds > 1 ? 's' : '' ?> pending</strong>
            <span>Review and process from the <a href="<?= BASE_URL ?>admin/bookings.php?status=Cancelled">Bookings page</a>.</span>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($lowSeatAlerts as $alert): ?>
    <div class="admin-alert-card admin-alert-card--info">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
        <div>
            <strong>Low seats: <?= e($alert['origin']) ?> → <?= e($alert['destination']) ?></strong>
            <span><?= (int)$alert['available_seats'] ?> seat<?= $alert['available_seats'] > 1 ? 's' : '' ?> left – <?= date('d M', strtotime($alert['departure_date'])) ?> <?= formatTime($alert['departure_time']) ?></span>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if ($inactiveBusCount > 0): ?>
    <div class="admin-alert-card admin-alert-card--neutral">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
        <div>
            <strong><?= $inactiveBusCount ?> bus<?= $inactiveBusCount > 1 ? 'es' : '' ?> inactive or in maintenance</strong>
            <span>Review fleet status on the <a href="<?= BASE_URL ?>admin/buses.php">Buses page</a>.</span>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- BOTTOM GRID: Recent Bookings + Popular Routes -->
<div class="admin-bottom-grid">

    <!-- Recent Bookings Table -->
    <div class="admin-card admin-card--wide">
        <div class="admin-card-header">
            <h2 class="admin-card-title">Recent Bookings</h2>
            <a href="<?= BASE_URL ?>admin/bookings.php" class="btn-admin-text">View all →</a>
        </div>

        <?php if (empty($recentBookings)): ?>
            <div class="admin-empty">No bookings yet.</div>
        <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th>Route</th>
                        <th>Travel Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBookings as $bk):
                        $sc = match($bk['booking_status']) {
                            'Confirmed'       => 'confirmed',
                            'Cancelled'       => 'cancelled',
                            'Pending Payment' => 'pending',
                            default           => 'pending',
                        };
                    ?>
                    <tr>
                        <td class="td-mono">
                            <a href="<?= BASE_URL ?>ticket.php?booking_id=<?= (int)$bk['booking_id'] ?>" class="admin-ref-link">
                                <?= e($bk['booking_reference']) ?>
                            </a>
                        </td>
                        <td><?= e($bk['customer_name']) ?></td>
                        <td class="td-route"><?= e($bk['origin']) ?> → <?= e($bk['destination']) ?></td>
                        <td><?= date('d M Y', strtotime($bk['departure_date'])) ?></td>
                        <td class="td-amount"><?= formatCurrency($bk['total_amount']) ?></td>
                        <td><span class="booking-status-badge bsb--<?= $sc ?>"><?= e($bk['booking_status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Popular Routes -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2 class="admin-card-title">Popular Routes</h2>
        </div>
        <?php if (empty($popularRoutes)): ?>
            <div class="admin-empty">No data yet.</div>
        <?php else: ?>
        <div class="popular-routes-list">
            <?php
            $maxCount = max(array_column($popularRoutes, 'booking_count')) ?: 1;
            foreach ($popularRoutes as $i => $pr):
                $pct = round(($pr['booking_count'] / $maxCount) * 100);
            ?>
            <div class="pr-item">
                <div class="pr-rank"><?= $i + 1 ?></div>
                <div class="pr-info">
                    <span class="pr-route"><?= e($pr['origin']) ?> → <?= e($pr['destination']) ?></span>
                    <div class="pr-bar-wrap">
                        <div class="pr-bar" style="width:<?= $pct ?>%"></div>
                    </div>
                </div>
                <span class="pr-count"><?= $pr['booking_count'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once 'admin_footer.php'; ?>

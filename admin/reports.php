<?php
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';

requireLogin();
requireAdmin();

// ── SUMMARY STATS ─────────────────────────────────────────────

$stats = [];

// Booking breakdown by status
$res = $conn->query("SELECT booking_status, COUNT(*) AS n, SUM(total_amount) AS revenue FROM bookings GROUP BY booking_status");
while ($row = $res->fetch_assoc()) {
    $stats['bookings'][$row['booking_status']] = ['count' => (int)$row['n'], 'revenue' => (float)$row['revenue']];
}

$stats['total_bookings']    = array_sum(array_column($stats['bookings'] ?? [], 'count'));
$stats['total_revenue']     = (float)($conn->query("SELECT COALESCE(SUM(amount_paid),0) AS r FROM payments WHERE payment_status='Paid'")->fetch_assoc()['r']);
$stats['cancelled_count']   = (int)($stats['bookings']['Cancelled']['count']       ?? 0);
$stats['confirmed_count']   = (int)($stats['bookings']['Confirmed']['count']       ?? 0);
$stats['pending_count']     = (int)($stats['bookings']['Pending Payment']['count'] ?? 0);
$stats['pending_refunds']   = (int)($conn->query("SELECT COUNT(*) AS n FROM bookings WHERE refund_status='Pending'")->fetch_assoc()['n']);
$stats['total_passengers']  = (int)($conn->query("SELECT COUNT(*) AS n FROM booking_passengers")->fetch_assoc()['n']);
$stats['total_customers']   = (int)($conn->query("SELECT COUNT(*) AS n FROM users WHERE role='customer'")->fetch_assoc()['n']);

// ── POPULAR ROUTES ────────────────────────────────────────────
$popularRoutes = $conn->query("
    SELECT r.origin, r.destination, r.base_price,
           COUNT(b.booking_id)              AS booking_count,
           COUNT(bp.passenger_id)           AS passenger_count,
           COALESCE(SUM(b.total_amount), 0) AS route_revenue
    FROM   routes r
    JOIN   schedules s  ON s.route_id    = r.route_id
    JOIN   bookings  b  ON b.schedule_id = s.schedule_id AND b.booking_status = 'Confirmed'
    JOIN   booking_passengers bp ON bp.booking_id = b.booking_id
    GROUP  BY r.route_id
    ORDER  BY booking_count DESC
    LIMIT  8
")->fetch_all(MYSQLI_ASSOC);

// ── MONTHLY SUMMARY (last 6 months) ──────────────────────────
$monthlySummary = $conn->query("
    SELECT
        DATE_FORMAT(b.booking_date, '%Y-%m')  AS month_key,
        DATE_FORMAT(b.booking_date, '%b %Y')  AS month_label,
        COUNT(b.booking_id)                    AS bookings,
        SUM(CASE WHEN b.booking_status='Confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN b.booking_status='Cancelled' THEN 1 ELSE 0 END) AS cancelled,
        COALESCE(SUM(CASE WHEN b.booking_status='Confirmed' THEN b.total_amount END), 0) AS revenue
    FROM bookings b
    WHERE b.booking_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP  BY month_key, month_label
    ORDER  BY month_key DESC
")->fetch_all(MYSQLI_ASSOC);

// ── PASSENGER TYPE BREAKDOWN ──────────────────────────────────
$passengerTypes = $conn->query("
    SELECT passenger_type, COUNT(*) AS n, COALESCE(SUM(fare_amount),0) AS revenue
    FROM   booking_passengers
    GROUP  BY passenger_type
    ORDER  BY n DESC
")->fetch_all(MYSQLI_ASSOC);

// ── PAYMENT METHOD BREAKDOWN ──────────────────────────────────
$paymentMethods = $conn->query("
    SELECT payment_method, payment_status, COUNT(*) AS n, COALESCE(SUM(amount_paid),0) AS total
    FROM   payments
    GROUP  BY payment_method, payment_status
    ORDER  BY n DESC
")->fetch_all(MYSQLI_ASSOC);

// ── BUS UTILISATION ───────────────────────────────────────────
$busUtil = $conn->query("
    SELECT b.bus_name, b.bus_number, b.bus_type, b.seat_capacity,
           COUNT(DISTINCT s.schedule_id)   AS trips,
           COUNT(DISTINCT bp.passenger_id) AS passengers_carried
    FROM   buses b
    LEFT JOIN schedules s  ON s.bus_id       = b.bus_id
    LEFT JOIN bookings  bk ON bk.schedule_id = s.schedule_id AND bk.booking_status = 'Confirmed'
    LEFT JOIN booking_passengers bp ON bp.booking_id = bk.booking_id
    GROUP  BY b.bus_id
    ORDER  BY passengers_carried DESC
    LIMIT  6
")->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Reports';
$adminPage = 'reports';
require_once 'admin_header.php';
?>

<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">Reports</h1>
        <p class="admin-page-sub">Booking statistics, revenue, and operational overview. Data is live from the database.</p>
    </div>
    <span class="report-timestamp">Generated: <?= date('d M Y, g:i A') ?></span>
</div>

<!-- TOP SUMMARY CARDS -->
<div class="report-summary-grid">
    <div class="report-stat-card report-stat-card--blue">
        <div class="rsc-icon">📋</div>
        <div class="rsc-body">
            <span class="rsc-num"><?= number_format($stats['total_bookings']) ?></span>
            <span class="rsc-label">Total Bookings</span>
        </div>
        <div class="rsc-breakdown">
            <span class="rsc-sub rsc-sub--green"><?= $stats['confirmed_count'] ?> confirmed</span>
            <span class="rsc-sub rsc-sub--red"><?= $stats['cancelled_count'] ?> cancelled</span>
            <span class="rsc-sub"><?= $stats['pending_count'] ?> pending</span>
        </div>
    </div>

    <div class="report-stat-card report-stat-card--green">
        <div class="rsc-icon">💰</div>
        <div class="rsc-body">
            <span class="rsc-num"><?= formatCurrency($stats['total_revenue']) ?></span>
            <span class="rsc-label">Total Revenue</span>
        </div>
        <div class="rsc-breakdown">
            <span class="rsc-sub">from confirmed & paid bookings</span>
        </div>
    </div>

    <div class="report-stat-card report-stat-card--orange">
        <div class="rsc-icon">👥</div>
        <div class="rsc-body">
            <span class="rsc-num"><?= number_format($stats['total_passengers']) ?></span>
            <span class="rsc-label">Total Passengers</span>
        </div>
        <div class="rsc-breakdown">
            <span class="rsc-sub"><?= $stats['total_customers'] ?> registered customers</span>
        </div>
    </div>

    <div class="report-stat-card report-stat-card--red">
        <div class="rsc-icon">❌</div>
        <div class="rsc-body">
            <span class="rsc-num"><?= number_format($stats['cancelled_count']) ?></span>
            <span class="rsc-label">Cancellations</span>
        </div>
        <div class="rsc-breakdown">
            <?php if ($stats['pending_refunds'] > 0): ?>
                <span class="rsc-sub rsc-sub--warn"><?= $stats['pending_refunds'] ?> refund<?= $stats['pending_refunds'] > 1 ? 's' : '' ?> pending</span>
            <?php else: ?>
                <span class="rsc-sub rsc-sub--green">No pending refunds</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MONTHLY SUMMARY + POPULAR ROUTES -->
<div class="report-grid-2">

    <!-- Monthly Summary -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2 class="admin-card-title">Monthly Summary <span class="report-period">Last 6 months</span></h2>
        </div>
        <?php if (empty($monthlySummary)): ?>
            <div class="admin-empty">No booking data yet.</div>
        <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th class="td-center">Total</th>
                        <th class="td-center">Confirmed</th>
                        <th class="td-center">Cancelled</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $maxRevenue = max(array_column($monthlySummary, 'revenue')) ?: 1;
                foreach ($monthlySummary as $mo):
                    $barPct = round(($mo['revenue'] / $maxRevenue) * 100);
                ?>
                <tr>
                    <td class="td-nowrap"><strong><?= e($mo['month_label']) ?></strong></td>
                    <td class="td-center"><?= (int)$mo['bookings'] ?></td>
                    <td class="td-center"><span style="color:var(--green);font-weight:600"><?= (int)$mo['confirmed'] ?></span></td>
                    <td class="td-center"><span style="color:var(--red);font-weight:600"><?= (int)$mo['cancelled'] ?></span></td>
                    <td>
                        <div class="report-bar-cell">
                            <div class="report-mini-bar" style="width:<?= $barPct ?>%"></div>
                            <span><?= formatCurrency($mo['revenue']) ?></span>
                        </div>
                    </td>
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
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Route</th>
                        <th class="td-center">Bookings</th>
                        <th class="td-center">Passengers</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $maxBook = max(array_column($popularRoutes, 'booking_count')) ?: 1;
                foreach ($popularRoutes as $i => $pr):
                    $pct = round(($pr['booking_count'] / $maxBook) * 100);
                ?>
                <tr>
                    <td>
                        <div class="report-route-cell">
                            <span class="report-rank"><?= $i + 1 ?></span>
                            <span><?= e($pr['origin']) ?> → <?= e($pr['destination']) ?></span>
                        </div>
                    </td>
                    <td class="td-center"><strong><?= (int)$pr['booking_count'] ?></strong></td>
                    <td class="td-center"><?= (int)$pr['passenger_count'] ?></td>
                    <td class="td-amount"><?= formatCurrency($pr['route_revenue']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- PASSENGER TYPES + PAYMENT METHODS + BUS UTILISATION -->
<div class="report-grid-3">

    <!-- Passenger Type Breakdown -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2 class="admin-card-title">Passenger Types</h2>
        </div>
        <?php if (empty($passengerTypes)): ?>
            <div class="admin-empty">No data yet.</div>
        <?php else: ?>
        <?php $totalPax = array_sum(array_column($passengerTypes, 'n')); ?>
        <div class="pax-type-list">
            <?php foreach ($passengerTypes as $pt):
                $pct = $totalPax > 0 ? round(($pt['n'] / $totalPax) * 100) : 0;
            ?>
            <div class="pax-type-row">
                <div class="pax-type-info">
                    <span class="pax-type-name"><?= e($pt['passenger_type']) ?></span>
                    <span class="pax-type-count"><?= (int)$pt['n'] ?> passengers (<?= $pct ?>%)</span>
                    <div class="pax-type-bar-wrap">
                        <div class="pax-type-bar" style="width:<?= $pct ?>%"></div>
                    </div>
                </div>
                <span class="pax-type-rev"><?= formatCurrency($pt['revenue']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="report-total-row">
            <span>Total passengers</span>
            <strong><?= number_format($totalPax) ?></strong>
        </div>
        <?php endif; ?>
    </div>

    <!-- Payment Methods -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2 class="admin-card-title">Payment Methods</h2>
        </div>
        <?php if (empty($paymentMethods)): ?>
            <div class="admin-empty">No data yet.</div>
        <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Method</th>
                        <th>Status</th>
                        <th class="td-center">Count</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($paymentMethods as $pm): ?>
                <tr>
                    <td><strong><?= e($pm['payment_method']) ?></strong></td>
                    <td>
                        <span class="td-pstatus td-pstatus--<?= strtolower($pm['payment_status']) ?>">
                            <?= e($pm['payment_status']) ?>
                        </span>
                    </td>
                    <td class="td-center"><?= (int)$pm['n'] ?></td>
                    <td class="td-amount"><?= formatCurrency($pm['total']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bus Utilisation -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2 class="admin-card-title">Bus Utilisation</h2>
        </div>
        <?php if (empty($busUtil)): ?>
            <div class="admin-empty">No data yet.</div>
        <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Bus</th>
                        <th class="td-center">Trips</th>
                        <th class="td-center">Passengers</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($busUtil as $bu): ?>
                <tr>
                    <td>
                        <span class="td-name"><?= e($bu['bus_name']) ?></span>
                        <span class="td-email"><?= e($bu['bus_number']) ?> · <?= e($bu['bus_type']) ?></span>
                    </td>
                    <td class="td-center"><?= (int)$bu['trips'] ?></td>
                    <td class="td-center"><strong><?= (int)$bu['passengers_carried'] ?></strong></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once 'admin_footer.php'; ?>

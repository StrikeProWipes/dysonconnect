<?php
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';

requireLogin();
requireAdmin();

// ── UPDATE REFUND STATUS ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_refund'])) {
    $bookingId   = (int) $_POST['booking_id'];
    $refundStatus = $_POST['refund_status'] ?? '';
    $validStatuses = ['Not Applicable', 'Pending', 'Processed', 'Rejected'];

    if ($bookingId > 0 && in_array($refundStatus, $validStatuses)) {
        $stmt = $conn->prepare("UPDATE bookings SET refund_status = ? WHERE booking_id = ?");
        $stmt->bind_param('si', $refundStatus, $bookingId);
        $stmt->execute();
        $stmt->close();
        setFlash('success', 'Refund status updated.');
    } else {
        setFlash('error', 'Invalid update request.');
    }

    // Preserve filter on redirect
    $qs = http_build_query(array_filter([
        'status' => $_POST['filter_status'] ?? '',
        'search' => $_POST['filter_search'] ?? '',
    ]));
    header('Location: ' . BASE_URL . 'admin/bookings.php' . ($qs ? '?' . $qs : ''));
    exit;
}

// ── FILTERS ──────────────────────────────────────────────────
$filterStatus = trim($_GET['status'] ?? '');
$filterSearch = trim($_GET['search'] ?? '');

$validStatuses = ['Confirmed', 'Cancelled', 'Pending Payment'];

// ── FETCH BOOKINGS ────────────────────────────────────────────
$sql = "
    SELECT
        b.booking_id,
        b.booking_reference,
        b.booking_date,
        b.total_amount,
        b.booking_status,
        b.refund_status,
        u.full_name      AS customer_name,
        u.email          AS customer_email,
        r.origin,
        r.destination,
        s.departure_date,
        s.departure_time,
        s.arrival_time,
        bus.bus_name,
        p.payment_method,
        p.payment_status,
        COUNT(bp.passenger_id) AS passenger_count
    FROM   bookings b
    JOIN   users     u   ON u.user_id     = b.user_id
    JOIN   schedules s   ON s.schedule_id = b.schedule_id
    JOIN   routes    r   ON r.route_id    = s.route_id
    JOIN   buses     bus ON bus.bus_id    = s.bus_id
    LEFT JOIN payments p ON p.booking_id  = b.booking_id
    LEFT JOIN booking_passengers bp ON bp.booking_id = b.booking_id
    WHERE  1=1
";

$params = [];
$types  = '';

if (!empty($filterStatus) && in_array($filterStatus, $validStatuses)) {
    $sql    .= " AND b.booking_status = ?";
    $params[] = $filterStatus;
    $types   .= 's';
}

if (!empty($filterSearch)) {
    $like     = '%' . $filterSearch . '%';
    $sql     .= " AND (b.booking_reference LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sss';
}

$sql .= " GROUP BY b.booking_id ORDER BY b.booking_date DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── SUMMARY COUNTS FOR FILTER TABS ───────────────────────────
$counts = [];
$res = $conn->query("
    SELECT booking_status, COUNT(*) AS n
    FROM   bookings
    GROUP  BY booking_status
");
while ($row = $res->fetch_assoc()) {
    $counts[$row['booking_status']] = (int) $row['n'];
}
$counts['All'] = array_sum($counts);

$pageTitle = 'Manage Bookings';
$adminPage = 'bookings';
require_once 'admin_header.php';
?>

<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">Bookings</h1>
        <p class="admin-page-sub">All customer bookings. Update refund status as needed.</p>
    </div>
</div>

<!-- FILTER TABS + SEARCH -->
<div class="admin-filter-bar">
    <div class="admin-filter-tabs">
        <a href="<?= BASE_URL ?>admin/bookings.php"
           class="aft-tab <?= empty($filterStatus) ? 'aft-tab--active' : '' ?>">
            All <span class="aft-count"><?= $counts['All'] ?? 0 ?></span>
        </a>
        <a href="<?= BASE_URL ?>admin/bookings.php?status=Confirmed"
           class="aft-tab <?= $filterStatus === 'Confirmed' ? 'aft-tab--active' : '' ?>">
            Confirmed <span class="aft-count"><?= $counts['Confirmed'] ?? 0 ?></span>
        </a>
        <a href="<?= BASE_URL ?>admin/bookings.php?status=Pending+Payment"
           class="aft-tab <?= $filterStatus === 'Pending Payment' ? 'aft-tab--active' : '' ?>">
            Pending <span class="aft-count"><?= $counts['Pending Payment'] ?? 0 ?></span>
        </a>
        <a href="<?= BASE_URL ?>admin/bookings.php?status=Cancelled"
           class="aft-tab <?= $filterStatus === 'Cancelled' ? 'aft-tab--active' : '' ?>">
            Cancelled <span class="aft-count"><?= $counts['Cancelled'] ?? 0 ?></span>
        </a>
    </div>

    <form method="GET" action="<?= BASE_URL ?>admin/bookings.php" class="admin-search-form">
        <?php if ($filterStatus): ?>
            <input type="hidden" name="status" value="<?= e($filterStatus) ?>">
        <?php endif; ?>
        <div class="admin-search-wrap">
            <svg class="admin-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="search" value="<?= e($filterSearch) ?>"
                   placeholder="Search by reference, name, or email…"
                   class="admin-search-input">
        </div>
        <button type="submit" class="btn-admin-primary">Search</button>
        <?php if ($filterSearch): ?>
            <a href="<?= BASE_URL ?>admin/bookings.php<?= $filterStatus ? '?status=' . urlencode($filterStatus) : '' ?>" class="btn-admin-ghost">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- RESULTS COUNT -->
<div class="admin-results-bar">
    <span><?= count($bookings) ?> booking<?= count($bookings) !== 1 ? 's' : '' ?> shown</span>
</div>

<!-- BOOKINGS TABLE -->
<div class="admin-card admin-card--flush">

    <?php if (empty($bookings)): ?>
        <div class="admin-empty admin-empty--tall">No bookings match your filter.</div>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Customer</th>
                    <th>Route</th>
                    <th>Travel Date</th>
                    <th>Pax</th>
                    <th>Total</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Refund</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $bk):
                    $sc = match($bk['booking_status']) {
                        'Confirmed'       => 'confirmed',
                        'Cancelled'       => 'cancelled',
                        'Pending Payment' => 'pending',
                        default           => 'pending',
                    };
                ?>
                <tr class="<?= $bk['booking_status'] === 'Cancelled' ? 'tr--cancelled' : '' ?>">
                    <td class="td-mono">
                        <a href="<?= BASE_URL ?>ticket.php?booking_id=<?= (int)$bk['booking_id'] ?>" class="admin-ref-link" title="View ticket">
                            <?= e($bk['booking_reference']) ?>
                        </a>
                    </td>
                    <td>
                        <span class="td-name"><?= e($bk['customer_name']) ?></span>
                        <span class="td-email"><?= e($bk['customer_email']) ?></span>
                    </td>
                    <td class="td-route"><?= e($bk['origin']) ?> → <?= e($bk['destination']) ?></td>
                    <td class="td-nowrap">
                        <?= date('d M Y', strtotime($bk['departure_date'])) ?>
                        <span class="td-time"><?= formatTime($bk['departure_time']) ?></span>
                    </td>
                    <td class="td-center"><?= (int)$bk['passenger_count'] ?></td>
                    <td class="td-amount"><?= formatCurrency($bk['total_amount']) ?></td>
                    <td>
                        <?php if ($bk['payment_method']): ?>
                            <span class="td-payment"><?= e($bk['payment_method']) ?></span>
                            <span class="td-pstatus td-pstatus--<?= strtolower(str_replace(' ', '-', $bk['payment_status'] ?? '')) ?>">
                                <?= e($bk['payment_status'] ?? '') ?>
                            </span>
                        <?php else: ?>
                            <span class="td-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="booking-status-badge bsb--<?= $sc ?>"><?= e($bk['booking_status']) ?></span></td>
                    <td>
                        <span class="refund-badge <?= getRefundClass($bk['refund_status']) ?>">
                            <?= e($bk['refund_status']) ?>
                        </span>
                    </td>
                    <td>
                        <!-- Inline refund update form -->
                        <form method="POST" action="<?= BASE_URL ?>admin/bookings.php" class="refund-update-form">
                            <input type="hidden" name="booking_id" value="<?= (int)$bk['booking_id'] ?>">
                            <input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>">
                            <input type="hidden" name="filter_search" value="<?= e($filterSearch) ?>">
                            <select name="refund_status" class="refund-select" onchange="this.form.submit()">
                                <?php foreach (['Not Applicable', 'Pending', 'Processed', 'Rejected'] as $rs): ?>
                                    <option value="<?= $rs ?>" <?= $rs === $bk['refund_status'] ? 'selected' : '' ?>>
                                        <?= $rs ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="update_refund" value="1">
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
function getRefundClass(string $status): string {
    return match($status) {
        'Pending'        => 'refund--pending',
        'Processed'      => 'refund--processed',
        'Rejected'       => 'refund--rejected',
        default          => '',
    };
}
?>

<?php require_once 'admin_footer.php'; ?>

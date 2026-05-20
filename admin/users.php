<?php
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';

requireLogin();
requireAdmin();

// ── SEARCH / FILTER ───────────────────────────────────────────
$search = trim($_GET['search'] ?? '');

// ── FETCH CUSTOMERS ───────────────────────────────────────────
// Join to bookings to get each user's total booking count and
// the date of their most recent booking.
$sql = "
    SELECT
        u.user_id,
        u.full_name,
        u.email,
        u.phone,
        u.role,
        u.created_at,
        COUNT(b.booking_id)                              AS total_bookings,
        SUM(CASE WHEN b.booking_status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
        MAX(b.booking_date)                              AS last_booking_date,
        COALESCE(SUM(b.total_amount), 0)                AS total_spent
    FROM users u
    LEFT JOIN bookings b ON b.user_id = u.user_id
    WHERE u.role = 'customer'
";

$params = [];
$types  = '';

if (!empty($search)) {
    $like    = '%' . $search . '%';
    $sql    .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sss';
}

$sql .= " GROUP BY u.user_id ORDER BY u.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── QUICK STATS ───────────────────────────────────────────────
$totalCustomers = (int)$conn->query("SELECT COUNT(*) AS n FROM users WHERE role='customer'")->fetch_assoc()['n'];
$activeToday    = (int)$conn->query("SELECT COUNT(DISTINCT user_id) AS n FROM bookings WHERE DATE(booking_date)=CURDATE()")->fetch_assoc()['n'];

$pageTitle = 'Manage Customers';
$adminPage = 'users';
require_once 'admin_header.php';
?>

<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">Customers</h1>
        <p class="admin-page-sub">All registered customer accounts. View booking history and account details.</p>
    </div>
</div>

<!-- STAT CARDS -->
<div class="admin-stat-grid admin-stat-grid--3col">
    <div class="admin-stat-card">
        <div class="asc-icon asc-icon--blue">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="asc-body">
            <span class="asc-num"><?= number_format($totalCustomers) ?></span>
            <span class="asc-label">Total Customers</span>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="asc-icon asc-icon--green">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="asc-body">
            <span class="asc-num"><?= count($customers) ?></span>
            <span class="asc-label"><?= !empty($search) ? 'Matching Search' : 'Showing All' ?></span>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="asc-icon asc-icon--orange">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <div class="asc-body">
            <span class="asc-num"><?= number_format($activeToday) ?></span>
            <span class="asc-label">Active Today</span>
        </div>
    </div>
</div>

<!-- SEARCH BAR -->
<form method="GET" action="<?= BASE_URL ?>admin/users.php" class="admin-filter-bar admin-filter-bar--spaced">
    <div class="admin-search-wrap">
        <svg class="admin-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="search" value="<?= e($search) ?>"
               placeholder="Search by name, email, or phone…"
               class="admin-search-input">
    </div>
    <button type="submit" class="btn-admin-primary">Search</button>
    <?php if (!empty($search)): ?>
        <a href="<?= BASE_URL ?>admin/users.php" class="btn-admin-ghost">Clear</a>
    <?php endif; ?>
</form>

<div class="admin-results-bar">
    <span><?= count($customers) ?> customer<?= count($customers) !== 1 ? 's' : '' ?> found<?= !empty($search) ? ' for "' . e($search) . '"' : '' ?></span>
</div>

<!-- CUSTOMERS TABLE -->
<div class="admin-card admin-card--flush">
    <?php if (empty($customers)): ?>
        <div class="admin-empty" style="padding:48px;">
            <?= !empty($search) ? 'No customers match your search.' : 'No customers registered yet.' ?>
        </div>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th>Registered</th>
                    <th>Total Bookings</th>
                    <th>Confirmed</th>
                    <th>Total Spent</th>
                    <th>Last Booking</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td>
                        <span class="td-name"><?= e($c['full_name']) ?></span>
                        <span class="td-email"><?= e($c['email']) ?></span>
                    </td>
                    <td class="td-muted">
                        <?= !empty($c['phone']) ? e($c['phone']) : '<span class="td-muted">—</span>' ?>
                    </td>
                    <td class="td-nowrap">
                        <?= date('d M Y', strtotime($c['created_at'])) ?>
                    </td>
                    <td class="td-center">
                        <?php if ((int)$c['total_bookings'] > 0): ?>
                            <a href="<?= BASE_URL ?>admin/bookings.php?search=<?= urlencode($c['email']) ?>" class="admin-ref-link">
                                <?= (int)$c['total_bookings'] ?>
                            </a>
                        <?php else: ?>
                            <span class="td-muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="td-center">
                        <?php
                        $conf = (int)$c['confirmed_bookings'];
                        $cls  = $conf > 0 ? 'bsb--confirmed' : '';
                        echo $conf > 0
                            ? "<span class=\"booking-status-badge $cls\">$conf</span>"
                            : '<span class="td-muted">0</span>';
                        ?>
                    </td>
                    <td class="td-amount">
                        <?= (float)$c['total_spent'] > 0 ? formatCurrency($c['total_spent']) : '<span class="td-muted">—</span>' ?>
                    </td>
                    <td class="td-muted td-nowrap">
                        <?= !empty($c['last_booking_date'])
                            ? date('d M Y', strtotime($c['last_booking_date']))
                            : '<span class="td-muted">Never</span>' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'admin_footer.php'; ?>

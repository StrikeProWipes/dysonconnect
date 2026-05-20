<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

// Optional filter by origin
$filterOrigin = trim($_GET['origin'] ?? '');
$filterDest   = trim($_GET['destination'] ?? '');

// Build query — show active routes, optionally filtered
$sql = "
    SELECT
        r.route_id,
        r.origin,
        r.destination,
        r.distance_km,
        r.base_price,
        r.route_tags,
        r.status,
        COUNT(DISTINCT s.schedule_id)              AS total_schedules,
        MIN(CASE WHEN s.departure_date >= CURDATE()
                  AND s.status = 'scheduled'
             THEN s.departure_date END)            AS next_departure,
        SUM(CASE WHEN s.departure_date >= CURDATE()
                  AND s.status = 'scheduled'
             THEN s.available_seats ELSE 0 END)    AS total_available_seats
    FROM routes r
    LEFT JOIN schedules s ON s.route_id = r.route_id
    WHERE r.status = 'active'
";

$params = [];
$types  = '';

if (!empty($filterOrigin)) {
    $sql .= " AND r.origin = ?";
    $params[] = $filterOrigin;
    $types   .= 's';
}

if (!empty($filterDest)) {
    $sql .= " AND r.destination = ?";
    $params[] = $filterDest;
    $types   .= 's';
}

$sql .= " GROUP BY r.route_id ORDER BY r.origin, r.destination";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$routes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Distinct origins and destinations for filter dropdowns
$origins = [];
$dests   = [];
$res = $conn->query("SELECT DISTINCT origin FROM routes WHERE status='active' ORDER BY origin");
while ($row = $res->fetch_assoc()) $origins[] = $row['origin'];
$res = $conn->query("SELECT DISTINCT destination FROM routes WHERE status='active' ORDER BY destination");
while ($row = $res->fetch_assoc()) $dests[] = $row['destination'];

$pageTitle = 'Routes';
require_once 'includes/header.php';
?>

<div class="page-hero page-hero--blue">
    <div class="container">
        <h1 class="page-hero-title">All Routes</h1>
        <p class="page-hero-sub">Browse intercity connections across Victoria and New South Wales.</p>
    </div>
</div>

<!-- FILTER BAR -->
<div class="routes-filter-bar">
    <div class="container">
        <form method="GET" action="<?= BASE_URL ?>routes.php" class="routes-filter-form">
            <div class="rf-field">
                <label for="rf-origin">From</label>
                <select name="origin" id="rf-origin">
                    <option value="">All origins</option>
                    <?php foreach ($origins as $o): ?>
                        <option value="<?= e($o) ?>" <?= $o === $filterOrigin ? 'selected' : '' ?>><?= e($o) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rf-field">
                <label for="rf-dest">To</label>
                <select name="destination" id="rf-dest">
                    <option value="">All destinations</option>
                    <?php foreach ($dests as $d): ?>
                        <option value="<?= e($d) ?>" <?= $d === $filterDest ? 'selected' : '' ?>><?= e($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-rf-filter">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                Filter
            </button>
            <?php if ($filterOrigin || $filterDest): ?>
                <a href="<?= BASE_URL ?>routes.php" class="btn-rf-clear">Clear</a>
            <?php endif; ?>
        </form>
        <span class="routes-result-count"><?= count($routes) ?> route<?= count($routes) !== 1 ? 's' : '' ?> found</span>
    </div>
</div>

<!-- ROUTES LIST -->
<section class="routes-page-section">
    <div class="container">

        <?php if (empty($routes)): ?>
            <div class="empty-state">
                <div class="empty-icon">🗺️</div>
                <h2>No routes found</h2>
                <p>Try adjusting your filter or <a href="<?= BASE_URL ?>routes.php">view all routes</a>.</p>
            </div>
        <?php else: ?>
            <div class="routes-page-grid">
                <?php foreach ($routes as $route):
                    $tags       = parseRouteTags($route['route_tags']);
                    $hasTrips   = (int)$route['total_available_seats'] > 0;
                    $nextDep    = $route['next_departure'];
                ?>
                <div class="route-page-card">

                    <div class="rpc-header">
                        <div class="rpc-path">
                            <div class="rpc-city-row">
                                <span class="rpc-city"><?= e($route['origin']) ?></span>
                                <div class="rpc-line">
                                    <span class="rpc-dot"></span>
                                    <span class="rpc-dash"></span>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="color:var(--orange)"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                </div>
                                <span class="rpc-city"><?= e($route['destination']) ?></span>
                            </div>
                        </div>
                        <div class="rpc-price-col">
                            <span class="rpc-from-label">from</span>
                            <span class="rpc-price"><?= formatCurrency($route['base_price']) ?></span>
                        </div>
                    </div>

                    <div class="rpc-meta">
                        <span class="rpc-meta-item">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <?= e($route['distance_km']) ?> km
                        </span>
                        <?php if ($nextDep): ?>
                        <span class="rpc-meta-item rpc-meta-item--green">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            Next: <?= date('d M', strtotime($nextDep)) ?>
                        </span>
                        <?php endif; ?>
                        <span class="rpc-meta-item">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <?= (int)$route['total_schedules'] ?> scheduled trip<?= (int)$route['total_schedules'] !== 1 ? 's' : '' ?>
                        </span>
                        <?php if ($hasTrips): ?>
                        <span class="rpc-meta-item rpc-meta-item--blue">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/></svg>
                            <?= (int)$route['total_available_seats'] ?> seats available
                        </span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($tags)): ?>
                    <div class="rpc-tags">
                        <?php foreach ($tags as $tag): ?>
                            <span class="route-tag"><?= e($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="rpc-footer">
                        <?php if ($hasTrips): ?>
                            <a href="<?= BASE_URL ?>search.php?origin=<?= urlencode($route['origin']) ?>&destination=<?= urlencode($route['destination']) ?>"
                               class="btn-book-route">
                                View Available Trips
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            </a>
                        <?php else: ?>
                            <span class="btn-book-route btn-book-route--unavailable">No Upcoming Trips</span>
                        <?php endif; ?>
                        <div class="fare-types-mini">
                            <span title="Adult">A: <?= formatCurrency(calculateFare($route['base_price'], 'Adult')) ?></span>
                            <span title="Student">S: <?= formatCurrency(calculateFare($route['base_price'], 'Student')) ?></span>
                            <span title="Senior">Sr: <?= formatCurrency(calculateFare($route['base_price'], 'Senior')) ?></span>
                            <span title="Child">C: <?= formatCurrency(calculateFare($route['base_price'], 'Child')) ?></span>
                        </div>
                    </div>

                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</section>

<?php require_once 'includes/footer.php'; ?>

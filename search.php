<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

// --- Inputs ---
$origin        = trim($_GET['origin']        ?? '');
$destination   = trim($_GET['destination']   ?? '');
$departureDate = trim($_GET['departure_date'] ?? '');

// --- Validation ---
$errors = [];
if (empty($origin))        $errors[] = 'Please select a departure city.';
if (empty($destination))   $errors[] = 'Please select a destination.';
if ($origin === $destination && !empty($origin)) $errors[] = 'Origin and destination cannot be the same.';
if (empty($departureDate)) {
    $errors[] = 'Please select a travel date.';
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $departureDate) || strtotime($departureDate) === false) {
    $errors[] = 'Travel date is not valid.';
} elseif ($departureDate < date('Y-m-d')) {
    $errors[] = 'Travel date cannot be in the past.';
}

// --- Available origins/destinations for the modify-search dropdowns ---
$origins = [];
$destinations = [];
$res = $conn->query("SELECT DISTINCT origin FROM routes WHERE status='active' ORDER BY origin");
while ($row = $res->fetch_assoc()) $origins[] = $row['origin'];
$res = $conn->query("SELECT DISTINCT destination FROM routes WHERE status='active' ORDER BY destination");
while ($row = $res->fetch_assoc()) $destinations[] = $row['destination'];

// --- Run search ---
$results = [];
if (empty($errors)) {
    $stmt = $conn->prepare("
        SELECT *
        FROM   v_schedule_summary
        WHERE  origin          = ?
          AND  destination     = ?
          AND  departure_date  = ?
          AND  schedule_status IN ('scheduled')
        ORDER BY departure_time ASC
    ");
    $stmt->bind_param('sss', $origin, $destination, $departureDate);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$pageTitle = 'Search Results';
require_once 'includes/header.php';
?>

<!-- PAGE HEADER / MODIFY SEARCH BAR -->
<section class="search-bar-section">
    <div class="container">
        <form action="<?= BASE_URL ?>search.php" method="GET" class="modify-search-form" id="search-form">
            <div class="modify-search-fields">
                <div class="modify-field">
                    <label for="origin">From</label>
                    <select name="origin" id="origin" required>
                        <option value="">Select city</option>
                        <?php foreach ($origins as $o): ?>
                            <option value="<?= e($o) ?>" <?= $o === $origin ? 'selected' : '' ?>><?= e($o) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="button" class="modify-swap" id="swap-btn" title="Swap">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 16V4m0 0L3 8m4-4l4 4M17 8v12m0 0l4-4m-4 4l-4-4"/></svg>
                </button>

                <div class="modify-field">
                    <label for="destination">To</label>
                    <select name="destination" id="destination" required>
                        <option value="">Select city</option>
                        <?php foreach ($destinations as $d): ?>
                            <option value="<?= e($d) ?>" <?= $d === $destination ? 'selected' : '' ?>><?= e($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modify-field">
                    <label for="departure_date">Date</label>
                    <input type="date" name="departure_date" id="departure_date"
                           value="<?= e($departureDate) ?>"
                           min="<?= date('Y-m-d') ?>" required>
                </div>

                <button type="submit" class="btn-search-modify">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Search
                </button>
            </div>
        </form>
    </div>
</section>

<!-- RESULTS -->
<section class="results-section">
    <div class="container results-layout">

        <!-- Left: Results -->
        <div class="results-main">

            <!-- Breadcrumb / heading -->
            <div class="results-heading">
                <?php if (!empty($origin) && !empty($destination)): ?>
                    <h1 class="results-title">
                        <?= e($origin) ?>
                        <span class="results-arrow">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </span>
                        <?= e($destination) ?>
                    </h1>
                    <?php if (!empty($departureDate)): ?>
                        <p class="results-date"><?= formatDate($departureDate) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <h1 class="results-title">Bus Search</h1>
                <?php endif; ?>
            </div>

            <!-- Validation errors -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $err): ?>
                        <p><?= e($err) ?></p>
                    <?php endforeach; ?>
                </div>

            <!-- No results -->
            <?php elseif (empty($results)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🚌</div>
                    <h2>No trips found</h2>
                    <p>
                        There are no scheduled trips from <strong><?= e($origin) ?></strong> to
                        <strong><?= e($destination) ?></strong> on <strong><?= formatDate($departureDate) ?></strong>.
                    </p>
                    <p>Try a different date or check our <a href="<?= BASE_URL ?>routes.php">routes page</a> for all available connections.</p>
                    <a href="<?= BASE_URL ?>index.php" class="btn-primary-lg" style="margin-top:24px;color:#fff!important;text-decoration:none;">&#8592; Back to Home</a>
                </div>

            <!-- Results list -->
            <?php else: ?>
                <div class="results-count">
                    <?= count($results) ?> trip<?= count($results) !== 1 ? 's' : '' ?> available
                </div>

                <div class="trip-list">
                    <?php foreach ($results as $trip):
                        $tags     = parseRouteTags($trip['route_tags']);
                        $duration = $trip['journey_duration'];
                        // Format HH:MM from HH:MM:SS
                        $durParts = explode(':', $duration);
                        $durText  = $durParts[0] . 'h ' . $durParts[1] . 'm';
                        $seatsLeft = (int) $trip['available_seats'];
                        $lowSeats  = $seatsLeft > 0 && $seatsLeft <= 5;
                        $soldOut   = $seatsLeft === 0;
                    ?>
                    <div class="trip-card <?= $soldOut ? 'trip-card--sold-out' : '' ?>">
                        <div class="trip-card-main">

                            <!-- Time & Route -->
                            <div class="trip-times">
                                <div class="trip-time-block">
                                    <span class="trip-time"><?= formatTime($trip['departure_time']) ?></span>
                                    <span class="trip-city"><?= e($trip['origin']) ?></span>
                                </div>
                                <div class="trip-duration-block">
                                    <span class="trip-duration"><?= e($durText) ?></span>
                                    <div class="trip-line">
                                        <span class="trip-dot"></span>
                                        <span class="trip-dash-line"></span>
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                    </div>
                                    <span class="trip-direct">Direct</span>
                                </div>
                                <div class="trip-time-block trip-time-block--right">
                                    <span class="trip-time"><?= formatTime($trip['arrival_time']) ?></span>
                                    <span class="trip-city"><?= e($trip['destination']) ?></span>
                                </div>
                            </div>

                            <!-- Bus Info -->
                            <div class="trip-bus-info">
                                <span class="trip-bus-name"><?= e($trip['bus_name']) ?></span>
                                <span class="trip-bus-type"><?= e($trip['bus_type']) ?></span>
                                <?php if (!empty($trip['driver_name'])): ?>
                                    <span class="trip-driver">Driver: <?= e($trip['driver_name']) ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Tags -->
                            <?php if (!empty($tags)): ?>
                            <div class="trip-tags">
                                <?php foreach ($tags as $tag): ?>
                                    <span class="route-tag"><?= e($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                        </div>

                        <!-- Price & CTA -->
                        <div class="trip-card-action">
                            <div class="trip-price-block">
                                <span class="trip-price-from">from</span>
                                <span class="trip-price"><?= formatCurrency($trip['base_price']) ?></span>
                                <span class="trip-price-note">per person</span>
                            </div>

                            <?php if ($soldOut): ?>
                                <span class="seats-badge seats-badge--sold">Sold Out</span>
                            <?php elseif ($lowSeats): ?>
                                <span class="seats-badge seats-badge--low"><?= $seatsLeft ?> seats left</span>
                            <?php else: ?>
                                <span class="seats-badge seats-badge--ok"><?= $seatsLeft ?> seats</span>
                            <?php endif; ?>

                            <?php if (!$soldOut): ?>
                                <a href="<?= BASE_URL ?>bus_details.php?schedule_id=<?= (int)$trip['schedule_id'] ?>"
                                   class="btn-view-trip">
                                    View Details
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                </a>
                            <?php else: ?>
                                <span class="btn-view-trip btn-view-trip--disabled">Unavailable</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </div>

        <!-- Right: Journey info sidebar -->
        <?php if (!empty($origin) && !empty($destination) && empty($errors)): ?>
        <aside class="results-sidebar">
            <div class="sidebar-card">
                <h3 class="sidebar-title">Journey Info</h3>
                <div class="sidebar-row">
                    <span class="sidebar-label">From</span>
                    <span class="sidebar-value"><?= e($origin) ?></span>
                </div>
                <div class="sidebar-row">
                    <span class="sidebar-label">To</span>
                    <span class="sidebar-value"><?= e($destination) ?></span>
                </div>
                <?php if (!empty($departureDate)): ?>
                <div class="sidebar-row">
                    <span class="sidebar-label">Date</span>
                    <span class="sidebar-value"><?= date('d M Y', strtotime($departureDate)) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($results)): ?>
                <div class="sidebar-row">
                    <span class="sidebar-label">Trips</span>
                    <span class="sidebar-value"><?= count($results) ?> available</span>
                </div>
                <?php endif; ?>
            </div>

            <div class="sidebar-card sidebar-card--info">
                <h3 class="sidebar-title">Good to know</h3>
                <ul class="sidebar-tips">
                    <li>Fares shown are base adult prices. Student, senior, and child discounts apply at checkout.</li>
                    <li>Seats are not reserved until payment is completed.</li>
                    <li>You can cancel a confirmed booking from your account page.</li>
                </ul>
            </div>
        </aside>
        <?php endif; ?>

    </div>
</section>

<script>
// Swap origin/destination on this page too
const swapBtn = document.getElementById('swap-btn');
const originSel = document.getElementById('origin');
const destSel   = document.getElementById('destination');
if (swapBtn && originSel && destSel) {
    swapBtn.addEventListener('click', function () {
        const tmp = originSel.value;
        originSel.value = destSel.value;
        destSel.value   = tmp;
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>

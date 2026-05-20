<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

// Validate schedule_id
$scheduleId = isset($_GET['schedule_id']) ? (int) $_GET['schedule_id'] : 0;

$schedule = null;
if ($scheduleId > 0) {
    $schedule = getScheduleById($conn, $scheduleId);
}

// Friendly error if not found
if (!$schedule) {
    $pageTitle = 'Trip Not Found';
    require_once 'includes/header.php';
    ?>
    <div class="container" style="padding: 80px 24px; text-align: center;">
        <div class="empty-state">
            <div class="empty-icon">🔍</div>
            <h2>Trip not found</h2>
            <p>That schedule doesn't exist or may no longer be available.</p>
            <div style="display:flex; gap:12px; justify-content:center; margin-top:24px;">
                <a href="<?= BASE_URL ?>search.php" class="btn-primary-lg">Back to Search</a>
                <a href="<?= BASE_URL ?>index.php" class="btn-outline-nav">Home</a>
            </div>
        </div>
    </div>
    <?php
    require_once 'includes/footer.php';
    exit;
}

// Prep data
$tags        = parseRouteTags($schedule['route_tags']);
$durParts    = explode(':', $schedule['journey_duration']);
$durText     = $durParts[0] . 'h ' . $durParts[1] . 'm';
$seatsLeft   = (int) $schedule['available_seats'];
$soldOut     = $seatsLeft === 0;
$lowSeats    = $seatsLeft > 0 && $seatsLeft <= 5;
$statusLabel = ucfirst($schedule['schedule_status']);

// Fare breakdown for passenger types
$fareTypes = [
    'Adult'   => calculateFare($schedule['base_price'], 'Adult'),
    'Student' => calculateFare($schedule['base_price'], 'Student'),
    'Senior'  => calculateFare($schedule['base_price'], 'Senior'),
    'Child'   => calculateFare($schedule['base_price'], 'Child'),
];

$pageTitle = e($schedule['origin']) . ' to ' . e($schedule['destination']);
require_once 'includes/header.php';
?>

<!-- BREADCRUMB -->
<div class="details-breadcrumb">
    <div class="container">
        <a href="<?= BASE_URL ?>index.php">Home</a>
        <span class="bc-sep">›</span>
        <a href="<?= BASE_URL ?>search.php?origin=<?= urlencode($schedule['origin']) ?>&destination=<?= urlencode($schedule['destination']) ?>&departure_date=<?= e($schedule['departure_date']) ?>">
            Search Results
        </a>
        <span class="bc-sep">›</span>
        <span><?= e($schedule['origin']) ?> → <?= e($schedule['destination']) ?></span>
    </div>
</div>

<!-- DETAILS HERO BAR -->
<div class="details-hero">
    <div class="container details-hero-inner">
        <div class="details-route-display">
            <div class="dh-city">
                <span class="dh-time"><?= formatTime($schedule['departure_time']) ?></span>
                <span class="dh-name"><?= e($schedule['origin']) ?></span>
            </div>
            <div class="dh-middle">
                <span class="dh-duration"><?= e($durText) ?></span>
                <div class="dh-line">
                    <span class="dh-dot"></span>
                    <span class="dh-dash"></span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="dh-arrow"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </div>
                <span class="dh-direct">Direct · <?= e($schedule['distance_km']) ?> km</span>
            </div>
            <div class="dh-city dh-city--right">
                <span class="dh-time"><?= formatTime($schedule['arrival_time']) ?></span>
                <span class="dh-name"><?= e($schedule['destination']) ?></span>
            </div>
        </div>
        <div class="dh-date-badge">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <?= formatDate($schedule['departure_date']) ?>
        </div>
    </div>
</div>

<!-- MAIN CONTENT -->
<section class="details-section">
    <div class="container details-layout">

        <!-- LEFT: Cards -->
        <div class="details-main">

            <!-- Bus Info Card -->
            <div class="detail-card">
                <h2 class="detail-card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    Bus Information
                </h2>
                <div class="detail-rows">
                    <div class="detail-row">
                        <span class="dr-label">Bus Name</span>
                        <span class="dr-value"><?= e($schedule['bus_name']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="dr-label">Bus Number</span>
                        <span class="dr-value"><?= e($schedule['bus_number']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="dr-label">Bus Type</span>
                        <span class="dr-value">
                            <span class="type-badge type-badge--<?= strtolower($schedule['bus_type']) ?>">
                                <?= e($schedule['bus_type']) ?>
                            </span>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="dr-label">Total Seats</span>
                        <span class="dr-value"><?= e($schedule['seat_capacity']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="dr-label">Available Seats</span>
                        <span class="dr-value">
                            <?php if ($soldOut): ?>
                                <span class="seats-badge seats-badge--sold">Sold Out</span>
                            <?php elseif ($lowSeats): ?>
                                <span class="seats-badge seats-badge--low"><?= $seatsLeft ?> remaining</span>
                            <?php else: ?>
                                <span class="seats-badge seats-badge--ok"><?= $seatsLeft ?> available</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if (!empty($schedule['driver_name'])): ?>
                    <div class="detail-row">
                        <span class="dr-label">Driver</span>
                        <span class="dr-value"><?= e($schedule['driver_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="detail-row">
                        <span class="dr-label">Status</span>
                        <span class="dr-value">
                            <span class="status-badge status-badge--<?= strtolower($schedule['schedule_status']) ?>">
                                <?= e($statusLabel) ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Amenities Card -->
            <?php if (!empty($tags)): ?>
            <div class="detail-card">
                <h2 class="detail-card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    On Board Amenities
                </h2>
                <div class="amenities-grid">
                    <?php
                    $amenityIcons = [
                        'Express'               => '⚡',
                        'Wi-Fi'                 => '📶',
                        'Luggage'               => '🧳',
                        'Wheelchair Accessible' => '♿',
                        'Standard'              => '🚌',
                        'Sleeper'               => '🛏️',
                    ];
                    foreach ($tags as $tag):
                        $icon = $amenityIcons[$tag] ?? '✓';
                    ?>
                    <div class="amenity-item">
                        <span class="amenity-icon"><?= $icon ?></span>
                        <span class="amenity-label"><?= e($tag) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Fare Breakdown Card -->
            <div class="detail-card">
                <h2 class="detail-card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    Fare Breakdown
                </h2>
                <p class="fare-note">Fares are per person, one way. Discounts applied at checkout.</p>
                <div class="fare-table">
                    <?php foreach ($fareTypes as $type => $fare):
                        $discounts = ['Adult' => 0, 'Student' => 15, 'Senior' => 20, 'Child' => 40];
                        $pct = $discounts[$type];
                    ?>
                    <div class="fare-row <?= $type === 'Adult' ? 'fare-row--highlight' : '' ?>">
                        <div class="fare-type">
                            <span class="fare-type-name"><?= $type ?></span>
                            <?php if ($pct > 0): ?>
                                <span class="fare-discount"><?= $pct ?>% off</span>
                            <?php endif; ?>
                        </div>
                        <span class="fare-amount"><?= formatCurrency($fare) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <!-- RIGHT: Journey summary + CTA -->
        <aside class="details-sidebar">

            <!-- Journey Summary -->
            <div class="journey-summary-card">
                <div class="js-header">
                    <h3>Journey Summary</h3>
                </div>
                <div class="js-body">
                    <div class="js-route">
                        <div class="js-city">
                            <span class="js-time"><?= formatTime($schedule['departure_time']) ?></span>
                            <span class="js-place"><?= e($schedule['origin']) ?></span>
                        </div>
                        <div class="js-line-vert">
                            <span class="js-vdot"></span>
                            <span class="js-vline"></span>
                            <span class="js-vdot js-vdot--dest"></span>
                        </div>
                        <div class="js-city">
                            <span class="js-time"><?= formatTime($schedule['arrival_time']) ?></span>
                            <span class="js-place"><?= e($schedule['destination']) ?></span>
                        </div>
                    </div>

                    <div class="js-details">
                        <div class="js-detail-row">
                            <span>Date</span>
                            <span><?= date('d M Y', strtotime($schedule['departure_date'])) ?></span>
                        </div>
                        <div class="js-detail-row">
                            <span>Duration</span>
                            <span><?= e($durText) ?></span>
                        </div>
                        <div class="js-detail-row">
                            <span>Distance</span>
                            <span><?= e($schedule['distance_km']) ?> km</span>
                        </div>
                        <div class="js-detail-row">
                            <span>Bus</span>
                            <span><?= e($schedule['bus_name']) ?></span>
                        </div>
                    </div>

                    <div class="js-price">
                        <span class="js-price-label">Starting from</span>
                        <span class="js-price-amount"><?= formatCurrency($schedule['base_price']) ?></span>
                        <span class="js-price-note">per person (adult)</span>
                    </div>

                    <?php if (!$soldOut && $schedule['schedule_status'] === 'scheduled'): ?>
                        <a href="<?= BASE_URL ?>seat_selection.php?schedule_id=<?= (int)$scheduleId ?>"
                           class="btn-select-seats">
                            Select Seats
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </a>
                        <p class="js-seats-note">
                            <?php if ($lowSeats): ?>
                                <span style="color: var(--orange-dark); font-weight:600;">Only <?= $seatsLeft ?> seats left!</span>
                            <?php else: ?>
                                <?= $seatsLeft ?> seats available
                            <?php endif; ?>
                        </p>
                    <?php elseif ($soldOut): ?>
                        <div class="btn-select-seats btn-select-seats--disabled">Sold Out</div>
                        <p class="js-seats-note">No seats remaining for this trip.</p>
                    <?php else: ?>
                        <div class="btn-select-seats btn-select-seats--disabled">Not Available</div>
                        <p class="js-seats-note">This trip is no longer accepting bookings.</p>
                    <?php endif; ?>
                </div>
            </div>

            <a href="<?= BASE_URL ?>search.php?origin=<?= urlencode($schedule['origin']) ?>&destination=<?= urlencode($schedule['destination']) ?>&departure_date=<?= e($schedule['departure_date']) ?>"
               class="btn-back-search">
                ← Back to search results
            </a>

        </aside>

    </div>
</section>

<?php require_once 'includes/footer.php'; ?>

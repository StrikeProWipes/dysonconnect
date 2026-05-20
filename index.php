<?php
require_once 'includes/db_connect.php';

$origins = [];
$destinations = [];
$res = $conn->query("SELECT DISTINCT origin FROM routes WHERE status='active' ORDER BY origin");
while ($row = $res->fetch_assoc()) $origins[] = $row['origin'];
$res = $conn->query("SELECT DISTINCT destination FROM routes WHERE status='active' ORDER BY destination");
while ($row = $res->fetch_assoc()) $destinations[] = $row['destination'];

$featuredRoutes = [];
// Step 1: Pick exactly ONE interesting long-distance route per non-Melbourne origin
// Priority order: NSW cities first (Sydney, Newcastle, Wollongong), then VIC regionals
$res = $conn->query("
    SELECT r.route_id, r.origin, r.destination, r.distance_km, r.base_price, r.route_tags,
           MIN(s.departure_date) AS next_dep
    FROM routes r
    INNER JOIN schedules s ON s.route_id = r.route_id
        AND s.departure_date >= CURDATE() AND s.status = 'scheduled'
    WHERE r.status = 'active'
      AND r.origin NOT IN ('Melbourne CBD')
    GROUP BY r.route_id
    ORDER BY r.distance_km DESC, r.origin ASC
");
$seenOther = [];
while ($row = $res->fetch_assoc()) {
    if (!isset($seenOther[$row['origin']]) && count($featuredRoutes) < 4) {
        $seenOther[$row['origin']] = true;
        $featuredRoutes[] = $row;
    }
}

// Step 2: Fill remaining slots (up to 2) with diverse Melbourne CBD routes
// Pick the longest/most interesting ones (interstate + regional spread)
$shownIds = array_column($featuredRoutes, 'route_id');
$placeholders = implode(',', array_fill(0, count($shownIds), '?'));
$res2 = $conn->prepare("
    SELECT r.route_id, r.origin, r.destination, r.distance_km, r.base_price, r.route_tags,
           MIN(s.departure_date) AS next_dep
    FROM routes r
    INNER JOIN schedules s ON s.route_id = r.route_id
        AND s.departure_date >= CURDATE() AND s.status = 'scheduled'
    WHERE r.status = 'active'
      AND r.origin = 'Melbourne CBD'
      AND r.route_id NOT IN ($placeholders)
    GROUP BY r.route_id
    ORDER BY r.distance_km DESC
    LIMIT 2
");
$types = str_repeat('i', count($shownIds));
$res2->bind_param($types, ...$shownIds);
$res2->execute();
$melbRows = $res2->get_result();
while ($row = $melbRows->fetch_assoc()) {
    $featuredRoutes[] = $row;
}

// Step 3: If still under 6, fill any remaining gaps
if (count($featuredRoutes) < 6) {
    $shownIds = array_column($featuredRoutes, 'route_id');
    $res3 = $conn->query("
        SELECT r.route_id, r.origin, r.destination, r.distance_km, r.base_price, r.route_tags,
               MIN(s.departure_date) AS next_dep
        FROM routes r
        INNER JOIN schedules s ON s.route_id = r.route_id
            AND s.departure_date >= CURDATE() AND s.status = 'scheduled'
        WHERE r.status = 'active'
        GROUP BY r.route_id
        ORDER BY r.base_price DESC
    ");
    while ($row = $res3->fetch_assoc()) {
        if (!in_array($row['route_id'], $shownIds) && count($featuredRoutes) < 6) {
            $featuredRoutes[] = $row;
        }
    }
}

$pageTitle = 'Book Regional Bus Travel – Victoria & NSW';
require_once 'includes/header.php';
?>

<!-- ===================== HERO ===================== -->
<section class="hero">
    <div class="container hero-inner">

        <!-- Left: Text + Search -->
        <div class="hero-left">
            <h1 class="hero-title">
                Find bus tickets for your<br>next trip in Australia
            </h1>
            <p class="hero-sub">Easily compare and book intercity bus travel across Victoria and New South Wales.</p>

            <div class="hero-trust-row">
                <div class="hero-trust-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Live seat selection
                </div>
                <div class="hero-trust-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Instant confirmation
                </div>
                <div class="hero-trust-item">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Easy cancellation
                </div>
            </div>

            <!-- Search bar — Busbud style: full width below text -->
            <div class="hero-search-bar">
                <form action="<?= BASE_URL ?>search.php" method="GET" id="search-form" class="hsb-form">
                    <div class="hsb-field">
                        <label for="origin">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2"/></svg>
                            Origin
                        </label>
                        <select name="origin" id="origin" required>
                            <option value="">Leaving from...</option>
                            <?php foreach ($origins as $o): ?>
                                <option value="<?= e($o) ?>"><?= e($o) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="button" class="hsb-swap" id="swap-btn" title="Swap">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 16V4m0 0L3 8m4-4l4 4M17 8v12m0 0l4-4m-4 4l-4-4"/></svg>
                    </button>

                    <div class="hsb-field">
                        <label for="destination">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            Destination
                        </label>
                        <select name="destination" id="destination" required>
                            <option value="">Going to...</option>
                            <?php foreach ($destinations as $d): ?>
                                <option value="<?= e($d) ?>"><?= e($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="hsb-field hsb-field--date">
                        <label for="departure_date">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            Date
                        </label>
                        <input type="date" name="departure_date" id="departure_date"
                               min="<?= date('Y-m-d') ?>" required>
                    </div>

                    <button type="submit" class="hsb-submit">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        Search
                    </button>
                </form>
            </div>
        </div>

        <!-- Right: Illustration -->
        <div class="hero-right">
            <img src="<?= BASE_URL ?>assets/images/hero-bus.png"
                 alt="Passengers boarding a DysonConnect bus"
                 class="hero-bus-img">
        </div>

    </div>
</section>

<!-- ===================== TRUST STRIP ===================== -->
<section class="trust-strip">
    <div class="container">
        <div class="trust-items">
            <div class="trust-item">
                <div class="trust-icon trust-icon--blue">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                </div>
                <div>
                    <strong>Express Routes</strong>
                    <span>Faster intercity travel</span>
                </div>
            </div>
            <div class="trust-item">
                <div class="trust-icon trust-icon--orange">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="2.5"/><path d="M8 8h8M8 12h8M8 16h4"/></svg>
                </div>
                <div>
                    <strong>Wi-Fi On Board</strong>
                    <span>Stay connected on the go</span>
                </div>
            </div>
            <div class="trust-item">
                <div class="trust-icon trust-icon--blue">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                </div>
                <div>
                    <strong>Luggage Storage</strong>
                    <span>Ample space for bags</span>
                </div>
            </div>
            <div class="trust-item">
                <div class="trust-icon trust-icon--orange">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div>
                    <strong>Choose Your Seat</strong>
                    <span>Window, aisle or middle</span>
                </div>
            </div>
            <div class="trust-item">
                <div class="trust-icon trust-icon--blue">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                </div>
                <div>
                    <strong>Easy Payment</strong>
                    <span>Card, debit, internet banking, e-wallet or cash</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== POPULAR ROUTES ===================== -->
<?php if (!empty($featuredRoutes)): ?>
<section class="section-routes">
    <div class="container">
        <div class="section-header-row">
            <div>
                <h2 class="section-title">Popular Routes</h2>
                <p class="section-sub">Frequently travelled intercity connections</p>
            </div>
            <a href="<?= BASE_URL ?>routes.php" class="btn-text-link">View all routes →</a>
        </div>

        <div class="routes-grid">
            <?php foreach ($featuredRoutes as $route):
                $tags = parseRouteTags($route['route_tags']);
            ?>
            <div class="route-card">
                <div class="route-card-inner">
                    <div class="route-header">
                        <div class="route-path">
                            <span class="route-city"><?= e($route['origin']) ?></span>
                            <div class="route-line">
                                <span class="route-dot"></span>
                                <span class="route-dash"></span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                <span class="route-dash"></span>
                                <span class="route-dot"></span>
                            </div>
                            <span class="route-city"><?= e($route['destination']) ?></span>
                        </div>
                        <div class="route-price-badge">
                            <span class="price-from">from</span>
                            <span class="price-amount"><?= formatCurrency($route['base_price']) ?></span>
                        </div>
                    </div>
                    <div class="route-meta">
                        <span class="route-meta-item">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/></svg>
                            <?= e($route['distance_km']) ?> km
                        </span>
                        <?php if ($route['next_dep']): ?>
                        <span class="route-meta-item">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            Next: <?= date('d M', strtotime($route['next_dep'])) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="route-tags">
                        <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                            <span class="route-tag"><?= e($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <a href="<?= BASE_URL ?>search.php?origin=<?= urlencode($route['origin']) ?>&destination=<?= urlencode($route['destination']) ?>" class="route-cta">
                        View trips
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===================== SYSTEM OVERVIEW ===================== -->
<section class="section-overview">
    <div class="container">
        <h2 class="section-title-center">Search, book, and manage your trip online</h2>
        <p class="section-sub-center">DysonConnect is the Dyson Group's online booking system. Find a bus, pick a seat, pay, and get your ticket — all without calling the depot.</p>
        <div class="overview-grid">
            <div class="overview-card">
                <div class="overview-icon overview-icon--blue">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                </div>
                <h3>Search Routes</h3>
                <p>Search trips by origin, destination, and date. See real-time availability across all Dyson Group routes.</p>
            </div>
            <div class="overview-card">
                <div class="overview-icon overview-icon--orange">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                </div>
                <h3>Seat Selection</h3>
                <p>View the live seat layout and choose your preferred window, aisle, or middle seat before you pay.</p>
            </div>
            <div class="overview-card">
                <div class="overview-icon overview-icon--blue">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>
                </div>
                <h3>Secure Payment</h3>
                <p>Pay with credit card, debit card, internet banking, online wallet (including Paytm), or cash at the depot.</p>
            </div>
            <div class="overview-card">
                <div class="overview-icon overview-icon--orange">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="12" x2="15" y2="12"/></svg>
                </div>
                <h3>E-Ticket &amp; History</h3>
                <p>Get an instant booking confirmation. View, print, or download your e-ticket any time from My Bookings.</p>
            </div>
            <div class="overview-card">
                <div class="overview-icon overview-icon--blue">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
                <h3>Cancel &amp; Refund</h3>
                <p>Cancel an upcoming trip from your account and track the refund status without calling the depot.</p>
            </div>
            <div class="overview-card">
                <div class="overview-icon overview-icon--orange">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </div>
                <h3>Notifications</h3>
                <p>Receive in-app notifications for booking confirmations, payment receipts, cancellations, and schedule changes.</p>
            </div>
        </div>
    </div>
</section>

<!-- ===================== HOW IT WORKS ===================== -->
<section class="section-how">
    <div class="container">
        <div class="section-how-header">
            <h2 class="section-title-center">Book your trip in 4 steps</h2>
            <p class="section-sub-center">No phone calls, no queues. Search, pick, pay, and go.</p>
        </div>
        <div class="steps-grid">

            <div class="step-card">
                <div class="step-badge">
                    <div class="step-badge-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                        </svg>
                    </div>
                </div>
                <div class="step-num-label">Step 01</div>
                <h3>Search Routes</h3>
                <p>Choose your origin, destination, and travel date. See all available trips instantly.</p>
            </div>

            <div class="step-card">
                <div class="step-badge step-badge--2">
                    <div class="step-badge-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>
                        </svg>
                    </div>
                </div>
                <div class="step-num-label">Step 02</div>
                <h3>Pick Your Seat</h3>
                <p>View the live seat map and choose window, aisle, or middle, before you pay.</p>
            </div>

            <div class="step-card">
                <div class="step-badge step-badge--3">
                    <div class="step-badge-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
                        </svg>
                    </div>
                </div>
                <div class="step-num-label">Step 03</div>
                <h3>Pay Securely</h3>
                <p>Card, debit, internet banking, e-wallet or cash. Booking confirmed in seconds.</p>
            </div>

            <div class="step-card">
                <div class="step-badge step-badge--4">
                    <div class="step-badge-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>
                        </svg>
                    </div>
                </div>
                <div class="step-num-label">Step 04</div>
                <h3>Board &amp; Travel</h3>
                <p>Download your e-ticket, show it at the door, and you're ready to go.</p>
            </div>

        </div>
    </div>
</section>

<!-- ===================== WHY DYSONCONNECT ===================== -->
<section class="section-why">
    <div class="container why-inner">
        <div class="why-text">
            <span class="why-eyebrow">Why DysonConnect</span>
            <h2>A better way to travel regionally</h2>
            <p>Booking a regional bus used to mean calling ahead, visiting a depot, or hoping a seat was available. DysonConnect puts the whole process online: search, seat selection, payment, and ticket in one flow.</p>
            <ul class="why-list">
                <li><span class="why-check">✓</span> Live seat availability per trip</li>
                <li><span class="why-check">✓</span> Passenger type fares: adult, student, senior, child</li>
                <li><span class="why-check">✓</span> Manage and cancel bookings from your account</li>
                <li><span class="why-check">✓</span> Printable e-ticket with full trip details</li>
            </ul>
            <a href="<?= BASE_URL ?>register.php" class="btn-primary-lg">Create a free account</a>
        </div>
        <div class="why-cards">
            <div class="why-stat-card why-stat-card--blue">
                <div class="stat-num">600+</div>
                <div class="stat-label">Buses in fleet</div>
            </div>
            <div class="why-stat-card why-stat-card--orange">
                <div class="stat-num">18</div>
                <div class="stat-label">Active routes</div>
            </div>
            <div class="why-stat-card why-stat-card--light">
                <div class="stat-num">4</div>
                <div class="stat-label">Passenger types</div>
            </div>
            <div class="why-stat-card why-stat-card--dark">
                <div class="stat-num">5</div>
                <div class="stat-label">Payment methods</div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== GROUP MEMBERS ===================== -->
<section class="section-team">
    <div class="container">
        <div class="team-header">
            <p class="team-eyebrow">Meet the Team</p>
            <h2 class="section-title-center">Who Built DysonConnect</h2>
            <p class="section-sub-center">DWIN309 &ndash; Developing Web Information Systems &nbsp;&middot;&nbsp; Kent Institute Australia</p>
        </div>
        <div class="team-grid">

            <div class="team-card">
                <div class="team-avatar-wrap">
                    <div class="team-avatar">BS</div>
                </div>
                <div class="team-info">
                    <span class="team-id">K241034</span>
                    <span class="team-name">Bibek Subedi</span>
                    <span class="team-role">Admin Panel, Database Design &amp; System Integration</span>
                    <span class="team-role-tag">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        Admin &amp; Database
                    </span>
                </div>
            </div>

            <div class="team-card team-card--2">
                <div class="team-avatar-wrap">
                    <div class="team-avatar team-avatar--2">WG</div>
                </div>
                <div class="team-info">
                    <span class="team-id">K240381</span>
                    <span class="team-name">Wasik Gaus</span>
                    <span class="team-role">Bus Search, Route Listing &amp; Seat Selection</span>
                    <span class="team-role-tag">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        Search &amp; Seats
                    </span>
                </div>
            </div>

            <div class="team-card team-card--3">
                <div class="team-avatar-wrap">
                    <div class="team-avatar team-avatar--3">SS</div>
                </div>
                <div class="team-info">
                    <span class="team-id">K241054</span>
                    <span class="team-name">Santosh Silwal</span>
                    <span class="team-role">Passenger Booking Flow &amp; Payment Module</span>
                    <span class="team-role-tag">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                        Booking &amp; Payment
                    </span>
                </div>
            </div>

            <div class="team-card team-card--4">
                <div class="team-avatar-wrap">
                    <div class="team-avatar team-avatar--4">SB</div>
                </div>
                <div class="team-info">
                    <span class="team-id">K231952</span>
                    <span class="team-name">Sushil Bhusal</span>
                    <span class="team-role">User Login, Registration &amp; Profile Management</span>
                    <span class="team-role-tag">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Auth &amp; Profile
                    </span>
                </div>
            </div>

        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>

<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

requireLogin();

$bookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;

if ($bookingId < 1) {
    setFlash('error', 'Invalid booking reference.');
    header('Location: ' . BASE_URL . 'my_bookings.php');
    exit;
}

$booking = getBookingDetails($conn, $bookingId);

if (!$booking) {
    setFlash('error', 'Booking not found.');
    header('Location: ' . BASE_URL . 'my_bookings.php');
    exit;
}

// Only the booking owner or an admin can view the ticket
if (!isAdmin() && (int) $booking['user_id'] !== (int) currentUserId()) {
    setFlash('error', 'You do not have permission to view that ticket.');
    header('Location: ' . BASE_URL . 'my_bookings.php');
    exit;
}

// Clear booking session now that ticket is shown
unset(
    $_SESSION['booking_schedule_id'],
    $_SESSION['booking_seat_ids'],
    $_SESSION['booking_passengers'],
    $_SESSION['booking_total_fare']
);

$tags     = parseRouteTags($booking['route_tags']);
$durText  = '';
if (!empty($booking['departure_time']) && !empty($booking['arrival_time'])) {
    $diff = strtotime($booking['arrival_time']) - strtotime($booking['departure_time']);
    $h    = floor($diff / 3600);
    $m    = floor(($diff % 3600) / 60);
    $durText = $h . 'h ' . $m . 'm';
}

$statusClass = match($booking['booking_status']) {
    'Confirmed'       => 'confirmed',
    'Cancelled'       => 'cancelled',
    'Pending Payment' => 'pending',
    default           => 'pending',
};

$pageTitle = 'E-Ticket – ' . $booking['booking_reference'];
require_once 'includes/header.php';
?>
<style>
/* Print-specific styles — active only when window.print() is called */
@media print {
    .site-header, .site-nav, .nav-toggle,
    .booking-progress-bar, .ticket-actions,
    .site-footer, .flash-wrap,
    .btn-print-ticket, .btn-primary-lg, .btn-back-search,
    .ticket-action-info { display: none !important; }

    .ticket-layout { display: block !important; }
    body, .site-main { background: #fff !important; }
    .ticket-card { box-shadow: none !important; border: 1.5px solid #ccc; }
    .ticket-boarding-notice { border: 1px solid #aaa; }
}
</style>

<!-- BOOKING PROGRESS — all done -->
<div class="booking-progress-bar">
    <div class="container">
        <div class="booking-steps">
            <div class="bstep bstep--done"><span class="bstep-num">✓</span><span class="bstep-label">Select Seats</span></div>
            <div class="bstep-connector bstep-connector--done"></div>
            <div class="bstep bstep--done"><span class="bstep-num">✓</span><span class="bstep-label">Passenger Details</span></div>
            <div class="bstep-connector bstep-connector--done"></div>
            <div class="bstep bstep--done"><span class="bstep-num">✓</span><span class="bstep-label">Payment</span></div>
            <div class="bstep-connector bstep-connector--done"></div>
            <div class="bstep bstep--active"><span class="bstep-num">4</span><span class="bstep-label">Confirmation</span></div>
        </div>
    </div>
</div>

<!-- SUCCESS BANNER -->
<?php if ($booking['booking_status'] === 'Confirmed'): ?>
<div class="ticket-success-banner">
    <div class="container">
        <div class="tsb-inner">
            <span class="tsb-icon">🎉</span>
            <div>
                <strong>Booking confirmed!</strong>
                <span>Your e-ticket is ready. You can print it or access it anytime from My Bookings.</span>
            </div>
            <a href="<?= BASE_URL ?>my_bookings.php" class="tsb-link">View all bookings →</a>
        </div>
    </div>
</div>
<?php endif; ?>

<section class="ticket-section">
    <div class="container ticket-layout">

        <!-- TICKET CARD -->
        <div class="ticket-wrap" id="ticket-printable">

            <!-- Ticket Header -->
            <div class="ticket-header">
                <div class="ticket-brand">
                    <img src="<?= BASE_URL ?>assets/images/logo.png" alt="DysonConnect" class="ticket-logo">
                    <div>
                        <span class="ticket-brand-name">DysonConnect</span>
                        <span class="ticket-brand-sub">Regional Bus Booking</span>
                    </div>
                </div>
                <div class="ticket-ref-block">
                    <span class="ticket-ref-label">Booking Reference</span>
                    <span class="ticket-ref"><?= e($booking['booking_reference']) ?></span>
                    <span class="ticket-status ticket-status--<?= $statusClass ?>">
                        <?= e($booking['booking_status']) ?>
                    </span>
                </div>
            </div>

            <!-- Ticket Tear Line -->
            <div class="ticket-tear">
                <span class="tear-circle tear-circle--left"></span>
                <div class="tear-line"></div>
                <span class="tear-circle tear-circle--right"></span>
            </div>

            <!-- Route Display -->
            <div class="ticket-route">
                <div class="tr-city">
                    <span class="tr-time"><?= formatTime($booking['departure_time']) ?></span>
                    <span class="tr-name"><?= e($booking['origin']) ?></span>
                    <span class="tr-date"><?= date('d M Y', strtotime($booking['departure_date'])) ?></span>
                </div>
                <div class="tr-middle">
                    <?php if ($durText): ?>
                        <span class="tr-duration"><?= e($durText) ?></span>
                    <?php endif; ?>
                    <div class="tr-line">
                        <span class="tr-dot"></span>
                        <span class="tr-dash"></span>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="var(--orange)"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        <span class="tr-dash"></span>
                        <span class="tr-dot tr-dot--dest"></span>
                    </div>
                    <span class="tr-direct">Direct Service</span>
                </div>
                <div class="tr-city tr-city--right">
                    <span class="tr-time"><?= formatTime($booking['arrival_time']) ?></span>
                    <span class="tr-name"><?= e($booking['destination']) ?></span>
                    <span class="tr-date"><?= date('d M Y', strtotime($booking['departure_date'])) ?></span>
                </div>
            </div>

            <!-- Ticket Tear Line -->
            <div class="ticket-tear">
                <span class="tear-circle tear-circle--left"></span>
                <div class="tear-line"></div>
                <span class="tear-circle tear-circle--right"></span>
            </div>

            <!-- Trip Info Grid -->
            <div class="ticket-info-grid">
                <div class="ticket-info-block">
                    <span class="ti-label">Bus</span>
                    <span class="ti-value"><?= e($booking['bus_name']) ?></span>
                </div>
                <div class="ticket-info-block">
                    <span class="ti-label">Bus Number</span>
                    <span class="ti-value"><?= e($booking['bus_number']) ?></span>
                </div>
                <div class="ticket-info-block">
                    <span class="ti-label">Bus Type</span>
                    <span class="ti-value"><?= e($booking['bus_type']) ?></span>
                </div>
                <?php if (!empty($booking['driver_name'])): ?>
                <div class="ticket-info-block">
                    <span class="ti-label">Driver</span>
                    <span class="ti-value"><?= e($booking['driver_name']) ?></span>
                </div>
                <?php endif; ?>
                <div class="ticket-info-block">
                    <span class="ti-label">Payment Method</span>
                    <span class="ti-value"><?= e($booking['payment_method'] ?? 'N/A') ?></span>
                </div>
                <div class="ticket-info-block">
                    <span class="ti-label">Payment Status</span>
                    <span class="ti-value"><?= e($booking['payment_status'] ?? 'N/A') ?></span>
                </div>
                <div class="ticket-info-block">
                    <span class="ti-label">Booked On</span>
                    <span class="ti-value"><?= date('d M Y, g:i A', strtotime($booking['booking_date'])) ?></span>
                </div>
                <?php if (!empty($tags)): ?>
                <div class="ticket-info-block ticket-info-block--full">
                    <span class="ti-label">Amenities</span>
                    <div class="ti-tags">
                        <?php foreach ($tags as $tag): ?>
                            <span class="route-tag"><?= e($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tear Line -->
            <div class="ticket-tear">
                <span class="tear-circle tear-circle--left"></span>
                <div class="tear-line"></div>
                <span class="tear-circle tear-circle--right"></span>
            </div>

            <!-- Passengers Table -->
            <div class="ticket-passengers">
                <h3 class="ticket-section-title">Passengers &amp; Seats</h3>
                <div class="ticket-pax-table">
                    <div class="tpax-head">
                        <span>#</span>
                        <span>Name</span>
                        <span>Type</span>
                        <span>Seat</span>
                        <span>Seat Type</span>
                        <span>Fare</span>
                    </div>
                    <?php foreach ($booking['passengers'] as $i => $pax): ?>
                    <div class="tpax-row">
                        <span class="tpax-num"><?= $i + 1 ?></span>
                        <span class="tpax-name"><?= e($pax['passenger_name']) ?></span>
                        <span class="tpax-type"><?= e($pax['passenger_type']) ?></span>
                        <span class="tpax-seat"><strong><?= e($pax['seat_number']) ?></strong></span>
                        <span class="tpax-stype"><?= e($pax['seat_type']) ?></span>
                        <span class="tpax-fare"><?= formatCurrency($pax['fare_amount']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="ticket-total-row">
                    <span>Total Paid</span>
                    <span class="ticket-total-amount"><?= formatCurrency($booking['total_amount']) ?></span>
                </div>
            </div>

            <!-- Boarding Notice -->
            <div class="ticket-boarding-notice">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Show this ticket to the driver or depot staff before boarding.
            </div>

            <!-- Ticket Footer -->
            <div class="ticket-footer-strip">
                <span>DysonConnect Regional Bus Booking</span>
                <span>Booking Ref: <?= e($booking['booking_reference']) ?></span>
                <span>Valid for travel on date shown above</span>
            </div>

        </div>

        <!-- ACTIONS SIDEBAR -->
        <aside class="ticket-actions">
            <button onclick="window.print()" class="btn-print-ticket">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print Ticket
            </button>

            <a href="<?= BASE_URL ?>my_bookings.php" class="btn-primary-lg btn-block">
                My Bookings
            </a>

            <a href="<?= BASE_URL ?>index.php" class="btn-back-search btn-back-search--center">
                Back to Home
            </a>

            <div class="ticket-action-info">
                <div class="tai-item">
                    <span class="tai-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
                    </span>
                    <span>Keep your booking reference: <strong><?= e($booking['booking_reference']) ?></strong></span>
                </div>
                <div class="tai-item">
                    <span class="tai-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </span>
                    <span>Arrive at the depot at least 15 minutes before departure.</span>
                </div>
                <div class="tai-item">
                    <span class="tai-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    </span>
                    <span>Need to cancel? Go to My Bookings and select Cancel.</span>
                </div>
            </div>
        </aside>

    </div>
</section>

<?php require_once 'includes/footer.php'; ?>

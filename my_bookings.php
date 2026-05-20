<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

requireLogin();

$userId = currentUserId();

// Handle cancellation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking_id'])) {
    $cancelId = (int) $_POST['cancel_booking_id'];
    $result   = cancelBooking($conn, $cancelId, $userId);

    if ($result['success']) {
        setFlash('success', $result['message']);
    } else {
        setFlash('error', $result['message']);
    }

    header('Location: ' . BASE_URL . 'my_bookings.php');
    exit;
}

// Fetch all bookings for this user
$bookings = getUserBookings($conn, $userId);

// For each booking, also fetch the seat numbers
$bookingSeats = [];
if (!empty($bookings)) {
    $ids = array_column($bookings, 'booking_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt = $conn->prepare("
        SELECT bp.booking_id, s.seat_number, bp.passenger_type
        FROM   booking_passengers bp
        JOIN   seats s ON s.seat_id = bp.seat_id
        WHERE  bp.booking_id IN ($placeholders)
        ORDER BY bp.booking_id, s.seat_number
    ");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $bookingSeats[$row['booking_id']][] = $row;
    }
}

// Split into upcoming and past
$today    = date('Y-m-d');
$upcoming = [];
$past     = [];

foreach ($bookings as $b) {
    if ($b['booking_status'] !== 'Cancelled' && $b['departure_date'] >= $today) {
        $upcoming[] = $b;
    } else {
        $past[] = $b;
    }
}

$pageTitle = 'My Bookings';
require_once 'includes/header.php';
?>

<div class="page-hero page-hero--blue">
    <div class="container">
        <h1 class="page-hero-title">My Bookings</h1>
        <p class="page-hero-sub">View your upcoming and past trips, download tickets, or cancel a booking.</p>
    </div>
</div>

<section class="bookings-section">
    <div class="container">

        <?php if (empty($bookings)): ?>
            <div class="empty-state">
                <div class="empty-icon">🎟️</div>
                <h2>No bookings yet</h2>
                <p>You haven't booked any trips with DysonConnect. Search for available routes to get started.</p>
                <a href="<?= BASE_URL ?>search.php" class="btn-primary-lg empty-cta-btn">Find a Trip</a>
            </div>

        <?php else: ?>

            <!-- UPCOMING TRIPS -->
            <div class="bookings-group">
                <h2 class="bookings-group-title">
                    Upcoming Trips
                    <span class="bookings-count"><?= count($upcoming) ?></span>
                </h2>

                <?php if (empty($upcoming)): ?>
                    <div class="bookings-empty-group">No upcoming trips. <a href="<?= BASE_URL ?>search.php">Book one now →</a></div>
                <?php else: ?>
                    <div class="bookings-list">
                        <?php foreach ($upcoming as $b):
                            $seats = $bookingSeats[$b['booking_id']] ?? [];
                            $seatNums = array_column($seats, 'seat_number');
                            $canCancel = $b['booking_status'] === 'Confirmed' || $b['booking_status'] === 'Pending Payment';
                        ?>
                        <div class="booking-card booking-card--upcoming">
                            <?= renderBookingCard($b, $seatNums, $canCancel) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- PAST / CANCELLED TRIPS -->
            <div class="bookings-group">
                <h2 class="bookings-group-title">
                    Past &amp; Cancelled
                    <span class="bookings-count"><?= count($past) ?></span>
                </h2>

                <?php if (empty($past)): ?>
                    <div class="bookings-empty-group">No past trips to show.</div>
                <?php else: ?>
                    <div class="bookings-list">
                        <?php foreach ($past as $b):
                            $seats = $bookingSeats[$b['booking_id']] ?? [];
                            $seatNums = array_column($seats, 'seat_number');
                        ?>
                        <div class="booking-card booking-card--past">
                            <?= renderBookingCard($b, $seatNums, false) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>
</section>

<?php
// Helper — render one booking card
function renderBookingCard(array $b, array $seatNums, bool $canCancel): string {
    $statusClass = match($b['booking_status']) {
        'Confirmed'       => 'confirmed',
        'Cancelled'       => 'cancelled',
        'Pending Payment' => 'pending',
        default           => 'pending',
    };

    $refundBadge = '';
    if ($b['refund_status'] !== 'Not Applicable') {
        $rClass = match($b['refund_status']) {
            'Pending'   => 'refund--pending',
            'Processed' => 'refund--processed',
            'Rejected'  => 'refund--rejected',
            default     => '',
        };
        $refundBadge = '<span class="refund-badge ' . $rClass . '">'
            . 'Refund: ' . htmlspecialchars($b['refund_status'], ENT_QUOTES) . '</span>';
    }

    $seatList = !empty($seatNums) ? implode(', ', $seatNums) : '—';

    ob_start();
    ?>
    <div class="bc-header">
        <div class="bc-route">
            <span class="bc-city"><?= e($b['origin']) ?></span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color:var(--orange);flex-shrink:0"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            <span class="bc-city"><?= e($b['destination']) ?></span>
        </div>
        <div class="bc-badges">
            <span class="booking-status-badge bsb--<?= $statusClass ?>"><?= e($b['booking_status']) ?></span>
            <?= $refundBadge ?>
        </div>
    </div>

    <div class="bc-body">
        <div class="bc-detail-grid">
            <div class="bc-detail">
                <span class="bc-dl">Date</span>
                <span class="bc-dv"><?= date('d M Y', strtotime($b['departure_date'])) ?></span>
            </div>
            <div class="bc-detail">
                <span class="bc-dl">Departure</span>
                <span class="bc-dv"><?= formatTime($b['departure_time']) ?></span>
            </div>
            <div class="bc-detail">
                <span class="bc-dl">Arrival</span>
                <span class="bc-dv"><?= formatTime($b['arrival_time']) ?></span>
            </div>
            <div class="bc-detail">
                <span class="bc-dl">Bus</span>
                <span class="bc-dv"><?= e($b['bus_name']) ?> <small>(<?= e($b['bus_type']) ?>)</small></span>
            </div>
            <div class="bc-detail">
                <span class="bc-dl">Seats</span>
                <span class="bc-dv"><?= e($seatList) ?></span>
            </div>
            <div class="bc-detail">
                <span class="bc-dl">Reference</span>
                <span class="bc-dv bc-ref"><?= e($b['booking_reference']) ?></span>
            </div>
            <div class="bc-detail">
                <span class="bc-dl">Total Paid</span>
                <span class="bc-dv bc-fare"><?= formatCurrency($b['total_amount']) ?></span>
            </div>
            <div class="bc-detail">
                <span class="bc-dl">Booked</span>
                <span class="bc-dv"><?= date('d M Y', strtotime($b['booking_date'])) ?></span>
            </div>
        </div>
    </div>

    <div class="bc-footer">
        <a href="<?= BASE_URL ?>ticket.php?booking_id=<?= (int)$b['booking_id'] ?>" class="btn-view-ticket-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            View Ticket
        </a>

        <?php if ($canCancel): ?>
        <form method="POST" action="<?= BASE_URL ?>my_bookings.php"
              onsubmit="return confirm('Cancel booking <?= e($b['booking_reference']) ?>? This cannot be undone.')">
            <input type="hidden" name="cancel_booking_id" value="<?= (int)$b['booking_id'] ?>">
            <button type="submit" class="btn-cancel-booking">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                Cancel Booking
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
?>

<?php require_once 'includes/footer.php'; ?>

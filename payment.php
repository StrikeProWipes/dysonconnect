<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

requireLogin();

// Read session — must have come through passenger_details
$scheduleId  = $_SESSION['booking_schedule_id'] ?? 0;
$seatIds     = $_SESSION['booking_seat_ids']     ?? [];
$passengers  = $_SESSION['booking_passengers']   ?? [];
$totalFare   = $_SESSION['booking_total_fare']   ?? 0;

if (empty($scheduleId) || empty($passengers)) {
    setFlash('error', 'Your session expired. Please start your booking again.');
    header('Location: ' . BASE_URL . 'search.php');
    exit;
}

$schedule = getScheduleById($conn, (int) $scheduleId);
if (!$schedule) {
    setFlash('error', 'That trip is no longer available.');
    header('Location: ' . BASE_URL . 'search.php');
    exit;
}

$validMethods = ['Card', 'Debit Card', 'Internet Banking', 'Online Wallet', 'Cash'];
$errors = [];

// Handle POST — confirm booking
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method = trim($_POST['payment_method'] ?? '');

    if (!in_array($method, $validMethods)) {
        $errors[] = 'Please select a payment method.';
    }

    // Last-chance seat availability check before we commit
    if (empty($errors)) {
        foreach ($passengers as $pax) {
            if (!isSeatAvailable($conn, $pax['seat_id'], $scheduleId)) {
                $errors[] = 'Seat ' . $pax['seat_number'] . ' was just taken by another user. Please go back and reselect your seats.';
                break;
            }
        }
    }

    if (empty($errors)) {
        $userId    = currentUserId();
        $reference = generateBookingReference($conn);
        $seatCount = count($passengers);

        $conn->begin_transaction();

        try {
            // 1. Insert booking
            $stmt = $conn->prepare("
                INSERT INTO bookings
                    (user_id, schedule_id, booking_reference, total_amount, booking_status, refund_status)
                VALUES (?, ?, ?, ?, 'Confirmed', 'Not Applicable')
            ");
            $stmt->bind_param('iisd', $userId, $scheduleId, $reference, $totalFare);
            $stmt->execute();
            $bookingId = $conn->insert_id;
            $stmt->close();

            // 2. Insert each passenger record
            $stmtPax = $conn->prepare("
                INSERT INTO booking_passengers
                    (booking_id, seat_id, passenger_name, passenger_type, fare_amount)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($passengers as $pax) {
                $stmtPax->bind_param(
                    'iissd',
                    $bookingId,
                    $pax['seat_id'],
                    $pax['passenger_name'],
                    $pax['passenger_type'],
                    $pax['fare_amount']
                );
                $stmtPax->execute();
            }
            $stmtPax->close();

            // 3. Insert payment record
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("
                INSERT INTO payments
                    (booking_id, payment_method, payment_status, payment_date, amount_paid)
                VALUES (?, ?, 'Paid', ?, ?)
            ");
            $stmt->bind_param('issd', $bookingId, $method, $now, $totalFare);
            $stmt->execute();
            $stmt->close();

            // 4. Decrement available seats on schedule
            $stmt = $conn->prepare("
                UPDATE schedules
                SET available_seats = available_seats - ?
                WHERE schedule_id = ?
                  AND available_seats >= ?
            ");
            $stmt->bind_param('iii', $seatCount, $scheduleId, $seatCount);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                throw new Exception('Not enough seats remaining. Please try again.');
            }
            $stmt->close();

            $conn->commit();

            // Create booking and payment confirmation notifications
            createNotification(
                $conn, $userId, $bookingId,
                'booking_confirmed',
                'Booking Confirmed – ' . $reference,
                'Your booking ' . $reference . ' for ' . $schedule['origin'] . ' → ' . $schedule['destination']
                . ' on ' . date('j M Y', strtotime($schedule['departure_date']))
                . ' (' . formatTime($schedule['departure_time']) . ')'
                . ' is confirmed. ' . count($passengers) . ' passenger(s). Total: ' . formatCurrency($totalFare) . '.'
            );
            createNotification(
                $conn, $userId, $bookingId,
                'payment_confirmed',
                'Payment Received – ' . $reference,
                'Payment of ' . formatCurrency($totalFare) . ' via ' . $method . ' received for booking ' . $reference . '.'
            );

            // Clear booking session — ticket.php will re-fetch from DB
            unset(
                $_SESSION['booking_schedule_id'],
                $_SESSION['booking_seat_ids'],
                $_SESSION['booking_passengers'],
                $_SESSION['booking_total_fare']
            );

            header('Location: ' . BASE_URL . 'ticket.php?booking_id=' . $bookingId);
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = $e->getMessage() ?: 'Something went wrong. Please try again.';
            error_log('Payment transaction failed: ' . $e->getMessage());
        }
    }
}

$durParts = explode(':', $schedule['journey_duration']);
$durText  = $durParts[0] . 'h ' . $durParts[1] . 'm';
$tags     = parseRouteTags($schedule['route_tags']);

$pageTitle = 'Payment';
require_once 'includes/header.php';
?>

<!-- BOOKING PROGRESS -->
<div class="booking-progress-bar">
    <div class="container">
        <div class="booking-steps">
            <div class="bstep bstep--done">
                <span class="bstep-num">✓</span>
                <span class="bstep-label">Select Seats</span>
            </div>
            <div class="bstep-connector bstep-connector--done"></div>
            <div class="bstep bstep--done">
                <span class="bstep-num">✓</span>
                <span class="bstep-label">Passenger Details</span>
            </div>
            <div class="bstep-connector bstep-connector--done"></div>
            <div class="bstep bstep--active">
                <span class="bstep-num">3</span>
                <span class="bstep-label">Payment</span>
            </div>
            <div class="bstep-connector"></div>
            <div class="bstep">
                <span class="bstep-num">4</span>
                <span class="bstep-label">Confirmation</span>
            </div>
        </div>
    </div>
</div>

<!-- ROUTE MINI HEADER -->
<div class="booking-route-header">
    <div class="container booking-route-inner">
        <div class="brh-route">
            <span class="brh-city"><?= e($schedule['origin']) ?></span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color:var(--orange)"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            <span class="brh-city"><?= e($schedule['destination']) ?></span>
        </div>
        <div class="brh-meta">
            <span><?= formatDate($schedule['departure_date']) ?></span>
            <span class="brh-dot">·</span>
            <span><?= formatTime($schedule['departure_time']) ?> – <?= formatTime($schedule['arrival_time']) ?></span>
            <span class="brh-dot">·</span>
            <span><?= count($passengers) ?> passenger<?= count($passengers) > 1 ? 's' : '' ?></span>
        </div>
    </div>
</div>

<section class="payment-section">
    <div class="container payment-layout">

        <!-- LEFT: Payment Form -->
        <div class="payment-main">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $err): ?>
                        <p><?= e($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Passenger Summary -->
            <div class="payment-card">
                <h2 class="payment-card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Passenger Summary
                </h2>
                <div class="pax-summary-table">
                    <div class="pax-summary-head">
                        <span>Seat</span>
                        <span>Name</span>
                        <span>Type</span>
                        <span>Fare</span>
                    </div>
                    <?php foreach ($passengers as $pax): ?>
                    <div class="pax-summary-row">
                        <span class="pax-sum-seat"><?= e($pax['seat_number']) ?></span>
                        <span class="pax-sum-name"><?= e($pax['passenger_name']) ?></span>
                        <span class="pax-sum-type"><?= e($pax['passenger_type']) ?></span>
                        <span class="pax-sum-fare"><?= formatCurrency($pax['fare_amount']) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div class="pax-summary-total">
                        <span>Total</span>
                        <span><?= formatCurrency($totalFare) ?></span>
                    </div>
                </div>
            </div>

            <!-- Payment Method -->
            <div class="payment-card">
                <h2 class="payment-card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    Payment Method
                </h2>
                <p class="payment-note">This is a simulated payment for demonstration purposes. No real transaction takes place.</p>

                <form method="POST" action="<?= BASE_URL ?>payment.php" id="payment-form">
                    <div class="payment-methods">

                        <label class="payment-method-option" id="opt-card">
                            <input type="radio" name="payment_method" value="Card" class="pm-radio" required>
                            <div class="pm-card-inner">
                                <div class="pm-icon pm-icon--card">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                                </div>
                                <div class="pm-details">
                                    <span class="pm-name">Credit Card</span>
                                    <span class="pm-desc">Visa, Mastercard, AMEX</span>
                                </div>
                                <span class="pm-check">✓</span>
                            </div>
                        </label>

                        <label class="payment-method-option" id="opt-debit">
                            <input type="radio" name="payment_method" value="Debit Card" class="pm-radio">
                            <div class="pm-card-inner">
                                <div class="pm-icon pm-icon--card">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/><rect x="4" y="13" width="4" height="3" rx="1"/></svg>
                                </div>
                                <div class="pm-details">
                                    <span class="pm-name">Debit Card</span>
                                    <span class="pm-desc">Visa Debit, Mastercard Debit, eftpos</span>
                                </div>
                                <span class="pm-check">✓</span>
                            </div>
                        </label>

                        <label class="payment-method-option" id="opt-netbank">
                            <input type="radio" name="payment_method" value="Internet Banking" class="pm-radio">
                            <div class="pm-card-inner">
                                <div class="pm-icon pm-icon--bank">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                </div>
                                <div class="pm-details">
                                    <span class="pm-name">Internet Banking</span>
                                    <span class="pm-desc">CommBank, ANZ, Westpac, NAB</span>
                                </div>
                                <span class="pm-check">✓</span>
                            </div>
                        </label>

                        <label class="payment-method-option" id="opt-wallet">
                            <input type="radio" name="payment_method" value="Online Wallet" class="pm-radio">
                            <div class="pm-card-inner">
                                <div class="pm-icon pm-icon--wallet">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>
                                </div>
                                <div class="pm-details">
                                    <span class="pm-name">Online Wallet</span>
                                    <span class="pm-desc">PayPal, Apple Pay, Google Pay, Paytm</span>
                                </div>
                                <span class="pm-check">✓</span>
                            </div>
                        </label>

                        <label class="payment-method-option" id="opt-cash">
                            <input type="radio" name="payment_method" value="Cash" class="pm-radio">
                            <div class="pm-card-inner">
                                <div class="pm-icon pm-icon--cash">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>
                                </div>
                                <div class="pm-details">
                                    <span class="pm-name">Cash</span>
                                    <span class="pm-desc">Pay at the depot before departure</span>
                                </div>
                                <span class="pm-check">✓</span>
                            </div>
                        </label>

                    </div>

                    <button type="submit" class="btn-confirm-payment" id="btn-pay">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        Confirm and Pay <?= formatCurrency($totalFare) ?>
                    </button>
                </form>
            </div>

        </div>

        <!-- RIGHT: Journey Summary Sidebar -->
        <aside class="payment-sidebar">
            <div class="journey-summary-card">
                <div class="js-header">
                    <h3>Booking Summary</h3>
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
                            <span>Bus</span>
                            <span><?= e($schedule['bus_name']) ?></span>
                        </div>
                        <div class="js-detail-row">
                            <span>Passengers</span>
                            <span><?= count($passengers) ?></span>
                        </div>
                        <?php if (!empty($tags)): ?>
                        <div class="js-detail-row">
                            <span>Amenities</span>
                            <span style="text-align:right; font-size:0.78rem;"><?= e(implode(', ', array_slice($tags, 0, 3))) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="js-price">
                        <span class="js-price-label">Total to pay</span>
                        <span class="js-price-amount"><?= formatCurrency($totalFare) ?></span>
                        <span class="js-price-note"><?= count($passengers) ?> passenger<?= count($passengers) > 1 ? 's' : '' ?>, all fares included</span>
                    </div>
                </div>
            </div>

            <div class="sidebar-card sidebar-card--info">
                <div class="sidebar-title" style="margin-bottom:10px; border-bottom:none; padding-bottom:0;">What happens next</div>
                <ul class="sidebar-tips">
                    <li>Your booking is confirmed immediately on payment.</li>
                    <li>An e-ticket is generated with your booking reference.</li>
                    <li>You can view and print your ticket from My Bookings.</li>
                    <li>Cancel anytime before departure from your account.</li>
                </ul>
            </div>

            <a href="<?= BASE_URL ?>passenger_details.php" class="btn-back-search">
                ← Back to passenger details
            </a>
        </aside>

    </div>
</section>

<script>
// Highlight selected payment option
document.querySelectorAll('.pm-radio').forEach(function (radio) {
    radio.addEventListener('change', function () {
        document.querySelectorAll('.payment-method-option').forEach(function (opt) {
            opt.classList.remove('payment-method-option--selected');
        });
        radio.closest('.payment-method-option').classList.add('payment-method-option--selected');
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>

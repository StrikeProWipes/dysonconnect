<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

requireLogin();

// Read booking session
$scheduleId  = $_SESSION['booking_schedule_id'] ?? 0;
$seatIds     = $_SESSION['booking_seat_ids']    ?? [];

// Guard — must have come through seat_selection
if (empty($scheduleId) || empty($seatIds)) {
    setFlash('error', 'Please select your seats first.');
    header('Location: ' . BASE_URL . 'search.php');
    exit;
}

// Fetch schedule
$schedule = getScheduleById($conn, (int) $scheduleId);
if (!$schedule) {
    setFlash('error', 'That schedule is no longer available.');
    header('Location: ' . BASE_URL . 'search.php');
    exit;
}

// Fetch seat details for the selected IDs
// Build placeholders for IN() query
$placeholders = implode(',', array_fill(0, count($seatIds), '?'));
$types        = str_repeat('i', count($seatIds));
$stmt = $conn->prepare("
    SELECT seat_id, seat_number, seat_type
    FROM   seats
    WHERE  seat_id IN ($placeholders)
    ORDER BY seat_number
");
$stmt->bind_param($types, ...$seatIds);
$stmt->execute();
$selectedSeats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$passengerTypes = ['Adult', 'Student', 'Child', 'Senior'];
$errors = [];
$formData = [];  // preserves values on validation fail

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $passengers = [];
    $totalFare  = 0;
    $valid      = true;

    foreach ($selectedSeats as $i => $seat) {
        $name = trim($_POST['passenger_name'][$i] ?? '');
        $type = trim($_POST['passenger_type'][$i] ?? '');

        // Keep for re-fill
        $formData[$i] = ['name' => $name, 'type' => $type];

        if (empty($name)) {
            $errors[] = 'Passenger name is required for seat ' . $seat['seat_number'] . '.';
            $valid = false;
        } elseif (strlen($name) < 2) {
            $errors[] = 'Name for seat ' . $seat['seat_number'] . ' is too short.';
            $valid = false;
        }

        if (!in_array($type, $passengerTypes)) {
            $errors[] = 'Please select a valid passenger type for seat ' . $seat['seat_number'] . '.';
            $valid = false;
        }

        if ($valid || empty($errors)) {
            $fare        = calculateFare($schedule['base_price'], $type);
            $totalFare  += $fare;
            $passengers[] = [
                'seat_id'        => $seat['seat_id'],
                'seat_number'    => $seat['seat_number'],
                'seat_type'      => $seat['seat_type'],
                'passenger_name' => $name,
                'passenger_type' => $type,
                'fare_amount'    => $fare,
            ];
        }
    }

    if (empty($errors)) {
        // Store everything needed for payment.php
        $_SESSION['booking_passengers']  = $passengers;
        $_SESSION['booking_total_fare']  = round($totalFare, 2);

        header('Location: ' . BASE_URL . 'payment.php');
        exit;
    }
}

$durParts = explode(':', $schedule['journey_duration']);
$durText  = $durParts[0] . 'h ' . $durParts[1] . 'm';

$pageTitle = 'Passenger Details';
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
            <div class="bstep bstep--active">
                <span class="bstep-num">2</span>
                <span class="bstep-label">Passenger Details</span>
            </div>
            <div class="bstep-connector"></div>
            <div class="bstep">
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
            <span><?= count($selectedSeats) ?> seat<?= count($selectedSeats) > 1 ? 's' : '' ?></span>
        </div>
    </div>
</div>

<section class="pax-section">
    <div class="container pax-layout">

        <!-- LEFT: Passenger Forms -->
        <div class="pax-main">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $err): ?>
                        <p><?= e($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?= BASE_URL ?>passenger_details.php" novalidate id="pax-form">

                <?php foreach ($selectedSeats as $i => $seat):
                    $savedName = $formData[$i]['name'] ?? '';
                    $savedType = $formData[$i]['type'] ?? 'Adult';
                    $fare      = calculateFare($schedule['base_price'], $savedType ?: 'Adult');
                ?>
                <div class="pax-card" id="pax-card-<?= $i ?>">
                    <div class="pax-card-header">
                        <div class="pax-card-seat">
                            <span class="pax-seat-icon">💺</span>
                            <div>
                                <span class="pax-seat-num">Seat <?= e($seat['seat_number']) ?></span>
                                <span class="pax-seat-type"><?= e($seat['seat_type']) ?></span>
                            </div>
                        </div>
                        <span class="pax-num-badge">Passenger <?= $i + 1 ?></span>
                    </div>

                    <div class="pax-card-body">
                        <div class="pax-fields">
                            <div class="form-group">
                                <label for="pax_name_<?= $i ?>">Full name</label>
                                <div class="input-icon-wrap">
                                    <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <input
                                        type="text"
                                        id="pax_name_<?= $i ?>"
                                        name="passenger_name[]"
                                        value="<?= e($savedName) ?>"
                                        placeholder="e.g. Jane Smith"
                                        autocomplete="off"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="pax_type_<?= $i ?>">Passenger type</label>
                                <select
                                    id="pax_type_<?= $i ?>"
                                    name="passenger_type[]"
                                    class="pax-type-select"
                                    data-base="<?= (float) $schedule['base_price'] ?>"
                                    data-card="<?= $i ?>"
                                    onchange="updateFare(this)"
                                >
                                    <?php foreach ($passengerTypes as $pt): ?>
                                        <?php
                                        $disc = ['Adult' => 0, 'Student' => 15, 'Senior' => 20, 'Child' => 40][$pt];
                                        $label = $pt . ($disc > 0 ? " ({$disc}% off)" : ' (full price)');
                                        ?>
                                        <option value="<?= $pt ?>" <?= ($savedType ?: 'Adult') === $pt ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="pax-fare-display" id="pax-fare-<?= $i ?>">
                            <span class="pax-fare-label">Fare</span>
                            <span class="pax-fare-amount" id="fare-amount-<?= $i ?>"><?= formatCurrency($fare) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="pax-submit-row">
                    <a href="<?= BASE_URL ?>seat_selection.php?schedule_id=<?= $scheduleId ?>" class="btn-back-search">
                        ← Change seats
                    </a>
                    <button type="submit" class="btn-continue">
                        Continue to Payment
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </button>
                </div>

            </form>
        </div>

        <!-- RIGHT: Order Summary Sidebar -->
        <aside class="pax-sidebar">
            <div class="journey-summary-card">
                <div class="js-header">
                    <h3>Order Summary</h3>
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
                    </div>

                    <!-- Per-seat fare breakdown (live updated by JS) -->
                    <div class="pax-fare-breakdown" id="fare-breakdown">
                        <?php foreach ($selectedSeats as $i => $seat):
                            $savedType = $formData[$i]['type'] ?? 'Adult';
                            $fare      = calculateFare($schedule['base_price'], $savedType ?: 'Adult');
                        ?>
                        <div class="fare-breakdown-row" id="breakdown-row-<?= $i ?>">
                            <span>Seat <?= e($seat['seat_number']) ?></span>
                            <span id="breakdown-amount-<?= $i ?>"><?= formatCurrency($fare) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="js-price" id="js-total-block">
                        <span class="js-price-label">Total</span>
                        <span class="js-price-amount" id="sidebar-total">
                            <?php
                            $initTotal = 0;
                            foreach ($selectedSeats as $i => $seat) {
                                $t = $formData[$i]['type'] ?? 'Adult';
                                $initTotal += calculateFare($schedule['base_price'], $t ?: 'Adult');
                            }
                            echo formatCurrency($initTotal);
                            ?>
                        </span>
                        <span class="js-price-note"><?= count($selectedSeats) ?> passenger<?= count($selectedSeats) > 1 ? 's' : '' ?></span>
                    </div>

                </div>
            </div>

            <div class="sidebar-card sidebar-card--info">
                <div class="sidebar-title" style="margin-bottom:10px; border-bottom:none; padding-bottom:0;">Discount rates</div>
                <div class="fare-table" style="margin-top:0;">
                    <div class="fare-row"><div class="fare-type"><span class="fare-type-name">Adult</span></div><span class="fare-amount">Full price</span></div>
                    <div class="fare-row"><div class="fare-type"><span class="fare-type-name">Student</span><span class="fare-discount">15% off</span></div><span class="fare-amount"><?= formatCurrency(calculateFare($schedule['base_price'], 'Student')) ?></span></div>
                    <div class="fare-row"><div class="fare-type"><span class="fare-type-name">Senior</span><span class="fare-discount">20% off</span></div><span class="fare-amount"><?= formatCurrency(calculateFare($schedule['base_price'], 'Senior')) ?></span></div>
                    <div class="fare-row"><div class="fare-type"><span class="fare-type-name">Child</span><span class="fare-discount">40% off</span></div><span class="fare-amount"><?= formatCurrency(calculateFare($schedule['base_price'], 'Child')) ?></span></div>
                </div>
            </div>
        </aside>

    </div>
</section>

<script>
// Fare lookup matching calculateFare() PHP logic
const discounts = { Adult: 0, Student: 0.15, Senior: 0.20, Child: 0.40 };

function calcFare(base, type) {
    return Math.round(base * (1 - (discounts[type] || 0)) * 100) / 100;
}

function formatCur(n) {
    return '$' + n.toFixed(2);
}

function updateFare(selectEl) {
    const i    = selectEl.dataset.card;
    const base = parseFloat(selectEl.dataset.base);
    const type = selectEl.value;
    const fare = calcFare(base, type);

    // Update inline fare display
    const fareEl = document.getElementById('fare-amount-' + i);
    if (fareEl) fareEl.textContent = formatCur(fare);

    // Update sidebar breakdown row
    const breakdownEl = document.getElementById('breakdown-amount-' + i);
    if (breakdownEl) breakdownEl.textContent = formatCur(fare);

    // Recalculate total across all selects
    let total = 0;
    document.querySelectorAll('.pax-type-select').forEach(function (sel) {
        total += calcFare(parseFloat(sel.dataset.base), sel.value);
    });

    const totalEl = document.getElementById('sidebar-total');
    if (totalEl) totalEl.textContent = formatCur(total);
}
</script>

<?php require_once 'includes/footer.php'; ?>

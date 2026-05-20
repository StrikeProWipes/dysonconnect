<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

requireLogin();

$scheduleId = isset($_GET['schedule_id']) ? (int) $_GET['schedule_id'] : 0;

// Validate schedule
$schedule = null;
if ($scheduleId > 0) {
    $schedule = getScheduleById($conn, $scheduleId);
}

if (!$schedule || $schedule['schedule_status'] !== 'scheduled') {
    $pageTitle = 'Trip Not Found';
    require_once 'includes/header.php';
    ?>
    <div class="page-error-wrap">
        <div class="page-error-box">
            <div class="page-error-icon">🚌</div>
            <h2>Trip not available</h2>
            <p>This schedule doesn't exist or is no longer accepting bookings.</p>
            <a href="<?= BASE_URL ?>search.php" class="btn-primary-lg">Back to Search</a>
        </div>
    </div>
    <?php
    require_once 'includes/footer.php';
    exit;
}

// Handle POST — store selections in session and move on
$seatError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedRaw = $_POST['selected_seats'] ?? '';
    $selectedIds = [];

    if (!empty($selectedRaw)) {
        foreach (explode(',', $selectedRaw) as $sid) {
            $sid = (int) trim($sid);
            if ($sid > 0) $selectedIds[] = $sid;
        }
    }

    if (empty($selectedIds)) {
        $seatError = 'Please select at least one seat before continuing.';
    } else {
        // Re-verify each seat is still available (race condition guard)
        $stillAvailable = true;
        foreach ($selectedIds as $sid) {
            if (!isSeatAvailable($conn, $sid, $scheduleId)) {
                $stillAvailable = false;
                break;
            }
        }

        if (!$stillAvailable) {
            $seatError = 'One or more selected seats were just taken. Please reselect.';
        } else {
            // Store in session and proceed
            $_SESSION['booking_schedule_id'] = $scheduleId;
            $_SESSION['booking_seat_ids']    = $selectedIds;

            header('Location: ' . BASE_URL . 'passenger_details.php');
            exit;
        }
    }
}

// Fetch all seats for this schedule
$allSeats = getSeatsForSchedule($conn, $scheduleId);

// Organise into a grid: rows → columns
// seat_number format: "1A", "2F", etc.
$seatGrid = [];
$columns  = [];
foreach ($allSeats as $seat) {
    $num = $seat['seat_number'];              // e.g. "3B"
    preg_match('/^(\d+)([A-Z]+)$/', $num, $m);
    if (count($m) < 3) continue;
    $row = (int) $m[1];
    $col = $m[2];
    $seatGrid[$row][$col] = $seat;
    $columns[$col] = true;
}
ksort($seatGrid);
$columns = array_keys($columns);
sort($columns);

// Work out the aisle split — for 6-col buses (A-F) the aisle is between C and D
$aisleAfter = 'C'; // hardcoded for our bus layout

$durParts = explode(':', $schedule['journey_duration']);
$durText  = $durParts[0] . 'h ' . $durParts[1] . 'm';
$tags     = parseRouteTags($schedule['route_tags']);

$pageTitle = 'Select Seats – ' . e($schedule['origin']) . ' to ' . e($schedule['destination']);
require_once 'includes/header.php';
?>

<!-- BOOKING PROGRESS -->
<div class="booking-progress-bar">
    <div class="container">
        <div class="booking-steps">
            <div class="bstep bstep--active">
                <span class="bstep-num">1</span>
                <span class="bstep-label">Select Seats</span>
            </div>
            <div class="bstep-connector"></div>
            <div class="bstep">
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
            <span><?= e($durText) ?></span>
            <span class="brh-dot">·</span>
            <span><?= e($schedule['bus_name']) ?></span>
        </div>
    </div>
</div>

<section class="seat-section">
    <div class="container seat-layout">

        <!-- LEFT: Seat Map -->
        <div class="seat-map-wrap">
            <div class="seat-map-card">

                <div class="seat-map-header">
                    <h2 class="seat-map-title">Choose your seat<?= count($allSeats) > 1 ? 's' : '' ?></h2>
                    <p class="seat-map-sub">Click available seats to select. Click again to deselect.</p>
                </div>

                <?php if ($seatError): ?>
                    <div class="alert alert-error" style="margin: 0 0 16px;"><?= e($seatError) ?></div>
                <?php endif; ?>

                <!-- Legend -->
                <div class="seat-legend">
                    <div class="legend-item">
                        <span class="seat-demo seat-demo--available"></span>
                        <span>Available</span>
                    </div>
                    <div class="legend-item">
                        <span class="seat-demo seat-demo--selected"></span>
                        <span>Selected</span>
                    </div>
                    <div class="legend-item">
                        <span class="seat-demo seat-demo--booked"></span>
                        <span>Booked</span>
                    </div>
                </div>

                <!-- Bus front indicator -->
                <div class="bus-front">
                    <div class="bus-front-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                        Driver / Front
                    </div>
                </div>

                <!-- Seat Grid -->
                <form method="POST" action="<?= BASE_URL ?>seat_selection.php?schedule_id=<?= $scheduleId ?>" id="seat-form">
                    <input type="hidden" name="selected_seats" id="selected_seats_input" value="">

                    <div class="bus-body">
                        <!-- Column headers -->
                        <div class="seat-row seat-row--header">
                            <span class="seat-row-num"></span>
                            <?php foreach ($columns as $i => $col): ?>
                                <?php if ($i > 0 && $columns[$i - 1] === $aisleAfter): ?>
                                    <span class="aisle-gap"></span>
                                <?php endif; ?>
                                <span class="col-header"><?= e($col) ?></span>
                            <?php endforeach; ?>
                        </div>

                        <?php foreach ($seatGrid as $rowNum => $rowSeats): ?>
                        <div class="seat-row">
                            <span class="seat-row-num"><?= $rowNum ?></span>
                            <?php foreach ($columns as $i => $col): ?>
                                <?php if ($i > 0 && $columns[$i - 1] === $aisleAfter): ?>
                                    <span class="aisle-gap"></span>
                                <?php endif; ?>

                                <?php if (isset($rowSeats[$col])): ?>
                                    <?php $seat = $rowSeats[$col]; ?>
                                    <button
                                        type="button"
                                        class="seat seat--<?= strtolower($seat['seat_status']) ?>"
                                        data-seat-id="<?= (int) $seat['seat_id'] ?>"
                                        data-seat-num="<?= e($seat['seat_number']) ?>"
                                        data-seat-type="<?= e($seat['seat_type']) ?>"
                                        <?= $seat['seat_status'] === 'Booked' ? 'disabled aria-disabled="true"' : '' ?>
                                        title="<?= e($seat['seat_number']) ?> – <?= e($seat['seat_type']) ?> – <?= e($seat['seat_status']) ?>"
                                    >
                                        <?= e($seat['seat_number']) ?>
                                    </button>
                                <?php else: ?>
                                    <span class="seat seat--empty"></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Selection summary + Continue -->
                    <div class="seat-action-bar" id="seat-action-bar">
                        <div class="seat-selection-info" id="seat-selection-info">
                            <span id="seats-selected-label">No seats selected</span>
                        </div>
                        <button type="submit" class="btn-continue" id="btn-continue" disabled>
                            Continue to Passenger Details
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </form>

            </div>
        </div>

        <!-- RIGHT: Journey Summary Sidebar -->
        <aside class="seat-sidebar">
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
                            <span>Bus</span>
                            <span><?= e($schedule['bus_name']) ?></span>
                        </div>
                        <div class="js-detail-row">
                            <span>Base fare</span>
                            <span><?= formatCurrency($schedule['base_price']) ?></span>
                        </div>
                    </div>

                    <!-- Live selected seats list -->
                    <div class="js-selected-seats" id="js-selected-seats">
                        <div class="js-seats-placeholder">Select a seat to begin</div>
                    </div>

                    <div class="js-price">
                        <span class="js-price-label">Estimated total</span>
                        <span class="js-price-amount" id="js-total-price"><?= formatCurrency(0) ?></span>
                        <span class="js-price-note">based on adult fare × seats</span>
                    </div>
                </div>
            </div>

            <?php if (!empty($tags)): ?>
            <div class="sidebar-card" style="margin-top:0;">
                <div class="sidebar-title" style="margin-bottom:12px;">On this trip</div>
                <div class="trip-tags" style="gap:6px;">
                    <?php foreach ($tags as $tag): ?>
                        <span class="route-tag"><?= e($tag) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <a href="<?= BASE_URL ?>bus_details.php?schedule_id=<?= $scheduleId ?>" class="btn-back-search">
                ← Back to trip details
            </a>
        </aside>

    </div>
</section>

<script>
(function () {
    const selected   = new Set();
    const basePrice  = <?= (float) $schedule['base_price'] ?>;
    const form       = document.getElementById('seat-form');
    const hiddenIn   = document.getElementById('selected_seats_input');
    const continueBtn = document.getElementById('btn-continue');
    const label      = document.getElementById('seats-selected-label');
    const totalEl    = document.getElementById('js-total-price');
    const seatListEl = document.getElementById('js-selected-seats');

    function formatCurrency(n) {
        return '$' + n.toFixed(2);
    }

    function updateUI() {
        hiddenIn.value = Array.from(selected).join(',');
        continueBtn.disabled = selected.size === 0;

        if (selected.size === 0) {
            label.textContent = 'No seats selected';
            totalEl.textContent = formatCurrency(0);
            seatListEl.innerHTML = '<div class="js-seats-placeholder">Select a seat to begin</div>';
        } else {
            label.textContent = selected.size + ' seat' + (selected.size > 1 ? 's' : '') + ' selected';
            totalEl.textContent = formatCurrency(basePrice * selected.size);

            // Build selected seat tags
            let html = '<div class="js-seat-chips">';
            document.querySelectorAll('.seat--selected').forEach(function (btn) {
                html += '<span class="js-seat-chip">'
                    + btn.dataset.seatNum + ' <small>(' + btn.dataset.seatType + ')</small>'
                    + '</span>';
            });
            html += '</div>';
            seatListEl.innerHTML = html;
        }
    }

    document.querySelectorAll('.seat--available').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = btn.dataset.seatId;
            if (selected.has(id)) {
                selected.delete(id);
                btn.classList.remove('seat--selected');
                btn.classList.add('seat--available');
            } else {
                selected.add(id);
                btn.classList.remove('seat--available');
                btn.classList.add('seat--selected');
            }
            updateUI();
        });
    });

    updateUI();
})();
</script>

<?php require_once 'includes/footer.php'; ?>

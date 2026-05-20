<?php
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';

requireLogin();
requireAdmin();

$errors = [];

// Safe to delete only if no confirmed/pending bookings exist
function scheduleHasBookings($conn, int $scheduleId): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS n FROM bookings
        WHERE schedule_id = ? AND booking_status != 'Cancelled'
    ");
    $stmt->bind_param('i', $scheduleId);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n > 0;
}

$scheduleStatuses = ['scheduled','departed','cancelled','completed'];
$action           = $_POST['action'] ?? $_GET['action'] ?? '';
$scheduleId       = (int)($_POST['schedule_id'] ?? $_GET['schedule_id'] ?? 0);

// Fetch buses and routes for dropdowns
$buses = $conn->query("
    SELECT bus_id, bus_name, bus_number, seat_capacity
    FROM   buses WHERE status='active' ORDER BY bus_name
")->fetch_all(MYSQLI_ASSOC);

$routes = $conn->query("
    SELECT route_id, origin, destination, distance_km
    FROM   routes WHERE status='active' ORDER BY origin, destination
")->fetch_all(MYSQLI_ASSOC);

// ── POST HANDLERS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $busId         = (int)($_POST['bus_id']         ?? 0);
    $routeId       = (int)($_POST['route_id']       ?? 0);
    $depDate       = trim($_POST['departure_date']  ?? '');
    $depTime       = trim($_POST['departure_time']  ?? '');
    $arrTime       = trim($_POST['arrival_time']    ?? '');
    $availSeats    = (int)($_POST['available_seats'] ?? 0);
    $status        = trim($_POST['status']           ?? 'scheduled');

    if ($action === 'add' || $action === 'edit') {
        if ($busId < 1)                              $errors[] = 'Please select a bus.';
        if ($routeId < 1)                            $errors[] = 'Please select a route.';
        if (empty($depDate))                         $errors[] = 'Departure date is required.';
        if (empty($depTime))                         $errors[] = 'Departure time is required.';
        if (empty($arrTime))                         $errors[] = 'Arrival time is required.';
        if ($availSeats < 0)                         $errors[] = 'Available seats cannot be negative.';
        if (!in_array($status, $scheduleStatuses))   $errors[] = 'Invalid status.';

        if (!empty($depTime) && !empty($arrTime) && $arrTime <= $depTime) {
            $errors[] = 'Arrival time must be after departure time.';
        }

        // Cap available_seats to bus seat_capacity
        if ($busId > 0 && $availSeats > 0) {
            foreach ($buses as $b) {
                if ((int)$b['bus_id'] === $busId && $availSeats > (int)$b['seat_capacity']) {
                    $errors[] = 'Available seats cannot exceed bus seat capacity (' . $b['seat_capacity'] . ').';
                    break;
                }
            }
        }
    }

    if ($action === 'add' && empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO schedules (bus_id, route_id, departure_date, departure_time, arrival_time, available_seats, status)
            VALUES (?,?,?,?,?,?,?)
        ");
        $stmt->bind_param('iisssis', $busId, $routeId, $depDate, $depTime, $arrTime, $availSeats, $status);
        if ($stmt->execute()) {
            setFlash('success', 'Schedule added.');
            $stmt->close();
            header('Location: ' . BASE_URL . 'admin/schedules.php'); exit;
        }
        $errors[] = 'Failed to add schedule.';
        $stmt->close();
        $action = 'add';

    } elseif ($action === 'edit' && $scheduleId > 0 && empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE schedules
            SET bus_id=?, route_id=?, departure_date=?, departure_time=?,
                arrival_time=?, available_seats=?, status=?
            WHERE schedule_id=?
        ");
        $stmt->bind_param('iisssisi', $busId, $routeId, $depDate, $depTime, $arrTime, $availSeats, $status, $scheduleId);
        if ($stmt->execute()) {
            $stmt->close();

            // Notify all confirmed passengers on this schedule of the change
            $affectedUsers = $conn->prepare("
                SELECT DISTINCT b.user_id, b.booking_id, b.booking_reference,
                       r.origin, r.destination
                FROM   bookings b
                JOIN   schedules s ON s.schedule_id = b.schedule_id
                JOIN   routes r    ON r.route_id    = s.route_id
                WHERE  b.schedule_id = ? AND b.booking_status = 'Confirmed'
            ");
            $affectedUsers->bind_param('i', $scheduleId);
            $affectedUsers->execute();
            $affectedRows = $affectedUsers->get_result()->fetch_all(MYSQLI_ASSOC);
            $affectedUsers->close();

            foreach ($affectedRows as $affected) {
                createNotification(
                    $conn,
                    $affected['user_id'],
                    $affected['booking_id'],
                    'schedule_changed',
                    'Schedule Updated – ' . $affected['booking_reference'],
                    'The schedule for your booking ' . $affected['booking_reference']
                    . ' (' . $affected['origin'] . ' → ' . $affected['destination'] . ')'
                    . ' has been updated by the operator. Please check your booking for the latest times.'
                );
            }

            $notified = count($affectedRows);
            $msg = 'Schedule updated.';
            if ($notified > 0) {
                $msg .= ' ' . $notified . ' passenger' . ($notified > 1 ? 's' : '') . ' notified.';
            }
            setFlash('success', $msg);
            header('Location: ' . BASE_URL . 'admin/schedules.php'); exit;
        }
        $errors[] = 'Update failed.';
        $stmt->close();
        $action = 'edit';

    } elseif ($action === 'delete' && $scheduleId > 0) {
        if (scheduleHasBookings($conn, $scheduleId)) {
            setFlash('error', 'Cannot delete — this schedule has confirmed bookings.');
        } else {
            $stmt = $conn->prepare("DELETE FROM schedules WHERE schedule_id=?");
            $stmt->bind_param('i', $scheduleId);
            $stmt->execute() ? setFlash('success', 'Schedule deleted.') : setFlash('error', 'Delete failed.');
            $stmt->close();
        }
        header('Location: ' . BASE_URL . 'admin/schedules.php'); exit;
    }
}

// ── FETCH ALL SCHEDULES ───────────────────────────────────────
$filter = trim($_GET['filter'] ?? '');
$validFilters = ['scheduled','departed','cancelled','completed'];

$sql = "
    SELECT s.*, b.bus_name, b.bus_number, r.origin, r.destination,
           COUNT(bk.booking_id) AS booking_count
    FROM  schedules s
    JOIN  buses b ON b.bus_id = s.bus_id
    JOIN  routes r ON r.route_id = s.route_id
    LEFT JOIN bookings bk ON bk.schedule_id = s.schedule_id AND bk.booking_status != 'Cancelled'
    WHERE 1=1
";
$params = []; $types = '';
if ($filter && in_array($filter, $validFilters)) {
    $sql .= " AND s.status = ?"; $params[] = $filter; $types .= 's';
}
$sql .= " GROUP BY s.schedule_id ORDER BY s.departure_date DESC, s.departure_time DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Status counts for filter tabs
$statusCounts = [];
$res = $conn->query("SELECT status, COUNT(*) AS n FROM schedules GROUP BY status");
while ($row = $res->fetch_assoc()) $statusCounts[$row['status']] = (int)$row['n'];
$statusCounts['all'] = array_sum($statusCounts);

// Edit form data
$formSched = [];
if ($action === 'edit' && $scheduleId > 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $conn->prepare("SELECT * FROM schedules WHERE schedule_id=?");
        $stmt->bind_param('i', $scheduleId);
        $stmt->execute();
        $formSched = $stmt->get_result()->fetch_assoc() ?? [];
        $stmt->close();
        if (empty($formSched)) { setFlash('error','Schedule not found.'); header('Location: ' . BASE_URL . 'admin/schedules.php'); exit; }
    } else {
        $formSched = [
            'schedule_id'    => $scheduleId,
            'bus_id'         => $_POST['bus_id']          ?? 0,
            'route_id'       => $_POST['route_id']        ?? 0,
            'departure_date' => $_POST['departure_date']  ?? '',
            'departure_time' => $_POST['departure_time']  ?? '',
            'arrival_time'   => $_POST['arrival_time']    ?? '',
            'available_seats'=> $_POST['available_seats'] ?? 0,
            'status'         => $_POST['status']          ?? 'scheduled',
        ];
    }
} elseif ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $formSched = [
        'bus_id'=>$_POST['bus_id']??0,'route_id'=>$_POST['route_id']??0,
        'departure_date'=>$_POST['departure_date']??'','departure_time'=>$_POST['departure_time']??'',
        'arrival_time'=>$_POST['arrival_time']??'','available_seats'=>$_POST['available_seats']??0,
        'status'=>$_POST['status']??'scheduled',
    ];
}

$pageTitle = 'Manage Schedules';
$adminPage = 'schedules';
require_once 'admin_header.php';
?>

<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">Schedules</h1>
        <p class="admin-page-sub">Manage trip schedules — assign buses to routes with dates and times.</p>
    </div>
    <?php if ($action !== 'add'): ?>
    <a href="<?= BASE_URL ?>admin/schedules.php?action=add" class="btn-admin-primary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Schedule
    </a>
    <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error" style="margin-bottom:20px;">
    <?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ADD / EDIT FORM -->
<?php if ($action === 'add' || ($action === 'edit' && !empty($formSched))): ?>
<?php $isEdit = ($action === 'edit'); ?>
<div class="admin-card admin-form-card">
    <div class="admin-card-header">
        <h2 class="admin-card-title"><?= $isEdit ? 'Edit Schedule' : 'Add New Schedule' ?></h2>
        <a href="<?= BASE_URL ?>admin/schedules.php" class="btn-admin-ghost">Cancel</a>
    </div>
    <form method="POST" action="<?= BASE_URL ?>admin/schedules.php" class="admin-form">
        <input type="hidden" name="action" value="<?= $isEdit ? 'edit' : 'add' ?>">
        <?php if ($isEdit): ?><input type="hidden" name="schedule_id" value="<?= (int)$formSched['schedule_id'] ?>"><?php endif; ?>

        <div class="admin-form-grid admin-form-grid--3">
            <div class="form-group">
                <label for="bus_id">Bus <span class="req">*</span></label>
                <select id="bus_id" name="bus_id" required onchange="updateSeats(this)">
                    <option value="">— Select bus —</option>
                    <?php foreach ($buses as $b): ?>
                        <option value="<?= (int)$b['bus_id'] ?>"
                                data-seats="<?= (int)$b['seat_capacity'] ?>"
                            <?= (int)($formSched['bus_id'] ?? 0) === (int)$b['bus_id'] ? 'selected' : '' ?>>
                            <?= e($b['bus_name']) ?> (<?= e($b['bus_number']) ?>) · <?= (int)$b['seat_capacity'] ?> seats
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="route_id">Route <span class="req">*</span></label>
                <select id="route_id" name="route_id" required>
                    <option value="">— Select route —</option>
                    <?php foreach ($routes as $rt): ?>
                        <option value="<?= (int)$rt['route_id'] ?>"
                            <?= (int)($formSched['route_id'] ?? 0) === (int)$rt['route_id'] ? 'selected' : '' ?>>
                            <?= e($rt['origin']) ?> → <?= e($rt['destination']) ?> (<?= (int)$rt['distance_km'] ?> km)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="status">Status <span class="req">*</span></label>
                <select id="status" name="status">
                    <?php foreach ($scheduleStatuses as $st): ?>
                        <option value="<?= $st ?>" <?= ($formSched['status'] ?? 'scheduled') === $st ? 'selected' : '' ?>>
                            <?= ucfirst($st) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="departure_date">Departure Date <span class="req">*</span></label>
                <input type="date" id="departure_date" name="departure_date"
                       value="<?= e($formSched['departure_date'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="departure_time">Departure Time <span class="req">*</span></label>
                <input type="time" id="departure_time" name="departure_time"
                       value="<?= e(substr($formSched['departure_time'] ?? '', 0, 5)) ?>" required>
            </div>

            <div class="form-group">
                <label for="arrival_time">Arrival Time <span class="req">*</span></label>
                <input type="time" id="arrival_time" name="arrival_time"
                       value="<?= e(substr($formSched['arrival_time'] ?? '', 0, 5)) ?>" required>
            </div>

            <div class="form-group">
                <label for="available_seats">Available Seats <span class="req">*</span></label>
                <input type="number" id="available_seats" name="available_seats"
                       value="<?= (int)($formSched['available_seats'] ?? 0) ?>"
                       min="0" max="200" required>
                <p class="field-note" id="seats-hint">Select a bus to see seat capacity.</p>
            </div>
        </div>

        <div class="admin-form-actions">
            <button type="submit" class="btn-admin-primary"><?= $isEdit ? 'Save Changes' : 'Add Schedule' ?></button>
            <a href="<?= BASE_URL ?>admin/schedules.php" class="btn-admin-ghost">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- FILTER TABS -->
<div class="admin-filter-bar" style="margin-bottom:16px;">
    <div class="admin-filter-tabs">
        <?php
        $tabs = ['all' => 'All', 'scheduled' => 'Scheduled', 'departed' => 'Departed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
        foreach ($tabs as $key => $label):
            $href = BASE_URL . 'admin/schedules.php' . ($key !== 'all' ? '?filter=' . $key : '');
            $isActive = ($key === 'all' && !$filter) || $key === $filter;
        ?>
        <a href="<?= $href ?>" class="aft-tab <?= $isActive ? 'aft-tab--active' : '' ?>">
            <?= $label ?> <span class="aft-count"><?= $statusCounts[$key] ?? 0 ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- SCHEDULES TABLE -->
<div class="admin-card" style="padding:0; overflow:hidden;">
    <div class="admin-card-header" style="padding:16px 20px;">
        <h2 class="admin-card-title">Schedules <span class="admin-count-badge"><?= count($schedules) ?></span></h2>
    </div>
    <?php if (empty($schedules)): ?>
        <div class="admin-empty">No schedules found.</div>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Route</th>
                    <th>Bus</th>
                    <th>Date</th>
                    <th>Depart</th>
                    <th>Arrive</th>
                    <th>Seats</th>
                    <th>Bookings</th>
                    <th>Status</th>
                    <th style="width:140px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($schedules as $sc):
                $hasBookings = (int)$sc['booking_count'] > 0;
                $stCls = match($sc['status']) {
                    'scheduled'  => 'status-pill--active',
                    'completed'  => 'status-pill--inactive',
                    'departed'   => 'status-pill--maintenance',
                    'cancelled'  => 'status-pill--cancelled',
                    default      => '',
                };
                $isPast = $sc['departure_date'] < date('Y-m-d');
            ?>
            <tr class="<?= $isPast && $sc['status'] === 'scheduled' ? 'tr--warning' : '' ?>">
                <td class="td-route"><?= e($sc['origin']) ?> → <?= e($sc['destination']) ?></td>
                <td>
                    <span class="td-name"><?= e($sc['bus_name']) ?></span>
                    <span class="td-email"><?= e($sc['bus_number']) ?></span>
                </td>
                <td class="td-nowrap"><?= date('d M Y', strtotime($sc['departure_date'])) ?></td>
                <td class="td-nowrap"><?= formatTime($sc['departure_time']) ?></td>
                <td class="td-nowrap"><?= formatTime($sc['arrival_time']) ?></td>
                <td class="td-center"><?= (int)$sc['available_seats'] ?></td>
                <td class="td-center">
                    <?php if ($hasBookings): ?>
                        <span class="booking-status-badge bsb--confirmed"><?= (int)$sc['booking_count'] ?></span>
                    <?php else: ?>
                        <span class="td-muted">0</span>
                    <?php endif; ?>
                </td>
                <td><span class="status-pill <?= $stCls ?>"><?= ucfirst(e($sc['status'])) ?></span></td>
                <td>
                    <div class="admin-row-actions">
                        <a href="<?= BASE_URL ?>admin/schedules.php?action=edit&schedule_id=<?= (int)$sc['schedule_id'] ?>" class="btn-row-edit">Edit</a>
                        <?php if ($hasBookings): ?>
                            <span class="btn-row-delete btn-row-delete--disabled" title="Has confirmed bookings — cannot delete">Delete</span>
                        <?php else: ?>
                            <form method="POST" action="<?= BASE_URL ?>admin/schedules.php"
                                  onsubmit="return confirm('Delete this schedule?');">
                                <input type="hidden" name="action"      value="delete">
                                <input type="hidden" name="schedule_id" value="<?= (int)$sc['schedule_id'] ?>">
                                <button type="submit" class="btn-row-delete">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
function updateSeats(sel) {
    const opt   = sel.options[sel.selectedIndex];
    const cap   = opt.dataset.seats || 0;
    const input = document.getElementById('available_seats');
    const hint  = document.getElementById('seats-hint');
    if (cap > 0) {
        input.max         = cap;
        hint.textContent  = 'Bus capacity: ' + cap + ' seats.';
        // Only auto-fill if field is empty or editing a new schedule
        if (!input.value || parseInt(input.value) === 0) input.value = cap;
    } else {
        hint.textContent = 'Select a bus to see seat capacity.';
    }
}
// Run on page load for edit form
document.addEventListener('DOMContentLoaded', function () {
    const sel = document.getElementById('bus_id');
    if (sel && sel.value) updateSeats(sel);
});
</script>

<?php require_once 'admin_footer.php'; ?>

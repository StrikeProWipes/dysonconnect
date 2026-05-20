<?php
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';

requireLogin();
requireAdmin();

$errors = [];

// Check if bus is linked to upcoming/active schedules
function busHasActiveSchedules($conn, int $busId): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS n FROM schedules
        WHERE  bus_id = ? AND status IN ('scheduled','departed')
          AND  departure_date >= CURDATE()
    ");
    $stmt->bind_param('i', $busId);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n > 0;
}

$busTypes  = ['Standard', 'Express', 'Sleeper', 'Mini'];
$busStates = ['active', 'inactive', 'maintenance'];
$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$busId     = (int)($_POST['bus_id'] ?? $_GET['bus_id'] ?? 0);

// ── POST HANDLERS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $busName  = trim($_POST['bus_name']      ?? '');
    $busNum   = trim($_POST['bus_number']    ?? '');
    $busType  = trim($_POST['bus_type']      ?? 'Standard');
    $seatCap  = (int)($_POST['seat_capacity'] ?? 40);
    $driverId = ($_POST['driver_id'] ?? '') !== '' ? (int)$_POST['driver_id'] : null;
    $status   = trim($_POST['status']        ?? 'active');

    // Handle image upload
    $busImg = trim($_POST['bus_image_existing'] ?? ''); // keep existing if no new file
    if (!empty($_FILES['bus_image']['name'])) {
        $uploadDir = dirname(__DIR__) . '/assets/images/buses/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        $fType   = mime_content_type($_FILES['bus_image']['tmp_name']);
        if (!in_array($fType, $allowed)) {
            $errors[] = 'Bus image must be a JPG, PNG, GIF, or WEBP file.';
        } elseif ($_FILES['bus_image']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Bus image must be under 5 MB.';
        } else {
            $ext     = pathinfo($_FILES['bus_image']['name'], PATHINFO_EXTENSION);
            $fname   = 'bus_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
            if (move_uploaded_file($_FILES['bus_image']['tmp_name'], $uploadDir . $fname)) {
                $busImg = 'assets/images/buses/' . $fname;
            } else {
                $errors[] = 'Failed to save the uploaded image.';
            }
        }
    }

    if ($action === 'add' || $action === 'edit') {
        if (empty($busName))                   $errors[] = 'Bus name is required.';
        if (empty($busNum))                    $errors[] = 'Bus number is required.';
        if (!in_array($busType, $busTypes))    $errors[] = 'Invalid bus type.';
        if ($seatCap < 1 || $seatCap > 200)   $errors[] = 'Seat capacity must be 1–200.';
        if (!in_array($status, $busStates))    $errors[] = 'Invalid status.';

        if (empty($errors)) {
            // Unique bus_number check
            $sql  = "SELECT bus_id FROM buses WHERE bus_number = ?";
            $args = [$busNum];
            $type = 's';
            if ($action === 'edit') { $sql .= " AND bus_id != ?"; $args[] = $busId; $type .= 'i'; }
            $chk = $conn->prepare($sql);
            $chk->bind_param($type, ...$args);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) $errors[] = 'Bus number is already in use.';
            $chk->close();
        }
    }

    if ($action === 'add' && empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO buses (bus_name,bus_number,bus_type,seat_capacity,bus_image,driver_id,status)
            VALUES (?,?,?,?,?,?,?)
        ");
        $stmt->bind_param('sssisis', $busName, $busNum, $busType, $seatCap, $busImg, $driverId, $status);
        if ($stmt->execute()) {
            setFlash('success', "Bus \"{$busName}\" added.");
            $stmt->close();
            header('Location: ' . BASE_URL . 'admin/buses.php'); exit;
        }
        $errors[] = 'Failed to add bus.';
        $stmt->close();
        $action = 'add';

    } elseif ($action === 'edit' && $busId > 0 && empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE buses SET bus_name=?,bus_number=?,bus_type=?,seat_capacity=?,
                            bus_image=?,driver_id=?,status=?
            WHERE bus_id=?
        ");
        $stmt->bind_param('sssisisi', $busName, $busNum, $busType, $seatCap, $busImg, $driverId, $status, $busId);
        if ($stmt->execute()) {
            setFlash('success', "Bus \"{$busName}\" updated.");
            $stmt->close();
            header('Location: ' . BASE_URL . 'admin/buses.php'); exit;
        }
        $errors[] = 'Update failed.';
        $stmt->close();
        $action = 'edit';

    } elseif ($action === 'delete' && $busId > 0) {
        if (busHasActiveSchedules($conn, $busId)) {
            setFlash('error', 'Cannot delete — this bus has upcoming or active schedules.');
        } else {
            $stmt = $conn->prepare("DELETE FROM buses WHERE bus_id=?");
            $stmt->bind_param('i', $busId);
            $stmt->execute() ? setFlash('success','Bus deleted.') : setFlash('error','Delete failed.');
            $stmt->close();
        }
        header('Location: ' . BASE_URL . 'admin/buses.php'); exit;
    }
}

// ── FETCH DATA ────────────────────────────────────────────────
$buses = $conn->query("
    SELECT b.*, d.full_name AS driver_name
    FROM   buses b
    LEFT JOIN drivers d ON d.driver_id = b.driver_id
    ORDER  BY b.bus_name
")->fetch_all(MYSQLI_ASSOC);

$drivers = $conn->query("
    SELECT driver_id, full_name, licence_number
    FROM   drivers WHERE status='active' ORDER BY full_name
")->fetch_all(MYSQLI_ASSOC);

// For editing: populate form from DB (GET) or keep POST values (failed POST)
$formBus = [];
if ($action === 'edit' && $busId > 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $conn->prepare("SELECT * FROM buses WHERE bus_id=?");
        $stmt->bind_param('i', $busId);
        $stmt->execute();
        $formBus = $stmt->get_result()->fetch_assoc() ?? [];
        $stmt->close();
        if (empty($formBus)) {
            setFlash('error', 'Bus not found.'); header('Location: ' . BASE_URL . 'admin/buses.php'); exit;
        }
    } else {
        $formBus = ['bus_id'=>$busId,'bus_name'=>$_POST['bus_name']??'','bus_number'=>$_POST['bus_number']??'',
                    'bus_type'=>$_POST['bus_type']??'Standard','seat_capacity'=>$_POST['seat_capacity']??40,
                    'bus_image'=>$busImg,'driver_id'=>$_POST['driver_id']??'','status'=>$_POST['status']??'active'];
    }
} elseif ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $formBus = ['bus_name'=>$_POST['bus_name']??'','bus_number'=>$_POST['bus_number']??'',
                'bus_type'=>$_POST['bus_type']??'Standard','seat_capacity'=>$_POST['seat_capacity']??40,
                'bus_image'=>$busImg,'driver_id'=>$_POST['driver_id']??'','status'=>$_POST['status']??'active'];
}

$pageTitle = 'Manage Buses';
$adminPage = 'buses';
require_once 'admin_header.php';
?>

<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">Buses</h1>
        <p class="admin-page-sub">Manage the DysonConnect fleet.</p>
    </div>
    <?php if ($action !== 'add'): ?>
    <a href="<?= BASE_URL ?>admin/buses.php?action=add" class="btn-admin-primary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Bus
    </a>
    <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error" style="margin-bottom:20px;">
    <?php foreach ($errors as $e_): ?><p><?= e($e_) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ADD / EDIT FORM -->
<?php if ($action === 'add' || ($action === 'edit' && !empty($formBus))): ?>
<?php $isEdit = ($action === 'edit'); ?>
<div class="admin-card admin-form-card">
    <div class="admin-card-header">
        <h2 class="admin-card-title"><?= $isEdit ? 'Edit Bus' : 'Add New Bus' ?></h2>
        <a href="<?= BASE_URL ?>admin/buses.php" class="btn-admin-ghost">Cancel</a>
    </div>
    <form method="POST" action="<?= BASE_URL ?>admin/buses.php" class="admin-form" enctype="multipart/form-data">
        <input type="hidden" name="action" value="<?= $isEdit ? 'edit' : 'add' ?>">
        <?php if ($isEdit): ?><input type="hidden" name="bus_id" value="<?= (int)$formBus['bus_id'] ?>"><?php endif; ?>

        <div class="admin-form-grid">
            <div class="form-group">
                <label for="bus_name">Bus Name <span class="req">*</span></label>
                <input type="text" id="bus_name" name="bus_name" value="<?= e($formBus['bus_name'] ?? '') ?>"
                       placeholder="e.g. Dyson Voyager 3" maxlength="100" required>
            </div>
            <div class="form-group">
                <label for="bus_number">Bus Number <span class="req">*</span></label>
                <input type="text" id="bus_number" name="bus_number" value="<?= e($formBus['bus_number'] ?? '') ?>"
                       placeholder="e.g. DC-006" maxlength="20" required>
            </div>
            <div class="form-group">
                <label for="bus_type">Type <span class="req">*</span></label>
                <select id="bus_type" name="bus_type">
                    <?php foreach ($busTypes as $bt): ?>
                        <option value="<?= $bt ?>" <?= ($formBus['bus_type'] ?? 'Standard') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="seat_capacity">Seat Capacity <span class="req">*</span></label>
                <input type="number" id="seat_capacity" name="seat_capacity"
                       value="<?= (int)($formBus['seat_capacity'] ?? 40) ?>" min="1" max="200" required>
            </div>
            <div class="form-group">
                <label for="driver_id">Assigned Driver</label>
                <select id="driver_id" name="driver_id">
                    <option value="">— No driver assigned —</option>
                    <?php foreach ($drivers as $drv): ?>
                        <option value="<?= (int)$drv['driver_id'] ?>"
                            <?= (string)($formBus['driver_id'] ?? '') === (string)$drv['driver_id'] ? 'selected' : '' ?>>
                            <?= e($drv['full_name']) ?> (<?= e($drv['licence_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="status">Status <span class="req">*</span></label>
                <select id="status" name="status">
                    <?php foreach ($busStates as $st): ?>
                        <option value="<?= $st ?>" <?= ($formBus['status'] ?? 'active') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group admin-form-full">
                <label for="bus_image">Bus Image <span class="form-hint">(optional — JPG, PNG, WEBP, max 5 MB)</span></label>
                <?php if (!empty($formBus['bus_image'])): ?>
                    <div class="bus-img-preview" style="margin-bottom:8px;">
                        <img src="<?= BASE_URL . e($formBus['bus_image']) ?>" alt="Current bus image"
                             style="height:64px;border-radius:6px;border:1px solid #ddd;object-fit:cover;">
                        <span class="form-hint" style="margin-left:8px;">Current image</span>
                    </div>
                <?php endif; ?>
                <input type="hidden" name="bus_image_existing" value="<?= e($formBus['bus_image'] ?? '') ?>">
                <input type="file" id="bus_image" name="bus_image" accept="image/jpeg,image/png,image/gif,image/webp">
            </div>
        </div>
        <div class="admin-form-actions">
            <button type="submit" class="btn-admin-primary"><?= $isEdit ? 'Save Changes' : 'Add Bus' ?></button>
            <a href="<?= BASE_URL ?>admin/buses.php" class="btn-admin-ghost">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- BUSES TABLE -->
<div class="admin-card" style="padding:0; overflow:hidden;">
    <div class="admin-card-header" style="padding:16px 20px;">
        <h2 class="admin-card-title">All Buses <span class="admin-count-badge"><?= count($buses) ?></span></h2>
    </div>
    <?php if (empty($buses)): ?>
        <div class="admin-empty">No buses yet. Add one above.</div>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Bus Name</th>
                    <th>Number</th>
                    <th>Type</th>
                    <th>Seats</th>
                    <th>Driver</th>
                    <th>Status</th>
                    <th style="width:140px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($buses as $bus):
                $hasSchedules = busHasActiveSchedules($conn, (int)$bus['bus_id']);
                $stCls = match($bus['status']) {
                    'active'      => 'status-pill--active',
                    'inactive'    => 'status-pill--inactive',
                    'maintenance' => 'status-pill--maintenance',
                    default       => '',
                };
            ?>
            <tr>
                <td>
                    <span class="td-name"><?= e($bus['bus_name']) ?></span>
                    <?php if ($bus['bus_image']): ?><span title="Has image">🖼</span><?php endif; ?>
                </td>
                <td class="td-mono"><?= e($bus['bus_number']) ?></td>
                <td><span class="type-badge type-badge--<?= strtolower($bus['bus_type']) ?>"><?= e($bus['bus_type']) ?></span></td>
                <td class="td-center"><?= (int)$bus['seat_capacity'] ?></td>
                <td><?= $bus['driver_name'] ? e($bus['driver_name']) : '<span class="td-muted">Unassigned</span>' ?></td>
                <td><span class="status-pill <?= $stCls ?>"><?= ucfirst(e($bus['status'])) ?></span></td>
                <td>
                    <div class="admin-row-actions">
                        <a href="<?= BASE_URL ?>admin/buses.php?action=edit&bus_id=<?= (int)$bus['bus_id'] ?>" class="btn-row-edit">Edit</a>
                        <?php if ($hasSchedules): ?>
                            <span class="btn-row-delete btn-row-delete--disabled" title="Has active schedules — cannot delete">Delete</span>
                        <?php else: ?>
                            <form method="POST" action="<?= BASE_URL ?>admin/buses.php"
                                  onsubmit="return confirm('Delete \'<?= e(addslashes($bus['bus_name'])) ?>\'?');">
                                <input type="hidden" name="action"  value="delete">
                                <input type="hidden" name="bus_id" value="<?= (int)$bus['bus_id'] ?>">
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

<?php require_once 'admin_footer.php'; ?>

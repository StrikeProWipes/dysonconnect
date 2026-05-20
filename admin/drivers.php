<?php
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';

requireLogin();
requireAdmin();

$errors = [];

// Driver can only be deleted if not assigned to any active bus
function driverAssignedToBus($conn, int $driverId): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS n FROM buses
        WHERE driver_id = ? AND status = 'active'
    ");
    $stmt->bind_param('i', $driverId);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n > 0;
}

$driverStatuses = ['active', 'inactive'];
$action         = $_POST['action'] ?? $_GET['action'] ?? '';
$driverId       = (int)($_POST['driver_id'] ?? $_GET['driver_id'] ?? 0);

// ── POST HANDLERS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName     = trim($_POST['full_name']      ?? '');
    $phone        = trim($_POST['phone']          ?? '');
    $licenceNum   = trim($_POST['licence_number'] ?? '');
    $status       = trim($_POST['status']         ?? 'active');

    if ($action === 'add' || $action === 'edit') {
        if (empty($fullName))                          $errors[] = 'Full name is required.';
        if (strlen($fullName) > 120)                   $errors[] = 'Name too long (max 120 chars).';
        if (empty($licenceNum))                        $errors[] = 'Licence number is required.';
        if (strlen($licenceNum) > 40)                  $errors[] = 'Licence number too long.';
        if (!empty($phone) && !preg_match('/^[0-9+\s\-]{7,20}$/', $phone))
                                                       $errors[] = 'Phone number format is invalid.';
        if (!in_array($status, $driverStatuses))       $errors[] = 'Invalid status.';

        if (empty($errors)) {
            // Unique licence check
            $sql  = "SELECT driver_id FROM drivers WHERE licence_number = ?";
            $args = [$licenceNum]; $type = 's';
            if ($action === 'edit') { $sql .= " AND driver_id != ?"; $args[] = $driverId; $type .= 'i'; }
            $chk = $conn->prepare($sql);
            $chk->bind_param($type, ...$args);
            $chk->execute(); $chk->store_result();
            if ($chk->num_rows > 0) $errors[] = 'Licence number is already registered.';
            $chk->close();
        }
    }

    if ($action === 'add' && empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO drivers (full_name, phone, licence_number, status) VALUES (?,?,?,?)");
        $stmt->bind_param('ssss', $fullName, $phone, $licenceNum, $status);
        if ($stmt->execute()) {
            setFlash('success', "Driver \"{$fullName}\" added.");
            $stmt->close();
            header('Location: ' . BASE_URL . 'admin/drivers.php'); exit;
        }
        $errors[] = 'Failed to add driver.';
        $stmt->close();
        $action = 'add';

    } elseif ($action === 'edit' && $driverId > 0 && empty($errors)) {
        $stmt = $conn->prepare("UPDATE drivers SET full_name=?, phone=?, licence_number=?, status=? WHERE driver_id=?");
        $stmt->bind_param('ssssi', $fullName, $phone, $licenceNum, $status, $driverId);
        if ($stmt->execute()) {
            setFlash('success', "Driver \"{$fullName}\" updated.");
            $stmt->close();
            header('Location: ' . BASE_URL . 'admin/drivers.php'); exit;
        }
        $errors[] = 'Update failed.';
        $stmt->close();
        $action = 'edit';

    } elseif ($action === 'delete' && $driverId > 0) {
        if (driverAssignedToBus($conn, $driverId)) {
            setFlash('error', 'Cannot delete — this driver is assigned to an active bus.');
        } else {
            $stmt = $conn->prepare("DELETE FROM drivers WHERE driver_id=?");
            $stmt->bind_param('i', $driverId);
            $stmt->execute() ? setFlash('success','Driver deleted.') : setFlash('error','Delete failed.');
            $stmt->close();
        }
        header('Location: ' . BASE_URL . 'admin/drivers.php'); exit;
    }
}

// ── FETCH DRIVERS ─────────────────────────────────────────────
$drivers = $conn->query("
    SELECT d.*, b.bus_name AS assigned_bus
    FROM   drivers d
    LEFT JOIN buses b ON b.driver_id = d.driver_id AND b.status = 'active'
    ORDER  BY d.full_name
")->fetch_all(MYSQLI_ASSOC);

// Edit form data
$formDriver = [];
if ($action === 'edit' && $driverId > 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $conn->prepare("SELECT * FROM drivers WHERE driver_id=?");
        $stmt->bind_param('i', $driverId);
        $stmt->execute();
        $formDriver = $stmt->get_result()->fetch_assoc() ?? [];
        $stmt->close();
        if (empty($formDriver)) { setFlash('error','Driver not found.'); header('Location: ' . BASE_URL . 'admin/drivers.php'); exit; }
    } else {
        $formDriver = ['driver_id'=>$driverId,'full_name'=>$_POST['full_name']??'',
                       'phone'=>$_POST['phone']??'','licence_number'=>$_POST['licence_number']??'',
                       'status'=>$_POST['status']??'active'];
    }
} elseif ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $formDriver = ['full_name'=>$_POST['full_name']??'','phone'=>$_POST['phone']??'',
                   'licence_number'=>$_POST['licence_number']??'','status'=>$_POST['status']??'active'];
}

$pageTitle = 'Manage Drivers';
$adminPage = 'drivers';
require_once 'admin_header.php';
?>

<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">Drivers</h1>
        <p class="admin-page-sub">Manage driver profiles and licence details.</p>
    </div>
    <?php if ($action !== 'add'): ?>
    <a href="<?= BASE_URL ?>admin/drivers.php?action=add" class="btn-admin-primary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Driver
    </a>
    <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error" style="margin-bottom:20px;">
    <?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ADD / EDIT FORM -->
<?php if ($action === 'add' || ($action === 'edit' && !empty($formDriver))): ?>
<?php $isEdit = ($action === 'edit'); ?>
<div class="admin-card admin-form-card">
    <div class="admin-card-header">
        <h2 class="admin-card-title"><?= $isEdit ? 'Edit Driver' : 'Add New Driver' ?></h2>
        <a href="<?= BASE_URL ?>admin/drivers.php" class="btn-admin-ghost">Cancel</a>
    </div>
    <form method="POST" action="<?= BASE_URL ?>admin/drivers.php" class="admin-form">
        <input type="hidden" name="action" value="<?= $isEdit ? 'edit' : 'add' ?>">
        <?php if ($isEdit): ?><input type="hidden" name="driver_id" value="<?= (int)$formDriver['driver_id'] ?>"><?php endif; ?>

        <div class="admin-form-grid">
            <div class="form-group">
                <label for="full_name">Full Name <span class="req">*</span></label>
                <div class="input-icon-wrap">
                    <svg class="input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" id="full_name" name="full_name"
                           value="<?= e($formDriver['full_name'] ?? '') ?>"
                           placeholder="e.g. Robert Walsh" maxlength="120" required>
                </div>
            </div>

            <div class="form-group">
                <label for="licence_number">Licence Number <span class="req">*</span></label>
                <div class="input-icon-wrap">
                    <svg class="input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                    <input type="text" id="licence_number" name="licence_number"
                           value="<?= e($formDriver['licence_number'] ?? '') ?>"
                           placeholder="e.g. VIC-LIC-006" maxlength="40" required>
                </div>
            </div>

            <div class="form-group">
                <label for="phone">Phone <span class="label-opt">(optional)</span></label>
                <div class="input-icon-wrap">
                    <svg class="input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 9.13a19.79 19.79 0 0 1-3.07-8.63A2 2 0 0 1 3.62 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.18 6.18l1.27-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <input type="tel" id="phone" name="phone"
                           value="<?= e($formDriver['phone'] ?? '') ?>"
                           placeholder="e.g. 0411 111 006" maxlength="20">
                </div>
            </div>

            <div class="form-group">
                <label for="status">Status <span class="req">*</span></label>
                <select id="status" name="status">
                    <?php foreach ($driverStatuses as $st): ?>
                        <option value="<?= $st ?>" <?= ($formDriver['status'] ?? 'active') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="admin-form-actions">
            <button type="submit" class="btn-admin-primary"><?= $isEdit ? 'Save Changes' : 'Add Driver' ?></button>
            <a href="<?= BASE_URL ?>admin/drivers.php" class="btn-admin-ghost">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- DRIVERS TABLE -->
<div class="admin-card" style="padding:0; overflow:hidden;">
    <div class="admin-card-header" style="padding:16px 20px;">
        <h2 class="admin-card-title">All Drivers <span class="admin-count-badge"><?= count($drivers) ?></span></h2>
    </div>
    <?php if (empty($drivers)): ?>
        <div class="admin-empty">No drivers yet. Add one above.</div>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Licence Number</th>
                    <th>Assigned Bus</th>
                    <th>Status</th>
                    <th style="width:140px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($drivers as $drv):
                $isAssigned = !empty($drv['assigned_bus']);
                $stCls = $drv['status'] === 'active' ? 'status-pill--active' : 'status-pill--inactive';
            ?>
            <tr>
                <td><span class="td-name"><?= e($drv['full_name']) ?></span></td>
                <td><?= $drv['phone'] ? e($drv['phone']) : '<span class="td-muted">—</span>' ?></td>
                <td class="td-mono"><?= e($drv['licence_number']) ?></td>
                <td>
                    <?php if ($isAssigned): ?>
                        <span class="driver-bus-badge"><?= e($drv['assigned_bus']) ?></span>
                    <?php else: ?>
                        <span class="td-muted">Unassigned</span>
                    <?php endif; ?>
                </td>
                <td><span class="status-pill <?= $stCls ?>"><?= ucfirst(e($drv['status'])) ?></span></td>
                <td>
                    <div class="admin-row-actions">
                        <a href="<?= BASE_URL ?>admin/drivers.php?action=edit&driver_id=<?= (int)$drv['driver_id'] ?>" class="btn-row-edit">Edit</a>
                        <?php if ($isAssigned): ?>
                            <span class="btn-row-delete btn-row-delete--disabled" title="Assigned to an active bus — cannot delete">Delete</span>
                        <?php else: ?>
                            <form method="POST" action="<?= BASE_URL ?>admin/drivers.php"
                                  onsubmit="return confirm('Delete driver \'<?= e(addslashes($drv['full_name'])) ?>\'?');">
                                <input type="hidden" name="action"    value="delete">
                                <input type="hidden" name="driver_id" value="<?= (int)$drv['driver_id'] ?>">
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

<?php
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';

requireLogin();
requireAdmin();

$errors = [];

// Check if route is linked to any future/active schedule
function routeHasActiveSchedules($conn, int $routeId): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS n FROM schedules
        WHERE  route_id = ? AND status IN ('scheduled','departed')
          AND  departure_date >= CURDATE()
    ");
    $stmt->bind_param('i', $routeId);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n > 0;
}

$routeStatus = ['active', 'inactive'];
$action      = $_POST['action'] ?? $_GET['action'] ?? '';
$routeId     = (int)($_POST['route_id'] ?? $_GET['route_id'] ?? 0);

// ── POST HANDLERS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $origin      = trim($_POST['origin']      ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $distKm      = (int)($_POST['distance_km'] ?? 0);
    $basePrice   = round((float)($_POST['base_price'] ?? 0), 2);
    $routeTags   = trim($_POST['route_tags']  ?? '');
    $status      = trim($_POST['status']      ?? 'active');

    if ($action === 'add' || $action === 'edit') {
        if (empty($origin))                      $errors[] = 'Origin is required.';
        if (empty($destination))                 $errors[] = 'Destination is required.';
        if ($origin === $destination && $origin) $errors[] = 'Origin and destination cannot be the same.';
        if ($distKm <= 0)                        $errors[] = 'Distance must be greater than 0.';
        if ($basePrice <= 0)                     $errors[] = 'Base price must be greater than 0.';
        if (!in_array($status, $routeStatus))    $errors[] = 'Invalid status.';
        if (strlen($origin) > 100)               $errors[] = 'Origin too long (max 100 chars).';
        if (strlen($destination) > 100)          $errors[] = 'Destination too long (max 100 chars).';
    }

    if ($action === 'add' && empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO routes (origin, destination, distance_km, base_price, route_tags, status)
            VALUES (?,?,?,?,?,?)
        ");
        $stmt->bind_param('ssidss', $origin, $destination, $distKm, $basePrice, $routeTags, $status);
        if ($stmt->execute()) {
            setFlash('success', "Route {$origin} → {$destination} added.");
            $stmt->close();
            header('Location: ' . BASE_URL . 'admin/routes.php'); exit;
        }
        $errors[] = 'Failed to add route.';
        $stmt->close();
        $action = 'add';

    } elseif ($action === 'edit' && $routeId > 0 && empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE routes
            SET origin=?, destination=?, distance_km=?, base_price=?, route_tags=?, status=?
            WHERE route_id=?
        ");
        $stmt->bind_param('ssidssi', $origin, $destination, $distKm, $basePrice, $routeTags, $status, $routeId);
        if ($stmt->execute()) {
            setFlash('success', "Route updated.");
            $stmt->close();
            header('Location: ' . BASE_URL . 'admin/routes.php'); exit;
        }
        $errors[] = 'Update failed.';
        $stmt->close();
        $action = 'edit';

    } elseif ($action === 'delete' && $routeId > 0) {
        if (routeHasActiveSchedules($conn, $routeId)) {
            setFlash('error', 'Cannot delete — this route has upcoming or active schedules.');
        } else {
            $stmt = $conn->prepare("DELETE FROM routes WHERE route_id=?");
            $stmt->bind_param('i', $routeId);
            $stmt->execute() ? setFlash('success','Route deleted.') : setFlash('error','Delete failed.');
            $stmt->close();
        }
        header('Location: ' . BASE_URL . 'admin/routes.php'); exit;
    }
}

// ── FETCH ALL ROUTES ──────────────────────────────────────────
$routes = $conn->query("
    SELECT r.*,
           COUNT(DISTINCT s.schedule_id) AS total_schedules,
           COUNT(DISTINCT CASE WHEN s.departure_date >= CURDATE()
                               AND s.status IN ('scheduled') THEN s.schedule_id END) AS upcoming_schedules
    FROM  routes r
    LEFT JOIN schedules s ON s.route_id = r.route_id
    GROUP BY r.route_id
    ORDER BY r.origin, r.destination
")->fetch_all(MYSQLI_ASSOC);

// Populate edit form
$formRoute = [];
if ($action === 'edit' && $routeId > 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $conn->prepare("SELECT * FROM routes WHERE route_id=?");
        $stmt->bind_param('i', $routeId);
        $stmt->execute();
        $formRoute = $stmt->get_result()->fetch_assoc() ?? [];
        $stmt->close();
        if (empty($formRoute)) {
            setFlash('error','Route not found.');
            header('Location: ' . BASE_URL . 'admin/routes.php'); exit;
        }
    } else {
        $formRoute = ['route_id'=>$routeId,'origin'=>$_POST['origin']??'','destination'=>$_POST['destination']??'',
                      'distance_km'=>$_POST['distance_km']??0,'base_price'=>$_POST['base_price']??0,
                      'route_tags'=>$_POST['route_tags']??'','status'=>$_POST['status']??'active'];
    }
} elseif ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $formRoute = ['origin'=>$_POST['origin']??'','destination'=>$_POST['destination']??'',
                  'distance_km'=>$_POST['distance_km']??0,'base_price'=>$_POST['base_price']??0,
                  'route_tags'=>$_POST['route_tags']??'','status'=>$_POST['status']??'active'];
}

$pageTitle = 'Manage Routes';
$adminPage = 'routes';
require_once 'admin_header.php';
?>

<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">Routes</h1>
        <p class="admin-page-sub">Manage intercity bus routes and pricing.</p>
    </div>
    <?php if ($action !== 'add'): ?>
    <a href="<?= BASE_URL ?>admin/routes.php?action=add" class="btn-admin-primary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Route
    </a>
    <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error" style="margin-bottom:20px;">
    <?php foreach ($errors as $e_): ?><p><?= e($e_) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ADD / EDIT FORM -->
<?php if ($action === 'add' || ($action === 'edit' && !empty($formRoute))): ?>
<?php $isEdit = ($action === 'edit'); ?>
<div class="admin-card admin-form-card">
    <div class="admin-card-header">
        <h2 class="admin-card-title"><?= $isEdit ? 'Edit Route' : 'Add New Route' ?></h2>
        <a href="<?= BASE_URL ?>admin/routes.php" class="btn-admin-ghost">Cancel</a>
    </div>
    <form method="POST" action="<?= BASE_URL ?>admin/routes.php" class="admin-form">
        <input type="hidden" name="action" value="<?= $isEdit ? 'edit' : 'add' ?>">
        <?php if ($isEdit): ?><input type="hidden" name="route_id" value="<?= (int)$formRoute['route_id'] ?>"><?php endif; ?>

        <div class="admin-form-grid">
            <div class="form-group">
                <label for="origin">Origin <span class="req">*</span></label>
                <input type="text" id="origin" name="origin"
                       value="<?= e($formRoute['origin'] ?? '') ?>"
                       placeholder="e.g. Melbourne CBD" maxlength="100" required>
            </div>
            <div class="form-group">
                <label for="destination">Destination <span class="req">*</span></label>
                <input type="text" id="destination" name="destination"
                       value="<?= e($formRoute['destination'] ?? '') ?>"
                       placeholder="e.g. Geelong" maxlength="100" required>
            </div>
            <div class="form-group">
                <label for="distance_km">Distance (km) <span class="req">*</span></label>
                <input type="number" id="distance_km" name="distance_km"
                       value="<?= (int)($formRoute['distance_km'] ?? 0) ?>"
                       min="1" max="5000" required>
            </div>
            <div class="form-group">
                <label for="base_price">Base Price (AUD) <span class="req">*</span></label>
                <input type="number" id="base_price" name="base_price"
                       value="<?= number_format((float)($formRoute['base_price'] ?? 0), 2, '.', '') ?>"
                       step="0.50" min="1" max="9999" required>
            </div>
            <div class="form-group">
                <label for="status">Status <span class="req">*</span></label>
                <select id="status" name="status">
                    <?php foreach ($routeStatus as $st): ?>
                        <option value="<?= $st ?>" <?= ($formRoute['status'] ?? 'active') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group admin-form-full">
                <label for="route_tags">Route Tags <span class="form-hint">(comma-separated — e.g. Express,Wi-Fi,Luggage)</span></label>
                <input type="text" id="route_tags" name="route_tags"
                       value="<?= e($formRoute['route_tags'] ?? '') ?>"
                       placeholder="Express,Wi-Fi,Luggage,Wheelchair Accessible" maxlength="255">
                <div class="tags-preview" id="tags-preview"></div>
            </div>
        </div>

        <!-- Fare preview -->
        <div class="fare-preview-box" id="fare-preview">
            <span class="fare-preview-label">Fare preview</span>
            <div class="fare-preview-items" id="fare-items">
                <span>Enter a price above to see fare breakdown</span>
            </div>
        </div>

        <div class="admin-form-actions">
            <button type="submit" class="btn-admin-primary"><?= $isEdit ? 'Save Changes' : 'Add Route' ?></button>
            <a href="<?= BASE_URL ?>admin/routes.php" class="btn-admin-ghost">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ROUTES TABLE -->
<div class="admin-card" style="padding:0; overflow:hidden;">
    <div class="admin-card-header" style="padding:16px 20px;">
        <h2 class="admin-card-title">All Routes <span class="admin-count-badge"><?= count($routes) ?></span></h2>
    </div>
    <?php if (empty($routes)): ?>
        <div class="admin-empty">No routes yet. Add one above.</div>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Route</th>
                    <th>Distance</th>
                    <th>Base Price</th>
                    <th>Tags</th>
                    <th>Schedules</th>
                    <th>Status</th>
                    <th style="width:140px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($routes as $rt):
                $hasSchedules = (int)$rt['upcoming_schedules'] > 0;
                $tags = parseRouteTags($rt['route_tags']);
            ?>
            <tr>
                <td>
                    <span class="td-name"><?= e($rt['origin']) ?> → <?= e($rt['destination']) ?></span>
                </td>
                <td><?= (int)$rt['distance_km'] ?> km</td>
                <td class="td-amount"><?= formatCurrency($rt['base_price']) ?></td>
                <td>
                    <div class="admin-tags-wrap">
                        <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                            <span class="route-tag" style="font-size:0.68rem;"><?= e($tag) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($tags) > 3): ?>
                            <span class="td-muted" style="font-size:0.75rem;">+<?= count($tags) - 3 ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="td-center">
                    <span title="<?= (int)$rt['upcoming_schedules'] ?> upcoming / <?= (int)$rt['total_schedules'] ?> total">
                        <?= (int)$rt['upcoming_schedules'] ?> upcoming
                    </span>
                </td>
                <td>
                    <span class="status-pill <?= $rt['status'] === 'active' ? 'status-pill--active' : 'status-pill--inactive' ?>">
                        <?= ucfirst(e($rt['status'])) ?>
                    </span>
                </td>
                <td>
                    <div class="admin-row-actions">
                        <a href="<?= BASE_URL ?>admin/routes.php?action=edit&route_id=<?= (int)$rt['route_id'] ?>" class="btn-row-edit">Edit</a>
                        <?php if ($hasSchedules): ?>
                            <span class="btn-row-delete btn-row-delete--disabled" title="Has active schedules — cannot delete">Delete</span>
                        <?php else: ?>
                            <form method="POST" action="<?= BASE_URL ?>admin/routes.php"
                                  onsubmit="return confirm('Delete route \'<?= e(addslashes($rt['origin'])) ?> to <?= e(addslashes($rt['destination'])) ?>\'?');">
                                <input type="hidden" name="action"   value="delete">
                                <input type="hidden" name="route_id" value="<?= (int)$rt['route_id'] ?>">
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
// Live tags preview
const tagsInput   = document.getElementById('route_tags');
const tagsPreview = document.getElementById('tags-preview');
if (tagsInput && tagsPreview) {
    function renderTags() {
        const raw  = tagsInput.value.split(',').map(t => t.trim()).filter(Boolean);
        tagsPreview.innerHTML = raw.length
            ? raw.map(t => `<span class="route-tag" style="font-size:0.75rem;">${t}</span>`).join('')
            : '';
    }
    tagsInput.addEventListener('input', renderTags);
    renderTags();
}

// Live fare preview
const priceInput  = document.getElementById('base_price');
const fareItems   = document.getElementById('fare-items');
const discounts   = { Adult: 0, Student: 0.15, Senior: 0.20, Child: 0.40 };

function renderFares() {
    if (!priceInput || !fareItems) return;
    const base = parseFloat(priceInput.value) || 0;
    if (base <= 0) {
        fareItems.innerHTML = '<span style="color:var(--text-muted)">Enter a price above to see fare breakdown</span>';
        return;
    }
    fareItems.innerHTML = Object.entries(discounts).map(([type, disc]) => {
        const fare = Math.round(base * (1 - disc) * 100) / 100;
        const pct  = disc > 0 ? ` <span style="font-size:0.7rem;color:var(--green)">(${disc*100}% off)</span>` : '';
        return `<div class="fare-preview-item"><span>${type}${pct}</span><strong>$${fare.toFixed(2)}</strong></div>`;
    }).join('');
}

if (priceInput) {
    priceInput.addEventListener('input', renderFares);
    renderFares();
}
</script>

<?php require_once 'admin_footer.php'; ?>

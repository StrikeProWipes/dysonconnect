<?php
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

requireLogin();

$userId = currentUserId();

// Mark a single notification as read (via GET ?mark_read=id)
if (isset($_GET['mark_read'])) {
    $nid = (int)$_GET['mark_read'];
    if ($nid > 0) {
        $stmt = $conn->prepare("
            UPDATE notifications SET read_status='read'
            WHERE notification_id = ? AND user_id = ?
        ");
        $stmt->bind_param('ii', $nid, $userId);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: ' . BASE_URL . 'notifications.php');
    exit;
}

// Mark all as read
if (isset($_POST['mark_all_read'])) {
    $stmt = $conn->prepare("
        UPDATE notifications SET read_status='read'
        WHERE user_id = ? AND read_status='unread'
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    setFlash('success', 'All notifications marked as read.');
    header('Location: ' . BASE_URL . 'notifications.php');
    exit;
}

// Fetch all notifications for this user, newest first
$stmt = $conn->prepare("
    SELECT notification_id, booking_id, type, title, message, read_status, created_at
    FROM   notifications
    WHERE  user_id = ?
    ORDER  BY created_at DESC
    LIMIT  50
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$unreadCount = 0;
foreach ($notifications as $n) {
    if ($n['read_status'] === 'unread') $unreadCount++;
}

$pageTitle = 'Notifications';
require_once 'includes/header.php';

// Icon map per type
function notifIcon($type) {
    return match($type) {
        'booking_confirmed'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>',
        'booking_cancelled'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        'payment_confirmed'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
        'schedule_changed'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        default              => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    };
}
function notifColorClass($type) {
    return match($type) {
        'booking_confirmed' => 'notif-icon--green',
        'booking_cancelled' => 'notif-icon--red',
        'payment_confirmed' => 'notif-icon--blue',
        'schedule_changed'  => 'notif-icon--orange',
        default             => 'notif-icon--grey',
    };
}
?>

<div class="page-hero page-hero--blue">
    <div class="container">
        <h1 class="page-hero-title">Notifications</h1>
        <p class="page-hero-sub">Booking confirmations, cancellations, and payment updates.</p>
    </div>
</div>

<section class="bookings-section">
    <div class="container notif-container">

        <!-- Header row -->
        <div class="notif-header-row">
            <div>
                <strong><?= count($notifications) ?></strong> notification<?= count($notifications) !== 1 ? 's' : '' ?>
                <?php if ($unreadCount > 0): ?>
                    &nbsp;<span class="booking-status-badge bsb--pending"><?= $unreadCount ?> unread</span>
                <?php endif; ?>
            </div>
            <?php if ($unreadCount > 0): ?>
            <form method="POST" action="<?= BASE_URL ?>notifications.php">
                <button type="submit" name="mark_all_read" value="1" class="btn-mark-all-read">
                    Mark all as read
                </button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="empty-icon">🔔</div>
                <h2>No notifications yet</h2>
                <p>You'll see booking confirmations, cancellations, and updates here.</p>
                <a href="<?= BASE_URL ?>search.php" class="btn-primary-lg notif-cta-btn">Book a Trip</a>
            </div>
        <?php else: ?>
            <div class="notif-list">
                <?php foreach ($notifications as $n): ?>
                <div class="notif-item <?= $n['read_status'] === 'unread' ? 'notif-item--unread' : '' ?>">
                    <div class="notif-icon-wrap <?= notifColorClass($n['type']) ?>">
                        <?= notifIcon($n['type']) ?>
                    </div>
                    <div class="notif-body">
                        <div class="notif-title"><?= e($n['title']) ?></div>
                        <div class="notif-message"><?= e($n['message']) ?></div>
                        <div class="notif-meta">
                            <span class="notif-date"><?= date('d M Y, g:i A', strtotime($n['created_at'])) ?></span>
                            <?php if (!empty($n['booking_id'])): ?>
                                <a href="<?= BASE_URL ?>ticket.php?booking_id=<?= (int)$n['booking_id'] ?>" class="notif-ticket-link">View ticket →</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($n['read_status'] === 'unread'): ?>
                    <div class="notif-actions">
                        <a href="<?= BASE_URL ?>notifications.php?mark_read=<?= (int)$n['notification_id'] ?>"
                           class="notif-mark-read" title="Mark as read">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </a>
                        <span class="notif-unread-dot"></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</section>

<?php require_once 'includes/footer.php'; ?>

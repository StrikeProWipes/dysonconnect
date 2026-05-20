<?php
// functions.php
// Shared helpers used across the project.
// Include this after db_connect.php.

// Generates a unique booking reference like DC-2026-000042.
// Loops until it finds one that isn't already in the database.
function generateBookingReference($conn) {
    $year = date('Y');

    do {
        $number    = random_int(1, 999999);
        $reference = 'DC-' . $year . '-' . str_pad($number, 6, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare("SELECT booking_id FROM bookings WHERE booking_reference = ?");
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

    } while ($exists);

    return $reference;
}


// Fare discounts by passenger type (applied against route base_price):
//   Adult 0% | Student 15% | Senior 20% | Child 40%
function calculateFare($basePrice, $passengerType) {
    $discounts = [
        'Adult'   => 0.00,
        'Student' => 0.15,
        'Senior'  => 0.20,
        'Child'   => 0.40,
    ];

    $discount = $discounts[$passengerType] ?? 0.00;
    $fare     = $basePrice * (1 - $discount);

    // Round to 2 decimal places so we don't get floating point weirdness
    return round($fare, 2);
}


// Returns all seats for a schedule with their current status.
// Queries v_seat_availability so availability is per-schedule only.
function getSeatsForSchedule($conn, $scheduleId) {
    $stmt = $conn->prepare("
        SELECT seat_id, seat_number, seat_type, seat_status
        FROM   v_seat_availability
        WHERE  schedule_id = ?
        ORDER BY seat_number
    ");
    $stmt->bind_param('i', $scheduleId);
    $stmt->execute();
    $result = $stmt->get_result();

    $seats = [];
    while ($row = $result->fetch_assoc()) {
        $seats[] = $row;
    }

    $stmt->close();
    return $seats;
}


// Checks seat availability before booking confirmation.
function isSeatAvailable($conn, $seatId, $scheduleId) {
    $stmt = $conn->prepare("
        SELECT seat_status
        FROM   v_seat_availability
        WHERE  seat_id     = ?
          AND  schedule_id = ?
    ");
    $stmt->bind_param('ii', $seatId, $scheduleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return false; // seat doesn't exist on this bus
    }

    return $row['seat_status'] === 'Available';
}


// Pulls full schedule info (route + bus + driver) via v_schedule_summary.
function getScheduleById($conn, $scheduleId) {
    $stmt = $conn->prepare("
        SELECT *
        FROM   v_schedule_summary
        WHERE  schedule_id = ?
    ");
    $stmt->bind_param('i', $scheduleId);
    $stmt->execute();
    $result   = $stmt->get_result();
    $schedule = $result->fetch_assoc();
    $stmt->close();

    return $schedule; // null if not found
}


// All bookings for a given user, newest first. Used on my_bookings.php.
function getUserBookings($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT
            b.booking_id,
            b.booking_reference,
            b.booking_date,
            b.total_amount,
            b.booking_status,
            b.refund_status,
            s.departure_date,
            s.departure_time,
            s.arrival_time,
            r.origin,
            r.destination,
            bus.bus_name,
            bus.bus_type
        FROM   bookings b
        JOIN   schedules s  ON s.schedule_id = b.schedule_id
        JOIN   routes    r  ON r.route_id    = s.route_id
        JOIN   buses     bus ON bus.bus_id   = s.bus_id
        WHERE  b.user_id = ?
        ORDER BY b.booking_date DESC
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result   = $stmt->get_result();
    $bookings = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $bookings;
}


// Full booking detail including all passengers. Used on ticket.php.
function getBookingDetails($conn, $bookingId) {
    // Main booking info
    $stmt = $conn->prepare("
        SELECT
            b.*,
            s.departure_date,
            s.departure_time,
            s.arrival_time,
            r.origin,
            r.destination,
            r.distance_km,
            r.route_tags,
            bus.bus_name,
            bus.bus_number,
            bus.bus_type,
            d.full_name AS driver_name,
            p.payment_method,
            p.payment_status
        FROM   bookings  b
        JOIN   schedules s   ON s.schedule_id = b.schedule_id
        JOIN   routes    r   ON r.route_id    = s.route_id
        JOIN   buses     bus ON bus.bus_id    = s.bus_id
        LEFT JOIN drivers d  ON d.driver_id   = bus.driver_id
        LEFT JOIN payments p ON p.booking_id  = b.booking_id
        WHERE  b.booking_id = ?
    ");
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $result  = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        return null;
    }

    // Passenger rows
    $stmt = $conn->prepare("
        SELECT
            bp.passenger_name,
            bp.passenger_type,
            bp.fare_amount,
            seats.seat_number,
            seats.seat_type
        FROM   booking_passengers bp
        JOIN   seats ON seats.seat_id = bp.seat_id
        WHERE  bp.booking_id = ?
        ORDER BY bp.passenger_id
    ");
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $result               = $stmt->get_result();
    $booking['passengers'] = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $booking;
}


// Cancels a booking, marks payment refunded, and adds seats back.
// Wrapped in a transaction so all three updates succeed or none do.
function cancelBooking($conn, $bookingId, $userId) {
    // First confirm this booking actually belongs to this user
    $stmt = $conn->prepare("
        SELECT booking_id, schedule_id, booking_status
        FROM   bookings
        WHERE  booking_id = ? AND user_id = ?
    ");
    $stmt->bind_param('ii', $bookingId, $userId);
    $stmt->execute();
    $result  = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found.'];
    }

    if ($booking['booking_status'] === 'Cancelled') {
        return ['success' => false, 'message' => 'This booking is already cancelled.'];
    }

    // Count how many passengers so we can add their seats back
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS passenger_count
        FROM   booking_passengers
        WHERE  booking_id = ?
    ");
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $result           = $stmt->get_result();
    $row              = $result->fetch_assoc();
    $passengerCount   = (int) $row['passenger_count'];
    $stmt->close();

    $conn->begin_transaction();

    try {
        // Mark the booking cancelled and flag refund as pending
        $stmt = $conn->prepare("
            UPDATE bookings
            SET booking_status = 'Cancelled', refund_status = 'Pending'
            WHERE booking_id = ?
        ");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $stmt->close();

        // Give the seats back
        $stmt = $conn->prepare("
            UPDATE schedules
            SET available_seats = available_seats + ?
            WHERE schedule_id = ?
        ");
        $stmt->bind_param('ii', $passengerCount, $booking['schedule_id']);
        $stmt->execute();
        $stmt->close();

        // Mark payment as refunded
        $stmt = $conn->prepare("
            UPDATE payments
            SET payment_status = 'Refunded'
            WHERE booking_id = ?
        ");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        // Notify the user that their booking has been cancelled
        // We fetch the booking reference separately as it's not passed into this function
        $ref = '';
        $refStmt = $conn->prepare("SELECT booking_reference FROM bookings WHERE booking_id = ?");
        $refStmt->bind_param('i', $bookingId);
        $refStmt->execute();
        $refRow = $refStmt->get_result()->fetch_assoc();
        $refStmt->close();
        $ref = $refRow['booking_reference'] ?? 'your booking';

        createNotification(
            $conn, $userId, $bookingId,
            'booking_cancelled',
            'Booking Cancelled – ' . $ref,
            'Your booking ' . $ref . ' has been cancelled. A refund is being processed where applicable.'
        );

        return ['success' => true, 'message' => 'Booking cancelled. Refund is being processed.'];

    } catch (Exception $e) {
        $conn->rollback();
        error_log('cancelBooking failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Something went wrong. Please try again.'];
    }
}


// Splits the route_tags VARCHAR into a clean array.
// e.g. 'Express,Wi-Fi,Luggage' -> ['Express', 'Wi-Fi', 'Luggage']
function parseRouteTags($tagsString) {
    if (empty($tagsString)) {
        return [];
    }
    return array_map('trim', explode(',', $tagsString));
}


// Shorthand for htmlspecialchars. Use this whenever printing anything
// from the database or user input to prevent XSS.
// e.g.  echo e($row['full_name']);
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}


// Date and currency formatters used across page templates.
function formatDate($dateString) {
    // e.g. 2026-06-01 -> Monday, 1 June 2026
    $ts = strtotime($dateString);
    return date('l, j F Y', $ts);
}

function formatTime($timeString) {
    // e.g. 07:00:00 -> 7:00 AM
    $ts = strtotime($timeString);
    return date('g:i A', $ts);
}

function formatCurrency($amount) {
    return '$' . number_format((float) $amount, 2);
}


// One-time messages stored in session, shown after a redirect.
// setFlash('success', 'Booking confirmed!') then redirect.
// Call getFlash() on the next page to retrieve and clear it.
function setFlash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}


// ── NOTIFICATION HELPERS ──────────────────────────────────────

// Creates a notification record for a user.
// Type: 'booking_confirmed', 'booking_cancelled', 'payment_confirmed', 'schedule_changed'
function createNotification($conn, $userId, $bookingId, $type, $title, $message) {
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, booking_id, type, title, message)
        VALUES (?, ?, ?, ?, ?)
    ");
    $nullBookingId = $bookingId ?: null;
    $stmt->bind_param('iisss', $userId, $nullBookingId, $type, $title, $message);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// Count unread notifications for a user (used in nav badge).
function countUnreadNotifications($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS n FROM notifications
        WHERE user_id = ? AND read_status = 'unread'
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n;
}

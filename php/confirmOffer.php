<?php
header('Content-Type: text/html; charset=utf-8');

$token = $_GET['token'] ?? '';
$message = '';
$isSuccess = false;
$sessionInfo = '';

if (!$token) {
    $message = 'Invalid token';
    goto render;
}

// Load offers
$offersFile = __DIR__ . '/../data/offers.json';
if (!file_exists($offersFile)) {
    $message = 'Offer not found';
    goto render;
}

$offers = json_decode(file_get_contents($offersFile), true);
if (!isset($offers[$token])) {
    $message = 'Offer not found';
    goto render;
}

$offer = $offers[$token];

// Check if offer is expired or already used
if ($offer['status'] !== 'pending') {
    $message = 'This offer has already been ' . $offer['status'];
    goto render;
}

if (strtotime($offer['expiresAt']) < time()) {
    $offer['status'] = 'expired';
    file_put_contents($offersFile, json_encode($offers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $message = 'Offer has expired';
    goto render;
}

// Load current bookings and slots
$bookingsFile = __DIR__ . '/../data/bookings.json';
$slotsFile = __DIR__ . '/../data/availableSlots.json';
$waitlistFile = __DIR__ . '/../data/waitlist.json';

$bookings = file_exists($bookingsFile) ? json_decode(file_get_contents($bookingsFile), true) : [];
$slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
$waitlist = file_exists($waitlistFile) ? json_decode(file_get_contents($waitlistFile), true) : [];

// Verify a spot is still available
$date = $offer['date'];
$time = $offer['time'];
$title = $offer['title'];
$blockId = $offer['blockId'] ?? null;
$blockDates = $offer['blockDates'] ?? [];

if (!isset($slots[$date])) {
    $message = 'Session not found';
    goto render;
}

// Find matching slot
$slotFound = false;
$slotKey = null;
foreach ($slots[$date] as $k => $slot) {
    if ($slot['time'] === $time && $slot['title'] === $title) {
        $slotFound = true;
        $slotKey = $k;

        // Infer block metadata for legacy offers
        if (empty($blockId) && !empty($slot['blockId'])) {
            $blockId = $slot['blockId'];
        }
        if (empty($blockDates) && !empty($slot['blockDates'])) {
            $blockDates = $slot['blockDates'];
        }
        
        // Check if spot is available
        $bookedCount = count($slot['bookedUsers'] ?? []);
        $capacity = $slot['numberOfSpots'] ?? 0;
        
        if ($bookedCount >= $capacity) {
            $message = 'No spots available';
            goto render;
        }
        
        // Add user to bookings
        if (!in_array($offer['email'], $slot['bookedUsers'])) {
            $slots[$date][$slotKey]['bookedUsers'][] = ['name' => $offer['name'], 'email' => $offer['email']];
        }
        break;
    }
}

if (!$slotFound) {
    $message = 'Session not found';
    goto render;
}

// Add booking entry with proper format
if (!isset($bookings[$date])) {
    $bookings[$date] = [];
}
$bookingString = $time . ' - ' . $title . ' (' . $offer['name'] . ') (' . $offer['email'] . ')';
$bookings[$date][] = $bookingString;

// Remove from waitlist
if (isset($waitlist[$date])) {
    $waitlist[$date] = array_filter($waitlist[$date], function($entry) use ($offer) {
        return $entry['email'] !== $offer['email'] || 
               $entry['time'] !== $offer['time'] || 
               $entry['title'] !== $offer['title'];
    });
    $waitlist[$date] = array_values($waitlist[$date]);
    if (empty($waitlist[$date])) {
        unset($waitlist[$date]);
    }
}

// Mark offer as confirmed
$offer['status'] = 'confirmed';
$offer['confirmedAt'] = date('Y-m-d H:i:s');
$offers[$token] = $offer;

// Save all changes
file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
file_put_contents($bookingsFile, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
file_put_contents($waitlistFile, json_encode($waitlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
file_put_contents($offersFile, json_encode($offers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

// Canonicalise slots from bookings.json to avoid any orphaned entries
require_once __DIR__ . '/lib_slot_sync.php';
$syncRes = sync_slots_from_bookings(__DIR__ . '/../data/bookings.json', __DIR__ . '/../data/availableSlots.json', true, true);
error_log('sync_slots_from_bookings (confirmOffer) result: ' . json_encode($syncRes));

// Update Bridge State for this slot
require_once __DIR__ . '/lib_bridge_state.php';
$slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
$primaryDate = (!empty($blockDates) ? $blockDates[0] : $date);
if ($primaryDate) {
    updateBridgeState($slots, $primaryDate, $time, $title, $bookings, $waitlist, $blockId);
    file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

$isSuccess = true;
$message = 'Spot confirmed!';
// Format date as "23rd March 2026"
[$year, $month, $day] = explode('-', $date);
$monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$dayNum = (int)$day;
$monthName = $monthNames[(int)$month - 1];
$suffix = 'th';
if ($dayNum % 10 === 1 && $dayNum !== 11) $suffix = 'st';
elseif ($dayNum % 10 === 2 && $dayNum !== 12) $suffix = 'nd';
elseif ($dayNum % 10 === 3 && $dayNum !== 13) $suffix = 'rd';
$formattedDate = "{$dayNum}{$suffix} {$monthName} {$year}";
$sessionInfo = htmlspecialchars($title) . ' on ' . $formattedDate . ' at ' . htmlspecialchars($time);

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isSuccess ? 'Spot Confirmed' : 'Offer Status'; ?> - Hoop Theory</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            max-width: 500px;
            width: 100%;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .header {
            padding: 40px 30px;
            text-align: center;
        }
        
        .header.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .header.error {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
            display: block;
        }
        
        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .header p {
            font-size: 14px;
            opacity: 0.95;
            line-height: 1.5;
        }
        
        .content {
            padding: 40px 30px;
        }
        
        .session-details {
            background: #f9fafb;
            border-left: 4px solid #10b981;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: <?php echo $isSuccess ? 'block' : 'none'; ?>;
        }
        
        .session-details strong {
            display: block;
            color: #065f46;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .session-details p {
            font-size: 16px;
            color: #1f2937;
            font-weight: 600;
        }
        
        .message {
            font-size: 15px;
            line-height: 1.6;
            color: #4b5563;
            margin-bottom: 30px;
        }
        
        .message.error {
            color: #991b1b;
            background: #fee2e2;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #dc2626;
        }
        
        .next-steps {
            background: #f0fdf4;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            display: <?php echo $isSuccess ? 'block' : 'none'; ?>;
        }
        
        .next-steps h3 {
            color: #065f46;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        
        .next-steps ul {
            list-style: none;
            padding: 0;
        }
        
        .next-steps li {
            color: #1f2937;
            font-size: 14px;
            margin-bottom: 8px;
            padding-left: 24px;
            position: relative;
        }
        
        .next-steps li:before {
            content: '✓';
            position: absolute;
            left: 0;
            color: #10b981;
            font-weight: 700;
        }
        
        .buttons {
            display: flex;
            gap: 12px;
        }
        
        .btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
        }
        
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            background: #f9fafb;
            font-size: 12px;
            color: #6b7280;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 700;
            color: #000;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header <?php echo $isSuccess ? 'success' : 'error'; ?>">
                <span class="icon"><?php echo $isSuccess ? '✓' : '✕'; ?></span>
                <h1><?php echo $isSuccess ? 'Spot Confirmed!' : 'Oops'; ?></h1>
                <p><?php echo $isSuccess ? 'You\'re all set for your session' : 'Unable to confirm your spot'; ?></p>
            </div>
            
            <div class="content">
                <?php if ($isSuccess): ?>
                    <div class="session-details">
                        <strong>Session Details</strong>
                        <p><?php echo $sessionInfo; ?></p>
                    </div>
                    
                    <div class="message">
                        Great news! Your spot has been confirmed. You're now registered for the session. A confirmation email has been sent to your inbox.
                    </div>
                    
                    <div class="next-steps">
                        <h3>What's Next</h3>
                        <ul>
                            <li>Check your email for session details</li>
                            <li>Add it to your calendar</li>
                            <li>Arrive 10 minutes early</li>
                            <li>Have fun!</li>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="message error">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                    <p style="font-size: 14px; color: #6b7280; line-height: 1.6;">
                        If you believe this is an error or have questions about your booking, please contact us at <strong>bao@hooptheory.co.uk</strong>.
                    </p>
                <?php endif; ?>
                
                <div class="buttons">
                    <a href="https://hooptheory.co.uk" class="btn btn-primary">Back to Hoop Theory</a>
                    <a href="mailto:bao@hooptheory.co.uk" class="btn btn-secondary">Contact Support</a>
                </div>
            </div>
            
            <div class="footer">
                <div class="logo">Hoop Theory</div>
                <p>Basketball Court Booking System</p>
            </div>
        </div>
    </div>
</body>
</html>


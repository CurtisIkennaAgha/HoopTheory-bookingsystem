<?php
// removeExpiredPendingBookings.php
// Scans bookings.json for pending bookings past expiry and cancels them

 $bookingsFile = __DIR__ . '/../data/bookings.json';
$bookingMappingsFile = __DIR__ . '/../data/bookingMappings.json';
$cancelBookingFile = __DIR__ . '/cancelBooking.php';

// Load bookingMappings
if (!file_exists($bookingMappingsFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'bookingMappings.json not found']);
    exit;
}
$bookingMappings = json_decode(file_get_contents($bookingMappingsFile), true);
if ($bookingMappings === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid bookingMappings.json']);
    exit;
}

// Load expiry config


$oldTz = date_default_timezone_get();
date_default_timezone_set('UTC');
$now = time();
$restoreTz = function() use ($oldTz) { date_default_timezone_set($oldTz); };

$expiredCount = 0;



// ANSI color codes for terminal/console
$CLR_HEADER = "\033[1;37;41m"; // white on red
$CLR_YELLOW = "\033[1;33m";
$CLR_GREEN = "\033[1;32m";
$CLR_RESET = "\033[0m";
$CLR_BOLD = "\033[1m";

$debugText = "[RemoveExpiredPendingBookings] " . date('Y-m-d H:i:s') . "\n";
$debugText .= $CLR_HEADER . "STEP 1: All pending bookings in bookingMappings.json:" . $CLR_RESET . "\n";
$pendingList = [];
$expiredList = [];
$cancelResponses = [];

// STEP 1: Print all pending bookings
foreach ($bookingMappings as $bookingId => $mapping) {
    if (isset($mapping['status']) && strtolower($mapping['status']) === 'pending') {
        $pendingList[] = $bookingId . ' | ' . ($mapping['name'] ?? '') . ' | ' . ($mapping['email'] ?? '') . ' | ' . ($mapping['createdAt'] ?? '');
    }
}
if (count($pendingList) > 0) {
    foreach ($pendingList as $item) {
        $debugText .= $CLR_YELLOW . '  - ' . $item . $CLR_RESET . "\n";
    }
} else {
    $debugText .= $CLR_YELLOW . '  No pending bookings found.' . $CLR_RESET . "\n";
}

$debugText .= $CLR_HEADER . "STEP 2: Pending bookings with expired date:" . $CLR_RESET . "\n";
// STEP 2: Print all pending bookings that are expired
foreach ($bookingMappings as $bookingId => $mapping) {
    if (isset($mapping['status']) && strtolower($mapping['status']) === 'pending') {
        // Use expiryTimestamp if present
        if (isset($mapping['expiryTimestamp'])) {
            $expiryTime = intval($mapping['expiryTimestamp']);
            $debugText .= $CLR_BOLD . "    [DEBUG] now: $now, expiryTimestamp: $expiryTime, now_readable: " . date('Y-m-d H:i:s', $now) . ", expiry_readable: " . date('Y-m-d H:i:s', $expiryTime) . $CLR_RESET . "\n";
            if ($now > $expiryTime) {
                $expiredList[] = [
                    'bookingId' => $bookingId,
                    'info' => $bookingId . ' | ' . ($mapping['name'] ?? '') . ' | ' . ($mapping['email'] ?? '') . ' | expiryTimestamp: ' . $expiryTime,
                    'mapping' => $mapping
                ];
            }
        }
    }
}
if (count($expiredList) > 0) {
    foreach ($expiredList as $item) {
        $debugText .= $CLR_YELLOW . '  - ' . $item['info'] . $CLR_RESET . "\n";
    }
} else {
    $debugText .= $CLR_YELLOW . '  No expired pending bookings found.' . $CLR_RESET . "\n";
}

$debugText .= $CLR_HEADER . "STEP 3: Removing expired pending bookings and showing cancelBooking.php response:" . $CLR_RESET . "\n";
$expiredCount = 0;
foreach ($expiredList as $item) {
    $bookingId = $item['bookingId'];
    $mapping = $item['mapping'];
    $date = $mapping['date'] ?? '';
    if ((empty($date) || $date === null) && !empty($mapping['blockDates']) && is_array($mapping['blockDates'])) {
        $date = $mapping['blockDates'][0] ?? '';
    }
    $payload = [
        'bookingId' => $bookingId,
        'name' => $mapping['name'] ?? '',
        'email' => $mapping['email'] ?? '',
        'date' => $date,
        'time' => $mapping['slot'] ?? '',
        'title' => $mapping['title'] ?? '',
        'isBlock' => $mapping['isBlock'] ?? false,
        'blockDates' => $mapping['blockDates'] ?? [],
        'blockId' => $mapping['blockId'] ?? null,
        'reason' => 'Expired pending booking',
        'admin' => true
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://hooptheory.co.uk/php/cancelBooking.php');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    curl_close($ch);
    $cancelResponses[] = [
        'bookingId' => $bookingId,
        'response' => $response
    ];
    $respData = json_decode($response, true);
    if (is_array($respData) && isset($respData['status']) && $respData['status'] === 'ok') {
        $expiredCount++;
    } else {
        $debugText .= $CLR_HEADER . '  ERROR: ' . ($respData['message'] ?? 'Unknown error') . $CLR_RESET . "\n";
    }
}
if (count($cancelResponses) > 0) {
    foreach ($cancelResponses as $resp) {
        $debugText .= $CLR_GREEN . '  - ' . $resp['bookingId'] . ': ' . $CLR_RESET . $resp['response'] . "\n";
    }
} else {
    $debugText .= $CLR_YELLOW . '  No expired pending bookings to remove.' . $CLR_RESET . "\n";
}

$debugText .= $CLR_HEADER . "STEP 4: Summary" . $CLR_RESET . "\n";
$debugText .= $expiredCount . " expired pending bookings cancelled.\n";

// Output only plain text with color codes, no HTML
header('Content-Type: text/plain');
echo $debugText;
if ($expiredCount > 0) {
    $debugHtml .= "<span style='color:#ffd600;'>" . $expiredCount . " expired pending bookings cancelled:</span><br>";
    $debugHtml .= "<ul style='margin:8px 0 0 16px;'>";
    foreach ($debugList as $item) {
        $debugHtml .= "<li style='color:#ffd600;font-size:14px;'>" . $item . "</li>";
    }
    $debugHtml .= "</ul>";
} else {
    $debugHtml .= "<span style='color:#ffd600;'>No expired pending bookings found.</span><br>";
}
$debugHtml .= "</div>";

header('Content-Type: text/html');
echo $debugHtml;

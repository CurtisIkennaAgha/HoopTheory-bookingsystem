<?php
header('Content-Type: application/json; charset=utf-8');

// Debug endpoint (non-destructive) to inspect why reserveOffer.php may reject an offer.
// Usage (local/dev only): php/debugReserveOffer.php?token=<token>
// IMPORTANT: do NOT enable this on a public production host without access controls.

$token = $_GET['token'] ?? '';
if (!$token) {
    http_response_code(400);
    echo json_encode(['error' => 'missing token']);
    exit;
}

$offersFile = __DIR__ . '/../data/offers.json';
$slotsFile = __DIR__ . '/../data/availableSlots.json';
$bookingsFile = __DIR__ . '/../data/bookings.json';
$waitlistFile = __DIR__ . '/../data/waitlist.json';

$offers = file_exists($offersFile) ? (json_decode(file_get_contents($offersFile), true) ?? []) : [];
$slots = file_exists($slotsFile) ? (json_decode(file_get_contents($slotsFile), true) ?? []) : [];
$bookings = file_exists($bookingsFile) ? (json_decode(file_get_contents($bookingsFile), true) ?? []) : [];
$waitlist = file_exists($waitlistFile) ? (json_decode(file_get_contents($waitlistFile), true) ?? []) : [];

if (!isset($offers[$token])) {
    http_response_code(404);
    echo json_encode(['error' => 'offer not found', 'token' => $token]);
    exit;
}
$offer = $offers[$token];

$date = $offer['date'] ?? null;
$time = $offer['time'] ?? null;
$title = $offer['title'] ?? null;
$sessionKey = $offer['sessionKey'] ?? null;
$email = $offer['email'] ?? null;

$result = [
    'token' => $token,
    'offer' => $offer,
    'date_exists_in_slots' => isset($slots[$date]),
    'matching_slots' => [],
    'bookings_for_date' => $bookings[$date] ?? [],
    'waitlist_for_date' => $waitlist[$date] ?? [],
];

if (isset($slots[$date]) && is_array($slots[$date])) {
    foreach ($slots[$date] as $k => $slot) {
        $matchByKey = (!empty($sessionKey) && !empty($slot['sessionKey']) && $slot['sessionKey'] === $sessionKey);
        $matchByLegacy = ($slot['time'] === $time && $slot['title'] === $title);
        if ($matchByKey || $matchByLegacy) {
            // collect booked emails (slot + bookings)
            $bookedEmails = [];
            if (!empty($slot['bookedUsers']) && is_array($slot['bookedUsers'])) {
                foreach ($slot['bookedUsers'] as $bu) {
                    if (is_array($bu) && !empty($bu['email'])) $bookedEmails[] = strtolower(trim($bu['email']));
                    elseif (is_string($bu) && preg_match('/\(([^\)]+@[^\)]+)\)$/', $bu, $m)) $bookedEmails[] = strtolower(trim($m[1]));
                }
            }
            if (!empty($bookings[$date]) && is_array($bookings[$date])) {
                foreach ($bookings[$date] as $bstr) {
                    if (!is_string($bstr)) continue;
                    if (strpos($bstr, $slot['time']) !== false && strpos($bstr, $slot['title']) !== false) {
                        if (preg_match('/\(([^\)]+@[^\)]+)\)$/', $bstr, $m)) $bookedEmails[] = strtolower(trim($m[1]));
                    }
                }
            }
            $bookedEmails = array_values(array_filter(array_unique($bookedEmails)));

            // Also compute canonical booked emails (from bookings.json) and any orphaned slot entries
            $canonicalBooked = [];
            if (!empty($bookings[$date]) && is_array($bookings[$date])) {
                foreach ($bookings[$date] as $bstr) {
                    if (!is_string($bstr)) continue;
                    if (strpos($bstr, $slot['time']) !== false && strpos($bstr, $slot['title']) !== false) {
                        if (preg_match('/\(([^\)]+@[^\)]+)\)$/', $bstr, $m)) $canonicalBooked[] = strtolower(trim($m[1]));
                    }
                }
            }
            $canonicalBooked = array_values(array_filter(array_unique($canonicalBooked)));
            $orphaned = array_values(array_diff($bookedEmails, $canonicalBooked));

            $result['matching_slots'][] = [
                'slotIndex' => $k,
                'slot' => $slot,
                'capacity' => intval($slot['numberOfSpots'] ?? 0),
                'bookedCount' => count($canonicalBooked),
                'bookedEmails' => $canonicalBooked,
                'slotBookedUsers' => $bookedEmails,
                'orphanedSlotBookedUsers' => $orphaned,
                'matches' => [ 'sessionKey' => $matchByKey, 'legacy' => $matchByLegacy ]
            ];
        }
    }
}

// Quick heuristics to explain common failure modes
$explanations = [];
if (!isset($slots[$date])) $explanations[] = 'No slot entry exists for that date in availableSlots.json';
if (empty($result['matching_slots'])) $explanations[] = 'No matching slot was found for the offer (sessionKey or time+title mismatch)';
else {
    foreach ($result['matching_slots'] as $s) {
        if ($s['bookedCount'] >= $s['capacity']) $explanations[] = 'Capacity reached for slot index ' . $s['slotIndex'];
    }
}

$result['diagnostic_explanations'] = $explanations;

// Don't leak secrets — redact any long tokens or env values
if (isset($result['offer']['token'])) unset($result['offer']['token']);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

<?php
header('Content-Type: application/json; charset=utf-8');
// Admin utility: sync slots[].bookedUsers from bookings.json
// Dry-run by default. To apply changes call: ?apply=1&confirm=1

$apply = isset($_GET['apply']) && $_GET['apply'] == '1';
$confirm = isset($_GET['confirm']) && $_GET['confirm'] == '1';
if ($apply && !$confirm) { http_response_code(400); echo json_encode(['error' => 'To apply changes you must pass confirm=1']); exit; }

// Delegate to shared helper (keeps logic DRY and ensures identical behaviour to in-path sync)
require_once __DIR__ . '/lib_slot_sync.php';
$slotsFile = __DIR__ . '/../data/availableSlots.json';
$bookingsFile = __DIR__ . '/../data/bookings.json';
$report = sync_slots_from_bookings($bookingsFile, $slotsFile, $apply, true);
if (!empty($report['error'])) { http_response_code(500); }
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;

$slotsFile = __DIR__ . '/../data/availableSlots.json';
$bookingsFile = __DIR__ . '/../data/bookings.json';
if (!file_exists($slotsFile) || !file_exists($bookingsFile)) { http_response_code(500); echo json_encode(['error' => 'Missing data files']); exit; }

$slots = json_decode(file_get_contents($slotsFile), true) ?? [];
$bookings = json_decode(file_get_contents($bookingsFile), true) ?? [];
$report = ['changes' => [], 'errors' => []];

foreach ($slots as $date => $daySlots) {
    foreach ($daySlots as $i => $slot) {
        $expected = [];
        if (!empty($bookings[$date]) && is_array($bookings[$date])) {
            foreach ($bookings[$date] as $bstr) {
                if (!is_string($bstr)) continue;
                if (strpos($bstr, $slot['time']) !== false && strpos($bstr, $slot['title']) !== false) {
                    if (preg_match('/\(([^\)]+@[^\)]+)\)$/', $bstr, $m)) {
                        $expected[] = ['email' => strtolower(trim($m[1])), 'name' => null];
                    }
                }
            }
        }

        $expectedEmails = array_values(array_unique(array_map(function($e){ return $e['email']; }, $expected)));
        $slotBooked = $slot['bookedUsers'] ?? [];
        $slotEmails = array_map(function($b){ if (is_array($b) && !empty($b['email'])) return strtolower(trim($b['email'])); if (is_string($b) && preg_match('/\(([^\)]+@[^\)]+)\)$/', $b, $m)) return strtolower(trim($m[1])); return null; }, $slotBooked);
        $slotEmails = array_values(array_filter(array_unique($slotEmails)));
        sort($expectedEmails); sort($slotEmails);

        if ($expectedEmails !== $slotEmails) {
            $report['changes'][] = ['date' => $date, 'slotIndex' => $i, 'time' => $slot['time'], 'title' => $slot['title'], 'expected' => $expectedEmails, 'current' => $slotEmails];
            if ($apply) {
                $newBooked = [];
                foreach ($expectedEmails as $em) { $newBooked[] = ['email' => $em, 'name' => null]; }
                $slots[$date][$i]['bookedUsers'] = $newBooked;
            }
        }
    }
}

if ($apply) {
    if (!file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        http_response_code(500);
        $report['errors'][] = 'Failed to write slots file';
    } else {
        $report['applied'] = true;
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

<?php
// --- BridgeState Cleanup Debug ---
$slotsFile = __DIR__ . '/../data/availableSlots.json';
$bookingsFile = __DIR__ . '/../data/bookings.json';
$waitlistFile = __DIR__ . '/../data/waitlist.json';
$bookings = file_exists($bookingsFile) ? json_decode(file_get_contents($bookingsFile), true) : [];
$waitlist = file_exists($waitlistFile) ? json_decode(file_get_contents($waitlistFile), true) : [];
// Per-session bridgeState cleanup
$debug = [];
$debug[] = "[START] Per-session BridgeState cleanup.";
$slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
$changes = [];
foreach ($slots as $date => &$slotArr) {
    $debug[] = "Checking date: $date (" . count($slotArr) . " slots)";
    foreach ($slotArr as &$slot) {
        $desc = sprintf("%s | %s | %s | blockId=%s", $date, $slot['time'], $slot['title'], $slot['blockId'] ?? 'null');
        $hasBookings = !empty($slot['bookedUsers']);
        $debug[] = "  [CHECK] $desc hasBookings: " . ($hasBookings ? 'true' : 'false');
        // Check for waitlist entries for this slot
        $hasWaitlist = false;
        if (is_array($waitlist) && isset($waitlist[$date])) {
            foreach ($waitlist[$date] as $waitlistEntry) {
                if (
                    $waitlistEntry['time'] === $slot['time'] &&
                    $waitlistEntry['title'] === $slot['title'] &&
                    (empty($slot['blockId']) || ($waitlistEntry['blockId'] ?? null) === $slot['blockId'])
                ) {
                    $hasWaitlist = true;
                    break;
                }
            }
        }
        $debug[] = "  [CHECK] $desc hasWaitlist: " . ($hasWaitlist ? 'true' : 'false');
        if (isset($slot['bridgeState'])) {
            $debug[] = "  [CHECK] $desc bridgeState: " . ($slot['bridgeState'] ? 'true' : 'false');
            if ($slot['bridgeState'] && !$hasBookings && !$hasWaitlist) {
                $slot['bridgeState'] = false;
                $changes[] = "REMOVED bridgeState for $desc";
                $debug[] = "    [ACTION] Reset bridgeState for $desc";
            } else {
                $debug[] = "    [SKIP] Did not reset bridgeState for $desc";
            }
        } else {
            $debug[] = "  [INFO] $desc has no bridgeState property.";
        }
    }
}
file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if (count($changes)) {
    foreach ($changes as $msg) $debug[] = $msg;
} else {
    $debug[] = "No bridgeState changes needed.";
}
$debug[] = "[END] Per-session BridgeState cleanup complete.";
foreach ($debug as $msg) echo $msg . "\n";
echo "BridgeState cleanup complete.\n";
// cleanupExpiredOffers.php: Remove waitlist entries for expired offers
// Run manually or via cron to keep waitlist.json in sync

date_default_timezone_set('Europe/London');

$offersFile = __DIR__ . '/../data/offers.json';
$waitlistFile = __DIR__ . '/../data/waitlist.json';
$deleteEndpoint = __DIR__ . '/deleteFromWaitlist.php';

if (!file_exists($offersFile)) {
    exit("offers.json not found\n");
}

$offers = json_decode(file_get_contents($offersFile), true);
if (!$offers) $offers = [];

$now = time();
$expired = [];
foreach ($offers as $token => $offer) {
    if (!isset($offer['expiresAt']) || !isset($offer['email']) || !isset($offer['date'])) continue;
    $expiresAt = strtotime($offer['expiresAt']);
    if ($expiresAt && $now > $expiresAt && $offer['status'] === 'pending') {
        // Mark as expired
        $offers[$token]['status'] = 'expired';
        // Remove from waitlist
        $expired[] = [
            'email' => $offer['email'],
            'date' => $offer['date'],
            'name' => $offer['name'] ?? '',
            'blockId' => $offer['blockId'] ?? null,
            'blockDates' => $offer['blockDates'] ?? null
        ];
    }
}

// Save updated offers
file_put_contents($offersFile, json_encode($offers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));


// Remove expired waitlist entries directly
$waitlist = file_exists($waitlistFile) ? json_decode(file_get_contents($waitlistFile), true) : [];
foreach ($expired as $entry) {
    $date = $entry['date'];
    $email = $entry['email'];
    echo "Checking for waitlist entry: $email on $date\n";
    if (isset($waitlist[$date])) {
        $before = count($waitlist[$date]);
        $waitlist[$date] = array_filter($waitlist[$date], function($w) use ($email) {
            $result = $w['email'] !== $email;
            if (!$result) {
                echo "Deleting entry: " . json_encode($w) . "\n";
            }
            return $result;
        });
        $waitlist[$date] = array_values($waitlist[$date]);
        $after = count($waitlist[$date]);
        if ($before !== $after) {
            echo "Removed waitlist for: $email on $date\n";
        } else {
            echo "No matching waitlist entry found for: $email on $date\n";
        }
        if (count($waitlist[$date]) === 0) {
            unset($waitlist[$date]);
        }
    } else {
        echo "No waitlist for date: $date\n";
    }
}
file_put_contents($waitlistFile, json_encode($waitlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Cleanup complete.\n";

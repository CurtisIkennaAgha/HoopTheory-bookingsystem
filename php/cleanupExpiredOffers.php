// Reset bridgeState for all slots if bookings and waitlist are empty
$slotsFile = __DIR__ . '/../data/availableSlots.json';
$bookingsFile = __DIR__ . '/../data/bookings.json';
$bookings = file_exists($bookingsFile) ? json_decode(file_get_contents($bookingsFile), true) : [];
if (empty($waitlist) && empty($bookings)) {
    $slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
    foreach ($slots as $date => &$slotArr) {
        foreach ($slotArr as &$slot) {
            if (isset($slot['bridgeState']) && $slot['bridgeState']) {
                $slot['bridgeState'] = false;
            }
        }
    }
    file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Reset bridgeState for all slots (no bookings/waitlist)\n";
}
<?php
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

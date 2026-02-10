<?php
/**
 * Rebuild slots[].bookedUsers from bookings.json (canonical source).
 * - Preserves other slot fields
 * - Writes atomically (temp + rename)
 * - Creates a timestamped backup of the previous slots file when applying
 *
 * Returns an array with keys: changed (bool), changes (array), error (string|null)
 */
function sync_slots_from_bookings(string $bookingsFile, string $slotsFile, bool $apply = true, bool $makeBackup = true): array {
    $res = ['changed' => false, 'changes' => [], 'error' => null];

    if (!file_exists($bookingsFile)) {
        $res['error'] = 'bookings.json not found';
        error_log('sync_slots_from_bookings: bookings.json missing - ' . $bookingsFile);
        return $res;
    }

    $bookings = json_decode(file_get_contents($bookingsFile), true) ?? [];
    $slots = file_exists($slotsFile) ? (json_decode(file_get_contents($slotsFile), true) ?? []) : [];

    $updated = $slots; // will modify bookedUsers only

    foreach ($updated as $date => $daySlots) {
        foreach ($daySlots as $i => $slot) {
            $expectedEmails = [];
            if (!empty($bookings[$date]) && is_array($bookings[$date])) {
                foreach ($bookings[$date] as $bstr) {
                    if (!is_string($bstr)) continue;
                    // match booking strings that reference this slot's time and title
                    if (strpos($bstr, $slot['time']) !== false && strpos($bstr, $slot['title']) !== false) {
                        if (preg_match('/\(([^")]+@[^")]+)\)$/', $bstr, $m)) {
                            $expectedEmails[] = strtolower(trim($m[1]));
                        }
                    }
                }
            }

            $expectedEmails = array_values(array_unique($expectedEmails));

            $slotBooked = $slot['bookedUsers'] ?? [];
            $slotEmails = array_map(function($b) {
                if (is_array($b) && !empty($b['email'])) return strtolower(trim($b['email']));
                if (is_string($b) && preg_match('/\(([^")]+@[^")]+)\)$/', $b, $m)) return strtolower(trim($m[1]));
                return null;
            }, $slotBooked);
            $slotEmails = array_values(array_filter(array_unique($slotEmails)));

            sort($expectedEmails); sort($slotEmails);

            if ($expectedEmails !== $slotEmails) {
                $res['changes'][] = [
                    'date' => $date,
                    'slotIndex' => $i,
                    'time' => $slot['time'] ?? null,
                    'title' => $slot['title'] ?? null,
                    'expected' => $expectedEmails,
                    'current' => $slotEmails
                ];

                $res['changed'] = true;

                if ($apply) {
                    $newBooked = [];
                    foreach ($expectedEmails as $em) {
                        $newBooked[] = ['email' => $em, 'name' => null];
                    }
                    $updated[$date][$i]['bookedUsers'] = $newBooked;
                }
            }
        }
    }

    if (!$res['changed']) {
        return $res;
    }

    if (!$apply) {
        return $res;
    }

    // Backup previous slots file
    if ($makeBackup && file_exists($slotsFile)) {
        $bak = $slotsFile . '.bak.' . date('YmdHis');
        @copy($slotsFile, $bak);
        error_log("sync_slots_from_bookings: backup created: $bak");
    }

    // Atomic write (temp + rename)
    $tmp = $slotsFile . '.tmp';
    $written = file_put_contents($tmp, json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($written === false) {
        $res['error'] = 'Failed to write temp slots file';
        error_log('sync_slots_from_bookings: failed to write temp file ' . $tmp);
        @unlink($tmp);
        return $res;
    }

    if (!@rename($tmp, $slotsFile)) {
        $res['error'] = 'Failed to atomically replace slots file';
        error_log('sync_slots_from_bookings: rename failed from ' . $tmp . ' to ' . $slotsFile);
        @unlink($tmp);
        return $res;
    }

    error_log('sync_slots_from_bookings: applied changes, slots file updated');
    return $res;
}

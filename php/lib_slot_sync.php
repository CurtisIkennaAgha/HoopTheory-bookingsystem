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
            $expectedUsers = [];
            if (!empty($bookings[$date]) && is_array($bookings[$date])) {
                foreach ($bookings[$date] as $bstr) {
                    if (!is_string($bstr)) continue;
                    // match booking strings that reference this slot's time and title
                    if (strpos($bstr, $slot['time']) !== false && strpos($bstr, $slot['title']) !== false) {
                        // Format: "HH:MM - Title (Name) (Email)"
                        if (preg_match('/^(.+?)\s*-\s*(.+?)\s*\(([^)]+)\)\s*\(([^)]+)\)$/', $bstr, $m)) {
                            $expectedUsers[] = [
                                'email' => strtolower(trim($m[4])),
                                'name' => trim($m[3])
                            ];
                        }
                    }
                }
            }
            // Remove duplicates by email+name
            $expectedUsers = array_values(array_unique($expectedUsers, SORT_REGULAR));

            $slotBooked = $slot['bookedUsers'] ?? [];
            $slotUsers = array_map(function($b) {
                if (is_array($b) && isset($b['email']) && isset($b['name'])) {
                    return [
                        'email' => strtolower(trim($b['email'])),
                        'name' => trim($b['name'])
                    ];
                }
                if (is_string($b) && preg_match('/^(.+?)\s*-\s*(.+?)\s*\(([^)]+)\)\s*\(([^)]+)\)$/', $b, $m)) {
                    return [
                        'email' => strtolower(trim($m[4])),
                        'name' => trim($m[3])
                    ];
                }
                return null;
            }, $slotBooked);
            $slotUsers = array_values(array_filter($slotUsers));

            // Sort for comparison
            usort($expectedUsers, function($a, $b) {
                return strcmp($a['email'] . $a['name'], $b['email'] . $b['name']);
            });
            usort($slotUsers, function($a, $b) {
                return strcmp($a['email'] . $a['name'], $b['email'] . $b['name']);
            });

            if ($expectedUsers !== $slotUsers) {
                $res['changes'][] = [
                    'date' => $date,
                    'slotIndex' => $i,
                    'time' => $slot['time'] ?? null,
                    'title' => $slot['title'] ?? null,
                    'expected' => $expectedUsers,
                    'current' => $slotUsers
                ];

                $res['changed'] = true;

                if ($apply) {
                    $updated[$date][$i]['bookedUsers'] = $expectedUsers;
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

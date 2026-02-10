<?php
header('Content-Type: application/json');

try {
    $rawInput = file_get_contents('php://input');
    
    if ($rawInput === '') {
        throw new Exception('No input data received');
    }
    
    $input = json_decode($rawInput, true);
    
    if ($input === null) {
        throw new Exception('Invalid JSON received');
    }
    
    $bookingId = $input['bookingId'] ?? '';
    $email = $input['email'] ?? '';
    $name = $input['name'] ?? '';
    $date = $input['date'] ?? '';
    $slot = $input['slot'] ?? '';
    $title = $input['title'] ?? '';
    $isBlock = $input['isBlock'] ?? false;
    $blockDates = $input['blockDates'] ?? [];
    $blockId = $input['blockId'] ?? null;
    $cancellationReason = $input['cancellationReason'] ?? '';
    
    error_log('🔵 PROCESSCANCEL: Raw input received: ' . json_encode($input));
    error_log('🔵 PROCESSCANCEL: Initial isBlock=' . ($isBlock ? 'true' : 'false') . ', blockDates count=' . count($blockDates));
    
    if (!$bookingId) {
        throw new Exception('Missing required booking details');
    }
    
    $bookingsFile = __DIR__ . '/../data/bookings.json';
    $slotsFile = __DIR__ . '/../data/availableSlots.json';
    $bookingMappingsFile = __DIR__ . '/../data/bookingMappings.json';
    $cancellationsFile = __DIR__ . '/../data/cancellations.json';
    
    // Load current data
    $bookings = file_exists($bookingsFile) ? json_decode(file_get_contents($bookingsFile), true) : [];
    $slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
    $bookingMappings = file_exists($bookingMappingsFile) ? json_decode(file_get_contents($bookingMappingsFile), true) : [];
    $cancellations = file_exists($cancellationsFile) ? json_decode(file_get_contents($cancellationsFile), true) : [];

    // Resolve details from mapping if available
    if (isset($bookingMappings[$bookingId])) {
        error_log('🟡 PROCESSCANCEL: Found bookingId in mappings: ' . $bookingId);
        $m = $bookingMappings[$bookingId];
        error_log('🟡 PROCESSCANCEL: Mapping data: ' . json_encode($m));
        $name = $m['name'] ?? $name;
        $email = $m['email'] ?? $email;
        $date = $m['date'] ?? $date;
        $slot = $m['slot'] ?? $slot;
        $title = $m['title'] ?? $title;
        $isBlock = !empty($m['isBlock']);
        $blockDates = $m['blockDates'] ?? $blockDates;
        $blockId = $m['blockId'] ?? ($blockId ?? null);
        error_log('🟡 PROCESSCANCEL: After mapping resolution: isBlock=' . ($isBlock ? 'true' : 'false') . ', blockDates=' . json_encode($blockDates));
    } else {
        error_log('❌ PROCESSCANCEL: bookingId NOT found in mappings! Available keys: ' . json_encode(array_keys($bookingMappings)));
    }

    // Validate required fields after mapping resolution
    if (!$email || !$slot || !$title || (!$date && (!$isBlock || empty($blockDates)))) {
        throw new Exception('Missing required booking details');
    }
    
    // Verify booking hasn't been cancelled already
    if (isset($cancellations[$bookingId])) {
        throw new Exception('This booking has already been cancelled');
    }
    
    // Helper to match booking entries regardless of name formatting
    $matchesBooking = function($entry) use ($slot, $title, $email) {
        $entryTime = '';
        $entryTitle = '';
        $entryEmail = '';
        if (preg_match('/^(.+?)\s*-\s*(.+?)\s*\(([^)]+)\)\s*\(([^)]+)\)$/', $entry, $m)) {
            $entryTime = $m[1];
            $entryTitle = $m[2];
            $entryEmail = $m[4];
        }
        if ($entryTime !== '' && $entryTitle !== '' && $entryEmail !== '') {
            return (strcasecmp(trim($entryTime), trim($slot)) === 0)
                && (strcasecmp(trim($entryTitle), trim($title)) === 0)
                && (strcasecmp(trim($entryEmail), trim($email)) === 0);
        }
        return (stripos($entry, $slot) !== false)
            && (stripos($entry, $title) !== false)
            && (stripos($entry, $email) !== false);
    };
    
    // Remove from bookings.json
    if ($isBlock && !empty($blockDates)) {
        // Handle block booking cancellation
        error_log('🟢 PROCESSCANCEL: Processing BLOCK session cancellation for ' . count($blockDates) . ' dates');
        foreach ($blockDates as $blockDate) {
            error_log('🟢 PROCESSCANCEL: Removing from blockDate: ' . $blockDate . ', slot=' . $slot . ', title=' . $title . ', email=' . $email);
            if (isset($bookings[$blockDate])) {
                $beforeCount = count($bookings[$blockDate]);
                $bookings[$blockDate] = array_filter($bookings[$blockDate], function($b) use ($matchesBooking) {
                    return !$matchesBooking($b);
                });
                $bookings[$blockDate] = array_values($bookings[$blockDate]);
                $afterCount = count($bookings[$blockDate]);
                error_log('🟢 PROCESSCANCEL: blockDate ' . $blockDate . ' had ' . $beforeCount . ' bookings, now ' . $afterCount . ' bookings');
                if (empty($bookings[$blockDate])) {
                    unset($bookings[$blockDate]);
                }
            } else {
                error_log('⚠️  PROCESSCANCEL: No bookings found for blockDate: ' . $blockDate);
            }
            
            // Remove from bookedUsers in slots
            if (isset($slots[$blockDate])) {
                foreach ($slots[$blockDate] as &$s) {
                    if ($s['time'] === $slot && $s['title'] === $title) {
                        error_log('🟢 PROCESSCANCEL: Removing from slot bookedUsers for ' . $blockDate);
                        if (isset($s['bookedUsers'])) {
                            $beforeCount = count($s['bookedUsers']);
                            $s['bookedUsers'] = array_filter($s['bookedUsers'], function($user) use ($email) {
                                return $user['email'] !== $email;
                            });
                            $s['bookedUsers'] = array_values($s['bookedUsers']);
                            $afterCount = count($s['bookedUsers']);
                            error_log('🟢 PROCESSCANCEL: bookedUsers reduced from ' . $beforeCount . ' to ' . $afterCount);
                        }
                    }
                }
            }
        }
    } else {
        // Handle single booking cancellation
        error_log('🟡 PROCESSCANCEL: Processing SINGLE session cancellation for date: ' . $date);
        if (isset($bookings[$date])) {
            $bookings[$date] = array_filter($bookings[$date], function($b) use ($matchesBooking) {
                return !$matchesBooking($b);
            });
            $bookings[$date] = array_values($bookings[$date]);
            if (empty($bookings[$date])) {
                unset($bookings[$date]);
            }
        }
        
        // Remove from bookedUsers in slots
        if (isset($slots[$date])) {
            foreach ($slots[$date] as &$s) {
                if ($s['time'] === $slot && $s['title'] === $title) {
                    if (isset($s['bookedUsers'])) {
                        $s['bookedUsers'] = array_filter($s['bookedUsers'], function($user) use ($email) {
                            return $user['email'] !== $email;
                        });
                        $s['bookedUsers'] = array_values($s['bookedUsers']);
                    }
                }
            }
        }
    }
    
    // Save updated files
    error_log('🟣 PROCESSCANCEL: Saving cancellation to bookings.json');
    if (!file_put_contents($bookingsFile, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
        throw new Exception('Failed to save cancellation to bookings');
    }
    error_log('✅ PROCESSCANCEL: bookings.json saved successfully');
    
    error_log('🟣 PROCESSCANCEL: Saving cancellation to availableSlots.json');
    if (!file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
        throw new Exception('Failed to save slot updates');
    }
    error_log('✅ PROCESSCANCEL: availableSlots.json saved successfully');
    
    // Log cancellation
    $cancellation = [
        'bookingId' => $bookingId,
        'name' => $name,
        'email' => $email,
        'date' => $date,
        'slot' => $slot,
        'title' => $title,
        'isBlock' => $isBlock,
        'blockDates' => $blockDates,
        'cancellationReason' => $cancellationReason,
        'cancelledAt' => date('Y-m-d H:i:s'),
        'timestamp' => time()
    ];
    
    $cancellations[$bookingId] = $cancellation;
    error_log('🟣 PROCESSCANCEL: Saving cancellation record to cancellations.json');
    
    if (!file_put_contents($cancellationsFile, json_encode($cancellations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
        throw new Exception('Failed to log cancellation');
    }
    error_log('✅ PROCESSCANCEL: Cancellation record saved');
    
    // Remove from booking mappings
    error_log('🟣 PROCESSCANCEL: Removing booking from mappings');
    if (isset($bookingMappings[$bookingId])) {
        unset($bookingMappings[$bookingId]);
        file_put_contents($bookingMappingsFile, json_encode($bookingMappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    
    // Re-sync to ensure slots[] exactly reflect bookings.json
    require_once __DIR__ . '/lib_slot_sync.php';
    $syncRes = sync_slots_from_bookings(__DIR__ . '/../data/bookings.json', __DIR__ . '/../data/availableSlots.json', true, true);
    error_log('sync_slots_from_bookings (self-service cancel) result: ' . json_encode($syncRes));

    // Update Bridge State for this slot
    $waitlistFile = __DIR__ . '/../data/waitlist.json';
    $waitlistData = file_exists($waitlistFile) ? json_decode(file_get_contents($waitlistFile), true) : [];
    require_once __DIR__ . '/lib_bridge_state.php';

    $slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
    $primaryDate = ($isBlock && !empty($blockDates)) ? $blockDates[0] : $date;
    if ($primaryDate) {
        updateBridgeState($slots, $primaryDate, $slot, $title, $bookings, $waitlistData, $blockId ?? null);
        file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    
    // Send cancellation confirmation email
    sendCancellationEmail($name, $email, $date, $slot, $title, $cancellationReason);
    
    error_log('Booking cancelled successfully via self-service: ' . $bookingId);
    echo json_encode([
        'status' => 'ok',
        'message' => 'Booking cancelled successfully'
    ], JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    error_log('processCancellation error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES);
}

function sendCancellationEmail($name, $email, $date, $slot, $title, $reason = '') {
    $data = [
        'name' => $name,
        'email' => $email,
        'slot' => $slot,
        'title' => $title,
        'date' => $date,
        'type' => 'cancellation',
        'cancellationReason' => $reason,
        'blockDates' => [],
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/php/sendEmail.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curlError) {
        error_log('CURL ERROR sending cancellation email: ' . $curlError);
    }
    if ($httpCode !== 200) {
        error_log('HTTP Error sending cancellation email - Code: ' . $httpCode . ', Response: ' . $response);
    }
    error_log('Cancellation email sent - http code: ' . $httpCode . ', email: ' . $email);
}
?>

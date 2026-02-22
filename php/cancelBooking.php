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
  
  $bookingsFile = '../data/bookings.json';
  $slotsFile = '../data/availableSlots.json';
  
  $name = $input['name'] ?? '';
  $email = $input['email'] ?? '';
  $date = $input['date'] ?? '';
  $time = $input['time'] ?? '';
  $title = $input['title'] ?? '';
  $isBlock = $input['isBlock'] ?? false;
  $blockDates = $input['blockDates'] ?? [];
  $blockId = $input['blockId'] ?? null;
  $bookingId = $input['bookingId'] ?? '';

  // If bookingId provided, use bookingMappings to resolve canonical details
  if ($bookingId) {
    $bookingMappingsFile = __DIR__ . '/../data/bookingMappings.json';
    $bookingMappings = file_exists($bookingMappingsFile) ? json_decode(file_get_contents($bookingMappingsFile), true) : [];
    if (isset($bookingMappings[$bookingId])) {
      $m = $bookingMappings[$bookingId];
      // Expiry check: treat expired bookings as normal cancellations
      $name = $m['name'] ?? $name;
      $email = $m['email'] ?? $email;
      $time = $m['slot'] ?? $time;
      $title = $m['title'] ?? $title;
      $isBlock = !empty($m['isBlock']);
      $blockDates = $m['blockDates'] ?? $blockDates;
      $blockId = $m['blockId'] ?? $blockId;
      if (!empty($m['date'])) {
        $date = $m['date'];
      }
    }
    // If bookingId is not found, throw error only if it was never in the system
    else {
      throw new Exception('BookingId not found in bookingMappings.json');
    }
  }
  
  if (!$email || !$date || !$time || !$title) {
    throw new Exception('Missing required data');
  }
  
  // Load bookings and slots
  $bookings = file_exists($bookingsFile) ? json_decode(file_get_contents($bookingsFile), true) : [];
  $slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
  
  // Deterministic matcher: parses and compares all fields exactly
  $matchesBooking = function($entry) use ($time, $title, $email, $name) {
    // Remove the last two parentheses groups (name + email) from the end
    $mainPart = preg_replace('/\s*\([^()]*\)\s*\([^()]*\)\s*$/', '', $entry);
    // Parse time and title from main part
    $mainParts = explode(' - ', $mainPart, 2);
    if (count($mainParts) !== 2) {
      return false; // Malformed entry
    }
    $entryTime = trim($mainParts[0]);
    $entryTitle = trim($mainParts[1]);
    // Extract all parentheses groups
    $groups = [];
    if (preg_match_all('/\(([^)]+)\)/', $entry, $matches)) {
      $groups = $matches[1];
    }
    if (count($groups) < 2) {
      return false; // Malformed entry
    }
    // Always use the last two groups for name and email
    $entryName = trim($groups[count($groups)-2]);
    $entryEmail = trim($groups[count($groups)-1]);
    // Compare all four fields exactly (case-insensitive for name/email)
    return (
      $entryTime === $time &&
      $entryTitle === $title &&
      strcasecmp($entryName, $name) === 0 &&
      strcasecmp($entryEmail, $email) === 0
    );
  };
  
  // Handle block booking cancellation
  if ($isBlock && !empty($blockDates)) {
    foreach ($blockDates as $blockDate) {
      // Remove from bookings.json
      if (isset($bookings[$blockDate])) {
        $bookings[$blockDate] = array_filter($bookings[$blockDate], function($b) use ($matchesBooking) {
          return !$matchesBooking($b);
        });
        $bookings[$blockDate] = array_values($bookings[$blockDate]);
        if (empty($bookings[$blockDate])) {
          unset($bookings[$blockDate]);
        }
      }
      
      // Remove from bookedUsers in slots
      if (isset($slots[$blockDate])) {
        foreach ($slots[$blockDate] as &$slotEntry) {
          if ($slotEntry['time'] === $time && $slotEntry['title'] === $title) {
            if (isset($slotEntry['bookedUsers'])) {
              $slotEntry['bookedUsers'] = array_filter($slotEntry['bookedUsers'], function($user) use ($email, $name) {
                return !($user['email'] === $email && isset($user['name']) && $user['name'] === $name);
              });
              $slotEntry['bookedUsers'] = array_values($slotEntry['bookedUsers']);
            }
          }
        }
      }
    }
  }
  // Handle single booking cancellation
  else {
    // Remove from bookings.json
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
      foreach ($slots[$date] as &$slotEntry) {
        if ($slotEntry['time'] === $time && $slotEntry['title'] === $title) {
          if (isset($slotEntry['bookedUsers'])) {
            $slotEntry['bookedUsers'] = array_filter($slotEntry['bookedUsers'], function($user) use ($email, $name) {
              return !($user['email'] === $email && isset($user['name']) && $user['name'] === $name);
            });
            $slotEntry['bookedUsers'] = array_values($slotEntry['bookedUsers']);
          }
        }
      }
    }
  }
  
  // Save updated files
  if (!file_put_contents($bookingsFile, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
    throw new Exception('Failed to write bookings.json');
  }
  
  if (!file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
    throw new Exception('Failed to write availableSlots.json');
  }

  // Remove bookingId mapping if present
  if (!empty($bookingId)) {
    $bookingMappingsFile = __DIR__ . '/../data/bookingMappings.json';
    $bookingMappings = file_exists($bookingMappingsFile) ? json_decode(file_get_contents($bookingMappingsFile), true) : [];
    if (isset($bookingMappings[$bookingId])) {
      unset($bookingMappings[$bookingId]);
      file_put_contents($bookingMappingsFile, json_encode($bookingMappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
  }

  // Re-sync to ensure slots[].bookedUsers exactly reflect bookings.json
  require_once __DIR__ . '/lib_slot_sync.php';
  $syncRes = sync_slots_from_bookings(__DIR__ . '/../data/bookings.json', __DIR__ . '/../data/availableSlots.json', true, true);
  error_log('sync_slots_from_bookings (cancel) result: ' . json_encode($syncRes));
  
  // Update Bridge State for this slot
  $waitlistFile = __DIR__ . '/../data/waitlist.json';
  $waitlistData = file_exists($waitlistFile) ? json_decode(file_get_contents($waitlistFile), true) : [];
  require_once __DIR__ . '/lib_bridge_state.php';
  
  // Reload slots after sync
  $slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
  $primaryDate = ($isBlock && !empty($blockDates)) ? $blockDates[0] : $date;
  if ($primaryDate) {
    updateBridgeState($slots, $primaryDate, $time, $title, $bookings, $waitlistData, $blockId);
    file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }
  
  // Send cancellation email to user
  // Use HTTP URL for sendEmail.php as in other scripts
  $sendEmailUrl = 'https://hooptheory.co.uk/php/sendEmail.php';
  // Safely fetch slot data for email payload after sync
  $slotData = null;
  if (isset($slots[$date])) {
    foreach ($slots[$date] as $s) {
      if ($s['time'] === $time && $s['title'] === $title) {
        $slotData = $s;
        break;
      }
    }
  }
  $emailPayload = [
    'email' => $email,
    'slot' => $time,
    'date' => $date,
    'title' => $title,
    'name' => $name,
    'blockDates' => $blockDates,
    'type' => 'booking_cancellation',
    'bookingId' => $bookingId,
    'location' => isset($slotData) && isset($slotData['location']) ? $slotData['location'] : '',
    'price' => isset($slotData) && isset($slotData['price']) ? $slotData['price'] : '',
  ];
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $sendEmailUrl);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailPayload));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
  curl_setopt($ch, CURLOPT_HEADER, true); // Get headers + body
  $emailResponse = curl_exec($ch);
  $emailErr = curl_error($ch);
  $emailInfo = curl_getinfo($ch);
  $httpCode = $emailInfo['http_code'] ?? null;
  $headerSize = $emailInfo['header_size'] ?? 0;
  $emailHeaders = substr($emailResponse, 0, $headerSize);
  $emailBody = substr($emailResponse, $headerSize);
  curl_close($ch);
  error_log('CancelBooking: sendEmail.php HTTP code: ' . print_r($httpCode, true));
  error_log('CancelBooking: sendEmail.php headers: ' . print_r($emailHeaders, true));
  error_log('CancelBooking: sendEmail.php body: ' . print_r($emailBody, true));
  if ($emailErr) {
    error_log('CancelBooking: sendEmail.php CURL error: ' . $emailErr);
  }

  $emailResultDecoded = null;
  if ($emailBody) {
    $emailResultDecoded = json_decode($emailBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      error_log('CancelBooking: sendEmail.php JSON decode error: ' . json_last_error_msg());
    }
  }

  echo json_encode([
    'status' => 'ok',
    'message' => 'Expired booking removed',
    'emailResult' => $emailResultDecoded,
    'emailHttpCode' => $httpCode,
    'emailCurlError' => $emailErr,
    'emailHeaders' => $emailHeaders,
    'emailBody' => $emailBody
  ], JSON_UNESCAPED_SLASHES);
  
} catch (Exception $e) {
  error_log('Cancel booking error: ' . $e->getMessage());
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
?>

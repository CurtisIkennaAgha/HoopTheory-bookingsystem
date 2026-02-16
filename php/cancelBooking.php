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
      $date = $m['date'] ?? $date;
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
  
  // Helper to match booking entries regardless of name formatting
  $matchesBooking = function($entry) use ($time, $title, $email) {
    $entryTime = '';
    $entryTitle = '';
    $entryEmail = '';
    if (preg_match('/^(.+?)\s*-\s*(.+?)\s*\(([^)]+)\)\s*\(([^)]+)\)$/', $entry, $m)) {
      $entryTime = $m[1];
      $entryTitle = $m[2];
      $entryEmail = $m[4];
    }
    if ($entryTime !== '' && $entryTitle !== '' && $entryEmail !== '') {
      return (strcasecmp(trim($entryTime), trim($time)) === 0)
        && (strcasecmp(trim($entryTitle), trim($title)) === 0)
        && (strcasecmp(trim($entryEmail), trim($email)) === 0);
    }
    // Fallback: match by substring for time/title/email
    return (stripos($entry, $time) !== false)
      && (stripos($entry, $title) !== false)
      && (stripos($entry, $email) !== false);
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
        foreach ($slots[$blockDate] as &$slot) {
          if ($slot['time'] === $time && $slot['title'] === $title) {
            if (isset($slot['bookedUsers'])) {
              $slot['bookedUsers'] = array_filter($slot['bookedUsers'], function($user) use ($email) {
                return $user['email'] !== $email;
              });
              $slot['bookedUsers'] = array_values($slot['bookedUsers']);
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
      foreach ($slots[$date] as &$slot) {
        if ($slot['time'] === $time && $slot['title'] === $title) {
          if (isset($slot['bookedUsers'])) {
            $slot['bookedUsers'] = array_filter($slot['bookedUsers'], function($user) use ($email) {
              return $user['email'] !== $email;
            });
            $slot['bookedUsers'] = array_values($slot['bookedUsers']);
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
  $emailPayload = [
    'email' => $email,
    'slot' => $time,
    'date' => $date,
    'title' => $title,
    'name' => $name,
    'blockDates' => $blockDates,
    'type' => 'booking_cancellation',
    'bookingId' => $bookingId
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

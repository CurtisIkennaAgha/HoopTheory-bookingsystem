<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function addUserToTracking($name, $email) {
  $usersFile = __DIR__ . '/../data/users.json';
  $users = [];
  
  if (file_exists($usersFile)) {
    $users = json_decode(file_get_contents($usersFile), true) ?? [];
  }
  
  if (!isset($users[$email])) {
    $users[$email] = [
      'email' => $email,
      'name' => $name,
      'bookings' => [],
      'waitlist' => [],
      'offers' => [],
      'addedAt' => date('Y-m-d H:i:s')
    ];
  } else {
    // Update name if different
    $users[$email]['name'] = $name;
  }
  
  file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function sendConfirmationEmail($name, $email, $time, $title, $date, $blockDates = [], $emailType = 'confirmation', $paymentRef = null, $deadline = null, $price = null, $location = null, $bookingId = null) {
    $data = [
        'name' => $name,
        'email' => $email,
        'slot' => $time,
        'title' => $title,
        'date' => $date,
        'blockDates' => $blockDates,
        'type' => $emailType
    ];
    
    // Generate unique bookingId if not provided
    if (!$bookingId) {
        $bookingId = 'BK-' . time() . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
    $data['bookingId'] = $bookingId;
    
    // Add payment details if provided
    if ($emailType === 'temporary_reservation') {
        if (!$paymentRef) {
            $paymentRef = 'HT-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6));
        }
        if (!$deadline) {
            $deadline = date('D, j M Y H:i', strtotime('+24 hours'));
        }
        $data['paymentRef'] = $paymentRef;
        $data['deadline'] = $deadline;
        if ($price) $data['price'] = $price;
        if ($location) $data['location'] = $location;
    }
    
    error_log('sendConfirmationEmail START - type: ' . $emailType . ', to: ' . $email);
    error_log('sendConfirmationEmail data: ' . json_encode($data));
    
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
        error_log('CURL ERROR in sendConfirmationEmail: ' . $curlError);
    }
    if ($httpCode !== 200) {
        error_log('HTTP Error in sendConfirmationEmail - Code: ' . $httpCode . ', Response: ' . $response);
    }
    error_log('sendConfirmationEmail completed - http code: ' . $httpCode);
}

try {
  $rawInput = file_get_contents('php://input');
  error_log('SaveBookings START - raw input: ' . $rawInput);
  
  if ($rawInput === '') {
    throw new Exception('No input data received');
  }
  
  $input = json_decode($rawInput, true);
  error_log('SaveBookings decoded JSON: ' . json_encode($input));
  
  if ($input === null) {
    throw new Exception('Invalid JSON received');
  }
  
  $bookingsFile = '../data/bookings.json';
  $slotsFile = '../data/availableSlots.json';
  
  // Check if this is a new booking (has name/email) or bulk update (full bookings object)
  if (isset($input['name']) && isset($input['email'])) {
    // NEW BOOKING FORMAT
    $bookings = file_exists($bookingsFile) ? json_decode(file_get_contents($bookingsFile), true) : [];
    $slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
    
    $name = $input['name'] ?? 'Unknown';
    $email = $input['email'] ?? 'N/A';
    $slot = $input['slot'] ?? [];
    $slotTitle = $slot['title'] ?? 'Unknown Session';
    $slotTime = $slot['time'] ?? 'Unknown Time';
    $blockDates = $input['blockDates'] ?? []; // For block sessions
    $blockId = $input['blockId'] ?? null;
    $price = $input['price'] ?? null;
    $location = $input['location'] ?? null;
    
    // Generate unique bookingId
    $bookingId = 'BK-' . time() . '-' . strtoupper(bin2hex(random_bytes(4)));
    
    // Format: "HH:MM - Title (Name) (Email)"
    $bookingString = "$slotTime - $slotTitle ($name) ($email)";
    
    // Handle single session booking
    if (isset($input['date'])) {
      $date = $input['date'];
      
      if (!isset($bookings[$date])) {
        $bookings[$date] = [];
      }
      
      $bookings[$date][] = $bookingString;
      
      // Update slot's bookedUsers array
      if (isset($slots[$date])) {
        foreach ($slots[$date] as &$s) {
          if ($s['time'] === $slotTime && $s['title'] === $slotTitle) {
            if (!isset($s['bookedUsers'])) {
              $s['bookedUsers'] = [];
            }
            $s['bookedUsers'][] = ['name' => $name, 'email' => $email];
            break;
          }
        }
      }
    }
    // Handle block session booking
    else if (isset($input['blockDates'])) {
      $blockDates = $input['blockDates'];
      
      foreach ($blockDates as $date) {
        if (!isset($bookings[$date])) {
          $bookings[$date] = [];
        }
        
        $bookings[$date][] = $bookingString;
        
        // Update slot's bookedUsers array for each date
        if (isset($slots[$date])) {
          foreach ($slots[$date] as &$s) {
            if ($s['time'] === $slotTime && $s['title'] === $slotTitle && isset($s['blockId'])) {
              if (!isset($s['bookedUsers'])) {
                $s['bookedUsers'] = [];
              }
              $s['bookedUsers'][] = ['name' => $name, 'email' => $email];
              break;
            }
          }
        }
      }
    }
    
    // Save files
    // Check file permissions before writing
    if (!is_writable(dirname($bookingsFile))) {
      error_log('Directory not writable for bookings.json');
      throw new Exception('Directory not writable for bookings.json');
    }
    if (!is_writable(dirname($slotsFile))) {
      error_log('Directory not writable for availableSlots.json');
      throw new Exception('Directory not writable for availableSlots.json');
    }
    clearstatcache(true, $bookingsFile);
    $bookingsWrite = @file_put_contents($bookingsFile, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($bookingsWrite === false) {
      error_log('Failed to write bookings.json');
      throw new Exception('Failed to write bookings.json');
    }
    clearstatcache(true, $slotsFile);
    $slotsWrite = @file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($slotsWrite === false) {
      error_log('Failed to write availableSlots.json');
      throw new Exception('Failed to write availableSlots.json');
    }

    // Re-sync slots[] from canonical bookings.json to avoid orphaned bookedUsers
    require_once __DIR__ . '/lib_slot_sync.php';
    $syncRes = sync_slots_from_bookings(__DIR__ . '/../data/bookings.json', __DIR__ . '/../data/availableSlots.json', true, true);
    error_log('sync_slots_from_bookings result: ' . json_encode($syncRes));
    
    // Update Bridge State for this slot
    $slotsFile = __DIR__ . '/../data/availableSlots.json';
    $waitlistFile = __DIR__ . '/../data/waitlist.json';
    $slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
    $waitlistData = file_exists($waitlistFile) ? json_decode(file_get_contents($waitlistFile), true) : [];
    require_once __DIR__ . '/lib_bridge_state.php';
    
    $primaryDate = isset($input['date']) ? $input['date'] : (!empty($blockDates) ? $blockDates[0] : null);
    if ($primaryDate) {
      updateBridgeState($slots, $primaryDate, $slotTime, $slotTitle, $bookings, $waitlistData, $blockId);
      file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    
    // Store bookingId mapping for later cancellation retrieval
    $bookingMappingsFile = __DIR__ . '/../data/bookingMappings.json';
    $bookingMappings = file_exists($bookingMappingsFile) ? json_decode(file_get_contents($bookingMappingsFile), true) : [];
    
    $bookingMappings[$bookingId] = [
        'name' => $name,
        'email' => $email,
        'date' => isset($input['date']) ? $input['date'] : null,
        'slot' => $slotTime,
        'title' => $slotTitle,
        'isBlock' => !empty($blockDates),
        'blockId' => $blockId,
        'blockDates' => $blockDates,
        'price' => $price,
        'location' => $location,
        'status' => 'Pending',
        'confirmedAt' => null,
        'createdAt' => date('Y-m-d H:i:s'),
        'timestamp' => time(),
        'reservationTimestamp' => time(),
        // Load expirySeconds from config if available
        'expiryTimestamp' => (function() {
          $configFile = __DIR__ . '/../data/bookingExpiryConfig.json';
          $expirySeconds = 40;
          if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            if (isset($config['expirySeconds']) && is_numeric($config['expirySeconds'])) {
              $expirySeconds = intval($config['expirySeconds']);
            }
          }
          error_log('[DEBUG] expirySeconds used for new booking: ' . $expirySeconds);
          return time() + $expirySeconds;
        })()
    ];
    
    file_put_contents($bookingMappingsFile, json_encode($bookingMappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
    // Add user to tracking
    addUserToTracking($name, $email);
    
    // NOTE: Email is NOT sent here. It will be sent after user closes payment popup.
    // The bookingId will be included in the email data.
    error_log('Booking created - bookingId: ' . $bookingId . ', email will be sent after payment popup closes. Email: ' . $email);
    
    // Also return expirySeconds used for this booking
    $configFile = __DIR__ . '/../data/bookingExpiryConfig.json';
    $expirySeconds = 40;
    if (file_exists($configFile)) {
      $config = json_decode(file_get_contents($configFile), true);
      if (isset($config['expirySeconds']) && is_numeric($config['expirySeconds'])) {
        $expirySeconds = intval($config['expirySeconds']);
      }
    }
    echo json_encode([
      'status' => 'ok',
      'message' => 'Booking added',
      'bookingId' => $bookingId,
      'expirySeconds' => $expirySeconds
    ], JSON_UNESCAPED_SLASHES);
  } else {
    // BULK UPDATE FORMAT (for deletes)
    error_log('Bulk update received with ' . count($input) . ' dates');
    error_log('Bulk update: ' . json_encode($input));
    if (!is_writable(dirname($bookingsFile))) {
      error_log('Directory not writable for bookings.json (bulk update)');
      throw new Exception('Directory not writable for bookings.json');
    }
    clearstatcache(true, $bookingsFile);
    $bulkWrite = @file_put_contents($bookingsFile, json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($bulkWrite === false) {
      error_log('Failed to write bookings.json (bulk update)');
      throw new Exception('Failed to write bookings.json');
    }

    // --- Remove bookingMappings for deleted bookings ---
    $bookingMappingsFile = __DIR__ . '/../data/bookingMappings.json';
    $bookingMappings = file_exists($bookingMappingsFile) ? json_decode(file_get_contents($bookingMappingsFile), true) : [];
    // Build a set of all current bookings (date, time, title, email)
    $currentBookings = [];
    foreach ($input as $date => $bookingArr) {
      foreach ($bookingArr as $bstr) {
        if (preg_match('/^(.+?)\s*-\s*(.+?)\s*\(([^)]+)\)\s*\(([^)]+)\)$/', $bstr, $m)) {
          $bTime = trim($m[1]);
          $bTitle = trim($m[2]);
          $bName = trim($m[3]);
          $bEmail = trim($m[4]);
          $currentBookings[] = [
            'date' => $date,
            'time' => $bTime,
            'title' => $bTitle,
            'email' => strtolower($bEmail)
          ];
        }
      }
    }
    // Remove any mapping that does not have a corresponding booking
    $newMappings = [];
    foreach ($bookingMappings as $bookingId => $mapping) {
      $mappingDate = isset($mapping['date']) ? $mapping['date'] : null;
      $mappingTime = isset($mapping['slot']) ? $mapping['slot'] : (isset($mapping['time']) ? $mapping['time'] : null);
      $mappingTitle = isset($mapping['title']) ? $mapping['title'] : null;
      $mappingEmail = isset($mapping['email']) ? strtolower($mapping['email']) : null;
      $isBlock = !empty($mapping['blockDates']);
      $blockDates = $isBlock && is_array($mapping['blockDates']) ? $mapping['blockDates'] : [];
      $found = false;
      foreach ($currentBookings as $cb) {
        $dateMatch = ($cb['date'] === $mappingDate) || ($isBlock && in_array($cb['date'], $blockDates));
        $timeMatch = $cb['time'] == $mappingTime;
        $titleMatch = $cb['title'] == $mappingTitle;
        $emailMatch = $cb['email'] == $mappingEmail;
        if ($dateMatch && $timeMatch && $titleMatch && $emailMatch) {
          $found = true;
          break;
        }
      }
      if ($found) {
        $newMappings[$bookingId] = $mapping;
      } else {
        error_log('[CASCADE DELETE] Removing mapping for deleted booking: ' . $bookingId . ' (' . $mappingEmail . ', ' . $mappingDate . ', ' . $mappingTime . ', ' . $mappingTitle . ')');
      }
    }
    file_put_contents($bookingMappingsFile, json_encode($newMappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    error_log('BookingMappings synchronized after bulk bookings update.');

    // Ensure slots file is canonical after bulk update
    require_once __DIR__ . '/lib_slot_sync.php';
    $syncRes = sync_slots_from_bookings(__DIR__ . '/../data/bookings.json', __DIR__ . '/../data/availableSlots.json', true, true);
    error_log('sync_slots_from_bookings (bulk update) result: ' . json_encode($syncRes));
    if (isset($syncRes['error']) && $syncRes['error']) {
      error_log('sync_slots_from_bookings error: ' . $syncRes['error']);
      http_response_code(500);
      echo json_encode(['status' => 'error', 'message' => 'Slot sync error: ' . $syncRes['error']], JSON_UNESCAPED_SLASHES);
      exit;
    }
    echo json_encode(['status' => 'ok', 'message' => 'Bookings updated'], JSON_UNESCAPED_SLASHES);
  }
} catch (Exception $e) {
  error_log('SaveBookings error: ' . $e->getMessage());
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
?>


<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$usersFile = '../data/users.json';
$bookingsFile = '../data/bookings.json';
$waitlistFile = '../data/waitlist.json';
$offersFile = '../data/offers.json';

$emails = [];

// Get users from users.json as base
if (file_exists($usersFile)) {
  $usersData = json_decode(file_get_contents($usersFile), true) ?? [];
  
  // Load canonical data sources
  $bookingsData = file_exists($bookingsFile) ? json_decode(file_get_contents($bookingsFile), true) ?? [] : [];
  $waitlistData = file_exists($waitlistFile) ? json_decode(file_get_contents($waitlistFile), true) ?? [] : [];
  $offersData = file_exists($offersFile) ? json_decode(file_get_contents($offersFile), true) ?? [] : [];
  
  // Convert associative array to indexed array
  foreach ($usersData as $email => $user) {
    // Compute live counts from canonical data sources
    $bookingCount = 0;
    $waitlistCount = 0;
    $offerCount = 0;
    
    // Count bookings: search all dates in bookings.json for this email
    foreach ($bookingsData as $dateBookings) {
      if (is_array($dateBookings)) {
        foreach ($dateBookings as $booking) {
          if (strpos($booking, "($email)") !== false) {
            $bookingCount++;
          }
        }
      }
    }
    
    // Count waitlist entries
    foreach ($waitlistData as $dateWaitlist) {
      if (is_array($dateWaitlist)) {
        foreach ($dateWaitlist as $entry) {
          if (isset($entry['email']) && $entry['email'] === $email) {
            $waitlistCount++;
          }
        }
      }
    }
    
    // Count offers
    foreach ($offersData as $offer) {
      if (isset($offer['email']) && $offer['email'] === $email) {
        $offerCount++;
      }
    }
    
    // Update user record with live counts
    $user['bookings'] = array_fill(0, $bookingCount, true); // Placeholder array of correct length
    $user['waitlist'] = array_fill(0, $waitlistCount, true);
    $user['offers'] = array_fill(0, $offerCount, true);
    
    $emails[] = $user;
  }
  
  usort($emails, function($a, $b) {
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
  });
}

echo json_encode([
  'success' => true,
  'emails' => $emails,
  'total' => count($emails)
]);
?>

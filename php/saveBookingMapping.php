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
  $data = $input['data'] ?? [];
  
  if (!$bookingId || empty($data)) {
    throw new Exception('Missing bookingId or data');
  }
  
  // Load existing mappings
  $mappingsFile = '../data/bookingMappings.json';
  $mappings = file_exists($mappingsFile) ? json_decode(file_get_contents($mappingsFile), true) : [];
  
  if (!is_array($mappings)) {
    $mappings = [];
  }
  
  // Add or update the mapping
  $mappings[$bookingId] = $data;
  
  // Save mappings
  if (!file_put_contents($mappingsFile, json_encode($mappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    throw new Exception('Failed to save booking mapping');
  }
  
  echo json_encode(['success' => true, 'message' => 'Booking mapping saved', 'bookingId' => $bookingId]);
  
} catch (Exception $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

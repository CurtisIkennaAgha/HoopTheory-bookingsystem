<?php
header('Content-Type: application/json');

try {
    $bookingId = $_GET['bookingId'] ?? '';
    
    error_log('🔵 GETBOOKINGDETAILS: Requesting details for bookingId: ' . $bookingId);
    
    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }
    
    // Load cancellations file to check if already cancelled
    $cancellationsFile = __DIR__ . '/../data/cancellations.json';
    $cancellations = [];
    if (file_exists($cancellationsFile)) {
        $cancellations = json_decode(file_get_contents($cancellationsFile), true) ?? [];
    }
    
    // Check if this booking has already been cancelled
    if (isset($cancellations[$bookingId])) {
        error_log('⚠️  GETBOOKINGDETAILS: Booking already cancelled: ' . $bookingId);
        http_response_code(200);
        echo json_encode([
            'status' => 'ok',
            'cancelled' => true,
            'message' => 'This booking has already been cancelled'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    // Load booking mappings file (maps bookingId to booking details)
    $bookingMappingsFile = __DIR__ . '/../data/bookingMappings.json';
    if (!file_exists($bookingMappingsFile)) {
        error_log('❌ GETBOOKINGDETAILS: bookingMappings.json not found!');
        throw new Exception('Booking not found');
    }
    
    $bookingMappings = json_decode(file_get_contents($bookingMappingsFile), true) ?? [];
    
    error_log('🟡 GETBOOKINGDETAILS: Available booking IDs in mappings: ' . json_encode(array_keys($bookingMappings)));
    
    if (!isset($bookingMappings[$bookingId])) {
        error_log('❌ GETBOOKINGDETAILS: bookingId NOT found in mappings: ' . $bookingId);
        throw new Exception('Booking not found');
    }
    
    $bookingDetails = $bookingMappings[$bookingId];
        // Check expiry only for unconfirmed bookings
        $isConfirmed = (isset($bookingDetails['status']) && $bookingDetails['status'] === 'Confirmed') || isset($bookingDetails['confirmedAt']);
        if (!$isConfirmed && isset($bookingDetails['expiryTimestamp']) && time() > $bookingDetails['expiryTimestamp']) {
            // Remove expired booking from bookingMappings
            unset($bookingMappings[$bookingId]);
            file_put_contents($bookingMappingsFile, json_encode($bookingMappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            error_log('❌ GETBOOKINGDETAILS: Booking expired and deleted: ' . $bookingId);
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Booking expired and deleted'
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
    
    error_log('🟢 GETBOOKINGDETAILS: Retrieved booking details: ' . json_encode($bookingDetails));
    error_log('🟢 GETBOOKINGDETAILS: isBlock=' . ($bookingDetails['isBlock'] ? 'true' : 'false') . ', blockDates=' . json_encode($bookingDetails['blockDates'] ?? []));
    
    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'cancelled' => false,
        'bookingId' => $bookingId,
        'name' => $bookingDetails['name'] ?? '',
        'email' => $bookingDetails['email'] ?? '',
        'date' => $bookingDetails['date'] ?? '',
        'slot' => $bookingDetails['slot'] ?? '',
        'title' => $bookingDetails['title'] ?? '',
        'isBlock' => $bookingDetails['isBlock'] ?? false,
        'blockDates' => $bookingDetails['blockDates'] ?? []
    ], JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    error_log('❌ GETBOOKINGDETAILS: Error - ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES);
}
?>

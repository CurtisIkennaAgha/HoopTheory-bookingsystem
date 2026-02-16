<?php
header('Content-Type: application/json');

try {
    $dataDir = realpath(__DIR__ . '/../data/');
    if (!$dataDir) throw new Exception('Data directory not found');

    // List of all data files to clear (reset to empty structure)
    $filesToClear = [
        'availableSlots.json' => (object)[], // object
        'bookings.json' => (object)[],       // object
        'bookingMappings.json' => (object)[],// object
        'waitlist.json' => (object)[],       // object
        'playerProfiles.json' => (object)[], // object
        'players.json' => (object)[],        // object
        'offers.json' => (object)[],         // object
        'cancellations.json' => (object)[],  // object
        // Add more files as needed
    ];

    $results = [];
    foreach ($filesToClear as $file => $emptyValue) {
        $filePath = $dataDir . DIRECTORY_SEPARATOR . $file;
        if (file_exists($filePath)) {
            if (!file_put_contents($filePath, json_encode($emptyValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
                throw new Exception('Failed to clear ' . $file);
            }
            $results[$file] = 'cleared';
        } else {
            // Optionally create the file if missing
            file_put_contents($filePath, json_encode($emptyValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            $results[$file] = 'created';
        }
    }

    echo json_encode(['status' => 'ok', 'results' => $results], JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}


<?php
// cancelSession.php
// Session cancellation endpoint for admin panel
// POST: { sessionId, extraMessage, blockDates, blockId }

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['sessionId'])) {
    echo json_encode(["success" => false, "error" => "Missing sessionId."]);
    exit;
}
?>

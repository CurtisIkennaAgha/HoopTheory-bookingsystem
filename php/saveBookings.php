<?php
header('Content-Type: application/json');
$data = file_get_contents('php://input');
file_put_contents('../data/bookings.json', $data);
echo json_encode(["status"=>"ok"]);
?>

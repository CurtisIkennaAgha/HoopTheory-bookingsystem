<?php
// Set timezone to Europe/London to ensure correct timestamps
date_default_timezone_set('Europe/London');
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

function loadEnv($filePath) {
    if (!file_exists($filePath)) return;
    foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v, " \"'"));
    }
}

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

loadEnv(__DIR__ . '/.env');

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';
$name = $data['name'] ?? 'Guest';
$date = $data['date'] ?? '';
$time = $data['time'] ?? '';
$title = $data['title'] ?? 'Session';

$sessionKey = $data['sessionKey'] ?? '';
$blockDates = $data['blockDates'] ?? []; // Array of dates for block sessions
$blockId = $data['blockId'] ?? null;
$price = isset($data['price']) ? $data['price'] : '';
$location = isset($data['location']) ? $data['location'] : '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid email']));
}

if (!$email || !$date || !$time || !$title) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing required data']));
}

// Check if this is a block session
$isBlockSession = !empty($blockDates) && count($blockDates) > 1;

// Generate unique token
$token = bin2hex(random_bytes(32));

// Store offer in JSON file
$offersFile = __DIR__ . '/../data/offers.json';
$offers = [];
if (file_exists($offersFile)) {
    $offers = json_decode(file_get_contents($offersFile), true) ?? [];
}

// Load expirySeconds from offer config (separate from booking expiry)
$offerExpiryConfigFile = __DIR__ . '/../data/offerExpiryConfig.json';
$expirySeconds = 86400; // default 24h
if (file_exists($offerExpiryConfigFile)) {
    $config = json_decode(file_get_contents($offerExpiryConfigFile), true);
    if (isset($config['expirySeconds']) && is_numeric($config['expirySeconds'])) {
        $expirySeconds = intval($config['expirySeconds']);
    }
}

// Set $now as close as possible to usage
$now = time();
// Debug: log the exact PHP time when $now is set
file_put_contents(__DIR__ . '/../data/debug_time.txt', date('Y-m-d H:i:s', $now) . "\n", FILE_APPEND);

$offers[$token] = [
    'email' => $email,
    'name' => $name,
    'date' => $date,
    'time' => $time,
    'title' => $title,
    'sessionKey' => $sessionKey,
    'blockId' => $blockId,
    'blockDates' => $blockDates,
    'createdAt' => date('Y-m-d H:i:s', $now),
    'expiresAt' => date('Y-m-d H:i:s', $now + $expirySeconds),
    'status' => 'pending'
];

if (!file_put_contents($offersFile, json_encode($offers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    http_response_code(500);
    exit(json_encode(['error' => 'Failed to save offer']));
}

// Add user to tracking
addUserToTracking($name, $email);

// Send email
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = getenv('MAIL_HOST');
    $mail->SMTPAuth = true;
    $mail->Username = getenv('MAIL_USERNAME');
    $mail->Password = getenv('MAIL_PASSWORD');
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;

    $mail->setFrom(getenv('MAIL_FROM_ADDRESS'), 'Hoop Theory');
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = "Space Available: $title";

    // Get base URL for the links
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host;
    $phpPath = dirname($_SERVER['SCRIPT_NAME']);
    
    $confirmUrl = $baseUrl . $phpPath . '/reserveOffer.php?token=' . urlencode($token);
    $declineUrl = $baseUrl . $phpPath . '/declineOffer.php?token=' . urlencode($token);

    // Unified simple table for all session types
    $sessionDetailsHtml = '<table cellpadding="6" cellspacing="0" width="100%" style="background:#fafafa;border:1px solid #eee;border-radius:8px;box-shadow:0 2px 8px #0001;">';
    $sessionDetailsHtml .= '<tr><td style="font-weight:bold;width:140px;">Title</td><td>' . htmlspecialchars($title) . '</td></tr>';
    if ($isBlockSession) {
        $sessionDetailsHtml .= '<tr><td style="font-weight:bold;">Dates</td><td>' . implode(', ', array_map('htmlspecialchars', $blockDates)) . '</td></tr>';
    } else {
        $sessionDetailsHtml .= '<tr><td style="font-weight:bold;">Date</td><td>' . htmlspecialchars($date) . '</td></tr>';
    }
    $sessionDetailsHtml .= '<tr><td style="font-weight:bold;">Time</td><td>' . htmlspecialchars($time) . '</td></tr>';
    if ($location !== '') {
        $sessionDetailsHtml .= '<tr><td style="font-weight:bold;">Location</td><td>' . htmlspecialchars($location) . '</td></tr>';
    }
    if ($price !== '') {
        $sessionDetailsHtml .= '<tr><td style="font-weight:bold;">Price</td><td>&pound;' . htmlspecialchars($price) . '</td></tr>';
    }
    $sessionDetailsHtml .= '</table>';

    // Set the email body ONCE, inside the try block
    $mail->Body = "<!DOCTYPE html>\n<html>\n<body style='margin:0;padding:0;background:#f5f5f5;'>\n<img src='https://hooptheory.co.uk/EMAILHEADER.png' alt='Hoop Theory Header' style='width:100%;max-width:600px;margin-bottom:20px;border-radius:8px;' />\n<table width='100%' cellpadding='0' cellspacing='0'>\n<tr><td align='center'>\n<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;'>\n<tr><td style='padding:40px 30px;font-family:Arial,sans-serif;color:#000;'>\n<h1 style='margin:0 0 10px;font-size:22px;'>Space Available!</h1>\n<p style='margin:0 0 16px;color:#666;'>Hi <strong>$name</strong>,</p>\n<p style='margin:0 0 16px;color:#333;'>A spot has opened up for the session you were waitlisted for.</p>\n$sessionDetailsHtml\n<p style='margin:0 0 16px;color:#333;'>Would you like to confirm your spot or decline the offer?</p>\n<table class='button-table' width='100%' cellpadding='0' cellspacing='0' style='margin: 30px 0;'>\n    <tr>\n        <td class='button-cell' width='50%' style='padding: 10px 8px; text-align: center;'>\n            <a href='$confirmUrl' style='display: inline-block; padding: 14px 24px; background: #10b981; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 15px; min-width: 140px; box-sizing: border-box;'>Reserve Spot</a>\n        </td>\n        <td class='button-cell' width='50%' style='padding: 10px 8px; text-align: center;'>\n            <a href='$declineUrl' style='display: inline-block; padding: 14px 24px; background: #f44336; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 15px; min-width: 140px; box-sizing: border-box;'>Decline Spot</a>\n        </td>\n    </tr>\n</table>\n<p style='font-size: 12px; color: #666;'>This offer expires in 6 hours. If you don't respond by then, the spot will be offered to the next person on the waitlist.</p>\n<hr style='margin:30px 0;border:none;border-top:1px solid #eee;'>\n<p style='text-align:center;font-size:13px;color:#128C7E;margin-bottom:8px;'>Contact us via WhatsApp: <a href='https://chat.whatsapp.com/FGFRQ3eiH5K73YSW4l3f5x' style='color:#128C7E;font-weight:bold;text-decoration:underline;'>Join Group Chat</a></p>\n<p style='text-align:center;font-size:12px;color:#999;'>© 2026 Hoop Theory · bao@hooptheory.co.uk</p>\n</td></tr></table>\n</td></tr></table>\n</body>\n</html>";
    $mail->send();
    echo json_encode(['success' => true, 'message' => 'Offer email sent']);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    exit(json_encode(['error' => 'Failed to send email', 'message' => $e->getMessage()]));
}

// Add this at the top for error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// After the try-catch block, add a fallback for any unexpected exit
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Fatal error', 'details' => $error['message']]);
    }
});

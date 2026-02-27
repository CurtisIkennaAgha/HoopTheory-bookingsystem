<?php
// Robust error handling: always return JSON
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return;
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'PHP Error',
        'details' => "$errstr in $errfile on line $errline"
    ]);
    exit;
});

set_exception_handler(function($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Exception',
        'details' => $e->getMessage()
    ]);
    exit;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Fatal error', 'details' => $error['message']]);
    }
});

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

// Debug log helper
$debugLogFile = __DIR__ . '/../data/sendOfferEmail_debug.log';
function debug_log($msg) {
    global $debugLogFile;
    file_put_contents($debugLogFile, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}
debug_log('--- sendOfferEmail.php called ---');
debug_log('Input: ' . json_encode($data));
try {
    debug_log('PHPMailer setup...');
    $mail->isSMTP();
    $mail->Host = getenv('MAIL_HOST');
    $mail->SMTPAuth = true;
    $mail->Username = getenv('MAIL_USERNAME');
    $mail->Password = getenv('MAIL_PASSWORD');
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;
    debug_log('SMTP config: host=' . getenv('MAIL_HOST') . ', user=' . getenv('MAIL_USERNAME'));

    $mail->setFrom(getenv('MAIL_FROM_ADDRESS'), 'Hoop Theory');
    $mail->addAddress($email);
    debug_log('Set from and to addresses');

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = "Space Available: $title";
    debug_log('Set subject and HTML');

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
    debug_log('Set email body');
    $mail->send();
    debug_log('Email sent successfully');
    echo json_encode(['success' => true, 'message' => 'Offer email sent']);
    exit;
} catch (Exception $e) {
    debug_log('ERROR: ' . $e->getMessage());
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

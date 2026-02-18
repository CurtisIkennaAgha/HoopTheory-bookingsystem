<?php
header('Content-Type: application/json');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

// Safety: accept only JSON POST and a validated email address. We never read or send to real user lists here.
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$testEmail = $input['email'] ?? '';
if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid test email']);
  exit;
}

// Helper: load minimal env (same as other scripts)
function loadEnv($path) {
  if (!file_exists($path)) return;
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strlen($line) === 0 || $line[0] === '#') continue;
    if (!str_contains($line, '=')) continue;
    [$k,$v] = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v, " \"'"));
  }
}
loadEnv(__DIR__ . '/.env');

// PHPMailer factory
function makeMailer() {
  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->Host = getenv('MAIL_HOST') ?: 'smtp.example.com';
  $mail->SMTPAuth = true;
  $mail->Username = getenv('MAIL_USERNAME') ?: '';
  $mail->Password = getenv('MAIL_PASSWORD') ?: '';
  $mail->SMTPSecure = getenv('MAIL_ENCRYPTION') ?: 'tls';
  $mail->Port = (int) (getenv('MAIL_PORT') ?: 587);
  $mail->setFrom(getenv('MAIL_FROM_ADDRESS') ?: $mail->Username, getenv('MAIL_FROM_NAME') ?: 'Hoop Theory (Debug)');
  $mail->isHTML(true);
  $mail->CharSet = 'utf-8';
  return $mail;
}

$results = [];

// Define representative test messages (no side effects — do NOT write to data files)
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// Builders: replicate the real HTML used in production (safe: no writes, no side-effects)
function buildBookingConfirmationHtml($name, $date, $slot, $title = 'Session') {
    $calendarUrl = htmlspecialchars('https://calendar.google.com/calendar/r/eventedit?text=' . urlencode($title . ' - ' . $name));
    $html = "";
    $html .= "<div style='font-family:Arial,Helvetica,sans-serif;color:#111'>";
    $html .= "<h1 style=\"font-size:22px;margin:0 0 8px;\">Thank you for booking, " . htmlspecialchars($name) . "!</h1>";
    $html .= "<div style='background:#f0f0f0;border-left:4px solid #000;border-radius:8px;padding:18px;margin:18px 0;'>";
    $html .= "<div style='font-size:14px;color:#666;margin-bottom:10px;'>Date: " . htmlspecialchars($date) . "</div>";
    $html .= "<div style='font-size:20px;font-weight:700;color:#000;'>" . htmlspecialchars($slot) . "</div>";
    $html .= "<div style='margin-top:12px;'><a href='" . $calendarUrl . "' style='display:inline-block;padding:10px 16px;background:#111;color:#fff;border-radius:6px;text-decoration:none;'>Add to Google Calendar</a></div>";
    $html .= "</div>";
    $html .= "<p style='color:#555;'>We are excited to work with you — this is a test preview of the booking confirmation template.</p>";
    $html .= "</div>";
    return $html;
}

function buildTemporaryReservationHtml($name, $date, $slot, $paymentRef) {
    $deadline = date('D, j M Y H:i', strtotime('+24 hours'));
    $html = "";
    $html .= "<h1 style='margin:0 0 10px;font-size:22px;'>Booking Reserved – Payment Required</h1>";
    $html .= "<p style='margin:0 0 16px;color:#666;'>Hi " . htmlspecialchars($name) . ",</p>";
    $html .= "<h3 style='margin:12px 0 8px;'>Session Details</h3>";
    $html .= "<table cellpadding='6' cellspacing='0' width='100%' style='background:#fafafa;border:1px solid #eee;border-radius:6px;'>";
    $html .= "<tr><td style='font-weight:bold;width:140px;'>Title</td><td>Basketball Training</td></tr>";
    $html .= "<tr><td style='font-weight:bold;'>Date</td><td>" . htmlspecialchars($date) . "</td></tr>";
    $html .= "<tr><td style='font-weight:bold;'>Time</td><td>" . htmlspecialchars($slot) . "</td></tr>";
    $html .= "<tr><td style='font-weight:bold;'>Location</td><td>Hoop Theory</td></tr>";
    $html .= "<tr><td style='font-weight:bold;'>Price</td><td>£25.00</td></tr>";
    $html .= "</table>";
    $html .= "<h3 style='margin:16px 0 8px;'>Bank Payment Details</h3>";
    $html .= "<table cellpadding='6' cellspacing='0' width='100%' style='background:#fffef6;border:1px solid #f3e8d8;border-radius:6px;'>";
    $html .= "<tr><td style='font-weight:bold;width:150px;'>Account Name</td><td>Hoop Theory</td></tr>";
    $html .= "<tr><td style='font-weight:bold;'>Account Number</td><td>46244409</td></tr>";
    $html .= "<tr><td style='font-weight:bold;'>Sort Code</td><td>569964</td></tr>";
      $html .= "<tr><td style='font-weight:bold;'>Account Name</td><td>Bao Tran</td></tr>";
    $html .= "<tr><td style='font-weight:bold;'>Reference</td><td>" . htmlspecialchars($paymentRef) . "</td></tr>";
    $html .= "<tr><td style='font-weight:bold;'>Payment Deadline</td><td>" . htmlspecialchars($deadline) . "</td></tr>";
    $html .= "</table>";
    $html .= "<p style='margin:12px 0 8px;color:#666;font-size:0.9rem;'><strong>Format for Payment Reference:</strong> FULLNAME-SESSIONNAME</p>";
    $html .= "<p style='margin:16px 0 8px;color:#333;'>You will also be sent an email with these payment details.</p>";
    $html .= "<p style='margin:0 0 16px;color:#000;font-weight:bold;'>Once payment is received, you will receive a final confirmation email.</p>";
    $html .= "<p style='margin:12px 0;color:#b91c1c;font-weight:bold;'>Important: This reservation will expire if payment is not received by the deadline above.</p>";
    $html .= "<p style='margin:12px 0;color:#b91c1c;font-weight:bold;'>Important: please note that refunds will not be issued after your spot has been confirmed.</p>";
    $html .= "<p style='margin:16px 0;text-align:center;padding:15px;background:#f3f4f6;border-radius:6px;border:1px solid #d1d5db;'><a href='https://hooptheory.co.uk/cancel-session.html?bookingId=TEST-ID' style='color:#ef4444;font-weight:bold;text-decoration:underline;'>Can't make this session? Cancel your booking here</a></p>";
    return $html;
}

function buildWaitlistConfirmationHtml($name, $date, $slot, $title, $position) {
    $html = "";
    $html .= "<h1 style='margin:0 0 10px;font-size:26px;'>Waitlist Request Confirmed</h1>";
    $html .= "<p style='margin:0 0 16px;color:#666;font-size:14px;'>Hi " . htmlspecialchars($name) . ",</p>";
    $html .= "<p style='margin:0 0 10px;color:#333;'>Your waitlist request is confirmed.</p>";
    $html .= "<p style='margin:0 0 10px;color:#333;'>You are currently number <strong>" . htmlspecialchars($position) . "</strong> on the waitlist.</p>";
    $html .= "<p style='margin:0 0 10px;color:#333;'>If a place becomes available, you will be notified and asked to complete payment within the stated time window. Unpaid offers may be released.</p>";
    $html .= "<p style='margin:0 0 20px;color:#333;font-weight:bold;'>Please note this is not a booking reservation.</p>";
    $html .= "<table width='100%' cellpadding='0' cellspacing='0' style='background:#f0f0f0;border-left:4px solid #000;border-radius:8px;'>";
    $html .= "<tr><td style='padding:20px;font-family:Arial,sans-serif;'>";
    $html .= "<p style='margin:0;font-size:12px;color:#999;font-weight:bold;'>DATE AND TIME</p>";
    $html .= "<p style='margin:8px 0 0;font-size:20px;color:#000;font-weight:bold;'>" . htmlspecialchars($slot) . "</p>";
    $html .= "<p style='margin:4px 0 16px;font-size:14px;color:#666;'>" . htmlspecialchars($date) . "</p>";
    $html .= "</td></tr></table>";
    return $html;
}

function buildCustomAdminEmailHtml($message) {
    $html = "";
    $html .= "<div style='font-family:Arial,Helvetica,sans-serif;color:#111'>";
    $html .= "<div style='background:linear-gradient(135deg,#4f46e5 0%,#6366f1 100%);padding:20px;color:#fff;border-radius:8px 8px 0 0;text-align:center;'><h1 style=\"margin:0;font-size:20px;\">Hoop Theory — Admin Message</h1></div>";
    $html .= "<div style='background:#fff;padding:20px;border-radius:0 0 8px 8px;'>";
    $html .= "<div style='background:#f5f5f5;padding:12px;border-radius:6px;border-left:4px solid #4f46e5;color:#333;white-space:pre-wrap;'>" . htmlspecialchars($message) . "</div>";
    $html .= "</div></div>";
    return $html;
}

function buildAdminConfirmationHtml($name, $date, $slot, $title, $price, $location) {
    $calendarUrl = htmlspecialchars('https://calendar.google.com/calendar/r/eventedit?text=' . urlencode($title . ' - ' . $name));
    $html = "";
    $html .= "<h1 style='margin:0 0 10px;font-size:26px;'>Thank you for booking, " . htmlspecialchars($name) . "!</h1>";
    $html .= "<p style='margin:0 0 30px;color:#666;font-size:14px;'>Your session is confirmed and we cannot wait to see you.</p>";
    $html .= "<table width='100%' cellpadding='0' cellspacing='0' style='background:#f0f0f0;border-left:4px solid #000;border-radius:8px;'>";
    $html .= "<tr><td style='padding:20px;font-family:Arial,sans-serif;'>";
    $html .= "<p style='margin:0;font-size:12px;color:#999;font-weight:bold;'>DATE AND TIME</p>";
    $html .= "<p style='margin:8px 0 0;font-size:20px;color:#000;font-weight:bold;'>" . htmlspecialchars($slot) . "</p>";
    $html .= "<p style='margin:4px 0 16px;font-size:14px;color:#666;'>" . htmlspecialchars($date) . "</p>";
    $html .= "<a href='" . $calendarUrl . "' style='display:inline-block;padding:12px 22px;background:#1a1a1a;color:#ffffff !important;text-decoration:none;border-radius:6px;font-size:14px;font-weight:bold;'>Add to Google Calendar</a>";
    $html .= "</td></tr></table>";
    $html .= "<p style='margin:30px 0 20px;font-size:14px;color:#555;line-height:1.6;'>We are excited to work with you! If you need to reschedule or have any questions, feel free to reach out to us.</p>";
    $html .= "<div style='text-align:center;'><a href='https://instagram.com/hoop.theory' style='display:inline-block;padding:12px 24px;background:#eeeeee;color:#000 !important;text-decoration:none;border-radius:6px;font-size:14px;font-weight:bold;'>Follow us on Instagram</a></div>";
    $html .= "<p style='margin:20px 0;text-align:center;padding:15px;background:#f3f4f6;border-radius:6px;border:1px solid #d1d5db;'><a href='https://hooptheory.co.uk/cancel-session.html?bookingId=TEST-ID' style='color:#ef4444;font-weight:bold;text-decoration:underline;'>Need to cancel? Click here</a></p>";
    return $html;
}

function buildCancellationEmailHtml($name, $date, $slot, $title) {
    $html = "";
    $html .= "<div style='font-family:Arial,Helvetica,sans-serif;color:#111'>";
    $html .= "<h1 style=\"font-size:22px;margin:0 0 8px;\">Booking Cancelled</h1>";
    $html .= "<p style='margin:0 0 20px;color:#666;font-size:14px;'>Your booking has been successfully cancelled.</p>";
    $html .= "<div style='background:#f9f9f9;border-left:4px solid #ef4444;border-radius:8px;padding:18px;margin:18px 0;'>";
    $html .= "<div style='font-size:12px;color:#666;font-weight:bold;text-transform:uppercase;margin-bottom:10px;'>Session Details</div>";
    $html .= "<div style='background:white;border-radius:6px;padding:15px;'>";
    $html .= "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Title:</span> " . htmlspecialchars($title) . "</div>";
    $html .= "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Date:</span> " . htmlspecialchars($date) . "</div>";
    $html .= "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Time:</span> " . htmlspecialchars($slot) . "</div>";
    $html .= "</div>";
    $html .= "</div>";
    $html .= "<p style='color:#666;margin:20px 0;'>The session spot is now available for other players. If you had made a payment for this booking, please contact us at <strong>bao@hooptheory.co.uk</strong> to arrange a refund.</p>";
    $html .= "<p><a href='https://hooptheory.co.uk/user.html' style='color:#667eea;font-weight:bold;text-decoration:none;'>Return to booking page →</a></p>";
    $html .= "</div>";
    return $html;
}

$bookingHtml = buildAdminConfirmationHtml('Test User', $tomorrow, '14:00', 'Basketball Training', '25.00', 'Hoop Theory');
$reservationHtml = buildTemporaryReservationHtml('Test User', $tomorrow, '14:00', 'TESTUSER-BASKETBALLTRAINING');
$waitlistHtml = buildWaitlistConfirmationHtml('Test User', $tomorrow, '14:00', 'Basketball Training', '3');
$cancellationHtml = buildCancellationEmailHtml('Test User', $tomorrow, '14:00', 'Basketball Training');
$customHtml = buildCustomAdminEmailHtml("This is a test message — ignore.");

$tests = [
  ['template' => 'admin_confirmation', 'subject' => "Session Confirmed (Test)", 'html' => $bookingHtml],
  ['template' => 'temporary_reservation', 'subject' => "Reservation (Payment Required) — Test", 'html' => $reservationHtml],
  ['template' => 'waitlist_confirmation', 'subject' => "Waitlist Request Confirmed — Test", 'html' => $waitlistHtml],
  ['template' => 'cancellation', 'subject' => "Booking Cancelled – Confirmation (Test)", 'html' => $cancellationHtml],
  ['template' => 'admin_message', 'subject' => "Admin Message (Test)", 'html' => $customHtml],
];

// If SMTP credentials are not present we still attempt to "mock" sending by returning simulated success
$smtpConfigured = (bool) (getenv('MAIL_USERNAME') && getenv('MAIL_PASSWORD') && getenv('MAIL_HOST'));

foreach ($tests as $t) {
  $entry = ['template' => $t['template'], 'recipient' => $testEmail, 'ok' => false, 'message' => ''];
  // Provide the full HTML preview to the UI (safe — no side effects)
  $entry['htmlPreview'] = $t['html'];

  try {
    if (!$smtpConfigured) {
      // Mock send: do not attempt SMTP; just report simulated success
      $entry['ok'] = true;
      $entry['message'] = 'SMTP not configured on server — simulated send (no network activity).';
      error_log("[sendAllTestEmails] simulated: {$t['template']} -> {$testEmail}");
      $results[] = $entry;
      continue;
    }

    $mail = makeMailer();
    $mail->clearAddresses();
    $mail->addAddress($testEmail);
    $mail->Subject = $t['subject'];
    $mail->Body = "<div style='font-family:Arial,Helvetica,sans-serif;color:#111'>" . $t['html'] . "<hr style='border:none;border-top:1px solid #eee;margin:14px 0'><small style='color:#666'>This is a test message generated by the Hoop Theory debug page.</small></div>";

    if ($mail->send()) {
      $entry['ok'] = true;
      $entry['message'] = 'Sent';
      error_log("[sendAllTestEmails] sent: {$t['template']} -> {$testEmail}");
    } else {
      $entry['ok'] = false;
      $entry['message'] = 'Failed: ' . ($mail->ErrorInfo ?? 'unknown');
      error_log("[sendAllTestEmails] failed: {$t['template']} -> {$testEmail} : " . ($mail->ErrorInfo ?? 'no-info'));
    }
  } catch (Exception $e) {
    $entry['ok'] = false;
    $entry['message'] = 'Exception: ' . $e->getMessage();
    error_log("[sendAllTestEmails] exception for {$t['template']}: " . $e->getMessage());
  }

  $results[] = $entry;
}

// Return structured results for the debug UI
http_response_code(200);
echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);

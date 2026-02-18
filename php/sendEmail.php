<?php
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
loadEnv(__DIR__ . '/.env');

// Debug log for received payload
$rawInput = file_get_contents('php://input');
error_log('[sendEmail.php] Raw input: ' . $rawInput);
$data  = json_decode($rawInput, true);
error_log('[sendEmail.php] Decoded payload: ' . json_encode($data));

$email = $data['email'] ?? '';
$slot  = $data['slot'] ?? '';
$date  = $data['date'] ?? '';
$name  = $data['name'] ?? 'Guest';
$title = $data['title'] ?? 'Session';
$blockDates = $data['blockDates'] ?? []; // Array of dates for block sessions
$adminMessage = $data['adminMessage'] ?? ''; // Optional message from admin
$emailType = $data['type'] ?? 'confirmation';
$paymentRef = $data['paymentRef'] ?? null;
$paymentDeadline = $data['deadline'] ?? null;
$price = $data['price'] ?? null;
$location = $data['location'] ?? null;
$bookingId = $data['bookingId'] ?? '';
$waitlistPosition = $data['waitlistPosition'] ?? '';

// If bookingId is not provided, look it up from bookingMappings.json
if (empty($bookingId) && !empty($email) && !empty($slot) && !empty($title)) {
    $mappingsFile = __DIR__ . '/../data/bookingMappings.json';
    if (file_exists($mappingsFile)) {
        $mappings = json_decode(file_get_contents($mappingsFile), true);
        if ($mappings) {
            foreach ($mappings as $bid => $booking) {
                $dateMatch = $booking['date'] === $date || 
                            (!empty($blockDates) && $booking['date'] === $blockDates[0]);
                if ($booking['email'] === $email && 
                    $booking['slot'] === $slot && 
                    $booking['title'] === $title &&
                    $dateMatch) {
                    $bookingId = $bid;
                    error_log('Found bookingId from mappings: ' . $bookingId);
                    break;
                }
            }
        }
    }
    if (empty($bookingId)) {
        error_log('WARNING: No bookingId found for email: ' . $email . ', slot: ' . $slot . ', title: ' . $title);
    }
}

error_log('SendEmail - type: ' . $emailType . ', paymentRef: ' . $paymentRef . ', deadline: ' . $paymentDeadline . ', bookingId: ' . $bookingId);

header('Content-Type: application/json');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid email',
        'email' => $email
    ]);
    exit;
}
if (!$slot || !$date) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing data',
        'email' => $email
    ]);
    exit;
}

// Handle booking_edited email type
if ($emailType === 'booking_edited') {
    error_log('Processing booking_edited email type');
    $changedFields = $data['changedFields'] ?? [];
    $fieldLabels = [
        'price' => 'Price',
        'location' => 'Location',
        'time' => 'Time',
        'date' => 'Date',
        'title' => 'Session Title',
        'capacity' => 'Capacity',
        'sessionType' => 'Session Type',
        'duration' => 'Duration',
    ];
    $changesHtml = '';
    if (!empty($changedFields) && is_array($changedFields)) {
        $changesHtml .= "<ul style='margin:10px 0 20px 20px;padding:0;color:#2563eb;font-size:15px;'>";
        foreach ($changedFields as $field => $change) {
            $label = $fieldLabels[$field] ?? ucfirst($field);
            $old = htmlspecialchars($change['old'] ?? '');
            $new = htmlspecialchars($change['new'] ?? '');
            $changesHtml .= "<li><strong>$label:</strong> <span style='color:#b91c1c;text-decoration:line-through;'>$old</span> → <span style='color:#059669;font-weight:bold;'>$new</span></li>";
        }
        $changesHtml .= "</ul>";
    } else {
        $changesHtml = '<p style="color:#b91c1c;">Session details have changed.</p>';
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = getenv('MAIL_HOST');
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('MAIL_USERNAME');
        $mail->Password   = getenv('MAIL_PASSWORD');
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom(getenv('MAIL_FROM_ADDRESS'), 'Hoop Theory');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = "Session Details Updated – Hoop Theory";
        $mail->Body = "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#f5f5f5;'>"
            . "<table width='100%' cellpadding='0' cellspacing='0'><tr><td align='center'>"
            . "<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;'><tr><td style='padding:40px 30px;font-family:Arial,sans-serif;color:#000;'>"
            . "<h1 style='margin:0 0 10px;font-size:24px;color:#2563eb;'>Session Details Changed</h1>"
            . "<p style='margin:0 0 18px;color:#444;font-size:15px;'>The details for a session you are signed up to have changed. Please review the updated information below:</p>"
            . $changesHtml
            . "<table width='100%' cellpadding='0' cellspacing='0' style='background:#f9f9f9;border-left:4px solid #2563eb;border-radius:8px;'><tr><td style='padding:20px;font-family:Arial,sans-serif;'>"
            . "<p style='margin:0 0 10px;font-size:12px;color:#666;font-weight:bold;text-transform:uppercase;'>Session Details</p>"
            . "<div style='background:white;border-radius:6px;padding:15px;margin:10px 0 0;'>"
            . "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Title:</span> " . htmlspecialchars($title) . "</div>"
            . "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Date:</span> " . htmlspecialchars($date) . "</div>"
            . "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Time:</span> " . htmlspecialchars($slot) . "</div>"
            . "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Location:</span> " . htmlspecialchars($location) . "</div>"
            . "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Price:</span> £" . htmlspecialchars($price) . "</div>"
            . "</div></td></tr></table>"
            . "<p style='margin:20px 0 10px;color:#666;font-size:14px;'>If you have any questions or can no longer attend, please contact us at <strong>bao@hooptheory.co.uk</strong>.</p>"
            . "<hr style='margin:30px 0;border:none;border-top:1px solid #eee;'>"
            . "<p style='text-align:center;font-size:13px;color:#128C7E;margin-bottom:8px;'>Contact us via WhatsApp: <a href='https://chat.whatsapp.com/FGFRQ3eiH5K73YSW4l3f5x' style='color:#128C7E;font-weight:bold;text-decoration:underline;'>Join Group Chat</a></p>"
            . "<p style='text-align:center;font-size:12px;color:#999;'>© 2026 Hoop Theory · bao@hooptheory.co.uk</p>"
            . "</td></tr></table></td></tr></table></body></html>";
        $mail->send();
        error_log('booking_edited email sent successfully to: ' . $email);
        echo json_encode(['success' => true, 'email' => $email]);
    } catch (Exception $e) {
        error_log('Mailer Error (booking_edited): ' . $mail->ErrorInfo);
        echo json_encode(['success' => false, 'error' => $mail->ErrorInfo, 'email' => $email]);
    }
    exit;
}

function createGoogleCalendarUrl($date, $startTime, $endTime, $title, $name) {
    [$y,$m,$d] = explode('-', $date);
    [$sh,$sm] = explode(':', $startTime);
    [$eh,$em] = explode(':', $endTime);

    $start = sprintf('%04d%02d%02dT%02d%02d00', $y,$m,$d,$sh,$sm);
    $end   = sprintf('%04d%02d%02dT%02d%02d00', $y,$m,$d,$eh,$em);

    return 'https://calendar.google.com/calendar/r/eventedit'
        . '?text=' . urlencode("$title - $name")
        . '&dates=' . $start . '/' . $end
        . '&details=' . urlencode('Session booking confirmed via Hoop Theory')
        . '&location=' . urlencode('Hoop Theory');
}

// Handle booking_cancellation email type
if ($emailType === 'booking_cancellation') {
    error_log('Processing booking_cancellation email type');
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = getenv('MAIL_HOST');
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('MAIL_USERNAME');
        $mail->Password   = getenv('MAIL_PASSWORD');
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom(getenv('MAIL_FROM_ADDRESS'), 'Hoop Theory');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = "Session Cancelled – Hoop Theory";
        $mail->Body = "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#f5f5f5;'>"
            . "<table width='100%' cellpadding='0' cellspacing='0'><tr><td align='center'>"
            . "<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;'><tr><td style='padding:40px 30px;font-family:Arial,sans-serif;color:#000;'>"
            . "<h1 style='margin:0 0 10px;font-size:26px;color:#ef4444;'>Session Cancelled</h1>"
            . "<p style='margin:0 0 30px;color:#666;font-size:14px;'>Your booking for the session below has been cancelled by the administrator.</p>"
            . "<table width='100%' cellpadding='0' cellspacing='0' style='background:#f9f9f9;border-left:4px solid #ef4444;border-radius:8px;'><tr><td style='padding:20px;font-family:Arial,sans-serif;'>"
            . "<p style='margin:0 0 10px;font-size:12px;color:#666;font-weight:bold;text-transform:uppercase;'>Session Details</p>"
            . "<div style='background:white;border-radius:6px;padding:15px;margin:10px 0 0;'>"
            . "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Title:</span> " . htmlspecialchars($title) . "</div>"
            . "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Date:</span> " . htmlspecialchars($date) . "</div>"
            . "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Time:</span> " . htmlspecialchars($slot) . "</div>"
            . "</div></td></tr></table>"
            . "<p style='margin:20px 0 10px;color:#666;font-size:14px;line-height:1.6;'>If you had made a payment for this booking, please contact us at <strong>bao@hooptheory.co.uk</strong> to arrange a refund.</p>"
            . "<p style='margin:20px 0 10px;color:#666;font-size:14px;'>If you'd like to rebook or have any questions, feel free to visit our <a href='https://hooptheory.co.uk/user.html' style='color:#667eea;font-weight:bold;text-decoration:none;'>booking page</a>.</p>"
            . "<hr style='margin:30px 0;border:none;border-top:1px solid #eee;'>"
            . "<p style='text-align:center;font-size:12px;color:#999;'>© 2026 Hoop Theory · bao@hooptheory.co.uk</p>"
            . "</td></tr></table></td></tr></table></body></html>";
        $mail->send();
        error_log('booking_cancellation email sent successfully to: ' . $email);
        echo json_encode(['success' => true, 'email' => $email]);
    } catch (Exception $e) {
        error_log('Mailer Error (booking_cancellation): ' . $mail->ErrorInfo);
        echo json_encode(['success' => false, 'error' => $mail->ErrorInfo, 'email' => $email]);
    }
    exit;
}

// Handle cancellation email type first
if ($emailType === 'cancellation') {
    error_log('Processing cancellation email type');
    $cancellationReason = $data['cancellationReason'] ?? '';

    $mail->isSMTP();
    $mail->Host       = getenv('MAIL_HOST');
    $mail->SMTPAuth   = true;
    $mail->Username   = getenv('MAIL_USERNAME');
    $mail->Password   = getenv('MAIL_PASSWORD');
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom(getenv('MAIL_FROM_ADDRESS'), 'Hoop Theory');
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = "Booking Cancelled – Confirmation";

    $mail->Body = "<!DOCTYPE html>
<html>
<body style='margin:0;padding:0;background:#f5f5f5;'>
<table width='100%' cellpadding='0' cellspacing='0'>
<tr>
<td align='center'>

<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;'>
<tr>
<td style='padding:40px 30px;font-family:Arial,sans-serif;color:#000;'>

<h1 style='margin:0 0 10px;font-size:26px;'>Booking Cancelled</h1>
<p style='margin:0 0 30px;color:#666;font-size:14px;'>
Your booking has been successfully cancelled.
</p>

<table width='100%' cellpadding='0' cellspacing='0' style='background:#f9f9f9;border-left:4px solid #ef4444;border-radius:8px;'>
<tr>
<td style='padding:20px;font-family:Arial,sans-serif;'>
<p style='margin:0 0 10px;font-size:12px;color:#666;font-weight:bold;text-transform:uppercase;'>Session Details</p>

<div style='background:white;border-radius:6px;padding:15px;margin:10px 0 0;'>
<div style='margin:10px 0;'>
<span style='font-weight:bold;color:#555;'>Title:</span> " . htmlspecialchars($title) . "
</div>
<div style='margin:10px 0;'>
<span style='font-weight:bold;color:#555;'>Date:</span> " . htmlspecialchars($date) . "
</div>
<div style='margin:10px 0;'>
<span style='font-weight:bold;color:#555;'>Time:</span> " . htmlspecialchars($slot) . "
</div>
</div>
</td>
</tr>
</table>

<p style='margin:20px 0 10px;color:#666;font-size:14px;line-height:1.6;'>
The session spot is now available for other players. If you had made a payment for this booking, 
please contact us at <strong>bao@hooptheory.co.uk</strong> to arrange a refund.
</p>";

    if (!empty($cancellationReason)) {
        $mail->Body .= "
<div style='background:#f3f4f6;border-left:4px solid #3b82f6;padding:15px;margin:20px 0;border-radius:6px;font-family:Arial,sans-serif;'>
<p style='margin:0 0 10px;font-weight:bold;color:#1f2937;font-size:13px;text-transform:uppercase;'>Your Feedback</p>
<p style='margin:0;color:#4b5563;font-size:14px;line-height:1.6;'>" . nl2br(htmlspecialchars($cancellationReason)) . "</p>
</div>
";
    }

    $mail->Body .= "
<p style='margin:20px 0 10px;color:#666;font-size:14px;'>
If you'd like to rebook or have any questions, feel free to visit our <a href='https://hooptheory.co.uk/user.html' style='color:#667eea;font-weight:bold;text-decoration:none;'>booking page</a>.
</p>

<hr style='margin:30px 0;border:none;border-top:1px solid #eee;'>


<p style='text-align:center;font-size:13px;color:#128C7E;margin-bottom:8px;'>
Contact us via WhatsApp: 
<a href='https://chat.whatsapp.com/FGFRQ3eiH5K73YSW4l3f5x' style='color:#128C7E;font-weight:bold;text-decoration:underline;'>Join Group Chat</a>
</p>
<p style='text-align:center;font-size:12px;color:#999;'>
© 2026 Hoop Theory · bao@hooptheory.co.uk
</p>

</td>
</tr>
</table>

</td>
</tr>
</table>
</body>
</html>";

    try {
        $mail->send();
        error_log('Cancellation email sent successfully to: ' . $email);
        echo json_encode(['success' => true, 'email' => $email]);
    } catch (Exception $e) {
        error_log('Mailer Error: ' . $mail->ErrorInfo);
        echo json_encode(['success' => false, 'error' => $mail->ErrorInfo, 'email' => $email]);
    }
    exit;
}

// Check if this is a block session
$isBlockSession = !empty($blockDates) && count($blockDates) > 1;

$endTime = '00:00';
$slotsFile = __DIR__ . '/../data/availableSlots.json';
if (file_exists($slotsFile)) {
    $slots = json_decode(file_get_contents($slotsFile), true);
    foreach ($slots[$date] ?? [] as $s) {
        if ($s['time'] === $slot) {
            $endTime = $s['endTime'];
            break;
        }
    }
}

$calendarUrl = createGoogleCalendarUrl($date, $slot, $endTime, $title, $name);

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = getenv('MAIL_HOST');
    $mail->SMTPAuth   = true;
    $mail->Username   = getenv('MAIL_USERNAME');
    $mail->Password   = getenv('MAIL_PASSWORD');
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom(getenv('MAIL_FROM_ADDRESS'), 'Hoop Theory');
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

        // Handle different email types
        if ($emailType === 'session_deleted' || $emailType === 'booking_cancellation') {
            if ($emailType === 'booking_cancellation') error_log('Processing booking_cancellation email type (new handler)');
            error_log('Processing session_deleted email type');
            $mail->Subject = "Session Cancelled – Hoop Theory";
            $mail->Body = "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#f5f5f5;'>"
                . "<table width='100%' cellpadding='0' cellspacing='0'><tr><td align='center'>"
                . "<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;'><tr><td style='padding:40px 30px;font-family:Arial,sans-serif;color:#000;'>"
                . "<h1 style='margin:0 0 10px;font-size:26px;color:#ef4444;'>Session Cancelled</h1>"
                . "<p style='margin:0 0 30px;color:#666;font-size:14px;'>"
                . "We regret to inform you that your booked session has been cancelled by the administrator."
                . "</p>"
                . "<table width='100%' cellpadding='0' cellspacing='0' style='background:#f9f9f9;border-left:4px solid #ef4444;border-radius:8px;'><tr><td style='padding:20px;font-family:Arial,sans-serif;'>"
                . "<p style='margin:0 0 10px;font-size:12px;color:#666;font-weight:bold;text-transform:uppercase;'>Session Details</p>"
                . "<div style='background:white;border-radius:6px;padding:15px;margin:10px 0 0;'>"
                . "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Title:</span> " . htmlspecialchars($title) . "</div>"
                . "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Date:</span> " . htmlspecialchars($date) . "</div>"
                . "<div style='margin:10px 0;'><span style='font-weight:bold;color:#555;'>Time:</span> " . htmlspecialchars($slot) . "</div>"
                . "</div></td></tr></table>"
                . "<p style='margin:20px 0 10px;color:#666;font-size:14px;line-height:1.6;'>"
                . "If you had made a payment for this booking, please contact us at <strong>bao@hooptheory.co.uk</strong> to arrange a refund."
                . "</p>"
                . "<p style='margin:20px 0 10px;color:#666;font-size:14px;'>"
                . "If you'd like to rebook or have any questions, feel free to visit our <a href='https://hooptheory.co.uk/user.html' style='color:#667eea;font-weight:bold;text-decoration:none;'>booking page</a>."
                . "</p>"
                . "<hr style='margin:30px 0;border:none;border-top:1px solid #eee;'>"
                . "<p style='text-align:center;font-size:12px;color:#999;'>© 2026 Hoop Theory · bao@hooptheory.co.uk</p>"
                . "</td></tr></table></td></tr></table></body></html>";
            try {
                $mail->send();
                error_log('session_deleted email sent successfully to: ' . $email);
                echo json_encode(['success' => true, 'email' => $email]);
            } catch (Exception $e) {
                error_log('Mailer Error (session_deleted): ' . $mail->ErrorInfo);
                echo json_encode(['success' => false, 'error' => $mail->ErrorInfo, 'email' => $email]);
            }
            exit;
        }
        if ($emailType === 'temporary_reservation') {
        error_log('Processing temporary_reservation email type');
        // Ensure we have a payment reference and deadline
        if (!$paymentRef) {
            $paymentRef = 'HT-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6));
            error_log('Generated paymentRef: ' . $paymentRef);
        }
        if (!$paymentDeadline) {
            // Load expirySeconds from bookingExpiryConfig.json
            $expiryConfigFile = __DIR__ . '/../data/bookingExpiryConfig.json';
            $expirySeconds = 86400; // fallback 24h
            if (file_exists($expiryConfigFile)) {
                $expiryConfig = json_decode(file_get_contents($expiryConfigFile), true);
                if (isset($expiryConfig['expirySeconds'])) {
                    $expirySeconds = (int)$expiryConfig['expirySeconds'];
                }
            }
            $paymentDeadline = date('D, j M Y, H:i', time() + $expirySeconds);
            error_log('Generated deadline from config: ' . $paymentDeadline . ' (expirySeconds=' . $expirySeconds . ')');
        }

        $mail->Subject = "Booking Reserved – Payment Required";

        // Use provided details or fallbacks
        $displayPrice = $price ? htmlspecialchars((string)$price) : '';
        $displayLocation = $location ? htmlspecialchars((string)$location) : '';

        $mail->Body = "<!DOCTYPE html>
<html>
<body style='margin:0;padding:0;background:#f5f5f5;'>
<table width='100%' cellpadding='0' cellspacing='0'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;'>
<tr><td style='padding:30px;font-family:Arial,sans-serif;color:#000;'>
<h1 style='margin:0 0 10px;font-size:22px;'>Booking Reserved – Payment Required</h1>
<p style='margin:0 0 16px;color:#666;'>Hi " . htmlspecialchars($name) . ",</p>

<h3 style='margin:12px 0 8px;'>Session Details</h3>
<table cellpadding='6' cellspacing='0' width='100%' style='background:#fafafa;border:1px solid #eee;border-radius:6px;'>
<tr><td style='font-weight:bold;width:140px;'>Title</td><td>" . htmlspecialchars($title) . "</td></tr>
<tr><td style='font-weight:bold;'>Date</td><td>" . htmlspecialchars($date) . "</td></tr>
<tr><td style='font-weight:bold;'>Time</td><td>" . htmlspecialchars($slot) . "</td></tr>" .
($displayLocation ? "<tr><td style='font-weight:bold;'>Location</td><td>$displayLocation</td></tr>" : "") .
($displayPrice ? "<tr><td style='font-weight:bold;'>Price</td><td>$displayPrice</td></tr>" : "") .
"</table>

<h3 style='margin:16px 0 8px;'>Bank Payment Details</h3>
<table cellpadding='6' cellspacing='0' width='100%' style='background:#fffef6;border:1px solid #f3e8d8;border-radius:6px;'>
<tr><td style='font-weight:bold;width:150px;'>Account Name</td><td>Hoop Theory</td></tr>
<tr><td style='font-weight:bold;'>Account Number</td><td>46244409</td></tr>
<tr><td style='font-weight:bold;'>Sort Code</td><td>560064</td></tr>
<tr><td style='font-weight:bold;'>Account Name</td><td>Bao Tran</td></tr>
<tr><td style='font-weight:bold;'>Reference</td><td>" . htmlspecialchars($paymentRef) . "</td></tr>
<tr><td style='font-weight:bold;'>Payment Deadline</td><td>" . htmlspecialchars($paymentDeadline) . "</td></tr>
</table>

<p style='margin:12px 0 8px;color:#666;font-size:0.9rem;'><strong>Format for Payment Reference:</strong> FULLNAME-SESSIONNAME</p>

<p style='margin:16px 0 8px;color:#333;'>You will also be sent an email with these payment details.</p>
<p style='margin:0 0 16px;color:#000;font-weight:bold;'>Once payment is received, you will receive a final confirmation email.</p>

<p style='margin:12px 0;color:#b91c1c;font-weight:bold;'>Important: This reservation will expire if payment is not received by the deadline above. Additionally, please note that refunds will not be issued after your spot has been confirmed.</p>

<p style='margin:16px 0;text-align:center;padding:15px;background:#f3f4f6;border-radius:6px;border:1px solid #d1d5db;'>
<a href='https://hooptheory.co.uk/cancel-session.html?bookingId=" . htmlspecialchars($bookingId) . "' style='color:#ef4444;font-weight:bold;text-decoration:underline;'>Can't make this session? Cancel your booking here</a>
</p>

<hr style='margin:20px 0;border:none;border-top:1px solid #eee;'>
<p style='font-size:12px;color:#999;text-align:center;'>© 2026 Hoop Theory · bao@hooptheory.co.uk</p>

</td></tr></table>
</td></tr></table>
</body>
</html>";

        $mail->send();
        error_log('temporary_reservation email sent successfully to: ' . $email);
        echo json_encode(['success' => true, 'message' => 'Email sent']);
        exit;
    }

    // Build session details HTML based on session type
    if ($isBlockSession) {
        $sessionDetailsHtml = "
<table width='100%' cellpadding='0' cellspacing='0' style='background:#fff8f0;border-left:6px solid #ff9800;border-radius:8px;'>
<tr>
<td style='padding:20px;font-family:Arial,sans-serif;'>
<p style='margin:0;font-size:12px;color:#e65100;font-weight:bold;text-transform:uppercase;'>4-Week Block Session</p>
<p style='margin:8px 0 15px;font-size:18px;color:#000;font-weight:bold;'>$title</p>
<p style='margin:8px 0 15px;font-size:14px;color:#666;'><strong>Time:</strong> $slot</p>
<p style='margin:8px 0 15px;font-size:13px;color:#333;font-weight:bold;'>All Session Dates:</p>
<table width='100%' cellpadding='0' cellspacing='0' style='background:white;border-radius:6px;overflow:hidden;'>
";
        foreach ($blockDates as $blockDate) {
            $sessionDetailsHtml .= "
<tr>
<td style='padding:10px 15px;border-bottom:1px solid #ffe0b2;font-family:Arial,sans-serif;color:#333;'>
✓ $blockDate
</td>
</tr>
";
        }
        $sessionDetailsHtml .= "
</table>
</td>
</tr>
</table>
";
    } else {
        $sessionDetailsHtml = "
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f0f0f0;border-left:4px solid #000;border-radius:8px;'>
<tr>
<td style='padding:20px;font-family:Arial,sans-serif;'>
<p style='margin:0;font-size:12px;color:#999;font-weight:bold;'>DATE AND TIME</p>
<p style='margin:8px 0 0;font-size:20px;color:#000;font-weight:bold;'>$slot</p>
<p style='margin:4px 0 16px;font-size:14px;color:#666;'>$date</p>

<a href='$calendarUrl'
style='display:inline-block;padding:12px 22px;background:#1a1a1a;color:#ffffff !important;
text-decoration:none;border-radius:6px;font-size:14px;font-weight:bold;'>
Add to Google Calendar
</a>
</td>

<td align='right' style='padding:20px;'>

</td>
</tr>
</table>
";
    }

    if ($emailType === 'waitlist_confirmation') {
        $displayPosition = !empty($waitlistPosition) ? htmlspecialchars((string)$waitlistPosition) : 'N/A';

        $mail->Subject = "Space Available: $title";
        $mail->Body = "
<!DOCTYPE html>
<html>
<body style='margin:0;padding:0;background:#f5f5f5;'>
<table width='100%' cellpadding='0' cellspacing='0'>
<tr>
<td align='center'>

<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;'>
<tr>
<td style='padding:40px 30px;font-family:Arial,sans-serif;color:#000;'>

<h1 style='margin:0 0 10px;font-size:26px;'>Waitlist Request Confirmed</h1>
<p style='margin:0 0 16px;color:#666;font-size:14px;'>Hi " . htmlspecialchars($name) . ",</p>

<p style='margin:0 0 10px;color:#333;'>Your waitlist request is confirmed.</p>
<p style='margin:0 0 10px;color:#333;'>You are currently number <strong>" . $displayPosition . "</strong> on the waitlist.</p>
<p style='margin:0 0 10px;color:#333;'>If a place becomes available, you will be notified and asked to complete payment within the stated time window. Unpaid offers may be released.</p>
<p style='margin:0 0 20px;color:#333;font-weight:bold;'>Please note this is not a booking reservation.</p>

$sessionDetailsHtml

<hr style='margin:30px 0;border:none;border-top:1px solid #eee;'>

<p style='text-align:center;font-size:12px;color:#999;'>
© 2026 Hoop Theory · bao@hooptheory.co.uk
</p>

</td>
</tr>
</table>

</td>
</tr>
</table>
</body>
</html>";

        $mail->send();
        echo json_encode(['success' => true, 'message' => 'Email sent']);
        exit;
    }

    $mail->Body = "
<!DOCTYPE html>
<html>
<body style='margin:0;padding:0;background:#f5f5f5;'>
<table width='100%' cellpadding='0' cellspacing='0'>
<tr>
<td align='center'>

<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;'>
<tr>
<td style='padding:40px 30px;font-family:Arial,sans-serif;color:#000;'>

<h1 style='margin:0 0 10px;font-size:26px;'>Thank you for booking, $name!</h1>
<p style='margin:0 0 30px;color:#666;font-size:14px;'>
Your session is confirmed and we cannot wait to see you.
</p>

$sessionDetailsHtml

<p style='margin:30px 0 20px;font-size:14px;color:#555;line-height:1.6;'>
We are excited to work with you! If you have any questiosn dont hesitate to reach out.
</p>
";

    // Add admin message if provided
    if (!empty($adminMessage)) {
        $mail->Body .= "
<div style='background:#f0fdf4;border-left:4px solid #10b981;padding:15px;margin:20px 0;border-radius:6px;font-family:Arial,sans-serif;'>
<p style='margin:0 0 10px;font-weight:bold;color:#065f46;font-size:13px;'>Message from Hoop Theory:</p>
<p style='margin:0;color:#1f2937;font-size:14px;line-height:1.6;'>" . nl2br(htmlspecialchars($adminMessage)) . "</p>
</div>
";
    }

    $mail->Body .= "
<div style='text-align:center;'>
<a href='https://instagram.com/hoop.theory'
style='display:inline-block;padding:12px 24px;background:#eeeeee;color:#000 !important;
text-decoration:none;border-radius:6px;font-size:14px;font-weight:bold;'>
Follow us on Instagram
</a>
</div>

<p style='margin:20px 0;text-align:center;padding:15px;background:#f3f4f6;border-radius:6px;border:1px solid #d1d5db;'>
<a href='https://hooptheory.co.uk/cancel-session.html?bookingId=" . htmlspecialchars($bookingId) . "' style='color:#ef4444;font-weight:bold;text-decoration:underline;'>Need to cancel? Click here</a>
</p>

<hr style='margin:40px 0;border:none;border-top:1px solid #eee;'>

<p style='text-align:center;font-size:12px;color:#999;'>
© 2026 Hoop Theory · bao@hooptheory.co.uk
</p>

</td>
</tr>
</table>

</td>
</tr>
</table>
</body>
</html>";

    $mail->send();
    echo json_encode(['success' => true, 'message' => 'Email sent']);
} catch (Exception $e) {
    error_log('Mailer Error: ' . $mail->ErrorInfo);
    echo json_encode(['success' => false, 'error' => $mail->ErrorInfo, 'email' => $email]);
}
exit;

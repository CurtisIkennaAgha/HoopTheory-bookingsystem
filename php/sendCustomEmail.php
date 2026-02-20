<?php
header('Content-Type: application/json');

require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function loadEnv() {
  $envFile = __DIR__ . '/.env';
  $env = [];
  if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
      }
    }
  }
  return $env;
}

try {
  $input = json_decode(file_get_contents('php://input'), true);
  
  if (!isset($input['recipient']) || !isset($input['message'])) {
    throw new Exception('Missing recipient or message');
  }

  $recipientInput = $input['recipient'];
  $message = $input['message'];

  if (empty($message)) {
    throw new Exception('Message cannot be empty');
  }

  // Build recipients array
  $recipients = [];
  
  if ($recipientInput === 'all') {
    // Get all emails from users.json
    $usersFile = '../data/users.json';
    
    if (file_exists($usersFile)) {
      $usersData = json_decode(file_get_contents($usersFile), true) ?? [];
      foreach ($usersData as $email => $user) {
        $recipients[] = $email;
      }
    }
  } elseif (is_array($recipientInput)) {
    // Array of specific recipients (from player profiles UI)
    foreach ($recipientInput as $r) {
      if (!filter_var($r, FILTER_VALIDATE_EMAIL)) {
        // Skip invalid addresses but continue processing others
        $failedRecipients[] = ['email' => $r, 'error' => 'Invalid email format'];
        continue;
      }
      $recipients[] = $r;
    }
    if (empty($recipients) && !empty($failedRecipients)) {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => 'No valid recipient emails provided', 'failed' => $failedRecipients]);
      exit;
    }
  } else {
    // Single email
    if (!filter_var($recipientInput, FILTER_VALIDATE_EMAIL)) {
      throw new Exception("Invalid email address: $recipientInput");
    }
    $recipients[] = $recipientInput;
  }

  if (empty($recipients)) {
    throw new Exception('No recipients found');
  }

  // Validate all email addresses
  foreach ($recipients as $recipient) {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
      throw new Exception("Invalid email address: $recipient");
    }
  }

  $env = loadEnv();

  // Validate SMTP config before creating mail object
  if (empty($env['MAIL_HOST']) || empty($env['MAIL_USERNAME']) || empty($env['MAIL_PASSWORD'])) {
    throw new Exception('SMTP configuration missing. Please check .env file.');
  }

  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->Host = $env['MAIL_HOST'];
  $mail->SMTPAuth = true;
  $mail->Username = $env['MAIL_USERNAME'];
  $mail->Password = $env['MAIL_PASSWORD'];
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Port = (int)($env['MAIL_PORT'] ?? 587);
  $mail->setFrom($env['MAIL_FROM_ADDRESS'] ?? 'noreply@hooptheory.co.uk', $env['MAIL_FROM_NAME'] ?? 'Hoop Theory');
  
  // Build HTML email

  // Use the same header/footer as sendEmail.php
  $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<body style='margin:0;padding:0;background:#f5f5f5;'>
<img src='https://hooptheory.co.uk/EMAILHEADER.png' alt='Hoop Theory Header' style='width:100%;max-width:600px;margin-bottom:20px;border-radius:8px;' />
<table width='100%' cellpadding='0' cellspacing='0'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;'>
<tr><td style='padding:40px 30px;font-family:Arial,sans-serif;color:#000;'>

<h1 style='margin:0 0 10px;font-size:24px;color:#2563eb;'>Message from Admin</h1>
<div style='margin:20px 0 30px 0; background:#f5f5f5; padding:18px 20px; border-radius:8px; border-left:4px solid #2563eb; color:#333; font-size:16px; white-space:pre-wrap; word-break:break-word;'>
HTML;

  $htmlBody .= htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

  $htmlBody .= <<<HTML
</div>

<hr style='margin:30px 0;border:none;border-top:1px solid #eee;'>
<p style='text-align:center;font-size:13px;color:#128C7E;margin-bottom:8px;'>Contact us via WhatsApp: <a href='https://chat.whatsapp.com/FGFRQ3eiH5K73YSW4l3f5x' style='color:#128C7E;font-weight:bold;text-decoration:underline;'>Join Group Chat</a></p>
<p style='text-align:center;font-size:12px;color:#999;'>© 2026 Hoop Theory · bao@hooptheory.co.uk</p>

</td></tr></table>
</td></tr></table>
</body>
</html>
HTML;

  $mail->isHTML(true);
  $mail->Subject = 'Message from Hoop Theory';
  $mail->Body = $htmlBody;
  $mail->AltBody = $message;

  $successCount = 0;
  $failedRecipients = [];

  foreach ($recipients as $recipient) {
    try {
      $mail->clearAddresses();
      $mail->addAddress($recipient);
      
      if ($mail->send()) {
        $successCount++;
      } else {
        $failedRecipients[] = ['email' => $recipient, 'error' => $mail->ErrorInfo];
      }
    } catch (Exception $e) {
      $failedRecipients[] = ['email' => $recipient, 'error' => $e->getMessage()];
    }
  }

  if ($successCount > 0) {
    echo json_encode([
      'success' => true,
      'message' => "Email sent to $successCount recipient(s)",
      'successCount' => $successCount,
      'failedCount' => count($failedRecipients),
      'failedRecipients' => $failedRecipients
    ]);
  } else {
    $errorMsg = 'Failed to send email to any recipients';
    if (!empty($failedRecipients)) {
      $errorMsg .= ': ' . $failedRecipients[0]['error'];
    }
    throw new Exception($errorMsg);
  }

} catch (Exception $e) {
  http_response_code(400);
  echo json_encode([
    'success' => false,
    'message' => $e->getMessage()
  ]);
}
?>

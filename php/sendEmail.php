<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

// Get data from AJAX JSON
$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';
$slot = $data['slot'] ?? '';
$date = $data['date'] ?? '';

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Invalid email address";
    exit;
}

// Sanitize other inputs
$slot = htmlspecialchars($slot);
$date = htmlspecialchars($date);

$mail = new PHPMailer(true);
try {
    // Enable SMTP debug output
    $mail->SMTPDebug = 2; // 0 = off, 1 = client, 2 = client + server
    $mail->Debugoutput = 'html';

    //Server settings
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com'; // your SMTP host
    $mail->SMTPAuth = true;
    $mail->Username = 'curtisikennaagha@gmail.com'; // your email
    $mail->Password = 'Topsy999'; // app password
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;

    //Recipients
    $mail->setFrom('curtisikennaagha@gmail.com','Your Company');
    $mail->addAddress($email);

    //Content
    $mail->isHTML(true);
    $mail->Subject = "Session Confirmed: $slot on $date";
    $mail->Body = "
    <html>
    <body style='font-family:Arial,sans-serif;'>
        <div style='max-width:600px;margin:0 auto;padding:20px;border:1px solid #ddd;border-radius:10px;text-align:center;'>
            <h2 style='color:#000;'>Thank You for Booking!</h2>
            <p>Your session is <strong>confirmed</strong>.</p>
            <p><strong>Date & Time:</strong> $slot on $date</p>
            <p>We’re excited to see you!</p>
            <p style='margin-top:30px;color:#888;font-size:0.85rem;'>Your Company Name</p>
        </div>
    </body>
    </html>
    ";

    $mail->send();
    echo "Email sent to $email";
} catch (Exception $e) {
    echo "Email could not be sent. Mailer Error: {$mail->ErrorInfo}";
}
?>

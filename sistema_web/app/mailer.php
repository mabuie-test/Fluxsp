<?php

function send_mail(string $to, string $subject, string $htmlBody): bool {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log('PHPMailer not installed (missing vendor/autoload.php)');
        return false;
    }

    require_once $autoload;

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('PHPMailer class not available');
        return false;
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: '';
        $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_USER') ?: '';
        $mail->Password = getenv('SMTP_PASS') ?: '';
        $mail->SMTPSecure = getenv('SMTP_SECURE') ?: PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $fromEmail = getenv('MAIL_FROM') ?: 'noreply@localhost';
        $fromName = getenv('MAIL_FROM_NAME') ?: 'Sistema Web';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        return $mail->send();
    } catch (Throwable $e) {
        error_log('send_mail error: ' . $e->getMessage());
        return false;
    }
}

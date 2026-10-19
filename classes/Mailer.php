<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/../lib/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Thin PHPMailer wrapper: SMTP first, falls back to PHP's native mail()
 * so a page never hard-fails just because email sending failed.
 */
final class Mailer
{
    /**
     * @param array<string,string> $headers extra headers, e.g. List-Unsubscribe on newsletters
     */
    public static function send(string $to, string $subject, string $htmlBody, string $altBody = '', string $replyTo = '', string $replyToName = '', array $headers = []): bool
    {
        // Header values must never carry a line break, or they could inject further headers.
        $headers = array_filter(
            array_map(fn($v) => str_replace(["\r", "\n"], '', (string) $v), $headers),
            fn($v, $k) => $v !== '' && preg_match('/^[A-Za-z0-9-]+$/', (string) $k),
            ARRAY_FILTER_USE_BOTH
        );

        if (SMTP_USER !== '' && SMTP_PASS !== '') {
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                // cPanel mailboxes: 465 = implicit SSL, 587 = STARTTLS.
                $mail->SMTPSecure = SMTP_PORT === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;
                // PHPMailer waits 300s by default; a sign-up must never hang that long on a bad mail server.
                $mail->Timeout    = 15;
                $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

                // From must be the authenticated mailbox or the host will reject it.
                $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
                $mail->addAddress($to);
                if ($replyTo !== '') {
                    $mail->addReplyTo($replyTo, $replyToName ?: $replyTo);
                }
                foreach ($headers as $name => $value) {
                    $mail->addCustomHeader($name, $value);
                }
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $htmlBody;
                $mail->AltBody = $altBody !== '' ? $altBody : strip_tags($htmlBody);

                return $mail->send();
            } catch (PHPMailerException $e) {
                error_log('Mailer SMTP failed: ' . $e->getMessage());
            }
        }

        $fromAddr = (SMTP_USER !== '') ? SMTP_USER : SITE_EMAIL;
        $mailHeaders = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $mailHeaders .= 'From: ' . SMTP_FROM_NAME . ' <' . $fromAddr . ">\r\n";
        if ($replyTo !== '') {
            $mailHeaders .= 'Reply-To: ' . $replyTo . "\r\n";
        }
        foreach ($headers as $name => $value) {
            $mailHeaders .= $name . ': ' . $value . "\r\n";
        }
        return @mail($to, $subject, $htmlBody, $mailHeaders);
    }
}

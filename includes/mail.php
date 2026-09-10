<?php
/**
 * Simple mail sender (log / SMTP / mail).
 */
declare(strict_types=1);

function mail_config_path(): string
{
    $path = __DIR__ . '/../config/mail.php';
    if (is_file($path)) {
        return $path;
    }
    return __DIR__ . '/../config/mail.example.php';
}

function mail_ensure_config(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    require_once mail_config_path();
    $loaded = true;
}

function mail_log_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function send_mail(string $to, string $subject, string $bodyText, ?string $bodyHtml = null): array
{
    mail_ensure_config();
    $to = strtolower(trim($to));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient email.'];
    }

    $from = defined('MAIL_FROM') ? (string) MAIL_FROM : 'noreply@localhost';
    $fromName = defined('MAIL_FROM_NAME') ? (string) MAIL_FROM_NAME : 'Inventory System';
    $driver = defined('MAIL_DRIVER') ? strtolower((string) MAIL_DRIVER) : 'log';

    if ($driver === 'log') {
        $line = sprintf(
            "[%s] TO:%s SUBJECT:%s\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            str_repeat('-', 40),
            $bodyText
        );
        @file_put_contents(mail_log_dir() . '/mail.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        return ['ok' => true, 'driver' => 'log'];
    }

    if ($driver === 'mail') {
        $headers = [
            'From: ' . $fromName . ' <' . $from . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        $ok = @mail($to, $subject, $bodyText, implode("\r\n", $headers));
        return $ok ? ['ok' => true, 'driver' => 'mail'] : ['ok' => false, 'error' => 'Could not send email.'];
    }

    if ($driver === 'smtp') {
        return send_mail_smtp($to, $subject, $bodyText, $from, $fromName);
    }

    return ['ok' => false, 'error' => 'Mail driver is not configured.'];
}

function send_mail_smtp(string $to, string $subject, string $body, string $from, string $fromName): array
{
    $host = (string) (defined('SMTP_HOST') ? SMTP_HOST : '');
    $port = (int) (defined('SMTP_PORT') ? SMTP_PORT : 587);
    $user = (string) (defined('SMTP_USER') ? SMTP_USER : '');
    $pass = (string) (defined('SMTP_PASS') ? SMTP_PASS : '');
    $secure = strtolower((string) (defined('SMTP_SECURE') ? SMTP_SECURE : 'tls'));

    if ($host === '' || $user === '' || $pass === '') {
        return ['ok' => false, 'error' => 'SMTP is not fully configured in config/mail.php'];
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host;
    $fp = @stream_socket_client($remote . ':' . $port, $errno, $errstr, 20);
    if (!$fp) {
        return ['ok' => false, 'error' => 'SMTP connection failed.'];
    }

    stream_set_timeout($fp, 20);
    $read = static function () use ($fp): string {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $write = static function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };

    $read();
    $write('EHLO localhost');
    $read();
    if ($secure === 'tls') {
        $write('STARTTLS');
        $read();
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $write('EHLO localhost');
        $read();
    }
    $write('AUTH LOGIN');
    $read();
    $write(base64_encode($user));
    $read();
    $write(base64_encode($pass));
    $resp = $read();
    if (!str_starts_with($resp, '235')) {
        fclose($fp);
        return ['ok' => false, 'error' => 'SMTP authentication failed.'];
    }

    $write('MAIL FROM:<' . $from . '>');
    $read();
    $write('RCPT TO:<' . $to . '>');
    $read();
    $write('DATA');
    $read();

    $message = 'From: ' . $fromName . ' <' . $from . ">\r\n";
    $message .= 'To: <' . $to . ">\r\n";
    $message .= 'Subject: ' . $subject . "\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $message .= $body . "\r\n.";
    $write($message);
    $read();
    $write('QUIT');
    fclose($fp);
    return ['ok' => true, 'driver' => 'smtp'];
}

function send_password_reset_otp_email(string $to, string $name, string $otp): array
{
    $subject = 'Your password reset code';
    $body = "Hello {$name},\n\n";
    $body .= "Your one-time password (OTP) to reset your Inventory Management account is:\n\n";
    $body .= "    {$otp}\n\n";
    $body .= "This code expires in 15 minutes. If you did not request a reset, you can ignore this email.\n\n";
    $body .= "— Inventory Management System\n";
    return send_mail($to, $subject, $body);
}

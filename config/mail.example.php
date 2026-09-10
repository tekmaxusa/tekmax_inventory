<?php
/**
 * Mail configuration — prefers environment variables (Docker / production).
 * For XAMPP without env: set values below or copy to mail.php.
 */
declare(strict_types=1);

// log = write emails to storage/logs/mail.log (good for local / Docker without SMTP)
// smtp = send via SMTP
// mail = PHP mail()
define('MAIL_DRIVER', getenv('MAIL_DRIVER') ?: 'log');
define('MAIL_FROM', getenv('MAIL_FROM') ?: 'noreply@inventory.local');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Inventory Management System');

define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls'); // tls | ssl | ''

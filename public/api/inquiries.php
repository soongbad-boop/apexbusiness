<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$mailerAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($mailerAutoload)) {
    require_once $mailerAutoload;
}
start_secure_session($config);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
require_csrf();

$payload = request_json();

// Honeypot: bots often populate every field. Return a neutral success response.
if (normalized_text($payload['website'] ?? '', 255) !== '') {
    json_response(['ok' => true], 202);
}

$type = normalized_text($payload['form_type'] ?? 'general', 40);
$allowedTypes = ['general', 'conversation', 'visa', 'automation', 'advisory', 'newsletter'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'general';
}

$name = normalized_text($payload['name'] ?? '', 160);
$emailRaw = strtolower(normalized_text($payload['email'] ?? '', 190));
$email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
$company = normalized_text($payload['company'] ?? '', 190);
$phone = normalized_text($payload['phone'] ?? '', 80);
$message = normalized_text($payload['message'] ?? ($payload['description'] ?? ($payload['topics'] ?? '')), 6000);

if ($email === '') {
    json_response(['error' => 'Enter a valid email address.'], 422);
}
if ($type !== 'newsletter' && ($name === '' || $message === '')) {
    json_response(['error' => 'Your name and enquiry details are required.'], 422);
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$bucket = 'inquiry:' . $ip;
$retryAfter = consume_rate_limit($bucket, $type === 'newsletter' ? 8 : 5, 3600);
if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    json_response(['error' => 'Too many submissions from this connection. Please try again later.'], 429);
}

$knownKeys = ['form_type', 'website', 'name', 'email', 'company', 'phone', 'message', 'description', 'topics'];
$metadata = [];
foreach ($payload as $key => $value) {
    if (!is_string($key) || in_array($key, $knownKeys, true)) {
        continue;
    }
    if (is_scalar($value) || $value === null) {
        $metadata[mb_substr($key, 0, 80)] = normalized_text($value, 500);
    }
}

$userAgent = normalized_text($_SERVER['HTTP_USER_AGENT'] ?? '', 500);
$subject = match ($type) {
    'conversation' => 'Conversation request',
    'visa' => 'Visa assistance request',
    'automation' => 'AI automation request',
    'advisory' => 'Advisory session request',
    'newsletter' => 'Newsletter subscription',
    default => 'Website enquiry',
};

function log_inquiry_mail_failure(string $message, array $secrets = []): void
{
    foreach ($secrets as $secret) {
        if (is_string($secret) && $secret !== '') {
            $message = str_replace($secret, '[redacted]', $message);
        }
    }
    $message = mb_substr($message, 0, 1000);
    $GLOBALS['apex_mail_failure'] = $message;
    error_log('Apex SMTP delivery failed: ' . $message);
}

function send_inquiry_email(array $config, string $subject, array $details): bool
{
    $mail = $config['mail'] ?? [];
    $host = trim((string) ($mail['host'] ?? ''));
    $username = trim((string) ($mail['username'] ?? ''));
    $password = (string) ($mail['password'] ?? '');
    $from = filter_var($mail['from_address'] ?? '', FILTER_VALIDATE_EMAIL);
    $recipient = filter_var($mail['to_address'] ?? '', FILTER_VALIDATE_EMAIL);
    $encryption = strtolower(trim((string) ($mail['encryption'] ?? '')));

    if (!class_exists(PHPMailer::class)) {
        log_inquiry_mail_failure('PHPMailer is missing; run composer install.');
        return false;
    }
    if (($mail['driver'] ?? '') !== 'smtp' || $host === '' || $username === '' || $password === '' || !$from || !$recipient || !in_array($encryption, ['ssl', 'tls'], true)) {
        log_inquiry_mail_failure('SMTP configuration is incomplete or invalid.');
        return false;
    }

    $mailer = new PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port = (int) ($mail['port'] ?? ($encryption === 'ssl' ? 465 : 587));
        $mailer->Timeout = max(5, min(60, (int) ($mail['timeout'] ?? 20)));
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($from, normalized_text($mail['from_name'] ?? 'Apex Business Consultancy', 100));
        $mailer->addAddress($recipient);
        if (filter_var($details['Email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $mailer->addReplyTo($details['Email'], normalized_text($details['Name'] ?? '', 160));
        }
        $mailer->Subject = '[Apex Website] ' . $subject;
        $lines = ['A new enquiry was submitted through the Apex website.', ''];
        foreach ($details as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        }
        $mailer->Body = implode("\r\n", $lines);
        $mailer->isHTML(false);
        return $mailer->send();
    } catch (Throwable $e) {
        $error = $mailer->ErrorInfo ?: $e->getMessage();
        log_inquiry_mail_failure($error, [$password, $username]);
        return false;
    }
}

try {
    $pdo = db($config);
    $stmt = $pdo->prepare('INSERT INTO inquiries (type, name, email, company, phone, subject, message, metadata, ip_hash, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UNHEX(SHA2(?, 256)), ?)');
    $stmt->execute([
        $type,
        $name ?: null,
        $email,
        $company ?: null,
        $phone ?: null,
        $subject,
        $message ?: null,
        json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        $ip,
        $userAgent ?: null,
    ]);
    $inquiryId = (int) $pdo->lastInsertId();
    $emailDetails = [
        'Reference' => (string) $inquiryId,
        'Type' => $type,
        'Name' => $name,
        'Email' => $email,
        'Company' => $company,
        'Phone' => $phone,
        'Message' => $message,
    ];
    foreach ($metadata as $key => $value) {
        $emailDetails[ucwords(str_replace(['_', '-'], ' ', $key))] = $value;
    }
    $emailSent = send_inquiry_email($config, $subject, $emailDetails);
    if (!$emailSent) {
        error_log('Apex enquiry email delivery failed for inquiry #' . $inquiryId);
        $error = 'Your enquiry was saved, but the notification email could not be sent. Please contact us by email or WhatsApp.';
        if (!empty($config['app_debug'])) {
            $diagnostic = (string) ($GLOBALS['apex_mail_failure'] ?? 'No SMTP diagnostic was captured.');
            $error .= ' SMTP diagnostic: ' . $diagnostic;
        }
        json_response(['error' => $error, 'id' => $inquiryId], 502);
    }
    json_response(['ok' => true, 'id' => $inquiryId, 'email_sent' => $emailSent], 201);
} catch (PDOException $e) {
    error_log('Apex inquiry database unavailable: ' . $e->getMessage());
    json_response(['error' => 'Unable to submit your enquiry right now. Please contact us by email or WhatsApp.'], 503);
}

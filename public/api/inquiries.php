<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
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

function send_inquiry_email(array $config, string $subject, array $details): bool
{
    $recipient = filter_var($config['mail']['to_address'] ?? '', FILTER_VALIDATE_EMAIL);
    $from = filter_var($config['mail']['from_address'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$recipient || !$from) {
        return false;
    }

    $lines = ['A new enquiry was submitted through the Apex website.', ''];
    foreach ($details as $label => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $lines[] = $label . ': ' . $value;
        }
    }

    $headers = [
        'From: ' . normalized_text($config['mail']['from_name'] ?? 'Apex Business Consultancy', 100) . ' <' . $from . '>',
        'Reply-To: ' . $details['Email'],
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
        'X-Mailer: Apex Website',
    ];

    $body = implode("\r\n", $lines);
    if (($config['mail']['driver'] ?? '') === 'smtp') {
        return smtp_send_mail($config['mail'], $recipient, '[Apex Website] ' . $subject, $body, $headers);
    }

    return mail($recipient, '[Apex Website] ' . $subject, $body, implode("\r\n", $headers));
}

function smtp_send_mail(array $mail, string $recipient, string $subject, string $body, array $headers): bool
{
    $host = trim((string) ($mail['host'] ?? ''));
    $port = (int) ($mail['port'] ?? 0);
    $username = trim((string) ($mail['username'] ?? ''));
    $password = (string) ($mail['password'] ?? '');
    $from = filter_var($mail['from_address'] ?? '', FILTER_VALIDATE_EMAIL);
    if ($host === '' || $port <= 0 || !$from || $username === '' || $password === '') {
        error_log('Apex SMTP delivery is not configured completely.');
        return false;
    }

    $encryption = strtolower(trim((string) ($mail['encryption'] ?? 'tls')));
    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        error_log('Apex SMTP connection failed: ' . $errno . ' ' . $errstr);
        return false;
    }

    stream_set_timeout($socket, 20);
    try {
        smtp_expect($socket, [220]);
        smtp_command($socket, 'EHLO ' . smtp_local_host(), [250]);
        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log('Apex SMTP STARTTLS negotiation failed.');
                return false;
            }
            smtp_command($socket, 'EHLO ' . smtp_local_host(), [250]);
        }
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);
        $message = implode("\r\n", [
            'Subject: ' . smtp_header_value($subject),
            'To: <' . $recipient . '>',
            ...$headers,
            '',
            $body,
            '.',
        ]);
        smtp_command($socket, smtp_dot_stuff($message), [250]);
        smtp_command($socket, 'QUIT', [221]);
        return true;
    } catch (Throwable $e) {
        error_log('Apex SMTP delivery failed: ' . $e->getMessage());
        return false;
    } finally {
        fclose($socket);
    }
}

function smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $expectedCodes);
}

function smtp_expect($socket, array $expectedCodes): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Unexpected SMTP response code ' . $code);
    }
    return $response;
}

function smtp_local_host(): string
{
    $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return $host !== '' ? $host : 'localhost';
}

function smtp_header_value(string $value): string
{
    return str_replace(["\r", "\n"], ' ', $value);
}

function smtp_dot_stuff(string $message): string
{
    return preg_replace('/^\./m', '..', $message) ?? $message;
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
        json_response(['error' => 'Your enquiry was saved, but the notification email could not be sent. Please contact us by email or WhatsApp.', 'id' => $inquiryId], 502);
    }
    json_response(['ok' => true, 'id' => $inquiryId, 'email_sent' => $emailSent], 201);
} catch (PDOException $e) {
    error_log('Apex inquiry database unavailable: ' . $e->getMessage());
    json_response(['error' => 'Unable to submit your enquiry right now. Please contact us by email or WhatsApp.'], 503);
}

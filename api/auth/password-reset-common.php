<?php
/**
 * Password reset helpers for MongoDB backend.
 */

require_once __DIR__ . '/../mongo/config.php';

function loadEnvValue($key, $default = '')
{
    return envOrDefault($key, $default);
}

function randomToken($length = 64)
{
    return bin2hex(random_bytes($length / 2));
}

function ensurePasswordResetTable($db)
{
    // Kept name for compatibility with existing calls.
    $db->password_resets->createIndex(['email' => 1, 'user_type' => 1]);
    $db->password_resets->createIndex(['expires_at' => 1]);
    $db->password_resets->createIndex(['used_at' => 1]);
}

function nextResetId($collection)
{
    $last = $collection->findOne([], ['sort' => ['_id' => -1], 'projection' => ['_id' => 1]]);
    return $last ? (normalizeId($last['_id']) + 1) : 1;
}

function smtpSendMail($toEmail, $subject, $htmlBody, $textBody = '')
{
    $host = loadEnvValue('SMTP_HOST', '');
    $port = (int)loadEnvValue('SMTP_PORT', '587');
    $username = loadEnvValue('SMTP_USER', '');
    $password = loadEnvValue('SMTP_PASS', '');
    $fromEmail = loadEnvValue('SMTP_FROM_EMAIL', $username ?: 'no-reply@plotconnect.local');
    $fromName = loadEnvValue('SMTP_FROM_NAME', 'PlotConnect');
    $secure = strtolower(loadEnvValue('SMTP_SECURE', 'tls')); // tls|ssl|none

    if (!$host || !$port || !$username || !$password) {
        throw new Exception('SMTP is not configured. Set SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS in .env');
    }

    $transport = ($secure === 'ssl') ? 'ssl' : 'tcp';
    $socket = @stream_socket_client(
        sprintf('%s://%s:%d', $transport, $host, $port),
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new Exception('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
    }

    stream_set_timeout($socket, 20);

    $read = function () use ($socket) {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        return $data;
    };

    $command = function ($cmd, $expect) use ($socket, $read) {
        if ($cmd !== null) {
            fwrite($socket, $cmd . "\r\n");
        }
        $response = $read();
        if (!preg_match('/^(' . $expect . ')/m', $response)) {
            throw new Exception('SMTP error on command [' . ($cmd ?? 'READ') . ']: ' . trim($response));
        }
        return $response;
    };

    $command(null, '220');
    $command('EHLO plotconnect.local', '250');

    if ($secure === 'tls') {
        $command('STARTTLS', '220');
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception('Unable to start TLS encryption with SMTP server');
        }
        $command('EHLO plotconnect.local', '250');
    }

    $command('AUTH LOGIN', '334');
    $command(base64_encode($username), '334');
    $command(base64_encode($password), '235');

    $command('MAIL FROM:<' . $fromEmail . '>', '250');
    $command('RCPT TO:<' . $toEmail . '>', '250|251');
    $command('DATA', '354');

    $boundary = 'bnd_' . md5(uniqid((string)mt_rand(), true));
    $headers = [];
    $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
    $headers[] = 'To: <' . $toEmail . '>';
    $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $plain = $textBody ?: strip_tags($htmlBody);
    $message = implode("\r\n", $headers) . "\r\n\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $message .= $plain . "\r\n\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $message .= $htmlBody . "\r\n\r\n";
    $message .= '--' . $boundary . "--\r\n.";

    fwrite($socket, $message . "\r\n");
    $command(null, '250');
    $command('QUIT', '221');

    fclose($socket);
}

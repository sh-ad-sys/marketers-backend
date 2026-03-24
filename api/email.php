<?php
/**
 * PlotConnect - Email Utility Class
 * Sends emails using SMTP
 */

class Email {
    private static $smtpHost = 'smtp.gmail.com';
    private static $smtpPort = 587;
    private static $smtpUsername = 'plotconnect01@gmail.com';
    private static $smtpPassword = 'PlotConnect@2024';
    private static $fromEmail = 'plotconnect01@gmail.com';
    private static $fromName = 'PlotConnect';
    
    /**
     * Send OTP email to user
     * @param string $email - Recipient email
     * @param string $name - Recipient name
     * @param string $otp - The OTP to send
     * @return bool - True if sent successfully
     */
    public static function sendOTP($email, $name, $otp) {
        $subject = 'Your PlotConnect Login OTP';
        $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #6366f1; color: white; padding: 20px; text-align: center; }
                .content { background: #f9f9f9; padding: 30px; }
                .otp-code { font-size: 32px; font-weight: bold; color: #6366f1; letter-spacing: 5px; text-align: center; padding: 20px; background: white; border-radius: 8px; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>PlotConnect</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>$name</strong>,</p>
                    <p>Your One-Time Password (OTP) for login is:</p>
                    <div class='otp-code'>$otp</div>
                    <p>This OTP will expire in <strong>10 minutes</strong>.</p>
                    <p>If you didn't request this OTP, please ignore this email.</p>
                </div>
                <div class='footer'>
                    <p>&copy; " . date('Y') . " PlotConnect. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return self::send($email, $name, $subject, $body);
    }
    
    /**
     * Send email using PHP mail() function (simpler for Gmail)
     * @param string $toEmail - Recipient email
     * @param string $toName - Recipient name
     * @param string $subject - Email subject
     * @param string $body - Email body (HTML)
     * @return bool - True if sent successfully
     */
    private static function send($toEmail, $toName, $subject, $body) {
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . self::$fromName . ' <' . self::$fromEmail . '>';
        $headers[] = 'Reply-To: ' . self::$fromEmail;
        $headers[] = 'X-Mailer: PHP/' . phpversion();
        
        $to = $toName . ' <' . $toEmail . '>';
        
        // Try using Gmail SMTP via fsockopen for better reliability
        try {
            return self::sendViaSMTP($toEmail, $subject, $body, $headers);
        } catch (Exception $e) {
            // Fallback to mail()
            error_log('SMTP Error: ' . $e->getMessage() . ', falling back to mail()');
            return mail($to, $subject, $body, implode("\r\n", $headers));
        }
    }
    
    /**
     * Send email via direct SMTP connection
     */
    private static function sendViaSMTP($toEmail, $subject, $body, $headers) {
        $sock = fsockopen(self::$smtpHost, self::$smtpPort, $errno, $errstr, 30);
        if (!$sock) {
            throw new Exception("Cannot connect to SMTP: $errstr ($errno)");
        }
        
        // Read greeting
        $response = fgets($sock, 515);
        
        // EHLO
        fputs($sock, "EHLO " . self::$smtpHost . "\r\n");
        self::readResponse($sock);
        
        // STARTTLS
        fputs($sock, "STARTTLS\r\n");
        self::readResponse($sock);
        
        // Upgrade to TLS
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        
        // EHLO again after TLS
        fputs($sock, "EHLO " . self::$smtpHost . "\r\n");
        self::readResponse($sock);
        
        // AUTH LOGIN
        fputs($sock, "AUTH LOGIN\r\n");
        self::readResponse($sock);
        
        // Username
        fputs($sock, base64_encode(self::$smtpUsername) . "\r\n");
        self::readResponse($sock);
        
        // Password
        fputs($sock, base64_encode(self::$smtpPassword) . "\r\n");
        $response = self::readResponse($sock);
        
        if (strpos($response, '235') === false) {
            fclose($sock);
            throw new Exception('SMTP authentication failed');
        }
        
        // MAIL FROM
        fputs($sock, "MAIL FROM: <" . self::$fromEmail . ">\r\n");
        self::readResponse($sock);
        
        // RCPT TO
        fputs($sock, "RCPT TO: <$toEmail>\r\n");
        self::readResponse($sock);
        
        // DATA
        fputs($sock, "DATA\r\n");
        self::readResponse($sock);
        
        // Email content
        $message = "To: $toEmail\r\n";
        $message .= "Subject: $subject\r\n";
        $message .= implode("\r\n", $headers) . "\r\n";
        $message .= "\r\n$body\r\n";
        $message .= ".\r\n";
        
        fputs($sock, $message);
        $response = self::readResponse($sock);
        
        // QUIT
        fputs($sock, "QUIT\r\n");
        fclose($sock);
        
        return strpos($response, '250') !== false;
    }
    
    private static function readResponse($sock) {
        $response = '';
        while ($row = fgets($sock, 515)) {
            $response .= $row;
            if (substr($row, 3, 1) == ' ') break;
        }
        return $response;
    }
}
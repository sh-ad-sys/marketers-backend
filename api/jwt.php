<?php
/**
 * PlotConnect - JWT Utility Class
 * Simple JWT implementation using HMAC-SHA256
 */

// JWT secret key - in production, store securely (env variable)
define('JWT_SECRET', 'plotconnect_jwt_secret_key_2024');
define('JWT_EXPIRY', 86400); // 24 hours in seconds

class JWT {
    /**
     * Create a JWT token
     * @param array $payload - Data to encode in token
     * @return string - Encoded JWT token
     */
    public static function create($payload) {
        // Add standard claims
        $payload['iat'] = time();
        $payload['exp'] = time() + JWT_EXPIRY;
        
        // Encode header
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        
        // Encode payload
        $payloadEncoded = base64_encode(json_encode($payload));
        
        // Create signature
        $signature = hash_hmac('sha256', $header . '.' . $payloadEncoded, JWT_SECRET, true);
        $signatureEncoded = base64_encode($signature);
        
        // Return token
        return $header . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
    
    /**
     * Verify and decode a JWT token
     * @param string $token - JWT token to verify
     * @return array|false - Decoded payload or false if invalid
     */
    public static function verify($token) {
        // Split token into parts
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        list($header, $payload, $signature) = $parts;
        
        // Verify signature
        $expectedSignature = base64_encode(hash_hmac('sha256', $header . '.' . $payload, JWT_SECRET, true));
        
        if ($signature !== $expectedSignature) {
            return false;
        }
        
        // Decode payload
        $payloadData = json_decode(base64_decode($payload), true);
        
        if (!$payloadData) {
            return false;
        }
        
        // Check expiration
        if (isset($payloadData['exp']) && $payloadData['exp'] < time()) {
            return false;
        }
        
        return $payloadData;
    }
    
    /**
     * Extract token from Authorization header
     * @return string|false - Token or false if not found
     */
    public static function getTokenFromHeader() {
        $headers = getallheaders();
        
        // Case-insensitive header lookup
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                // Expect format: "Bearer <token>"
                if (preg_match('/Bearer\s+(.+)$/i', $value, $matches)) {
                    return trim($matches[1]);
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if request has valid JWT
     * @return array|false - Payload if valid, false otherwise
     */
    public static function authenticate() {
        $token = self::getTokenFromHeader();
        
        if (!$token) {
            return false;
        }
        
        return self::verify($token);
    }
}
<?php
/**
 * PlotConnect - OTP Utility Class
 * Generates and verifies One-Time Passwords
 */

class OTP {
    /**
     * Generate a 6-digit OTP with high uniqueness
     * Uses multiple random sources to ensure uniqueness
     * @return string - 6-digit OTP
     */
    public static function generate() {
        // Use microseconds and random bytes for uniqueness
        $unique = uniqid(mt_rand(), true);
        $hash = md5($unique . microtime(true));
        // Get 6 digits from hash, ensuring it's not all zeros and is 6 digits
        $otp = hexdec(substr($hash, 0, 6)) % 900000 + 100000;
        return strval($otp);
    }
    
    /**
     * Hash the OTP for storage in database
     * @param string $otp - The plain OTP
     * @return string - Hashed OTP
     */
    public static function hash($otp) {
        return password_hash($otp, PASSWORD_DEFAULT);
    }
    
    /**
     * Verify an OTP against a hashed value
     * @param string $otp - The plain OTP to verify
     * @param string $hashedOtp - The hashed OTP from database
     * @return bool - True if valid, false otherwise
     */
    public static function verify($otp, $hashedOtp) {
        return password_verify($otp, $hashedOtp);
    }
    
    /**
     * Check if OTP has expired
     * @param string $otpExpiry - The expiry timestamp
     * @return bool - True if expired, false otherwise
     */
    public static function isExpired($otpExpiry) {
        if (empty($otpExpiry)) {
            return true;
        }
        return strtotime($otpExpiry) < time();
    }
    
    /**
     * Get expiry time (30 seconds from now)
     * @return string - MySQL timestamp
     */
    public static function getExpiry() {
        return date('Y-m-d H:i:s', strtotime('+30 seconds'));
    }
}
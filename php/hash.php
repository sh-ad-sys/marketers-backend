<?php
/**
 * Password Hashing Utility
 * Uses PHP's built-in password hashing functions (bcrypt)
 */

class Hash {
    /**
     * Hash a password using bcrypt (default)
     * @param string $password The plain text password
     * @return string The hashed password
     */
    public static function make($password) {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * Verify a password against a hash
     * @param string $password The plain text password
     * @param string $hash The hashed password
     * @return bool True if password matches, false otherwise
     */
    public static function check($password, $hash) {
        return password_verify($password, $hash);
    }

    /**
     * Check if a hash needs to be rehashed (for future algorithm upgrades)
     * @param string $hash The hashed password
     * @return bool True if hash needs rehashing
     */
    public static function needsRehash($hash) {
        return password_needs_rehash($hash, PASSWORD_BCRYPT);
    }
}

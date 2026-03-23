<?php
/**
 * Debug - Check environment variables
 */

header('Content-Type: text/plain');

echo "ADMIN_USERNAME from getenv: " . getenv('ADMIN_USERNAME') . "\n";
echo "ADMIN_USERNAME from \$_ENV: " . ($_ENV['ADMIN_USERNAME'] ?? 'NOT SET') . "\n";
echo "\n";
echo "ADMIN_PASSWORD from getenv: " . getenv('ADMIN_PASSWORD') . "\n";
echo "ADMIN_PASSWORD from \$_ENV: " . ($_ENV['ADMIN_PASSWORD'] ?? 'NOT SET') . "\n";
echo "\n";
echo "ADMIN_PASSWORD constant: " . (defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : 'NOT DEFINED') . "\n";

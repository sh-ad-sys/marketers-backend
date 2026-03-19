<?php
/**
 * Database Configuration for PlotConnect
 * Aiven MySQL with SSL
 */

// Database credentials
define('DB_HOST', 'plotconnect-shadrackmutua081-64f3.k.aivencloud.com');
define('DB_PORT', '27258');
define('DB_NAME', 'defaultdb');
define('DB_USER', 'avnadmin');
define('DB_PASS', 'AVNS_StD64oqi1o1g51qGri5');

// Admin credentials
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', '$2y$10$CCS1vn1/1pH4s0Q6W0HBhOb9lqNUog8W0xmfiQqDE15j91CoGNFRO');

// SSL Configuration
define('DB_SSL_MODE', 'REQUIRED');
define('DB_SSL_CA', '/plotconnect/ca.pem');

// Service URI (DSN)
$service_uri = sprintf(
    'mysql://%s:%s@%s:%s/%s?ssl-mode=%s',
    DB_USER,
    DB_PASS,
    DB_HOST,
    DB_PORT,
    DB_NAME,
    DB_SSL_MODE
);

// PDO options for SSL connection
$pdo_options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

/**
 * Get PDO database connection
 * @return PDO
 */
function getDBConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;sslmode=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_SSL_MODE
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_SSL_CA => DB_SSL_CA,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database Connection Error: ' . $e->getMessage());
            throw new Exception('Database connection failed. Please check your configuration.');
        }
    }
    
    return $pdo;
}

/**
 * Get the service URI for reference
 * @return string
 */
function getServiceURI() {
    return sprintf(
        'mysql://%s:%s@%s:%s/%s?ssl-mode=%s',
        DB_USER,
        DB_PASS,
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_SSL_MODE
    );
}

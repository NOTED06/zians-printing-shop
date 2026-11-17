<?php

$databaseConfig = [
    'host' => getenv('ZIAN_DB_HOST') ?: '127.0.0.1',
    'username' => getenv('ZIAN_DB_USER') ?: 'root',
    'password' => getenv('ZIAN_DB_PASSWORD') ?: '',
    'database' => getenv('ZIAN_DB_NAME') ?: 'zian',
    'port' => (int)(getenv('ZIAN_DB_PORT') ?: 3306),
];

$localConfigPath = __DIR__ . '/db_config.local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $hostOnly = explode(':', $serverHost)[0];
        $isLocalServer = in_array($hostOnly, ['localhost', '127.0.0.1', '::1']) || (php_sapi_name() === 'cli' && empty($_SERVER['HTTP_HOST']));
        $isRemoteHosting = isset($localConfig['host']) && (strpos($localConfig['host'], 'infinityfree') !== false || strpos($localConfig['host'], 'epizy') !== false);

        if (!$isLocalServer || !$isRemoteHosting) {
            $databaseConfig = array_replace($databaseConfig, $localConfig);
        }
    }
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(
    $databaseConfig['host'],
    $databaseConfig['username'],
    $databaseConfig['password'],
    $databaseConfig['database'],
    (int)$databaseConfig['port']
);

if (!$conn && $databaseConfig['host'] !== '127.0.0.1' && $databaseConfig['host'] !== 'localhost') {
    $conn = @mysqli_connect('127.0.0.1', 'root', '', 'zian', 3306);
}

if (!$conn) {
    error_log('Zian database connection failed: ' . mysqli_connect_error());
    http_response_code(503);
    die('Database connection is temporarily unavailable.');
}

mysqli_set_charset($conn, "utf8mb4");
?>


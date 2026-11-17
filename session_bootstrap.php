<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $logoutRole = $_GET['role'] ?? '';
    $isAdminRequest = strpos($requestPath, '/admin/') !== false || $scriptName === 'admin_login.php' || ($scriptName === 'logout.php' && $logoutRole === 'admin');
    $isCustomerRequest = strpos($requestPath, '/customer/') !== false || in_array($scriptName, ['index.php', 'chatbot_api.php'], true) || ($scriptName === 'logout.php' && $logoutRole === 'customer');
    $sessionName = $isAdminRequest ? 'ZIANADMINSESSID' : ($isCustomerRequest ? 'ZIANCUSTOMERSESSID' : 'ZIANSESSID');

    ini_set('session.gc_maxlifetime', (string)(86400 * 30));
    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
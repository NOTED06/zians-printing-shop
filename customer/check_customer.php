<?php

require_once __DIR__ . '/../security_headers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['type'] ?? '') !== 'customer') {
    header('Location: ../index.php');
    exit;
}

if (isset($conn) && isset($_SESSION['user_id'])) {
    $conn->query("UPDATE customers SET last_active = NOW() WHERE customer_id = " . (int)$_SESSION['user_id']);
}
?>

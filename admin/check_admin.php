<?php

require_once __DIR__ . '/../security_headers.php';

if (!isset($_SESSION['admin_id']) || ($_SESSION['type'] ?? '') !== 'admin') {
    unset($_SESSION['admin_id'], $_SESSION['admin_email']);
    header('Location: ../admin_login.php');
    exit;
}
?>

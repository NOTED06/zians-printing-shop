<?php

require_once __DIR__ . '/../session_bootstrap.php';
require_once '../db_conn.php';
require_once '../notifications_helper.php';
require_once '../csrf_helper.php';

if (!isset($_SESSION['admin_id']) || ($_SESSION['type'] ?? '') !== 'admin') {
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
}

if ($action === 'count') {
    header('Content-Type: application/json');
    echo json_encode(['count' => getUnreadNotifCount($conn, 'admin')]);
    exit;
}

if ($action === 'list') {
    header('Content-Type: application/json');
    $notifs = getNotifications($conn, 'admin', 0, 20);
    echo json_encode($notifs);
    exit;
}

if ($action === 'mark_read') {
    header('Content-Type: application/json');
    markAllRead($conn, 'admin');
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => 'invalid action']);

<?php

function createNotification($conn, $userType, $userId, $type, $title, $message = '', $link = '') {
    $stmt = $conn->prepare("INSERT INTO messages (record_type, user_type, user_id, type, title, message, link, date_created) VALUES ('notification', ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param('sissss', $userType, $userId, $type, $title, $message, $link);
    $stmt->execute();
}

function getUnreadNotifCount($conn, $userType, $userId = 0) {
    if ($userType === 'admin') {
        $res = $conn->query("SELECT COUNT(*) as c FROM messages WHERE record_type = 'notification' AND user_type = 'admin' AND is_read = 0");
    } else {
        $res = $conn->query("SELECT COUNT(*) as c FROM messages WHERE record_type = 'notification' AND user_type = 'customer' AND user_id = $userId AND is_read = 0");
    }
    return $res ? ($res->fetch_assoc()['c'] ?? 0) : 0;
}

function getNotifications($conn, $userType, $userId = 0, $limit = 20) {
    if ($userType === 'admin') {
        $res = $conn->query("SELECT * FROM messages WHERE record_type = 'notification' AND user_type = 'admin' ORDER BY date_created DESC LIMIT $limit");
    } else {
        $res = $conn->query("SELECT * FROM messages WHERE record_type = 'notification' AND user_type = 'customer' AND user_id = $userId ORDER BY date_created DESC LIMIT $limit");
    }
    $notifs = [];
    while ($r = $res->fetch_assoc()) $notifs[] = $r;
    return $notifs;
}

function markAllRead($conn, $userType, $userId = 0) {
    if ($userType === 'admin') {
        $conn->query("UPDATE messages SET is_read = 1 WHERE record_type = 'notification' AND user_type = 'admin' AND is_read = 0");
    } else {
        $conn->query("UPDATE messages SET is_read = 1 WHERE record_type = 'notification' AND user_type = 'customer' AND user_id = $userId AND is_read = 0");
    }
}

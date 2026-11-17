<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once '../db_conn.php';
require_once 'check_admin.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'sidebar_badges') {
    require_once __DIR__ . '/../seen_helper.php';
    $adminId = $_SESSION['admin_id'] ?? 0;
    $badges = getAdminBadgeCounts($conn, $adminId);
    echo json_encode($badges);
    exit;
}

if ($action === 'customer_statuses') {
    $res = $conn->query("SELECT customer_id, last_active FROM customers");
    $statuses = [];
    while ($r = $res->fetch_assoc()) {
        $statuses[] = [
            'customer_id' => (int)$r['customer_id'],
            'last_active' => $r['last_active']
        ];
    }
    echo json_encode(['customers' => $statuses]);
    exit;
}

if ($action === 'orders') {
    $res = $conn->query("SELECT co.*, p.img AS product_image, c.username as customer_name, c.email as customer_email, c.phone as customer_phone, c.address as customer_address FROM orders co LEFT JOIN products p ON p.record_type='product' AND co.product_id = p.product_id LEFT JOIN customers c ON co.customer_id = c.customer_id ORDER BY co.date_created DESC");
    $orders = [];
    while ($r = $res->fetch_assoc()) $orders[] = $r;

    
    
    $batchTotals = [];
    foreach ($orders as $order) {
        $key = !empty($order['batch_id']) ? $order['batch_id'] : 'solo_' . $order['id'];
        if (!isset($batchTotals[$key])) {
            $batchTotals[$key] = ['total' => 0, 'paid' => 0];
        }
        $batchTotals[$key]['total'] += (float)$order['price'] * (int)$order['quantity'];
        $batchTotals[$key]['paid'] += (float)$order['down_payment_amount'];
    }
    foreach ($orders as &$order) {
        $key = !empty($order['batch_id']) ? $order['batch_id'] : 'solo_' . $order['id'];
        $order['batch_total_price'] = $batchTotals[$key]['total'];
        $order['batch_payment_amount'] = min($batchTotals[$key]['paid'], $batchTotals[$key]['total']);
    }
    unset($order);

    echo json_encode(['orders' => $orders, 'count' => count($orders)]);
    exit;
}

if ($action === 'dashboard_stats') {
    $pending = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status IN ('In Cart','Processing')")->fetch_assoc()['c'];
    $processing = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Processing'")->fetch_assoc()['c'];
    $completed = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Completed'")->fetch_assoc()['c'];
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $completedWeek = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status='Completed' AND date_created >= '$weekStart'")->fetch_assoc()['c'];
    $products = $conn->query("SELECT COUNT(*) as c FROM products WHERE record_type='product'")->fetch_assoc()['c'];

    
    $recentRes = $conn->query("SELECT co.id, co.product_name, co.price, co.status, co.date_created, c.username as customer_name FROM orders co LEFT JOIN customers c ON co.customer_id = c.customer_id ORDER BY co.date_created DESC LIMIT 5");
    $recent = [];
    while ($r = $recentRes->fetch_assoc()) $recent[] = $r;

    echo json_encode([
        'pending' => (int)$pending,
        'processing' => (int)$processing,
        'completed' => (int)$completed,
        'completedWeek' => (int)$completedWeek,
        'products' => (int)$products,
        'recentOrders' => $recent
    ]);
    exit;
}

if ($action === 'products') {
    $res = $conn->query("SELECT p.*, (SELECT CASE WHEN COUNT(NULLIF(pv.image, '')) > 0 THEN COUNT(DISTINCT NULLIF(pv.image, '')) ELSE COUNT(*) END FROM products pv WHERE pv.record_type='variant' AND pv.product_id = p.product_id AND pv.is_active = 1) as variant_count FROM products p WHERE p.record_type='product' ORDER BY p.product_id DESC");
    $products = [];
    while ($r = $res->fetch_assoc()) $products[] = $r;
    echo json_encode(['products' => $products, 'count' => count($products)]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);

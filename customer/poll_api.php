<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once '../db_conn.php';
require_once 'check_customer.php';
header('Content-Type: application/json');

$customerId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';

if ($action === 'sidebar_badges') {
    require_once __DIR__ . '/../seen_helper.php';
    $badges = getCustomerBadgeCounts($conn, $customerId);
    $badges['cart_count'] = (int)$conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'In Cart'")->fetch_assoc()['c'];
    echo json_encode($badges);
    exit;
}

if ($action === 'orders') {
    $statusFilter = $_GET['status'] ?? 'all';
    $where = "WHERE customer_id = $customerId";
    if ($statusFilter !== 'all') {
        $safe = $conn->real_escape_string($statusFilter);
        $where .= " AND status = '$safe'";
    }
    $res = $conn->query("SELECT * FROM orders $where ORDER BY date_created DESC");
    $rawOrders = [];
    while ($r = $res->fetch_assoc()) $rawOrders[] = $r;

    
    $productCache = [];
    $variantCache = [];
    foreach ($rawOrders as $o) {
        $pid = $o['product_id'];
        $vid = $o['variant_id'] ?? 0;
        if ($pid && !isset($productCache[$pid])) {
            $pr = $conn->query("SELECT img, description FROM products WHERE record_type='product' AND product_id = " . (int)$pid);
            $productCache[$pid] = $pr ? $pr->fetch_assoc() : null;
        }
        if ($vid && !isset($variantCache[$vid])) {
            $vr = $conn->query("SELECT image FROM products WHERE record_type='variant' AND variant_id = " . (int)$vid);
            $variantCache[$vid] = $vr ? $vr->fetch_assoc() : null;
        }
    }

    
    $batchMap = [];
    foreach ($rawOrders as $o) {
        $key = !empty($o['batch_id']) ? $o['batch_id'] : 'solo_' . $o['id'];
        if (!isset($batchMap[$key])) {
            $pid = $o['product_id'];
            $prodImg = $productCache[$pid]['img'] ?? '';
            $prodDesc = $productCache[$pid]['description'] ?? '';
            $batchMap[$key] = [
                'batch_id'            => $o['batch_id'] ?? null,
                'first_id'            => $o['id'],
                'product_name'        => $o['product_name'],
                'product_img'         => $prodImg,
                'product_desc'        => $prodDesc,
                'image'               => $o['image'],
                'payment_mode'        => $o['payment_mode'],
                'gcash_reference'     => $o['gcash_reference'],
                'down_payment_amount' => $o['down_payment_amount'],
                'down_payment_proof'  => $o['down_payment_proof'] ?? '',
                'order_notes'         => $o['order_notes'],
                'status'              => $o['status'],
                'date_created'          => $o['date_created'],
                'updated_at'          => $o['updated_at'],
                'scheduled_release'   => $o['scheduled_release'],
                'estimated_finish_date' => $o['estimated_finish_date'] ?? null,
                'estimated_working_days' => $o['estimated_working_days'] ?? null,
                'items'               => [],
            ];
        }
        $vid = $o['variant_id'] ?? 0;
        $batchMap[$key]['items'][] = [
            'id'           => $o['id'],
            'variant_name' => $o['variant_name'] ?? '',
            'size'         => $o['size'] ?? '',
            'quantity'     => $o['quantity'],
            'price'        => $o['price'],
            'variant_img'  => $variantCache[$vid]['image'] ?? '',
        ];
        if ($o['updated_at'] > $batchMap[$key]['updated_at'])
            $batchMap[$key]['updated_at'] = $o['updated_at'];
    }
    $orders = array_values($batchMap);

    
    $allCount         = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId")->fetch_assoc()['c'];
    $cartCount        = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'In Cart'")->fetch_assoc()['c'];
    $processingCount  = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'Processing'")->fetch_assoc()['c'];
    $productionCount  = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'Production'")->fetch_assoc()['c'];
    $readyCount       = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'Ready for Pickup'")->fetch_assoc()['c'];
    $completedCount   = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'Completed'")->fetch_assoc()['c'];
    $cancelledCount   = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'Cancelled'")->fetch_assoc()['c'];

    echo json_encode([
        'orders' => $orders,
        'counts' => [
            'all'              => (int)$allCount,
            'in_cart'          => (int)$cartCount,
            'processing'       => (int)$processingCount,
            'production'       => (int)$productionCount,
            'ready_for_pickup' => (int)$readyCount,
            'completed'        => (int)$completedCount,
            'cancelled'        => (int)$cancelledCount,
        ]
    ]);
    exit;
}

if ($action === 'products') {
    $res = $conn->query("SELECT p.*, pc.category_name, (SELECT CASE WHEN COUNT(NULLIF(pv.image, '')) > 0 THEN COUNT(DISTINCT NULLIF(pv.image, '')) ELSE COUNT(*) END FROM products pv WHERE pv.record_type='variant' AND pv.product_id = p.product_id AND pv.is_active = 1) as variant_count FROM products p LEFT JOIN products pc ON pc.record_type='category' AND pc.category_id = p.category_id WHERE p.record_type='product' AND p.is_active = 1 ORDER BY p.product_id DESC");
    $products = [];
    while ($r = $res->fetch_assoc()) $products[] = $r;
    echo json_encode(['products' => $products, 'count' => count($products)]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);

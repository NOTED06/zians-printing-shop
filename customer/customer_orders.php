<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once '../db_conn.php';
require_once __DIR__ . '/../size_chart_helper.php';
require_once 'check_customer.php';
require_once '../csrf_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
}
require_once '../inventory_helper.php';

$customerId = $_SESSION['user_id'];
$customerName = $_SESSION['username'] ?? 'Customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    header('Content-Type: application/json');
    $orderId  = (int)$_POST['order_id'];
    $batchId  = trim($_POST['batch_id'] ?? '');
    $conn->begin_transaction();

    if ($batchId) {
        $stmt = $conn->prepare("SELECT id, order_id, product_id, variant_id, quantity FROM orders WHERE batch_id = ? AND customer_id = ? AND status IN ('In Cart', 'Processing') FOR UPDATE");
        $stmt->bind_param('si', $batchId, $customerId);
    } else {
        $stmt = $conn->prepare("SELECT id, order_id, product_id, variant_id, quantity FROM orders WHERE id = ? AND customer_id = ? AND status IN ('In Cart', 'Processing') FOR UPDATE");
        $stmt->bind_param('ii', $orderId, $customerId);
    }

    if (!$stmt->execute()) {
        $conn->rollback();
        echo json_encode(['success' => false]);
        exit;
    }

    $orders = [];
    $result = $stmt->get_result();
    while ($order = $result->fetch_assoc()) $orders[] = $order;
    if (empty($orders)) {
        $conn->rollback();
        echo json_encode(['success' => false]);
        exit;
    }

    foreach ($orders as $order) {
        if (!releaseInventory($conn, (int)$order['product_id'], $order['variant_id'] !== null ? (int)$order['variant_id'] : null, (int)$order['quantity'])) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Unable to restore stock. Please try again.']);
            exit;
        }
    }

    $lineIds = implode(',', array_unique(array_map('intval', array_column($orders, 'id'))));
    if (!$conn->query("UPDATE orders SET status = 'Cancelled', updated_at = NOW() WHERE id IN ($lineIds)")) {
        $conn->rollback();
        echo json_encode(['success' => false]);
        exit;
    }

    $conn->commit();
    echo json_encode(['success' => true]);
    exit;
}

$statusFilter = $_GET['status'] ?? 'all';
    $where = "WHERE customer_id = $customerId";
if ($statusFilter !== 'all') {
    $safe = $conn->real_escape_string($statusFilter);
    $where .= " AND status = '$safe'";
}
$ordersRes = $conn->query("SELECT * FROM orders $where ORDER BY date_created DESC");
$rawOrders = [];
while ($r = $ordersRes->fetch_assoc()) $rawOrders[] = $r;

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
    $fitDetails = parseOrderedFitDetails((string)($o['size'] ?? ''));
    $batchMap[$key]['items'][] = [
        'id'           => $o['id'],
        'variant_name' => $o['variant_name'] ?? '',
        'size'         => $fitDetails['size'],
        'recommended_size' => $fitDetails['recommended_size'],
        'fit_measurements' => $fitDetails['measurements'],
        'quantity'     => $o['quantity'],
        'price'        => $o['price'],
        'variant_img'  => $variantCache[$vid]['image'] ?? '',
    ];
    if ($o['updated_at'] > $batchMap[$key]['updated_at'])
        $batchMap[$key]['updated_at'] = $o['updated_at'];
}
$orders = array_values($batchMap);

$allCount        = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId")->fetch_assoc()['c'];
$cartCount       = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'In Cart'")->fetch_assoc()['c'];
$processingCount = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'Processing'")->fetch_assoc()['c'];
$productionCount = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'Production'")->fetch_assoc()['c'];
$readyCount      = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'Ready for Pickup'")->fetch_assoc()['c'];
$completedCount  = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'Completed'")->fetch_assoc()['c'];
$cancelledCount  = $conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $customerId AND status = 'Cancelled'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - Zian's PrintShop</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/customer.css?v=<?= time() ?>">
    <style>
    
    .main-content {
        padding: 40px 60px;
        max-width: 1200px;
        margin: 0 auto;
        box-sizing: border-box;
    }
    .page-header {
        display: block !important;
        margin-bottom: 24px;
    }
    .page-header h1 {
        font-size: 28px;
        font-weight: 700;
        color: #1a1a1a;
        margin-bottom: 6px;
    }
    .page-header p {
        font-size: 14px;
        color: #666;
    }
    @media (max-width: 768px) {
        .main-content {
            padding: 24px 16px;
        }
    }

    
    .order-card { padding: 1.5rem; }
    .order-card-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.5rem; }
    .order-id-badge { font-weight:800; color:#ff6b35; font-size:1rem; }
    .order-meta { display:flex; align-items:center; gap:0.75rem; }
    .order-date-sm { color:hsl(215 16% 47%); font-size:0.72rem; }

    
    .order-timeline { display:flex; align-items:center; margin-bottom:1.25rem; overflow-x:auto; padding-bottom:4px; }
    .timeline-step { display:flex; flex-direction:column; align-items:center; min-width:64px; position:relative; }
    .step-dot { width:32px; height:32px; border-radius:50%; border:2px solid hsl(214 32% 85%); background:hsl(210 20% 96%); display:flex; align-items:center; justify-content:center; font-size:0.95rem; transition:all 0.3s; flex-shrink:0; }
    .timeline-step.done .step-dot { background:#ff6b35; border-color:#ff6b35; }
    .timeline-step.active .step-dot { background:#ff6b35; border-color:#ff6b35; box-shadow:0 0 0 4px rgba(255,107,53,0.2); }
    .timeline-step.cancelled .step-dot { background:#ef4444; border-color:#ef4444; }
    .step-label { font-size:0.6rem; font-weight:600; color:hsl(215 16% 47%); margin-top:5px; text-align:center; white-space:nowrap; }
    .timeline-step.done .step-label, .timeline-step.active .step-label { color:#ff6b35; }
    .timeline-step.cancelled .step-label { color:#ef4444; }
    .timeline-step.pending .step-dot { opacity:0.35; filter:blur(0.8px); border-color:hsl(214 32% 80%); background:hsl(210 20% 94%); }
    .timeline-step.pending .step-label { opacity:0.4; }
    .timeline-connector { flex:1; height:2px; background:hsl(214 32% 88%); min-width:20px; margin-top:-16px; }
    .timeline-connector.done { background:#ff6b35; }

    
    .order-body-grid { display:grid; grid-template-columns:auto 1fr; gap:0.75rem 1rem; align-items:start; margin-bottom:1rem; }
    .order-ref-photos { display:flex; gap:6px; flex-wrap:wrap; }
    .order-ref-photos img { width:52px; height:52px; object-fit:cover; border-radius:8px; border:1px solid hsl(214 32% 91%); cursor:pointer; transition:opacity 0.2s; }
    .order-ref-photos img:hover { opacity:0.85; }
    .order-product-info .product-name { font-weight:700; font-size:0.95rem; }
    .order-product-info .variant-label { font-size:0.78rem; color:#ff6b35; font-weight:600; margin-top:2px; }
    .order-product-info .qty-price { font-size:0.78rem; color:hsl(215 16% 47%); margin-top:4px; }

    
    .info-row { display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.75rem; }
    .info-pill { background:hsl(210 20% 96%); border:1px solid hsl(214 32% 91%); border-radius:8px; padding:6px 10px; font-size:0.72rem; display:flex; align-items:center; gap:6px; color:hsl(222 47% 20%); }
    .info-pill.payment { border-color:rgba(34,197,94,0.3); color:#4ade80; }
    .info-pill.release { border-color:rgba(251,191,36,0.3); color:#fbbf24; }
    .info-pill.notes { border-color:rgba(147,197,253,0.3); color:#93c5fd; }

    
    .order-footer { display:grid; grid-template-columns:1fr auto; align-items:center; gap:0 12px; margin-top:1rem; padding-top:0.75rem; border-top:1px solid hsl(214 32% 91%); }
    .order-footer-status { grid-column:1 / -1; margin-top:0.65rem; }
    .btn-cancel { background:transparent; border:1px solid hsl(214 32% 85%); border-radius:8px; color:hsl(215 16% 47%); padding:6px 14px; font-size:0.75rem; font-weight:600; cursor:pointer; font-family:inherit; transition:all 0.2s; }
    .btn-cancel:hover { border-color:#ef4444; color:#ef4444; }

    
    .btn-view-details { background:#ff6b35; border:none; border-radius:8px; color:#fff; padding:6px 14px; font-size:0.75rem; font-weight:600; cursor:pointer; font-family:inherit; transition:all 0.2s; display:inline-flex; align-items:center; gap:5px; }
    .btn-view-details:hover { background:#e55a2b; box-shadow:0 2px 8px rgba(255,107,53,0.3); }

    
    .od-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); z-index:9999; display:none; align-items:center; justify-content:center; animation:odFadeIn .2s ease; }
    .od-overlay.active { display:flex; }
    @keyframes odFadeIn { from { opacity:0; } to { opacity:1; } }
    .od-modal { background:#fff; border-radius:16px; width:90%; max-width:560px; max-height:85vh; overflow-y:auto; box-shadow:0 25px 60px rgba(0,0,0,0.25); animation:odSlideUp .25s ease; }
    @keyframes odSlideUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
    .od-header { display:flex; justify-content:space-between; align-items:center; padding:18px 22px; border-bottom:1px solid hsl(214 32% 93%); position:sticky; top:0; background:#fff; border-radius:16px 16px 0 0; z-index:1; }
    .od-header h3 { margin:0; font-size:1rem; font-weight:700; color:hsl(222 47% 11%); }
    .od-close { background:none; border:none; cursor:pointer; color:hsl(215 16% 47%); font-size:1.2rem; padding:4px; border-radius:6px; transition:all .2s; line-height:1; }
    .od-close:hover { background:hsl(210 20% 96%); color:hsl(222 47% 11%); }
    .od-body { padding:18px 22px; }
    .od-section { margin-bottom:18px; }
    .od-section:last-child { margin-bottom:0; }
    .od-section-title { font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:hsl(215 16% 55%); margin-bottom:8px; }
    .od-status-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }

    
    .od-product-hero { display:flex; gap:14px; align-items:center; padding:12px; background:hsl(210 20% 97%); border-radius:12px; }
    .od-product-img { width:64px; height:64px; border-radius:10px; object-fit:cover; border:1px solid hsl(214 32% 91%); flex-shrink:0; }
    .od-product-img-placeholder { width:64px; height:64px; border-radius:10px; background:hsl(214 32% 91%); display:flex; align-items:center; justify-content:center; color:hsl(214 32% 70%); flex-shrink:0; }
    .od-product-name { font-weight:700; font-size:0.92rem; color:hsl(222 47% 11%); }
    .od-product-desc { font-size:0.75rem; color:hsl(215 16% 47%); margin-top:3px; line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }

    
    .od-variant-list { display:flex; flex-direction:column; gap:8px; }
    .od-variant-card { display:flex; gap:12px; align-items:center; padding:10px 12px; background:#fff; border:1px solid hsl(214 32% 91%); border-radius:10px; transition:box-shadow .2s; }
    .od-variant-card:hover { box-shadow:0 2px 8px rgba(0,0,0,0.06); }
    .od-variant-thumb { width:48px; height:48px; border-radius:8px; object-fit:cover; border:1px solid hsl(214 32% 91%); flex-shrink:0; }
    .od-variant-thumb-placeholder { width:48px; height:48px; border-radius:8px; background:hsl(210 20% 96%); border:1px solid hsl(214 32% 91%); display:flex; align-items:center; justify-content:center; color:hsl(214 32% 75%); font-size:0.7rem; flex-shrink:0; }
    .od-variant-info { flex:1; min-width:0; }
    .od-variant-name { font-weight:600; font-size:0.82rem; color:hsl(222 47% 11%); }
    .od-variant-meta { font-size:0.72rem; color:hsl(215 16% 47%); margin-top:2px; }
    .od-variant-price { font-weight:700; font-size:0.82rem; color:#ff6b35; white-space:nowrap; }

    
    .od-info-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
    .od-info-item { padding:10px 12px; background:hsl(210 20% 97%); border-radius:10px; }
    .od-info-label { font-size:0.65rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:hsl(215 16% 55%); margin-bottom:3px; }
    .od-info-value { font-size:0.8rem; font-weight:600; color:hsl(222 47% 11%); word-break:break-word; }

    .order-status-note { margin:10px 0 0; padding:10px 12px; border-radius:10px; font-size:0.75rem; line-height:1.45; border:1px solid transparent; }
    .processing-note { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
    .production-note { background:#fffbeb; color:#92400e; border-color:#fde68a; }
    .ready-note { background:#f5f3ff; color:#6d28d9; border-color:#ddd6fe; }
    .completed-note { background:#f0fdf4; color:#166534; border-color:#bbf7d0; }
    .cancelled-note { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
    .modal-status-note { margin-top:12px; }
    
    .od-ref-photos { display:flex; gap:8px; flex-wrap:wrap; }
    .od-ref-photos img { width:72px; height:72px; object-fit:cover; border-radius:10px; border:1px solid hsl(214 32% 91%); cursor:pointer; transition:transform .2s, box-shadow .2s; }
    .od-ref-photos img:hover { transform:scale(1.05); box-shadow:0 4px 12px rgba(0,0,0,0.12); }

    
    .od-total-bar { display:flex; justify-content:space-between; align-items:center; padding:14px 22px; border-top:1px solid hsl(214 32% 93%); background:hsl(210 20% 97%); border-radius:0 0 16px 16px; }
    .od-total-label { font-size:0.75rem; font-weight:600; color:hsl(215 16% 47%); }
    .od-total-value { font-size:1.05rem; font-weight:800; color:#ff6b35; }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1>My Orders</h1>
        <p>Track all your printing orders</p>
    </div>

    <div class="filter-bar">
        <a href="?status=all" class="filter-btn <?= $statusFilter==='all'?'active':'' ?>">All <span class="filter-count">(<?= $allCount ?>)</span></a>
        <a href="?status=In Cart" class="filter-btn <?= $statusFilter==='In Cart'?'active':'' ?>">In Cart <span class="filter-count">(<?= $cartCount ?>)</span></a>
        <a href="?status=Processing" class="filter-btn <?= $statusFilter==='Processing'?'active':'' ?>">Processing <span class="filter-count">(<?= $processingCount ?>)</span></a>
        <a href="?status=Production" class="filter-btn <?= $statusFilter==='Production'?'active':'' ?>">Production <span class="filter-count">(<?= $productionCount ?>)</span></a>
        <a href="?status=Ready for Pickup" class="filter-btn <?= $statusFilter==='Ready for Pickup'?'active':'' ?>">Ready for Pickup <span class="filter-count">(<?= $readyCount ?>)</span></a>
        <a href="?status=Completed" class="filter-btn <?= $statusFilter==='Completed'?'active':'' ?>">Completed <span class="filter-count">(<?= $completedCount ?>)</span></a>
        <a href="?status=Cancelled" class="filter-btn <?= $statusFilter==='Cancelled'?'active':'' ?>">Cancelled <span class="filter-count">(<?= $cancelledCount ?>)</span></a>
    </div>

    <?php if (empty($orders)): ?>
        <div class="empty-state">
            <h3>No orders found</h3>
            <p>You don't have any orders matching this filter.</p>
        </div>
    <?php else: ?>
        <div class="orders-list">
            <?php foreach ($orders as $o):
                $steps = ['Processing','Production','Ready for Pickup','Completed'];
                $cancelled = ($o['status'] === 'Cancelled');
                $currentIdx = array_search($o['status'], $steps);
                if ($currentIdx === false) $currentIdx = -1;
                $photos = array_filter(array_map('trim', explode(',', $o['image'] ?? '')));
                $statusColorMap = ['In Cart'=>'status-incart','Processing'=>'status-processing','Production'=>'status-production','Ready for Pickup'=>'status-ready','Completed'=>'status-completed','Cancelled'=>'status-cancelled'];
                $sc = $statusColorMap[$o['status']] ?? 'status-incart';
                $stepIcons = ['⚙️','🏭','📦','✅'];
                $stepLabels = ['Processing','Production','Ready for Pickup','Completed'];
                $isMulti = count($o['items']) > 1;
                $batchIdJs = htmlspecialchars($o['batch_id'] ?? '');
                $totalQty = array_sum(array_column($o['items'], 'quantity'));
                $totalPrice = array_sum(array_map(fn($it) => (float)$it['price'] * (int)$it['quantity'], $o['items']));
                $paymentLabel = $totalPrice > 0 && (float)$o['down_payment_amount'] >= $totalPrice - 0.005 ? 'Full Payment' : 'Down Payment';
            ?>
            <div class="order-card" id="order-<?= $o['first_id'] ?>">
                
                <div class="order-card-header">
                    <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                        <span class="order-id-badge">ORD-<?= str_pad($o['first_id'], 3, '0', STR_PAD_LEFT) ?></span>
                        <?php if ($cancelled): ?>
                            <span class="status-badge status-cancelled">Cancelled</span>
                        <?php else: ?>
                            <span class="status-badge <?= $sc ?>"><?= $o['status'] ?></span>
                        <?php endif; ?>
                        <?php if ($isMulti): ?><span style="font-size:0.65rem;background:hsl(214 32% 91%);color:hsl(222 47% 25%);padding:2px 8px;border-radius:10px;font-weight:700;"><?= count($o['items']) ?> variants</span><?php endif; ?>
                    </div>
                    <span class="order-date"><?= date('M d, Y h:i A', strtotime($o['date_created'])) ?></span>
                </div>

                <?php if (!$cancelled): ?>
                <div class="order-timeline">
                    <?php foreach ($steps as $si => $step):
                        $done    = $si < $currentIdx;
                        $active  = $si === $currentIdx;
                        $pending = $si > $currentIdx;
                        $cls     = $done ? 'done' : ($active ? 'active' : ($pending ? 'pending' : ''));
                    ?>
                        <div class="timeline-step <?= $cls ?>">
                            <div class="step-dot"><?= $stepIcons[$si] ?></div>
                            <div class="step-label"><?= $stepLabels[$si] ?></div>
                        </div>
                        <?php if ($si < count($steps)-1): ?>
                        <div class="timeline-connector <?= $done ? 'done' : '' ?>"></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($o['status'] === 'Processing'): ?>
                    <div class="order-status-note processing-note"><strong>Processing:</strong> Your order is being reviewed and inventory is being prepared.</div>
                <?php elseif ($o['status'] === 'Production'): ?>
                    <div class="order-status-note production-note"><strong>Production:</strong> Your order is being manufactured or prepared.
                        <?php if (!empty($o['estimated_finish_date'])): ?> Estimated completion: <strong><?= date('M d, Y', strtotime($o['estimated_finish_date'])) ?></strong><?php endif; ?>
                    </div>
                <?php endif; ?>

                
                <div class="order-body-grid">
                    <?php if (!empty($photos)): ?>
                    <div class="order-ref-photos">
                        <?php foreach ($photos as $ph): ?>
                            <img src="../<?= htmlspecialchars($ph) ?>" alt="Reference" onclick="viewPhoto('../<?= htmlspecialchars($ph) ?>')">
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div style="width:52px;height:52px;border-radius:8px;background:hsl(210 20% 96%);border:1px solid hsl(214 32% 91%);display:flex;align-items:center;justify-content:center;color:hsl(214 32% 70%);">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                    </div>
                    <?php endif; ?>

                    <div class="order-product-info">
                        <div class="product-name"><?= htmlspecialchars($o['product_name']) ?></div>
                        <?php foreach ($o['items'] as $it): ?>
                            <div style="display:flex;align-items:baseline;gap:0.4rem;flex-wrap:wrap;margin-top:3px;">
                                <?php if (!empty($it['variant_name'])): ?>
                                    <span class="variant-label" style="margin-top:0;"><?= htmlspecialchars($it['variant_name']) ?><?php if (!empty($it['size'])): ?><span style="color:hsl(215 16% 47%);font-weight:500;"> — <?= htmlspecialchars($it['size']) ?></span><?php endif; ?></span>
                                <?php endif; ?>
                                <span class="qty-price" style="margin-top:0;">&times;<?= $it['quantity'] ?><?php if ($it['price'] > 0): ?> @ &#8369;<?= number_format($it['price'], 2) ?> = <strong style="color:#ff6b35;">&#8369;<?= number_format($it['price'] * $it['quantity'], 2) ?></strong><?php endif; ?></span>
                            </div>
                            <?php if (!empty($it['recommended_size']) || !empty($it['fit_measurements'])): ?>
                                <div style="margin-top:3px;font-size:0.7rem;color:#6b7280;">
                                    <?php if (!empty($it['recommended_size'])): ?><strong style="color:#7c3aed;">Fit recommendation: <?= htmlspecialchars($it['recommended_size']) ?></strong><?php endif; ?>
                                    <?php if (!empty($it['fit_measurements'])): ?><div>Body measurements: <?= htmlspecialchars($it['fit_measurements']) ?></div><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($isMulti && $totalPrice > 0): ?>
                            <div style="margin-top:6px;font-size:0.78rem;font-weight:700;color:hsl(222 47% 11%);border-top:1px solid hsl(214 32% 91%);padding-top:4px;">Total: &#8369;<?= number_format($totalPrice, 2) ?> (<?= $totalQty ?> pcs)</div>
                        <?php endif; ?>
                    </div>
                </div>

                
                <div class="info-row">
                    <?php if (!empty($o['down_payment_amount']) && $o['down_payment_amount'] > 0): ?>
                        <div class="info-pill payment">📱 GCash <?= htmlspecialchars($paymentLabel) ?>: <strong>&#8369;<?= number_format($o['down_payment_amount'], 2) ?></strong><?php if (!empty($o['gcash_reference'])): ?> &bull; Ref: <?= htmlspecialchars($o['gcash_reference']) ?><?php endif; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($o['scheduled_release'])): ?>
                        <div class="info-pill release">📅 Release: <?= date('M d, Y', strtotime($o['scheduled_release'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($o['estimated_finish_date'])): ?>
                        <div class="info-pill">⏱️ Estimated finish: <?= date('M d, Y', strtotime($o['estimated_finish_date'])) ?><?php if (!empty($o['estimated_working_days'])): ?> (<?= (int)$o['estimated_working_days'] ?> working day<?= (int)$o['estimated_working_days'] === 1 ? '' : 's' ?>)<?php endif; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($o['order_notes'])): ?>
                        <div class="info-pill notes">💬 <?= htmlspecialchars($o['order_notes']) ?></div>
                    <?php endif; ?>
                </div>

                
                <div class="order-footer">
                    <div style="font-size:0.72rem;color:hsl(215 16% 55%);">Updated <?= date('M d, Y', strtotime($o['updated_at'])) ?></div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <button class="btn-view-details" onclick='viewOrderDetails(<?= json_encode($o, JSON_HEX_APOS|JSON_HEX_TAG) ?>)'>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            View Details
                        </button>
                        <?php if (in_array($o['status'], ['In Cart', 'Processing'])): ?>
                            <button class="btn-cancel" onclick="cancelOrder(<?= $o['first_id'] ?>,'<?= $batchIdJs ?>')">Cancel Order</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($o['status'] === 'Ready for Pickup'): ?>
                        <div class="order-status-note ready-note order-footer-status"><strong>Ready for Pickup:</strong> Your order is finished and ready at Zian's PrintShop. Please bring your order code when claiming it.
                            <?php if (!empty($o['scheduled_release'])): ?> Pickup date: <strong><?= date('M d, Y', strtotime($o['scheduled_release'])) ?></strong><?php endif; ?>
                        </div>
                    <?php elseif ($o['status'] === 'Completed'): ?>
                        <div class="order-status-note completed-note order-footer-status"><strong>Completed:</strong> This order has been claimed and completed.</div>
                    <?php elseif ($cancelled): ?>
                        <div class="order-status-note cancelled-note order-footer-status"><strong>Cancelled:</strong> This order was cancelled and is no longer in the production pipeline.</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="od-overlay" id="odOverlay" onclick="if(event.target===this)closeOrderDetail()">
    <div class="od-modal">
        <div class="od-header">
            <h3 id="odTitle">Order Details</h3>
            <button class="od-close" onclick="closeOrderDetail()">&times;</button>
        </div>
        <div class="od-body" id="odBody"></div>
        <div class="od-total-bar" id="odFooter"></div>
    </div>
</div>

<script>
function cancelOrder(orderId, batchId) {
    showConfirm({
        title: 'Cancel Order?',
        message: 'Are you sure you want to cancel this order? This action cannot be undone.',
        type: 'danger',
        confirmText: 'Cancel Order',
        onConfirm: () => { _doCancelOrder(orderId, batchId); }
    });
}
function _doCancelOrder(orderId, batchId) {
    const fd = new FormData();
    fd.append('action', 'cancel_order');
    fd.append('order_id', orderId);
    if (batchId) fd.append('batch_id', batchId);
    fetch('customer_orders.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Order Cancelled', 'Your order has been cancelled.', 'info');
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast('Error', 'Could not cancel order.', 'error');
            }
        })
        .catch(() => showToast('Error', 'Something went wrong.', 'error'));
}

function viewPhoto(src) {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:99999;display:flex;align-items:center;justify-content:center;cursor:pointer;';
    overlay.onclick = () => overlay.remove();
    const img = document.createElement('img');
    img.src = src;
    img.style.cssText = 'max-width:85%;max-height:85%;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.5);';
    overlay.appendChild(img);
    document.body.appendChild(overlay);
}

function viewOrderDetails(o) {
    const orderId = String(o.first_id).padStart(3, '0');
    const sc = STATUS_CLASS[o.status] || 'status-incart';
    const dateStr = new Date(o.date_created).toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric', year:'numeric' }) + ' ' + new Date(o.date_created).toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit', hour12:true });

    document.getElementById('odTitle').innerHTML = `<span style="color:#ff6b35;">ORD-${orderId}</span> Details`;

    const items = o.items || [];
    let totalPrice = 0, totalQty = 0;
    items.forEach(it => { totalPrice += (parseFloat(it.price)||0) * (parseInt(it.quantity)||1); totalQty += parseInt(it.quantity)||1; });
    const paymentAmount = parseFloat(o.down_payment_amount) || 0;
    const paymentLabel = totalPrice > 0 && paymentAmount >= totalPrice - 0.005 ? 'Full Payment' : 'Down Payment';
    const remainingBalance = Math.max(totalPrice - paymentAmount, 0);

    
    let html = `<div class="od-status-row">
        <span class="status-badge ${sc}">${escHtml(o.status)}</span>
        <span style="font-size:0.72rem;color:hsl(215 16% 47%);">${dateStr}</span>
    </div>`;

    
    const prodImg = o.product_img ? `<img class="od-product-img" src="../${escHtml(o.product_img)}" alt="Product">` : `<div class="od-product-img-placeholder"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div>`;
    html += `<div class="od-section">
        <div class="od-section-title">Product</div>
        <div class="od-product-hero">
            ${prodImg}
            <div>
                <div class="od-product-name">${escHtml(o.product_name)}</div>
                ${o.product_desc ? `<div class="od-product-desc">${escHtml(o.product_desc)}</div>` : ''}
            </div>
        </div>
    </div>`;

    
    html += `<div class="od-section">
        <div class="od-section-title">Variants (${items.length})</div>
        <div class="od-variant-list">`;
    items.forEach(it => {
        const p = parseFloat(it.price) || 0;
        const q = parseInt(it.quantity) || 1;
        const vImg = it.variant_img ? `<img class="od-variant-thumb" src="../${escHtml(it.variant_img)}" alt="Variant" onclick="viewPhoto('../${escHtml(it.variant_img)}')">` : `<div class="od-variant-thumb-placeholder">N/A</div>`;
        html += `<div class="od-variant-card">
            ${vImg}
            <div class="od-variant-info">
                <div class="od-variant-name">${escHtml(it.variant_name) || 'Default'}</div>
                <div class="od-variant-meta">${it.size ? 'Size: ' + escHtml(it.size) + ' &bull; ' : ''}Qty: ${q}</div>
                ${it.recommended_size ? `<div class="od-variant-meta" style="color:#7c3aed;font-weight:700;">Fit recommendation: ${escHtml(it.recommended_size)}</div>` : ''}
                ${it.fit_measurements ? `<div class="od-variant-meta">Customer measurements: ${escHtml(it.fit_measurements)}</div>` : ''}
            </div>
            <div class="od-variant-price">\u20b1${(p * q).toFixed(2)}</div>
        </div>`;
    });
    html += `</div></div>`;

    
    const photos = o.image ? o.image.split(',').map(s => s.trim()).filter(Boolean) : [];
    if (photos.length) {
        html += `<div class="od-section">
            <div class="od-section-title">Reference Photos</div>
            <div class="od-ref-photos">
                ${photos.map(p => `<img src="../${escHtml(p)}" alt="Ref" onclick="viewPhoto('../${escHtml(p)}')">`).join('')}
            </div>
        </div>`;
    }

    
    html += `<div class="od-section"><div class="od-section-title">Order Information</div><div class="od-info-grid">`;
    html += `<div class="od-info-item"><div class="od-info-label">Payment</div><div class="od-info-value">${escHtml(o.payment_mode) || 'N/A'}</div></div>`;
    if (paymentAmount > 0) {
        html += `<div class="od-info-item"><div class="od-info-label">${paymentLabel}</div><div class="od-info-value">\u20b1${paymentAmount.toFixed(2)}</div></div>`;
    }
    if (paymentLabel === 'Down Payment' && paymentAmount > 0 && remainingBalance > 0) {
        html += `<div class="od-info-item" style="grid-column:1 / -1;"><div class="od-info-label">Remaining Balance</div><div class="od-info-value">\u20b1${remainingBalance.toFixed(2)}</div></div>`;
    }
    if (o.gcash_reference) {
        html += `<div class="od-info-item"><div class="od-info-label">GCash Ref</div><div class="od-info-value">${escHtml(o.gcash_reference)}</div></div>`;
    }
    if (o.scheduled_release) {
        html += `<div class="od-info-item"><div class="od-info-label">Release Date</div><div class="od-info-value">${new Date(o.scheduled_release+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</div></div>`;
    }
    if (o.estimated_finish_date) {
        const workingDaysLabel = o.estimated_working_days ? ` (${o.estimated_working_days} working day${Number(o.estimated_working_days) === 1 ? '' : 's'})` : '';
        html += `<div class="od-info-item"><div class="od-info-label">Estimated Finish</div><div class="od-info-value">${new Date(o.estimated_finish_date+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}${workingDaysLabel}</div></div>`;
    }
    html += `</div></div>`;

    const statusMessages = {
        Processing: '<strong>Processing:</strong> Your order is being reviewed and inventory is being prepared.',
        Production: '<strong>Production:</strong> Your order is being manufactured or prepared.',
        'Ready for Pickup': '<strong>Ready for Pickup:</strong> Your order is finished. Bring your order code when claiming it.',
        Completed: '<strong>Completed:</strong> This order has been claimed and completed.',
        Cancelled: '<strong>Cancelled:</strong> This order is no longer in the production pipeline.'
    };
    if (statusMessages[o.status]) {
        html += `<div class="order-status-note modal-status-note">${statusMessages[o.status]}${o.estimated_finish_date && o.status === 'Production' ? ` Estimated completion: <strong>${new Date(o.estimated_finish_date+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</strong>.` : ''}</div>`;
    }

    
    if (o.order_notes) {
        html += `<div class="od-section"><div class="od-section-title">Notes</div><div style="font-size:0.8rem;color:hsl(222 47% 20%);line-height:1.5;padding:10px 12px;background:hsl(210 20% 97%);border-radius:10px;">${escHtml(o.order_notes)}</div></div>`;
    }

    document.getElementById('odBody').innerHTML = html;

    
    document.getElementById('odFooter').innerHTML = `
        <div class="od-total-label">${totalQty} item${totalQty !== 1 ? 's' : ''}</div>
        <div class="od-total-value">Total: \u20b1${totalPrice.toFixed(2)}</div>`;

    document.getElementById('odOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeOrderDetail() {
    document.getElementById('odOverlay').classList.remove('active');
    document.body.style.overflow = '';
}

let _lastCustOrderData = <?= json_encode(array_map(function($o) { return ['first_id' => $o['first_id'], 'status' => $o['status']]; }, $orders)) ?>;
const _currentFilter = '<?= addslashes($statusFilter) ?>';

function escHtml(str) {
    const d = document.createElement('div'); d.textContent = str || ''; return d.innerHTML;
}

const STEPS       = ['Processing','Production','Ready for Pickup','Completed'];
const STEP_ICONS  = ['\u2699\uFE0F','\uD83C\uDFED','\uD83D\uDCE6','\u2705'];
const STEP_LABELS = ['Processing','Production','Ready for Pickup','Completed'];
const STATUS_CLASS = {'In Cart':'status-incart','Processing':'status-processing','Production':'status-production','Ready for Pickup':'status-ready','Completed':'status-completed','Cancelled':'status-cancelled'};

function buildTimeline(status) {
    if (status === 'Cancelled') return '';
    const idx = STEPS.indexOf(status);
    let html = '<div class="order-timeline">';
    STEPS.forEach((s, i) => {
        const done   = i < idx;
        const active = i === idx;
        const cls    = done ? 'done' : (active ? 'active' : '');
        html += `<div class="timeline-step ${cls}">
            <div class="step-dot">${done ? '\u2713' : STEP_ICONS[i]}</div>
            <div class="step-label">${STEP_LABELS[i]}</div>
        </div>`;
        if (i < STEPS.length - 1) {
            html += `<div class="timeline-connector ${done ? 'done' : ''}"></div>`;
        }
    });
    html += '</div>';
    return html;
}

function buildStatusNote(order) {
    const messages = {
        Processing: ['processing-note', '<strong>Processing:</strong> Your order is being reviewed and inventory is being prepared.'],
        Production: ['production-note', '<strong>Production:</strong> Your order is being manufactured or prepared.'],
    };
    const message = messages[order.status];
    if (!message) return '';
    let extra = '';
    if (order.status === 'Production' && order.estimated_finish_date) {
        extra = ` Estimated completion: <strong>${new Date(order.estimated_finish_date+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</strong>.`;
    }
    if (order.status === 'Ready for Pickup' && order.scheduled_release) {
        extra = ` Pickup date: <strong>${new Date(order.scheduled_release+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</strong>.`;
    }
    return `<div class="order-status-note ${message[0]}">${message[1]}${extra}</div>`;
}

function buildPickupStatusNote(order) {
    if (order.status !== 'Ready for Pickup') return '';
    const releaseText = order.scheduled_release
        ? ` Pickup date: <strong>${new Date(order.scheduled_release + 'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</strong>.`
        : '';
    return `<div class="order-status-note ready-note order-footer-status"><strong>Ready for Pickup:</strong> Your order is finished and ready at Zian's PrintShop. Please bring your order code when claiming it.${releaseText}</div>`;
}

function buildOrderCard(o) {
    
    const orderId  = String(o.first_id).padStart(3, '0');
    const dateStr  = new Date(o.date_created).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) + ' ' + new Date(o.date_created).toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit', hour12:true });
    const updStr   = new Date(o.updated_at || o.date_created).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    const sc       = STATUS_CLASS[o.status] || 'status-incart';
    const cancelled = o.status === 'Cancelled';
    const isMulti  = o.items && o.items.length > 1;

    
    const photos = o.image ? o.image.split(',').map(s=>s.trim()).filter(Boolean) : [];
    const photosHtml = photos.length
        ? `<div class="order-ref-photos">${photos.map(p => `<img src="../${escHtml(p)}" onclick="viewPhoto('../${escHtml(p)}')" alt="ref">`).join('')}</div>`
        : `<div style="width:52px;height:52px;border-radius:8px;background:hsl(210 20% 96%);border:1px solid hsl(214 32% 91%);display:flex;align-items:center;justify-content:center;color:hsl(214 32% 70%);"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div>`;

    
    const items = o.items || [];
    let itemsHtml = '';
    let totalPrice = 0;
    let totalQty = 0;
    items.forEach(it => {
        const p = parseFloat(it.price) || 0;
        const q = parseInt(it.quantity) || 1;
        totalPrice += p * q;
        totalQty += q;
        itemsHtml += `<div style="display:flex;align-items:baseline;gap:0.4rem;flex-wrap:wrap;margin-top:3px;">`;
        if (it.variant_name) {
            itemsHtml += `<span class="variant-label" style="margin-top:0;">${escHtml(it.variant_name)}${it.size ? `<span style="color:hsl(215 16% 47%);font-weight:500;"> \u2014 ${escHtml(it.size)}</span>` : ''}</span>`;
        }
        itemsHtml += `<span class="qty-price" style="margin-top:0;">\u00d7${q}${p > 0 ? ` @ \u20b1${p.toFixed(2)} = <strong style="color:#ff6b35;">\u20b1${(p*q).toFixed(2)}</strong>` : ''}</span>`;
        itemsHtml += `</div>`;
    });
    if (isMulti && totalPrice > 0) {
        itemsHtml += `<div style="margin-top:6px;font-size:0.78rem;font-weight:700;color:hsl(222 47% 11%);border-top:1px solid hsl(214 32% 91%);padding-top:4px;">Total: \u20b1${totalPrice.toFixed(2)} (${totalQty} pcs)</div>`;
    }

    const paymentAmount = parseFloat(o.down_payment_amount) || 0;
    const paymentLabel = totalPrice > 0 && paymentAmount >= totalPrice - 0.005 ? 'Full Payment' : 'Down Payment';

    
    let pillsHtml = '';
    if (parseFloat(o.down_payment_amount) > 0) {
        pillsHtml += `<div class="info-pill payment">\uD83D\uDCF1 GCash ${paymentLabel}: <strong>\u20b1${paymentAmount.toFixed(2)}</strong>${o.gcash_reference ? ` &bull; Ref: ${escHtml(o.gcash_reference)}` : ''}</div>`;
    }
    if (o.scheduled_release) {
        pillsHtml += `<div class="info-pill release">\uD83D\uDCC5 Release: ${new Date(o.scheduled_release+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</div>`;
    }
    if (o.estimated_finish_date) {
        const days = o.estimated_working_days ? ` (${o.estimated_working_days} working day${Number(o.estimated_working_days) === 1 ? '' : 's'})` : '';
        pillsHtml += `<div class="info-pill">\u23F1 Estimated finish: ${new Date(o.estimated_finish_date+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}${days}</div>`;
    }
    if (o.order_notes) {
        pillsHtml += `<div class="info-pill notes">\uD83D\uDCAC ${escHtml(o.order_notes)}</div>`;
    }

    const batchParam = o.batch_id ? `,'${escHtml(o.batch_id)}'` : ',""';
    const cancelBtn = ['In Cart','Processing'].includes(o.status) ? `<button class="btn-cancel" onclick="cancelOrder(${o.first_id}${batchParam})">Cancel Order</button>` : '';
    const multiTag  = isMulti ? `<span style="font-size:0.65rem;background:hsl(214 32% 91%);color:hsl(222 47% 25%);padding:2px 8px;border-radius:10px;font-weight:700;">${items.length} variants</span>` : '';
    const orderDataAttr = escHtml(JSON.stringify(o));
    const viewBtn = `<button class="btn-view-details" onclick='viewOrderDetails(${JSON.stringify(o).replace(/'/g,"&#39;")})'>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View Details
    </button>`;

    return `<div class="order-card" id="order-${o.first_id}">
        <div class="order-card-header">
            <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                <span class="order-id-badge">ORD-${orderId}</span>
                <span class="status-badge ${sc}">${escHtml(o.status)}</span>
                ${multiTag}
            </div>
            <span class="order-date">${dateStr}</span>
        </div>
        ${buildTimeline(o.status)}
        ${buildStatusNote(o)}
        <div class="order-body-grid">
            ${photosHtml}
            <div class="order-product-info">
                <div class="product-name">${escHtml(o.product_name)}</div>
                ${itemsHtml}
            </div>
        </div>
        ${pillsHtml ? `<div class="info-row">${pillsHtml}</div>` : ''}
        <div class="order-footer">
            <div style="font-size:0.72rem;color:hsl(215 16% 55%);">Updated ${updStr}</div>
            <div style="display:flex;gap:8px;align-items:center;">
                ${viewBtn}
                ${cancelBtn}
            </div>
            ${buildPickupStatusNote(o)}
            ${o.status === 'Completed'
                ? '<div class="order-status-note completed-note order-footer-status"><strong>Completed:</strong> This order has been claimed and completed.</div>'
                : o.status === 'Cancelled'
                    ? '<div class="order-status-note cancelled-note order-footer-status"><strong>Cancelled:</strong> This order was cancelled and is no longer in the production pipeline.</div>'
                    : ''}
        </div>
    </div>`;
}

function pollCustomerOrders() {
    fetch('poll_api.php?action=orders&status=' + encodeURIComponent(_currentFilter))
        .then(r => r.json())
        .then(data => {
            const newOrders = data.orders; 
            const newMap = newOrders.map(o => o.first_id + ':' + o.status).join(',');
            const oldMap = _lastCustOrderData.map(o => o.first_id + ':' + o.status).join(',');

            if (newMap !== oldMap) {
                
                const oldStatusMap = {};
                _lastCustOrderData.forEach(o => oldStatusMap[o.first_id] = o.status);
                newOrders.forEach(o => {
                    if (oldStatusMap[o.first_id] && oldStatusMap[o.first_id] !== o.status) {
                        showToast('Order Updated', 'ORD-' + String(o.first_id).padStart(3,'0') + ' is now \u201c' + o.status + '\u201d', 'info');
                    }
                });

                
                const container = document.querySelector('.orders-list');
                const emptyState = document.querySelector('.empty-state');
                if (newOrders.length === 0) {
                    if (container) container.remove();
                    if (!emptyState) {
                        document.querySelector('.main-content').insertAdjacentHTML('beforeend', '<div class="empty-state"><h3>No orders found</h3><p>You don\'t have any orders matching this filter.</p></div>');
                    }
                } else {
                    if (emptyState) emptyState.remove();
                    if (container) {
                        container.innerHTML = newOrders.map(buildOrderCard).join('');
                    } else {
                        document.querySelector('.main-content').insertAdjacentHTML('beforeend', '<div class="orders-list">' + newOrders.map(buildOrderCard).join('') + '</div>');
                    }
                }

                
                const counts = data.counts;
                const filterBtns = document.querySelectorAll('.filter-btn .filter-count');
                if (filterBtns[0]) filterBtns[0].textContent = '(' + counts.all + ')';
                if (filterBtns[1]) filterBtns[1].textContent = '(' + counts.in_cart + ')';
                if (filterBtns[2]) filterBtns[2].textContent = '(' + counts.processing + ')';
                if (filterBtns[3]) filterBtns[3].textContent = '(' + counts.production + ')';
                if (filterBtns[4]) filterBtns[4].textContent = '(' + counts.ready_for_pickup + ')';
                if (filterBtns[5]) filterBtns[5].textContent = '(' + counts.completed + ')';
                if (filterBtns[6]) filterBtns[6].textContent = '(' + counts.cancelled + ')';

                _lastCustOrderData = newOrders.map(o => ({ first_id: o.first_id, status: o.status }));
            }
        })
        .catch(() => {});
}

setInterval(pollCustomerOrders, 5000);
</script>
<?php include 'chatbot_widget.php'; ?>
</body>
</html>

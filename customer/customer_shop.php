<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once '../db_conn.php';
require_once 'check_customer.php';
require_once '../inventory_helper.php';
require_once '../csrf_helper.php';
require_once '../upload_helper.php';
require_once '../schedule_helper.php';
require_once '../admin_notification_helper.php';
require_once '../order_write_helper.php';

$customerId = $_SESSION['user_id'];
$customerName = $_SESSION['username'] ?? 'Customer';

if (isset($_GET['get_variants'])) {
    header('Content-Type: application/json');
    $pid = (int)$_GET['get_variants'];
    $res = $conn->query("SELECT * FROM products WHERE record_type='variant' AND product_id = $pid AND is_active = 1 ORDER BY variant_id ASC");
    $variants = [];
    while ($r = $res->fetch_assoc()) $variants[] = $r;
    echo json_encode($variants);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart_multi') {
    require_csrf_token();
    header('Content-Type: application/json');
    $pid   = (int)$_POST['product_id'];
    $pname = trim($_POST['product_name'] ?? '');
    $variantItems = json_decode($_POST['variant_items'] ?? '[]', true);
    if (!is_array($variantItems) || empty($variantItems)) {
        echo json_encode(['success' => false, 'error' => 'No items selected']);
        exit;
    }
    $paymentMode = trim($_POST['payment_mode'] ?? 'GCash');
    $gcashRef    = trim($_POST['gcash_reference'] ?? '');
    $downPayment = (float)($_POST['down_payment_amount'] ?? 0);
    $orderNotes  = trim($_POST['order_notes'] ?? '');

    $productStmt = $conn->prepare("SELECT product_name, price, working_days FROM products WHERE record_type='product' AND product_id = ? AND is_active = 1 LIMIT 1");
    $productStmt->bind_param('i', $pid);
    $productStmt->execute();
    $product = $productStmt->get_result()->fetch_assoc();
    if (!$product) {
        echo json_encode(['success' => false, 'error' => 'The selected product is no longer available.']);
        exit;
    }
    $estimatedFinishDate = calculateEstimatedFinishDate($conn, date('Y-m-d'), (int)($product['working_days'] ?? 3));

    $normalizedItems = [];
    $totalPrice = 0.0;
    foreach ($variantItems as $item) {
        $variantId = isset($item['variant_id']) && $item['variant_id'] !== null ? (int)$item['variant_id'] : null;
        $qty = (int)($item['qty'] ?? 0);
        if ($qty < 1 || $qty > 1000) {
            echo json_encode(['success' => false, 'error' => 'Invalid item quantity.']);
            exit;
        }

        $serverName = $product['product_name'];
        $serverVariantName = '';
        $serverSize = '';
        $serverPrice = (float)$product['price'];
        $gender = strtolower(trim((string)($item['gender'] ?? 'male')));
        if (!in_array($gender, ['male', 'female'], true)) $gender = 'male';
        if ($variantId !== null && $variantId > 0) {
            $variantStmt = $conn->prepare("SELECT variant_name, size, price FROM products WHERE record_type='variant' AND variant_id = ? AND product_id = ? AND is_active = 1 LIMIT 1");
            $variantStmt->bind_param('ii', $variantId, $pid);
            $variantStmt->execute();
            $variant = $variantStmt->get_result()->fetch_assoc();
            if (!$variant) {
                echo json_encode(['success' => false, 'error' => 'One selected variant is no longer available.']);
                exit;
            }
            $serverVariantName = $variant['variant_name'];
            $serverSize = $variant['size'] ?? '';
            $serverPrice = (float)$variant['price'];
        }
        if ($serverSize !== '') $serverVariantName .= ' - Gender: ' . ucfirst($gender);

        $normalizedItems[] = [
            'variant_id' => $variantId,
            'variant_name' => $serverVariantName,
            'size' => $serverSize,
            'quantity' => $qty,
            'price' => $serverPrice,
        ];
        $totalPrice += $serverPrice * $qty;
    }
    if ($downPayment < 0 || $downPayment > $totalPrice) {
        echo json_encode(['success' => false, 'error' => 'Invalid payment amount.']);
        exit;
    }
    if ($totalPrice > 0 && $downPayment < ($totalPrice / 2)) {
        echo json_encode(['success' => false, 'error' => 'A minimum down payment of 50% is required.']);
        exit;
    }
    $pname = $product['product_name'];

    
    $imagePaths = [];
    if (isset($_FILES['ref_photos'])) {
        $fileCount = count($_FILES['ref_photos']['name']);
        $allowed = ['jpg','jpeg','png','gif','webp'];
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['ref_photos']['error'][$i] === UPLOAD_ERR_OK && is_valid_image_upload($_FILES['ref_photos']['tmp_name'][$i], $_FILES['ref_photos']['name'][$i])) {
                $ext = strtolower(pathinfo($_FILES['ref_photos']['name'][$i], PATHINFO_EXTENSION));
                if (in_array($ext, $allowed)) {
                    $filename = 'ref_' . $customerId . '_' . time() . '_' . mt_rand(1000,9999) . '_' . $i . '.' . $ext;
                    $dest = '../uploads/orders/' . $filename;
                    if (move_uploaded_file($_FILES['ref_photos']['tmp_name'][$i], $dest)) {
                        $imagePaths[] = 'uploads/orders/' . $filename;
                    }
                }
            }
        }
    }
    $imagePath = implode(',', $imagePaths);

    require_once '../notifications_helper.php';
    $custName = $_SESSION['username'] ?? 'Customer';
    $batchId = bin2hex(random_bytes(8));
    $conn->begin_transaction();
    try {
        $headerId = createOrderHeader($conn, [
            'customer_id' => $customerId, 'payment_mode' => $paymentMode, 'gcash_reference' => $gcashRef,
            'estimated_finish_date' => $estimatedFinishDate, 'status' => 'In Cart', 'batch_id' => $batchId,
        ]);
    } catch (Throwable $error) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Unable to save the order. Please try again.']);
        exit;
    }
    foreach ($normalizedItems as $itemIndex => $item) {
        $variantId   = $item['variant_id'];
        $variantName = $item['variant_name'];
        $size        = $item['size'];
        $qty         = $item['quantity'];
        $price       = $item['price'];
        $itemPayment = $itemIndex === 0 ? $downPayment : 0;
        if (!reserveInventory($conn, $pid, $variantId, $qty)) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Not enough stock available for one of the selected items.']);
            exit;
        }
        try {
            createOrderItem($conn, $headerId, [
                'product_id' => $pid, 'variant_id' => $variantId, 'product_name' => $pname,
                'variant_name' => $variantName, 'size' => $size, 'quantity' => $qty,
                'price' => $price, 'image' => $imagePath, 'order_notes' => $orderNotes,
                'paid_amount' => $itemPayment,
            ]);
        } catch (Throwable $error) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Unable to save the order. Please try again.']);
            exit;
        }
    }
    $conn->commit();

    $totalItems = count($normalizedItems);
    createNotification($conn, 'admin', 0, 'order', 'New Order', $custName . ' placed a bulk order for ' . $pname . ' (' . $totalItems . ' items)', 'orders.php');
    notifyAdminsOfNewOrder($conn, $custName . ' placed a bulk order for ' . $pname . ' (' . $totalItems . ' items).');

    createNotification($conn, 'customer', $customerId, 'order', 'Order Placed', 'Your order for ' . $pname . ' has been placed successfully.', 'customer_orders.php');

    
    require_once '../classes/HttpSmsGateway.php';
    $smsGateway = new HttpSmsGateway($conn);
    $smsConf = $smsGateway->getConfiguration();
    if (!empty($smsConf['gateway_type'])) {
        $cust = $conn->query("SELECT phone FROM customers WHERE customer_id = " . (int)$customerId)->fetch_assoc();
        if ($cust && !empty($cust['phone'])) {
            $smsMsg = "Hi {$custName}! We received your order for {$pname}. Thank you for choosing Zian's PrintShop! 🧡";
            $smsGateway->sendSMS($cust['phone'], $smsMsg);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
    require_csrf_token();
    header('Content-Type: application/json');
    $pid = (int)$_POST['product_id'];
    $pname = $_POST['product_name'];
    $variantId = isset($_POST['variant_id']) ? (int)$_POST['variant_id'] : null;
    $variantName = trim($_POST['variant_name'] ?? '');
    $size = trim($_POST['size'] ?? '');
    $qty = (int)$_POST['quantity'];
    $price = (float)$_POST['price'];
    $paymentMode    = trim($_POST['payment_mode'] ?? 'GCash');
    $gcashRef       = trim($_POST['gcash_reference'] ?? '');
    $downPayment    = (float)($_POST['down_payment_amount'] ?? 0);
    $orderNotes     = trim($_POST['order_notes'] ?? '');

    $productStmt = $conn->prepare("SELECT product_name, price, working_days FROM products WHERE record_type='product' AND product_id = ? AND is_active = 1 LIMIT 1");
    $productStmt->bind_param('i', $pid);
    $productStmt->execute();
    $product = $productStmt->get_result()->fetch_assoc();
    if (!$product) {
        echo json_encode(['success' => false, 'error' => 'The selected product is no longer available.']);
        exit;
    }
    $estimatedFinishDate = calculateEstimatedFinishDate($conn, date('Y-m-d'), (int)($product['working_days'] ?? 3));
    $pname = $product['product_name'];
    $price = (float)$product['price'];
    if ($variantId !== null && $variantId > 0) {
        $variantStmt = $conn->prepare("SELECT variant_name, size, price FROM products WHERE record_type='variant' AND variant_id = ? AND product_id = ? AND is_active = 1 LIMIT 1");
        $variantStmt->bind_param('ii', $variantId, $pid);
        $variantStmt->execute();
        $variant = $variantStmt->get_result()->fetch_assoc();
        if (!$variant) {
            echo json_encode(['success' => false, 'error' => 'The selected variant is no longer available.']);
            exit;
        }
        $variantName = $variant['variant_name'];
        $size = $variant['size'] ?? '';
        $price = (float)$variant['price'];
    }
    if ($qty < 1 || $qty > 1000 || $downPayment < 0 || $downPayment > ($price * $qty)) {
        echo json_encode(['success' => false, 'error' => 'Invalid quantity or payment amount.']);
        exit;
    }
    if ($price > 0 && $downPayment < (($price * $qty) / 2)) {
        echo json_encode(['success' => false, 'error' => 'A minimum down payment of 50% is required.']);
        exit;
    }
    
    $imagePaths = [];
    if (isset($_FILES['ref_photos'])) {
        $fileCount = count($_FILES['ref_photos']['name']);
        $allowed = ['jpg','jpeg','png','gif','webp'];
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['ref_photos']['error'][$i] === UPLOAD_ERR_OK && is_valid_image_upload($_FILES['ref_photos']['tmp_name'][$i], $_FILES['ref_photos']['name'][$i])) {
                $ext = strtolower(pathinfo($_FILES['ref_photos']['name'][$i], PATHINFO_EXTENSION));
                if (in_array($ext, $allowed)) {
                    $filename = 'ref_' . $customerId . '_' . time() . '_' . mt_rand(1000,9999) . '_' . $i . '.' . $ext;
                    $dest = '../uploads/orders/' . $filename;
                    if (move_uploaded_file($_FILES['ref_photos']['tmp_name'][$i], $dest)) {
                        $imagePaths[] = 'uploads/orders/' . $filename;
                    }
                }
            }
        }
    }
    $imagePath = implode(',', $imagePaths);

    $conn->begin_transaction();
    if (!reserveInventory($conn, $pid, $variantId, $qty)) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Not enough stock available for this item.']);
        exit;
    }
    try {
        $headerId = createOrderHeader($conn, [
            'customer_id' => $customerId, 'payment_mode' => $paymentMode, 'gcash_reference' => $gcashRef,
            'estimated_finish_date' => $estimatedFinishDate, 'status' => 'In Cart',
        ]);
        createOrderItem($conn, $headerId, [
            'product_id' => $pid, 'variant_id' => $variantId, 'product_name' => $pname,
            'variant_name' => $variantName, 'size' => $size, 'quantity' => $qty,
            'price' => $price, 'image' => $imagePath, 'order_notes' => $orderNotes,
            'paid_amount' => $downPayment,
        ]);
    } catch (Throwable $error) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Unable to save the order. Please try again.']);
        exit;
    }
    $conn->commit();

    require_once '../notifications_helper.php';
    $custName = $_SESSION['username'] ?? 'Customer';
    $varLabel = $variantName ? " ($variantName)" : '';
    createNotification($conn, 'admin', 0, 'order', 'New Order', $custName . ' ordered ' . $pname . $varLabel . ' x' . $qty, 'orders.php');
    notifyAdminsOfNewOrder($conn, $custName . ' ordered ' . $pname . $varLabel . ' x' . $qty . '.');
    createNotification($conn, 'customer', $customerId, 'order', 'Order Placed', 'Your order for ' . $pname . ' has been placed successfully.', 'customer_orders.php');
    
    
    require_once '../classes/HttpSmsGateway.php';
    $smsGateway = new HttpSmsGateway($conn);
    $smsConf = $smsGateway->getConfiguration();
    if (!empty($smsConf['gateway_type'])) {
        $cust = $conn->query("SELECT phone FROM customers WHERE customer_id = " . (int)$customerId)->fetch_assoc();
        if ($cust && !empty($cust['phone'])) {
            $smsMsg = "Hi {$custName}! We received your order for {$pname}. Thank you for choosing Zian's PrintShop! 🧡";
            $smsGateway->sendSMS($cust['phone'], $smsMsg);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

$products = [];
$productsRes = $conn->query("SELECT p.*, pc.category_name, (SELECT CASE WHEN COUNT(NULLIF(pv.image, '')) > 0 THEN COUNT(DISTINCT NULLIF(pv.image, '')) ELSE COUNT(*) END FROM products pv WHERE pv.record_type='variant' AND pv.product_id = p.product_id AND pv.is_active = 1) as variant_count FROM products p LEFT JOIN products pc ON pc.record_type='category' AND pc.category_id = p.category_id WHERE p.record_type='product' AND p.is_active = 1 ORDER BY p.product_id DESC");
while ($r = $productsRes->fetch_assoc()) $products[] = $r;

$categories = [];
$catRes = $conn->query("SELECT pc.*, (SELECT COUNT(*) FROM products p WHERE p.record_type='product' AND p.category_id = pc.category_id AND p.is_active = 1) as product_count FROM products pc WHERE pc.record_type='category' ORDER BY pc.category_id ASC");
if ($catRes) { while ($r = $catRes->fetch_assoc()) $categories[] = $r; }

$catEmojis = [
    'T-shirt' => ['👕', '#FFF6F6'], 'Sublimation' => ['👕', '#FFF6F6'], 'shirt' => ['👕', '#FFF6F6'],
    'Photo' => ['🖼️', '#F5F8FF'], 'Printing' => ['🖨️', '#F5F8FF'],
    'Mug' => ['☕', '#F3FBF3'], 'Tumbler' => ['☕', '#F3FBF3'],
    'Gift' => ['🎁', '#FFFBF0'], 'Custom' => ['🎨', '#FFFBF0'],
    'Tarpaulin' => ['🏷️', '#FAF6FF'], 'Sticker' => ['🏷️', '#FAF6FF'], 'Banner' => ['🏷️', '#FAF6FF'],
    'Fan' => ['🌀', '#F5F8FF'], 'Electric' => ['🌀', '#F5F8FF'],
];
function getCatStyle($name, $map) {
    foreach ($map as $key => $val) {
        if (stripos($name, $key) !== false) return $val;
    }
    return ['📦', '#F9F9F9'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop - Zian's PrintShop</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/customer.css?v=<?= time() ?>">
</head>
<body>

<?php 
$currentPage = 'customer_shop.php';
include 'sidebar.php'; 
?>

<div class="main-content">

    
    <div class="shop-header-card">
        <?php if (!empty($categories)): ?>
        <div class="cat-pills-container">
            <button class="pill-btn active" onclick="filterCategory('all', this)">All Products</button>
            <?php foreach ($categories as $cat): 
                $_jsCat = str_replace(["\r\n","\r","\n"], ' ', htmlspecialchars($cat['category_name'], ENT_QUOTES));
            ?>
                <button class="pill-btn" onclick="filterCategory('<?= $_jsCat ?>', this)"><?= htmlspecialchars($cat['category_name']) ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    
    <div class="section" id="productsSection" style="padding: 10px 40px 10px;">

        <?php if (empty($products)): ?>
            <div class="empty-state">
                <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="20" height="20" rx="2"/><path d="M12 8v8m-4-4h8"/></svg>
                <h3>No products available yet</h3>
                <p>Check back soon for new products and services!</p>
            </div>
        <?php else: ?>
            <div class="products-grid">
                <?php foreach ($products as $p):
                    $_pImg = $p['img'] ?? '';
                    if (strpos($_pImg, '../') === 0) $_pImg = substr($_pImg, 3);
                    $_jsName = str_replace(["\r\n","\r","\n"], ' ', htmlspecialchars($p['product_name'], ENT_QUOTES));
                    $_jsDesc = str_replace(["\r\n","\r","\n"], ' ', htmlspecialchars($p['description'] ?? '', ENT_QUOTES));
                    $_jsCategory = htmlspecialchars($p['category_name'] ?? '', ENT_QUOTES);
                    $_catLabel = htmlspecialchars($p['category_name'] ?? '');
                    $_price = !empty($p['price']) ? '₱' . number_format((float)$p['price'], 2) : '';
                    $catStyle = getCatStyle($p['category_name'] ?? '', $catEmojis);
                ?>
                <div class="product-card" onclick="openAlbum(<?= $p['product_id'] ?>, '<?= $_jsName ?>', '<?= $_jsDesc ?>', <?= $p['variant_count'] ?>, '<?= $_jsCategory ?>', <?= (float)($p['price'] ?? 0) ?>, '<?= htmlspecialchars($_pImg, ENT_QUOTES) ?>')">
                    <div class="product-img" style="background:<?= $catStyle[1] ?>;">
                        <?php if (!empty($_pImg)): ?>
                            <img src="../<?= htmlspecialchars($_pImg) ?>" alt="<?= htmlspecialchars($p['product_name']) ?>">
                        <?php else: ?>
                            <span style="font-size:64px;"><?= $catStyle[0] ?></span>
                        <?php endif; ?>
                        <?php if ($p['variant_count'] > 0): ?>
                            <span class="product-variant-badge"><?= $p['variant_count'] ?> variant<?= $p['variant_count'] > 1 ? 's' : '' ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="product-info">
                        <span class="product-category-tag" style="display:none;"><?= $_catLabel ?></span>
                        <p class="product-service-type" style="display:none;"><?= htmlspecialchars($p['description'] ?? '') ?></p>
                        <h3><?= htmlspecialchars($p['product_name']) ?></h3>
                        <?php if (!empty($p['price']) && $p['price'] > 0): ?>
                            <div class="product-price-tag">₱<?= number_format((float)$p['price'], 2) ?> PHP</div>
                        <?php else: ?>
                            <div class="product-price-tag">Inquire for price</div>
                        <?php endif; ?>
                        <div class="product-actions">
                            <?php if ($p['variant_count'] > 0): ?>
                                <button class="btn-add-cart" onclick="event.stopPropagation(); openAlbum(<?= $p['product_id'] ?>, '<?= $_jsName ?>', '<?= $_jsDesc ?>', <?= $p['variant_count'] ?>, '<?= $_jsCategory ?>', <?= (float)($p['price'] ?? 0) ?>, '<?= htmlspecialchars($_pImg, ENT_QUOTES) ?>')">Order now</button>
                            <?php else: ?>
                                <button class="btn-add-cart" onclick="event.stopPropagation(); quickOrder(<?= $p['product_id'] ?>, '<?= $_jsName ?>', '<?= $_jsDesc ?>', '<?= $_jsCategory ?>', <?= (float)($p['price'] ?? 0) ?>, '<?= htmlspecialchars($_pImg, ENT_QUOTES) ?>')">Order now</button>
                            <?php endif; ?>
                            <a href="customer_messages.php?inquire=<?= urlencode($p['product_name']) ?>" class="btn-inquire" onclick="event.stopPropagation();">Inquire</a>
                            <button class="btn-heart" onclick="event.stopPropagation(); this.innerHTML=this.innerHTML==='♡'?'♥':'♡'; this.style.color=this.innerHTML==='♥'?'#ff6b35':'#999';">♡</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    
    <div class="shop-pagination">
        <div style="flex:1;"></div>
        <div class="pagination-info" id="paginationInfo">1 / 1</div>
        <div class="pagination-btn-container">
            <button class="btn-pagination-next" id="btnPaginationNext" onclick="handlePaginationNext()">Next &rsaquo;</button>
        </div>
    </div>

    
    <footer class="site-footer" style="background:transparent; border-top:1px solid rgba(255,255,255,0.06); color:rgba(255,255,255,0.45); padding:30px 20px; font-weight:500;">
        &copy; 2026 <span style="color:#ff6b35;">Zian's PrintShop</span> — Toril, Davao City. All rights reserved.
    </footer>

</div>

<div id="checkoutModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(4px);">
    <div class="co-modal">
        <div class="co-header">
            <div class="co-header-top">
                <span class="co-product-meta" id="modalProductMeta"></span>
                <button onclick="closeCheckout()" class="co-close-btn">&times;</button>
            </div>
            <div class="co-steps">
                <div class="checkout-step active" id="si-1">
                    <div class="checkout-step-num">1</div>
                    <div class="checkout-step-label">Cart</div>
                </div>
                <div class="checkout-step-line" id="sl-12"></div>
                <div class="checkout-step" id="si-2">
                    <div class="checkout-step-num">2</div>
                    <div class="checkout-step-label">Details</div>
                </div>
                <div class="checkout-step-line" id="sl-23"></div>
                <div class="checkout-step" id="si-3">
                    <div class="checkout-step-num">3</div>
                    <div class="checkout-step-label">Payment</div>
                </div>
            </div>
        </div>

        <div class="co-body">

        
        <div id="checkout-step1">
            <h3 id="step1ProductName" class="co-title"></h3>
            <p id="step1ProductDesc" class="co-desc"></p>
            
            <div id="variantGrid"></div>

            <div id="cartListSection" class="co-cart-section">
                <div class="co-section-label">YOUR ORDER</div>
                <div id="cartList"></div>
            </div>

            <div class="co-btn-row">
                                <button onclick="backToProductDetail()" class="co-btn co-btn-secondary">← Back</button>
                <button onclick="goToStep(2)" id="s1ContinueBtn" class="co-btn co-btn-primary">Continue &rarr;</button>
            </div>
        </div>

        
        <div id="checkout-step2" style="display:none;">
            <div class="co-summary-card">
                <div class="co-section-label" style="margin-bottom:0.5rem;">Order Summary</div>
                <div style="display:flex; align-items:flex-start; gap:0.65rem;">
                    <img id="sumImg" class="co-summary-img">
                    <div style="flex:1; min-width:0;">
                        <div id="sumProduct" class="co-summary-name"></div>
                        <div id="sumVariant" class="co-summary-variants"></div>
                        <div id="sumQtyPrice" class="co-summary-total"></div>
                    </div>
                </div>
            </div>
            
            <label class="co-label">Order Notes <span style="color:rgba(255,255,255,0.35); font-weight:400;">(optional)</span></label>
            <textarea id="checkoutNotes" placeholder="Colors, font style, size, delivery notes..." class="co-textarea" onfocus="this.classList.add('focused')" onblur="this.classList.remove('focused')"></textarea>
            
            <div class="co-btn-row">
                <button onclick="backToProductDetail()" class="co-btn co-btn-secondary">&larr; Back</button>
                <button onclick="goToStep(3)" class="co-btn co-btn-primary">Continue &rarr;</button>
            </div>
        </div>

        
        <div id="checkout-step3" style="display:none;">
            <input type="hidden" id="checkoutGcashRef" value="">
            <input type="hidden" id="checkoutAmount" value="0">

            <div class="co-gcash-card">
                <div class="co-gcash-logo-wrap">
                    <img src="../download.jpg" alt="GCash" class="co-gcash-svg">
                    <span class="co-gcash-coming">Integration coming soon</span>
                </div>

                <div class="co-gcash-total-row">
                    <span class="co-gcash-total-label">Order Total</span>
                    <span class="co-gcash-total-val" id="step3Total">&#8369;0.00</span>
                </div>

                <div class="co-gcash-note">
                    GCash payment integration will be available soon. Place your order now and pay upon pickup or via GCash when ready.
                </div>
            </div>

            <div class="co-btn-row">
                <button onclick="goToStep(2)" class="co-btn co-btn-secondary">&larr; Back</button>
                <button onclick="placeOrder()" id="placeOrderBtn" class="co-btn co-btn-accent">Place Order &#10003;</button>
            </div>
        </div>

        
        <div id="checkout-success" style="display:none; text-align:center; padding:1.5rem 1rem;">
            <div style="width:52px; height:52px; background:linear-gradient(135deg,#22c55e,#16a34a); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 0.75rem;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20,6 9,17 4,12"/></svg>
            </div>
            <h3 style="font-size:1.05rem; font-weight:800; color:#fff; margin-bottom:0.35rem;">Order Placed!</h3>
            <p style="color:rgba(255,255,255,0.5); font-size:0.78rem; margin-bottom:0.65rem; line-height:1.5;">Your order has been received. Redirecting to My Orders...</p>
            <div style="display:inline-block; max-width:440px; padding:0.7rem 0.9rem; margin:0 auto 1rem; border:1px solid rgba(255,166,77,0.45); border-radius:12px; background:rgba(255,166,77,0.08); color:#ffd9a8; font-size:0.72rem; line-height:1.5; text-align:center;">
                <strong style="color:#ffb870;">Disclaimer:</strong> Once the product is already in production, the order is non-refundable. Please review all details carefully before checkout to avoid scams.
            </div>
            <div style="height:3px; background:rgba(255,255,255,0.06); border-radius:3px; overflow:hidden; margin-bottom:1rem;">
                <div id="successProgress" style="height:100%; background:linear-gradient(90deg,#ff6b35,#22c55e); width:0%; transition:width 2s linear;"></div>
            </div>
            <a href="customer_orders.php" class="co-btn co-btn-primary" style="display:inline-block; text-decoration:none; padding:8px 20px;">View My Orders &rarr;</a>
        </div>
    </div> 
    </div> 
</div> 

<div id="productDetailModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(6px);" onclick="if(event.target===this)closeProductDetail()">
    <div class="pd-modal-content" style="background:#ffffff; border-radius:24px; width:94%; max-width:820px; overflow-y:auto; position:relative; border:1px solid rgba(0,0,0,0.06); box-shadow:0 20px 60px rgba(0,0,0,0.25);">
        <button onclick="closeProductDetail()" style="position:absolute; top:1.2rem; right:1.2rem; background:rgba(0,0,0,0.04); border:none; color:#111111; font-size:1.3rem; cursor:pointer; z-index:10; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; transition:all 0.2s;" onmouseover="this.style.background='rgba(0,0,0,0.08)'" onmouseout="this.style.background='rgba(0,0,0,0.04)'">&times;</button>

        
        <div id="pdModalBody" style="display:flex; flex-direction:row; width:100%; min-height:520px;">

            
            <div id="pdImgPanel" style="flex:1; position:relative; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:2rem 1.5rem 1.5rem; box-sizing:border-box; background:radial-gradient(ellipse at 50% 30%, #1a1a1e 0%, #0e0e10 100%); border-radius:20px 0 0 20px; border-right:1px solid rgba(255,255,255,0.06); min-width:0;">

                
                <div id="pdMainImgWrap" onclick="openDetailImgFull()" style="width:100%; max-width:340px; aspect-ratio:1; border-radius:16px; overflow:hidden; background:#121214; display:flex; align-items:center; justify-content:center; cursor:zoom-in; position:relative; border:1px solid rgba(255,255,255,0.07); box-shadow:0 20px 50px rgba(0,0,0,0.6);">
                    <button id="pdNavPrev" class="pd-nav-btn" onclick="event.stopPropagation();prevDetailVariant()" style="display:none; left:10px;" title="Previous">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <button id="pdNavNext" class="pd-nav-btn" onclick="event.stopPropagation();nextDetailVariant()" style="display:none; right:10px;" title="Next">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                    <img id="pdMainImg" src="" alt="" style="width:100%; height:100%; object-fit:cover; display:none; transition:transform 0.4s ease;" onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                    <div id="pdMainImgPlaceholder" style="display:flex; flex-direction:column; align-items:center; justify-content:center; color:rgba(255,255,255,0.2); gap:0.5rem; padding:2rem;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                        <span style="font-size:0.7rem;">No image</span>
                    </div>
                    <div id="pdDotsWrap" style="position:absolute; bottom:10px; left:50%; transform:translateX(-50%); z-index:3; display:flex; gap:5px; background:rgba(0,0,0,0.4); padding:4px 8px; border-radius:100px; backdrop-filter:blur(6px); align-items:center;"></div>
                    <div id="pdZoomHint" class="pd-zoom-hint-badge" style="display:none;">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <span>Click to zoom</span>
                    </div>
                </div>

                
                <div id="pdThumbStrip" style="display:flex; gap:8px; flex-wrap:wrap; justify-content:center; max-width:340px; margin-top:1rem;"></div>
            </div>

            
            <div id="pdInfoCard" class="pd-info-card-overlay">
                <div id="pdCategory" style="font-size:0.6rem; font-weight:700; color:#ff6b35; text-transform:uppercase; letter-spacing:2px; margin-bottom:0.6rem;"></div>
                <h2 id="pdProductName" style="font-size:1.4rem; font-weight:900; color:#111111; margin:0 0 0.2rem; line-height:1.15; letter-spacing:-0.4px;"></h2>
                <div id="pdProductDesc" style="display:none;"></div>
                <div id="pdBasePrice" style="font-size:1.55rem; font-weight:800; color:#111111; margin-bottom:1.1rem; letter-spacing:-0.5px;"></div>

                <div id="pdSizesContainer" style="margin-bottom:1rem; display:none;">
                    <div style="font-size:0.6rem; font-weight:800; color:#111111; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:0.45rem;">Size</div>
                    <div class="pd-gender-options" role="group" aria-label="Choose sizing gender">
                        <button type="button" class="pd-gender-btn active" data-gender="male" onclick="setProductGender('male')">Male</button>
                        <button type="button" class="pd-gender-btn" data-gender="female" onclick="setProductGender('female')">Female</button>
                    </div>
                    <div id="pdSizesGrid" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                </div>

                <div id="pdVariantSwatches" style="display:none; margin-bottom:1rem;"></div>
                <div id="pdVariantInfo" style="display:none; font-size:0.72rem; color:#666666; margin-top:-0.5rem; margin-bottom:1rem;"></div>

                <div class="pd-divider" style="margin:0 0 1rem;"></div>

                <div id="pdCartSection" style="display:none; margin-bottom:1rem;">
                    <div style="font-size:0.6rem; font-weight:800; color:#111111; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:0.45rem;">Your Order</div>
                    <div id="pdInlineCart" style="max-height:130px; overflow-y:auto;"></div>
                </div>

                <div style="display:flex; flex-direction:column; gap:0.5rem; margin-top:auto;">
                    <button onclick="addToBag()" id="pdOrderNowBtn" style="padding:13px 20px; background:#000000; color:#ffffff; border:none; border-radius:50px; font-size:0.85rem; font-weight:700; cursor:pointer; font-family:inherit; transition:all 0.2s ease; box-shadow:0 4px 12px rgba(0,0,0,0.15); letter-spacing:0.3px;">Order Now</button>
                    <button onclick="inquireProduct()" style="padding:11px 20px; background:transparent; color:#111111; border:1.5px solid #111111; border-radius:50px; font-size:0.8rem; font-weight:600; cursor:pointer; font-family:inherit; transition:all 0.2s; display:flex; align-items:center; justify-content:center; gap:0.4rem;" onmouseover="this.style.borderColor='#ff6b35';this.style.color='#ff6b35'" onmouseout="this.style.borderColor='#111111';this.style.color='#111111'">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Inquire / Customize
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<div id="pdImgViewer" onclick="this.style.display='none'" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.93); z-index:1100; align-items:center; justify-content:center; cursor:zoom-out;">
    <img id="pdViewerImg" src="" alt="" style="max-width:92vw; max-height:92vh; object-fit:contain; border-radius:8px;">
    <button onclick="document.getElementById('pdImgViewer').style.display='none'" style="position:absolute; top:1rem; right:1.5rem; background:none; border:none; color:#fff; font-size:2rem; cursor:pointer; opacity:0.8;">&times;</button>
</div>

<style>
.co-modal {
    background: #0e0e10;
    border-radius: 20px;
    width: 94%;
    max-width: 480px;
    position: relative;
    max-height: 88vh;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    border: 1px solid rgba(255,255,255,0.06);
    box-shadow: 0 30px 80px rgba(0,0,0,0.8);
    color: #fff;
}
.co-header {
    padding: 0.85rem 1rem 0;
    position: sticky;
    top: 0;
    background: #0e0e10;
    z-index: 5;
    border-radius: 20px 20px 0 0;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    padding-bottom: 0.7rem;
}
.co-header-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.6rem;
}
.co-product-meta {
    font-size: 0.68rem;
    color: rgba(255,255,255,0.4);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.co-close-btn {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: none;
    background: rgba(255,255,255,0.08);
    color: #fff;
    font-size: 1.15rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    line-height: 1;
    padding: 0;
}
.co-close-btn:hover {
    background: rgba(255,255,255,0.2);
}
.co-steps {
    display: flex;
    align-items: center;
}
.co-body {
    padding: 0.75rem 1rem 1rem;
}
.co-title {
    font-size: 0.95rem;
    font-weight: 800;
    color: #fff;
    margin-bottom: 0.15rem;
    line-height: 1.3;
}
.co-desc {
    color: rgba(255,255,255,0.5);
    font-size: 0.72rem;
    margin-bottom: 0.75rem;
    line-height: 1.45;
}
.co-section-label {
    font-size: 0.62rem;
    font-weight: 700;
    color: rgba(255,255,255,0.4);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 0.4rem;
}
.co-cart-section {
    margin-top: 0.5rem;
    border-top: 1px solid rgba(255,255,255,0.06);
    padding-top: 0.5rem;
}
.co-btn-row {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.65rem;
    padding-top: 0.65rem;
    border-top: 1px solid rgba(255,255,255,0.06);
}
.co-btn {
    padding: 10px 16px;
    border-radius: 50px;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.2s;
    border: none;
    text-align: center;
}
.co-btn-secondary {
    flex: 1;
    background: transparent;
    border: 1.5px solid rgba(255,255,255,0.15);
    color: #fff;
}
.co-btn-secondary:hover {
    border-color: #fff;
    background: rgba(255,255,255,0.05);
}
.co-btn-primary {
    flex: 2;
    background: #fff;
    color: #0e0e10;
}
.co-btn-primary:hover {
    background: #ff6b35;
    color: #fff;
}
.co-btn-accent {
    flex: 2;
    background: #fff;
    color: #0e0e10;
}
.co-btn-accent:hover {
    background: #ff6b35;
    color: #fff;
}

.co-summary-card {
    background: #121214;
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 10px;
    padding: 0.7rem;
    margin-bottom: 0.75rem;
}
.co-summary-img {
    width: 48px;
    height: 48px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
    display: none;
}
.co-summary-name {
    font-size: 0.82rem;
    font-weight: 700;
    color: #fff;
    margin-bottom: 2px;
}
.co-summary-variants {
    font-size: 0.72rem;
    color: rgba(255,255,255,0.5);
    margin-bottom: 2px;
}
.co-summary-total {
    font-size: 0.75rem;
    color: #ff6b35;
    font-weight: 700;
}
.co-label {
    display: block;
    color: rgba(255,255,255,0.7);
    font-size: 0.72rem;
    font-weight: 600;
    margin-bottom: 0.3rem;
}
.co-textarea {
    width: 100%;
    padding: 8px 10px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 8px;
    color: #fff;
    font-size: 0.78rem;
    margin-bottom: 0.75rem;
    outline: none;
    resize: vertical;
    min-height: 64px;
    box-sizing: border-box;
    font-family: inherit;
    line-height: 1.5;
    transition: border-color 0.2s;
}
.co-textarea.focused { border-color: #ff6b35; }

.co-gcash-card {
    background: linear-gradient(135deg, #001a4d, #002b80);
    border: 1px solid rgba(0,125,254,0.35);
    border-radius: 12px;
    padding: 1.1rem;
    margin-bottom: 0.75rem;
}
.co-gcash-logo-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 1rem;
    padding-bottom: 0.85rem;
    border-bottom: 1px solid rgba(0,125,254,0.2);
}
.co-gcash-svg {
    height: 36px;
    width: auto;
    object-fit: contain;
}
.co-gcash-coming {
    font-size: 0.62rem;
    color: rgba(255,255,255,0.4);
    font-weight: 500;
    letter-spacing: 0.03em;
}
.co-gcash-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.85rem;
}
.co-gcash-total-label {
    font-size: 0.72rem;
    font-weight: 600;
    color: rgba(255,255,255,0.55);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.co-gcash-total-val {
    font-size: 1.25rem;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.02em;
}
.co-gcash-note {
    font-size: 0.68rem;
    color: rgba(255,255,255,0.38);
    line-height: 1.5;
    text-align: center;
}
.co-tip {
    background: #121214;
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 8px;
    padding: 0.6rem 0.7rem;
    margin-bottom: 0.75rem;
    font-size: 0.68rem;
    color: rgba(255,255,255,0.5);
    line-height: 1.5;
}
.co-tip strong { color: #fff; }

.checkout-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    flex-shrink: 0;
}
.checkout-step-num {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
    border: 2px solid rgba(255,255,255,0.1);
    color: rgba(255,255,255,0.4);
    font-size: 0.68rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
}
.checkout-step.active .checkout-step-num {
    background: #ff6b35;
    border-color: #ff6b35;
    color: #fff;
    box-shadow: 0 0 0 3px rgba(255,107,53,0.15);
}
.checkout-step.done .checkout-step-num {
    background: #22c55e;
    border-color: #22c55e;
    color: #fff;
}
.checkout-step-label {
    font-size: 0.58rem;
    font-weight: 600;
    color: rgba(255,255,255,0.4);
    transition: color 0.3s;
    white-space: nowrap;
}
.checkout-step.active .checkout-step-label { color: #ff6b35; }
.checkout-step.done .checkout-step-label { color: #22c55e; }
.checkout-step-line {
    flex: 1;
    height: 2px;
    background: rgba(255,255,255,0.08);
    margin: 0 6px;
    margin-bottom: 14px;
    transition: background 0.3s;
}
.checkout-step-line.done { background: #22c55e; }

.album-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
    gap: 6px;
    max-height: 230px;
    overflow-y: auto;
    padding-right: 2px;
}
.album-grid::-webkit-scrollbar { width: 4px; }
.album-grid::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }
.variant-card {
    background: #121214;
    border: 1.5px solid rgba(255,255,255,0.06);
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.18s;
}
.variant-card:hover { border-color: rgba(255,107,53,0.45); }
.variant-card.selected { border-color: #ff6b35; background: rgba(255,107,53,0.04); box-shadow: 0 0 0 1px rgba(255,107,53,0.15); }
.v-img {
    height: 60px;
    background: #18181c;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
}
.v-img img { width:100%; height:100%; object-fit:cover; }
.v-check {
    position: absolute;
    top: 3px;
    right: 3px;
    width: 16px;
    height: 16px;
    background: #ff6b35;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.15s;
}
.variant-card.selected .v-check { opacity: 1; }
.v-info { padding: 4px 5px 5px; }
.v-name { font-size: 0.62rem; font-weight: 700; color: #fff; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.v-size { font-size: 0.55rem; color: rgba(255,255,255,0.5); margin-top: 1px; }
.v-price { font-size: 0.62rem; color: #ff6b35; font-weight: 700; margin-top: 1px; }

.photo-preview-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    justify-content: flex-start;
}
.photo-thumb {
    position: relative;
    width: 50px;
    height: 50px;
}
.photo-thumb img { width:100%; height:100%; object-fit:cover; border-radius:6px; border:1.5px solid hsl(214 32% 88%); }
.photo-thumb button {
    position: absolute;
    top: -3px;
    right: -3px;
    width: 14px;
    height: 14px;
    background: #ff4444;
    border: none;
    border-radius: 50%;
    color: #fff;
    font-size: 0.55rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

#productDetailModal {
    --pd-accent: #ff6b35;
    --pd-accent-rgb: 255, 107, 53;
}

.pd-thumb {
    width: 54px; height: 54px; border-radius: 10px; overflow: hidden;
    border: 2px solid rgba(255,255,255,0.08); transition: all 0.22s ease;
    cursor: pointer; flex-shrink: 0;
    background: #18181c;
    display: flex; align-items: center; justify-content: center;
}
.pd-thumb img { width:100%; height:100%; object-fit:cover; }
.pd-thumb.active { 
    border-color: var(--pd-accent); 
    transform: translateY(-2px); 
    box-shadow: 0 4px 12px rgba(var(--pd-accent-rgb), 0.25); 
}
.pd-thumb:hover { border-color: rgba(var(--pd-accent-rgb), 0.5); }

.pd-nav-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 4;
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.25);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    opacity: 0;
    padding: 0;
    outline: none;
}
#pdMainImgWrap:hover .pd-nav-btn {
    opacity: 1;
}
.pd-nav-btn:hover {
    background: var(--pd-accent);
    color: #fff;
    transform: translateY(-50%) scale(1.08);
    box-shadow: 0 6px 15px rgba(var(--pd-accent-rgb), 0.45);
    border-color: transparent;
}

.pd-zoom-hint-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    color: #fff;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 5px 10px;
    border-radius: 8px;
    pointer-events: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    border: 1px solid rgba(255,255,255,0.08);
    z-index: 3;
    display: flex;
    align-items: center;
    gap: 4px;
}

.pd-divider {
    width: 100%;
    height: 1px;
    background: linear-gradient(90deg, transparent, #ff6b35 30%, #ff8c42 50%, #ff6b35 70%, transparent);
    box-shadow: 0 0 8px rgba(255,107,53,0.4);
    border-radius: 1px;
}

.pd-size-btn {
    background: transparent;
    border: 1.5px solid rgba(0,0,0,0.18);
    border-radius: 6px;
    color: #111111;
    padding: 5px 13px; font-size: 0.68rem; font-weight: 700;
    cursor: pointer; transition: all 0.18s; min-width: 36px;
    text-align: center; font-family: inherit;
}
.pd-size-btn:hover {
    border-color: #ff6b35;
    color: #ff6b35;
    background: rgba(255,107,53,0.06);
}
.pd-size-btn.active {
    border-color: #ff6b35;
    background: #ff6b35;
    color: #ffffff;
    box-shadow: 0 2px 10px rgba(255,107,53,0.35);
    transform: scale(1.05);
}
.pd-gender-options { display:flex; gap:6px; margin-bottom:0.6rem; }
.pd-gender-btn { flex:1; padding:7px 8px; border:1px solid #d3d7dd; border-radius:8px; background:#fff; color:#111827; font:700 0.72rem inherit; cursor:pointer; }
.pd-gender-btn.active { background:#111827; border-color:#111827; color:#fff; }
.pd-gender-btn:hover { border-color:#ff6b35; }

.pd-color-swatch-wrap { display: flex; flex-wrap: wrap; gap: 7px; }
.pd-color-swatch {
    width: 32px; height: 32px; border-radius: 50%;
    border: 2px solid rgba(0,0,0,0.15); padding: 2px;
    cursor: pointer; transition: all 0.2s; background: transparent;
    position: relative;
}
.pd-color-swatch-inner {
    width: 100%; height: 100%; border-radius: 50%; overflow: hidden;
    background: #fdf5f5; border: 1px solid rgba(0,0,0,0.06);
}
.pd-color-swatch-inner img { width:100%; height:100%; object-fit:cover; }
.pd-color-swatch:hover { border-color: #111111; transform: scale(1.08); }
.pd-color-swatch.active {
    border-color: #ff6b35;
    box-shadow: 0 0 10px rgba(255,107,53,0.4);
    transform: scale(1.15);
}
.pd-color-swatch.active::after {
    content: ''; position: absolute; inset: -4px; border-radius: 50%;
    border: 1.5px solid rgba(255,107,53,0.3); pointer-events: none;
}

.pd-info-card-overlay {
    width: 300px;
    flex-shrink: 0;
    background: #ffffff;
    border-left: 1px solid rgba(0,0,0,0.06);
    padding: 1.75rem 1.5rem;
    box-sizing: border-box;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    border-radius: 0 20px 20px 0;
}

.pd-divider {
    width: 100%;
    height: 1px;
    background: #e5e5e5;
    margin: 0.75rem 0;
}

.pd-thumb {
    width: 50px; height: 50px; border-radius: 8px; overflow: hidden;
    border: 2px solid rgba(0,0,0,0.06); transition: all 0.2s ease;
    cursor: pointer; flex-shrink: 0;
    background: #fdf5f5;
    display: flex; align-items: center; justify-content: center;
}
.pd-thumb img { width:100%; height:100%; object-fit:cover; }
.pd-thumb.active {
    border-color: #ff6b35;
    transform: translateY(-3px);
    box-shadow: 0 4px 10px rgba(255,107,53,0.25);
}
.pd-thumb:hover { border-color: #111111; }

#pdOrderNowBtn {
    background: #000000 !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    font-weight: 700 !important;
    letter-spacing: 0.3px;
    border: none;
}
#pdOrderNowBtn:hover {
    background: #ff6b35 !important;
    box-shadow: 0 6px 18px rgba(255,107,53,0.45) !important;
    transform: translateY(-1px);
}

@media (min-width: 769px) {
    .pd-info-card-overlay {
        width: 360px;
    }
    .pd-divider { display: none; }
}
@media (max-width: 768px) {
    #pdImgPanel { border-radius: 20px 20px 0 0 !important; }
    #pdMainImgWrap { max-width: 100% !important; }
    .pd-nav-btn { opacity: 0.95; }
    .pd-info-card-overlay {
        width: 100%;
        margin-top: 1.5rem;
    }
}
</style>

<script>
const csrfToken = <?= json_encode(csrf_token()) ?>;

let currentProductId = '';
let currentProductName = '';
let currentProductCategory = '';
let currentProductBaseImg = '';
let currentProductBasePrice = 0;
let currentProductGender = 'male';
let selectedVariant = null;
let cartItems = [];
let _detailCurrentIdx = 0;
let currentStep = 1;
let s1Photos = [];
let _detailVariants = [];

let _currentPage = 1;
const _itemsPerPage = 8;

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}
function escAttr(str) {
    return (str || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
}

const catModalBgs = {
    'T-shirt': 'radial-gradient(circle at center, #3a1d11 0%, #0c0c0e 100%)',
    'Sublimation': 'radial-gradient(circle at center, #3a1d11 0%, #0c0c0e 100%)',
    'shirt': 'radial-gradient(circle at center, #3a1d11 0%, #0c0c0e 100%)',
    'Photo': 'radial-gradient(circle at center, #11203a 0%, #0c0c0e 100%)',
    'Printing': 'radial-gradient(circle at center, #112a3a 0%, #0c0c0e 100%)',
    'Mug': 'radial-gradient(circle at center, #113a20 0%, #0c0c0e 100%)',
    'Tumbler': 'radial-gradient(circle at center, #113a20 0%, #0c0c0e 100%)',
    'Gift': 'radial-gradient(circle at center, #3a2e11 0%, #0c0c0e 100%)',
    'Custom': 'radial-gradient(circle at center, #3a1130 0%, #0c0c0e 100%)',
    'Tarpaulin': 'radial-gradient(circle at center, #23113a 0%, #0c0c0e 100%)',
    'Sticker': 'radial-gradient(circle at center, #23113a 0%, #0c0c0e 100%)',
    'Banner': 'radial-gradient(circle at center, #23113a 0%, #0c0c0e 100%)',
    'Fan': 'radial-gradient(circle at center, #112c3a 0%, #0c0c0e 100%)',
    'Electric': 'radial-gradient(circle at center, #112c3a 0%, #0c0c0e 100%)',
};
function getModalBg(name) {
    return '#fdf5f5'; 
}

function setProductGender(gender) {
    currentProductGender = gender === 'female' ? 'female' : 'male';
    if (selectedVariant) selectedVariant.gender = currentProductGender;
    document.querySelectorAll('.pd-gender-btn').forEach(button => {
        button.classList.toggle('active', button.dataset.gender === currentProductGender);
    });
}

function openAlbum(productId, productName, productDesc, variantCount, category, basePrice, baseImg) {
    currentProductId = productId;
    currentProductName = productName;
    currentProductCategory = category || '';
    currentProductBaseImg = baseImg || '';
    currentProductBasePrice = parseFloat(basePrice) || 0;
    setProductGender('male');
    selectedVariant = null;
    cartItems = [];
    _detailCurrentIdx = 0;
    s1Photos = [];
    _detailVariants = [];

    document.getElementById('pdCategory').textContent = category || 'Printing Service';
    document.getElementById('pdProductName').textContent = productName;
    document.getElementById('pdProductDesc').textContent = productDesc || 'Quality printing service';
    const priceNum = parseFloat(basePrice || 0);
    document.getElementById('pdBasePrice').textContent = priceNum > 0
        ? '\u20B1' + priceNum.toLocaleString('en-PH', {minimumFractionDigits:2})
        : 'Price on inquiry';

    setDetailMainImage('');
    document.getElementById('pdThumbStrip').innerHTML = '';
    document.getElementById('pdVariantSwatches').style.display = 'none';
    document.getElementById('pdVariantSwatches').innerHTML = '';
    document.getElementById('pdVariantInfo').style.display = 'none';
    document.getElementById('pdZoomHint').style.display = 'none';
    document.getElementById('pdSizesContainer').style.display = 'none';
    document.getElementById('pdSizesGrid').innerHTML = '';
    document.getElementById('pdCartSection').style.display = 'none';
    renderPdInlineCart();
    const orderBtn = document.getElementById('pdOrderNowBtn');
    if (orderBtn) orderBtn.textContent = 'Order Now';

    document.getElementById('pdImgPanel').style.background = getModalBg(category);
    document.getElementById('productDetailModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    const cb = document.getElementById('chatbot');
    if (cb) cb.style.display = 'none';

    if (variantCount > 0) {
        document.getElementById('pdThumbStrip').innerHTML = '<div style="color:hsl(215 16% 47%);font-size:0.78rem;padding:0.4rem 0;">Loading designs...</div>';
        fetch('customer_shop.php?get_variants=' + productId)
            .then(r => r.json())
            .then(variants => {
                if (currentProductBaseImg) {
                    const baseImgSrc = currentProductBaseImg;
                    const isDuplicate = variants.some(v => v.image && v.image === baseImgSrc);
                    if (!isDuplicate) {
                        variants.unshift({
                            variant_id: 'base',
                            variant_name: productName,
                            image: baseImgSrc,
                            price: basePrice || 0,
                            size: '',
                            quantity: null
                        });
                    }
                }
                renderDetailVariants(variants);
            })
            .catch(() => { document.getElementById('pdThumbStrip').innerHTML = ''; });
    } else if (currentProductBaseImg) {
        setDetailMainImage('../' + currentProductBaseImg);
    }
}

function setDetailMainImage(src) {
    const img = document.getElementById('pdMainImg');
    const ph  = document.getElementById('pdMainImgPlaceholder');
    const zh  = document.getElementById('pdZoomHint');
    if (src) {
        img.src = src; img.style.display = 'block';
        ph.style.display = 'none'; zh.style.display = 'block';
    } else {
        img.style.display = 'none'; img.src = '';
        ph.style.display = 'flex'; zh.style.display = 'none';
    }
}

function openDetailImgFull() {
    const src = document.getElementById('pdMainImg').src;
    if (!src) return;
    document.getElementById('pdViewerImg').src = src;
    document.getElementById('pdImgViewer').style.display = 'flex';
}

function prevDetailVariant() {
    if (_detailVariants.length < 2) return;
    _detailCurrentIdx = (_detailCurrentIdx - 1 + _detailVariants.length) % _detailVariants.length;
    selectDetailVariant(_detailVariants[_detailCurrentIdx].variant_id);
}

function nextDetailVariant() {
    if (_detailVariants.length < 2) return;
    _detailCurrentIdx = (_detailCurrentIdx + 1) % _detailVariants.length;
    selectDetailVariant(_detailVariants[_detailCurrentIdx].variant_id);
}

function renderDetailVariants(variants) {
    _detailVariants = variants;
    _detailCurrentIdx = 0;
    const prevBtn = document.getElementById('pdNavPrev');
    const nextBtn = document.getElementById('pdNavNext');
    if (!variants.length) {
        document.getElementById('pdThumbStrip').innerHTML = '';
        if (prevBtn) prevBtn.style.display = 'none';
        if (nextBtn) nextBtn.style.display = 'none';
        const dotsWrap = document.getElementById('pdDotsWrap');
        if (dotsWrap) { dotsWrap.style.display = 'none'; dotsWrap.innerHTML = ''; }
        return;
    }
    if (prevBtn) prevBtn.style.display = variants.length > 1 ? 'flex' : 'none';
    if (nextBtn) nextBtn.style.display = variants.length > 1 ? 'flex' : 'none';

    const dotsWrap = document.getElementById('pdDotsWrap');
    if (dotsWrap) {
        if (variants.length > 1) {
            dotsWrap.style.display = 'flex';
            let dotsHtml = '';
            variants.forEach(v => {
                const isBase = v.variant_id === 'base';
                const vidAttr = isBase ? "'base'" : v.variant_id;
                dotsHtml += `<span class="pd-dot" id="pdd-${v.variant_id}" onclick="event.stopPropagation();selectDetailVariant(${vidAttr})" style="width:6px; height:6px; border-radius:50%; background:rgba(255,255,255,0.3); display:inline-block; cursor:pointer; transition:all 0.25s cubic-bezier(0.4, 0, 0.2, 1);"></span>`;
            });
            dotsWrap.innerHTML = dotsHtml;
        } else {
            dotsWrap.style.display = 'none';
            dotsWrap.innerHTML = '';
        }
    }

    let thumbHtml = '';
    variants.forEach(v => {
        const imgSrc = v.image ? '../' + v.image : '';
        const isBase = v.variant_id === 'base';
        const vidAttr = isBase ? "'base'" : v.variant_id;
        thumbHtml += `<div class="pd-thumb" id="pdt-${v.variant_id}" onclick="selectDetailVariant(${vidAttr})" title="${escHtml(v.variant_name)}">`
            + (imgSrc ? `<img src="${escHtml(imgSrc)}" alt="${escHtml(v.variant_name)}">` : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="hsl(214 32% 40%)" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>')
            + '</div>';
    });
    document.getElementById('pdThumbStrip').innerHTML = thumbHtml;

    const sizesContainer = document.getElementById('pdSizesContainer');
    const sizesGrid = document.getElementById('pdSizesGrid');
    const uniqueSizes = [...new Set(variants.map(v => v.size).filter(Boolean))];
    if (uniqueSizes.length > 0) {
        sizesContainer.style.display = 'block';
        let sizesHtml = '';
        uniqueSizes.forEach(size => {
            const match = variants.find(v => v.size === size);
            const isBase = match.variant_id === 'base';
            const vidAttr = isBase ? "'base'" : match.variant_id;
            sizesHtml += `<button class="pd-size-btn" id="pdsz-${size}" onclick="event.stopPropagation();selectDetailVariant(${vidAttr})">${size.toUpperCase()}</button>`;
        });
        sizesGrid.innerHTML = sizesHtml;
    } else {
        sizesContainer.style.display = 'none';
        sizesGrid.innerHTML = '';
    }

    const swatchEl = document.getElementById('pdVariantSwatches');
    let sw = '<div style="font-size:0.68rem; font-weight:700; color:rgba(255,255,255,0.5); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.5rem;">Select Design</div><div class="pd-color-swatch-wrap">';
    variants.forEach(v => {
        const imgSrc = v.image ? '../' + v.image : '';
        const isBase = v.variant_id === 'base';
        const vidAttr = isBase ? "'base'" : v.variant_id;
        sw += `<div class="pd-color-swatch" id="pds-${v.variant_id}" onclick="togglePdCartItem(${vidAttr})" title="${escHtml(v.variant_name)}">`
            + `<div class="pd-color-swatch-inner">`
            + (imgSrc ? `<img src="${escHtml(imgSrc)}" alt="${escHtml(v.variant_name)}">` : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="hsl(214 32% 40%)" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>')
            + `</div>`
            + '</div>';
    });
    sw += '</div>';
    swatchEl.innerHTML = sw;
    swatchEl.style.display = 'block';

    selectDetailVariant(variants[0].variant_id);
}

function selectDetailVariant(variantId) {
    const v = _detailVariants.find(x => x.variant_id == variantId);
    if (!v) return;
    const idx = _detailVariants.indexOf(v);
    if (idx >= 0) _detailCurrentIdx = idx;
    const imgSrc = v.image ? '../' + v.image : '';
    const isBase = v.variant_id === 'base';
    selectedVariant = { id: isBase ? null : v.variant_id, name: v.variant_name, size: v.size || '', gender: currentProductGender, price: v.price, img: imgSrc };

    setDetailMainImage(imgSrc);

    let accentHex = '#ff6b35';
    let accentRgb = '255, 107, 53';
    const colorMap = {
        'white':  { hex: '#ffffff', rgb: '255, 255, 255' },
        'black':  { hex: '#333333', rgb: '51, 51, 51' },
        'violet': { hex: '#a78bfa', rgb: '167, 139, 250' },
        'purple': { hex: '#c084fc', rgb: '192, 132, 252' },
        'blue':   { hex: '#60a5fa', rgb: '96, 165, 250' },
        'red':    { hex: '#f87171', rgb: '248, 113, 113' },
        'orange': { hex: '#ff6b35', rgb: '255, 107, 53' },
        'green':  { hex: '#34d399', rgb: '52, 211, 153' },
        'yellow': { hex: '#fbbf24', rgb: '251, 191, 36' },
        'grey':   { hex: '#9ca3af', rgb: '156, 163, 175' },
        'gray':   { hex: '#9ca3af', rgb: '156, 163, 175' }
    };
    const nameLower = v.variant_name.toLowerCase();
    for (const [key, colors] of Object.entries(colorMap)) {
        if (nameLower.includes(key)) {
            accentHex = colors.hex;
            accentRgb = colors.rgb;
            break;
        }
    }
    const modalEl = document.getElementById('productDetailModal');
    if (modalEl) {
        modalEl.style.setProperty('--pd-accent', accentHex);
        modalEl.style.setProperty('--pd-accent-rgb', accentRgb);
    }

    document.querySelectorAll('.pd-dot').forEach(el => {
        el.style.background = 'rgba(255,255,255,0.3)';
        el.style.width = '6px';
        el.style.borderRadius = '50%';
    });
    const activeDot = document.getElementById('pdd-' + variantId);
    if (activeDot) {
        activeDot.style.background = 'var(--pd-accent)';
        activeDot.style.width = '14px';
        activeDot.style.borderRadius = '10px';
    }

    document.querySelectorAll('.pd-thumb').forEach(el => el.classList.remove('active'));
    const th = document.getElementById('pdt-' + variantId);
    if (th) th.classList.add('active');

    document.querySelectorAll('.pd-color-swatch').forEach(el => el.classList.remove('active'));
    const sw = document.getElementById('pds-' + variantId);
    if (sw) sw.classList.add('active');

    document.querySelectorAll('.pd-size-btn').forEach(el => el.classList.remove('active'));
    if (v.size) {
        const sz = document.getElementById('pdsz-' + v.size);
        if (sz) sz.classList.add('active');
    }

    const infoEl = document.getElementById('pdVariantInfo');
    const pn = parseFloat(v.price);
    const qty = v.quantity !== null && v.quantity !== undefined ? parseInt(v.quantity) : null;
    let info = `<strong style="color:#fff">${escHtml(v.variant_name)}</strong>`;
    if (v.size) info += `<span style="color:rgba(255,255,255,0.4)"> &middot; Size: ${escHtml(v.size.toUpperCase())}</span>`;
    if (qty !== null) {
        if (qty > 0) {
            info += `<span style="color:#34d399; font-weight:600;"> &middot; ${qty} in stock</span>`;
        } else {
            info += `<span style="color:#f87171; font-weight:600;"> &middot; Out of stock</span>`;
        }
    }
    infoEl.innerHTML = info;
    infoEl.style.display = 'block';

    if (pn > 0) {
        document.getElementById('pdBasePrice').textContent = '\u20B1' + pn.toLocaleString('en-PH',{minimumFractionDigits:2});
    }
}

function togglePdCartItem(variantId) {
    const isBase = variantId === 'base';
    const matchId = isBase ? null : variantId;
    const idx = cartItems.findIndex(ci => ci.variant && ci.variant.id == matchId && ci.variant.gender === (ci.variant.size ? currentProductGender : ''));
    if (idx >= 0) {
        cartItems.splice(idx, 1);
        
        const sw = document.getElementById('pds-' + variantId);
        if (sw) sw.classList.remove('active');
    } else {
        const v = _detailVariants.find(x => x.variant_id == variantId);
        if (!v) return;
        const imgSrc = v.image ? '../' + v.image : '';
        cartItems.push({ variant: { id: isBase ? null : v.variant_id, name: v.variant_name, size: v.size || '', gender: v.size ? currentProductGender : '', price: v.price, img: imgSrc }, qty: 1 });
        
        const sw = document.getElementById('pds-' + variantId);
        if (sw) sw.classList.add('active');
    }
    renderPdInlineCart();
    
    const btn = document.getElementById('pdOrderNowBtn');
    if (btn) btn.textContent = cartItems.length > 0
        ? `Order Now (${cartItems.length} item${cartItems.length > 1 ? 's' : ''})`
        : 'Order Now';
}

function renderPdInlineCart() {
    const el = document.getElementById('pdInlineCart');
    if (!el) return;
    if (!cartItems.length) {
        el.innerHTML = '<div style="color:#888888;font-size:0.72rem;padding:0.25rem 0;">Tap a design above to add to order</div>';
        return;
    }
    let html = '';
    cartItems.forEach((ci, idx) => {
        const v = ci.variant;
        const imgHtml = v && v.img
            ? `<img src="${escHtml(v.img)}" style="width:30px;height:30px;object-fit:cover;border-radius:5px;flex-shrink:0;border:1px solid rgba(0,0,0,0.08);">`
            : `<div style="width:30px;height:30px;border-radius:5px;background:#f5f5f5;flex-shrink:0;"></div>`;
        const pn = parseFloat(v ? v.price : 0);
        const meta = [v && v.size ? v.size.toUpperCase() : '', v && v.gender ? (v.gender === 'female' ? 'Female' : 'Male') : '', pn > 0 ? '\u20b1'+pn.toLocaleString('en-PH',{minimumFractionDigits:2}) : ''].filter(Boolean).join(' \u00b7 ');
        html += `<div style="display:flex;align-items:center;gap:0.45rem;padding:0.3rem 0;border-bottom:1px solid rgba(0,0,0,0.06);">
            ${imgHtml}
            <div style="flex:1;min-width:0;">
                <div style="font-size:0.72rem;font-weight:700;color:#111111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(v ? v.name : 'Item')}</div>
                ${meta ? `<div style="font-size:0.6rem;color:#666666;">${meta}</div>` : ''}
            </div>
            <div style="display:flex;align-items:center;gap:2px;flex-shrink:0;">
                <button onclick="pdCartQty(${idx},-1)" style="width:18px;height:18px;border-radius:3px;border:1px solid rgba(0,0,0,0.15);background:transparent;color:#111111;font-size:0.75rem;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;line-height:1;">-</button>
                <input type="number" id="pdc-qty-${idx}" value="${ci.qty}" min="1" onchange="pdCartSetQty(${idx},this.value)" oninput="pdCartSetQty(${idx},this.value)" style="width:36px;height:20px;border:1px solid rgba(0,0,0,0.15);border-radius:3px;text-align:center;font-size:0.65rem;font-weight:700;color:#111111;background:transparent;outline:none;padding:0;">
                <button onclick="pdCartQty(${idx},1)" style="width:18px;height:18px;border-radius:3px;border:1px solid rgba(0,0,0,0.15);background:transparent;color:#111111;font-size:0.75rem;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;line-height:1;">+</button>
            </div>
            <button onclick="pdCartRemove(${idx})" style="background:none;border:none;color:#999999;font-size:0.9rem;cursor:pointer;flex-shrink:0;padding:1px;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#999999'">&times;</button>
        </div>`;
    });
    el.innerHTML = html;
}

function pdCartQty(idx, delta) {
    if (!cartItems[idx]) return;
    const el = document.getElementById('pdc-qty-' + idx);
    const cur = el ? (parseInt(el.value) || 1) : cartItems[idx].qty;
    cartItems[idx].qty = Math.max(1, cur + delta);
    if (el) el.value = cartItems[idx].qty;
}

function pdCartSetQty(idx, val) {
    if (!cartItems[idx]) return;
    const n = parseInt(val);
    cartItems[idx].qty = isNaN(n) || n < 1 ? 1 : n;
}

function pdCartRemove(idx) {
    if (!cartItems[idx]) return;
    const vid = cartItems[idx].variant && cartItems[idx].variant.id;
    
    const sw = document.getElementById('pds-' + (vid === null ? 'base' : vid));
    if (sw) sw.classList.remove('active');
    cartItems.splice(idx, 1);
    renderPdInlineCart();
    const btn = document.getElementById('pdOrderNowBtn');
    if (btn) btn.textContent = cartItems.length > 0
        ? `Order Now (${cartItems.length} item${cartItems.length > 1 ? 's' : ''})`
        : 'Order Now';
}

function closeProductDetail() {
    document.getElementById('productDetailModal').style.display = 'none';
    document.body.style.overflow = '';
    const cb = document.getElementById('chatbot');
    if (cb) cb.style.display = '';
}

function addToBag() {
    
    cartItems.forEach((ci, idx) => {
        const el = document.getElementById('pdc-qty-' + idx);
        if (el) { const n = parseInt(el.value); if (!isNaN(n) && n >= 1) ci.qty = n; }
    });

    if (!cartItems.length) {
        
        if (!selectedVariant) {
            cartItems = [{
                variant: {
                    id: null,
                    name: currentProductName,
                    size: '',
                    price: currentProductBasePrice,
                    img: currentProductBaseImg ? '../' + currentProductBaseImg : ''
                },
                qty: 1
            }];
        } else {
            cartItems = [{ variant: { ...selectedVariant, gender: currentProductGender }, qty: 1 }];
        }
    }

    closeProductDetail();

    document.getElementById('modalProductMeta').textContent = currentProductCategory || 'Printing Service';
    document.getElementById('checkoutNotes').value = '';
    document.getElementById('checkoutGcashRef').value = '';
    document.getElementById('checkoutAmount').value = '';

    document.getElementById('checkoutModal').style.display = 'flex';
    
    goToStep(2);
}

function inquireProduct() {
    closeProductDetail();
    window.location.href = 'customer_messages.php?inquire=' + encodeURIComponent(currentProductName);
}

function quickOrder(productId, productName, productDesc, category, basePrice, baseImg) {
    currentProductId = productId;
    currentProductName = productName;
    currentProductCategory = category || '';
    currentProductBaseImg = baseImg || '';
    currentProductBasePrice = parseFloat(basePrice) || 0;
    selectedVariant = null;
    cartItems = [];
    _detailCurrentIdx = 0;
    s1Photos = [];
    _detailVariants = [];

    const descEl = document.getElementById('pdProductDesc');
    if (descEl) descEl.textContent = productDesc || 'Quality printing service';

    addToBag();
}

function renderCheckoutVariants(variants) {
    if (!variants.length) {
        document.getElementById('variantGrid').innerHTML = '<div style="text-align:center;padding:0.5rem 0;color:rgba(255,255,255,0.4);font-size:0.72rem;">No variants available.</div>';
        return;
    }
    let html = '<div style="font-size:0.62rem;font-weight:600;color:rgba(255,255,255,0.35);margin-bottom:0.35rem;">Tap to select variants for your order</div><div class="album-grid">';
    variants.forEach(v => {
        const imgSrc = v.image ? '../' + v.image : '';
        const priceStr = parseFloat(v.price) > 0 ? '&#8369;' + parseFloat(v.price).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2}) : '';
        const isBase = v.variant_id === 'base';
        const vidMatch = isBase ? null : v.variant_id;
        const inCart = cartItems.some(ci => ci.variant && ci.variant.id == vidMatch);
        const cartItem = cartItems.find(ci => ci.variant && ci.variant.id == vidMatch);
        const vidAttr = isBase ? "'base'" : v.variant_id;
        const qtyStepperHtml = inCart ? `<div onclick="event.stopPropagation()" style="display:flex;align-items:center;justify-content:center;gap:1px;padding:2px 3px 3px;border-top:1px solid rgba(255,255,255,0.06);background:rgba(255,255,255,0.03);">
            <button onclick="event.stopPropagation();setCartQtyByVid(${vidAttr},-1)" style="width:16px;height:16px;border-radius:3px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:#fff;cursor:pointer;font-size:0.72rem;display:flex;align-items:center;justify-content:center;line-height:1;padding:0;flex-shrink:0;">-</button>
            <input type="number" id="vcard-qty-${v.variant_id}" value="${cartItem.qty}" min="1" onclick="event.stopPropagation()" onchange="setCartQtyInputByVid(${vidAttr},this.value)" oninput="setCartQtyInputByVid(${vidAttr},this.value)" style="width:36px;height:16px;border:1px solid rgba(255,255,255,0.1);border-radius:3px;text-align:center;font-size:0.62rem;font-weight:700;color:#fff;background:rgba(255,255,255,0.04);outline:none;padding:0;">
            <button onclick="event.stopPropagation();setCartQtyByVid(${vidAttr},1)" style="width:16px;height:16px;border-radius:3px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:#fff;cursor:pointer;font-size:0.72rem;display:flex;align-items:center;justify-content:center;line-height:1;padding:0;flex-shrink:0;">+</button>
        </div>` : '';
        html += `<div class="variant-card${inCart ? ' selected' : ''}" id="vc-${v.variant_id}" onclick="toggleCartItem(${vidAttr})">
            <div style="position:relative;">
                <div class="v-img">${imgSrc ? `<img src="${escHtml(imgSrc)}" alt="${escHtml(v.variant_name)}">` : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.25)" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>'}</div>
                <div class="v-check"><svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20,6 9,17 4,12"/></svg></div>
            </div>
            <div class="v-info">
                <div class="v-name">${escHtml(v.variant_name)}</div>
                ${v.size ? `<div class="v-size">${escHtml(v.size)}</div>` : ''}
                ${priceStr ? `<div class="v-price">${priceStr}</div>` : ''}
            </div>
            ${qtyStepperHtml}
        </div>`;
    });
    html += '</div>';
    document.getElementById('variantGrid').innerHTML = html;
}

function toggleCartItem(variantId) {
    const isBase = variantId === 'base';
    const matchId = isBase ? null : variantId;
    const idx = cartItems.findIndex(ci => ci.variant && ci.variant.id == matchId);
    if (idx >= 0) {
        cartItems.splice(idx, 1);
    } else {
        const v = _detailVariants.find(x => x.variant_id == variantId);
        if (!v) return;
        const imgSrc = v.image ? '../' + v.image : '';
        cartItems.push({ variant: { id: isBase ? null : v.variant_id, name: v.variant_name, size: v.size || '', price: v.price, img: imgSrc }, qty: 1 });
    }
    renderCheckoutVariants(_detailVariants);
    renderCartList();
}

function setCartItemQty(idx, val) {
    if (!cartItems[idx]) return;
    const n = parseInt(val);
    cartItems[idx].qty = isNaN(n) || n < 1 ? 1 : n;
    if (_detailVariants.length) renderCheckoutVariants(_detailVariants);
}

function setCartItemQtyByIdx(idx, delta) {
    if (!cartItems[idx]) return;
    const input = document.getElementById('cart-qty-' + idx);
    const current = input ? parseInt(input.value) || 1 : cartItems[idx].qty;
    cartItems[idx].qty = Math.max(1, current + delta);
    if (input) input.value = cartItems[idx].qty;
    if (_detailVariants.length) renderCheckoutVariants(_detailVariants);
}

function setCartQtyByVid(variantId, delta) {
    const matchId = variantId === 'base' ? null : variantId;
    const item = cartItems.find(ci => ci.variant && ci.variant.id == matchId);
    if (!item) return;
    const elId = variantId === 'base' ? 'vcard-qty-base' : 'vcard-qty-' + variantId;
    const el = document.getElementById(elId);
    const cur = el ? (parseInt(el.value) || 1) : item.qty;
    item.qty = Math.max(1, cur + delta);
    if (el) el.value = item.qty;
    const idx = cartItems.indexOf(item);
    const cqEl = document.getElementById('cart-qty-' + idx);
    if (cqEl) cqEl.value = item.qty;
}

function setCartQtyInputByVid(variantId, val) {
    const matchId = variantId === 'base' ? null : variantId;
    const item = cartItems.find(ci => ci.variant && ci.variant.id == matchId);
    if (!item) return;
    const n = parseInt(val);
    item.qty = isNaN(n) || n < 1 ? 1 : n;
    const idx = cartItems.indexOf(item);
    const cqEl = document.getElementById('cart-qty-' + idx);
    if (cqEl) cqEl.value = item.qty;
}

function removeFromCartByIdx(idx) {
    cartItems.splice(idx, 1);
    if (_detailVariants.length) renderCheckoutVariants(_detailVariants);
    renderCartList();
}

function renderCartList() {
    const cl = document.getElementById('cartList');
    if (!cl) return;

    if (!cartItems.length) {
        cl.innerHTML = '<div style="color:rgba(255,255,255,0.35);font-size:0.72rem;padding:0.3rem 0;">No items yet — select a variant above.</div>';
        return;
    }

    let html = '';
    cartItems.forEach((ci, idx) => {
        const v = ci.variant;
        const imgHtml = v && v.img
            ? `<img src="${escHtml(v.img)}" style="width:36px;height:36px;object-fit:cover;border-radius:6px;flex-shrink:0;border:1px solid rgba(255,255,255,0.08);">`
            : `<div style="width:36px;height:36px;border-radius:6px;background:rgba(255,255,255,0.04);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.25)" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div>`;
        const pn = parseFloat(v ? v.price : 0);
        const priceStr = pn > 0 ? '\u20b1' + pn.toLocaleString('en-PH', {minimumFractionDigits:2}) : '';
        const sizeStr  = v && v.size ? escHtml(v.size) : '';
        const meta = [sizeStr, priceStr].filter(Boolean).join(' \u00b7 ');
        html += `<div style="display:flex;align-items:center;gap:0.5rem;padding:0.4rem 0;border-bottom:1px solid rgba(255,255,255,0.05);">
            ${imgHtml}
            <div style="flex:1;min-width:0;">
                <div style="font-size:0.75rem;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(v ? v.name : 'Custom Order')}</div>
                ${meta ? `<div style="font-size:0.62rem;color:rgba(255,255,255,0.45);margin-top:1px;">${meta}</div>` : ''}
            </div>
            <div style="display:flex;align-items:center;gap:2px;flex-shrink:0;">
                <button onclick="setCartItemQtyByIdx(${idx},-1)" style="width:20px;height:20px;border-radius:4px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:#fff;font-size:0.78rem;cursor:pointer;display:flex;align-items:center;justify-content:center;font-weight:700;padding:0;line-height:1;">-</button>
                <input type="number" id="cart-qty-${idx}" value="${ci.qty}" min="1" onchange="setCartItemQty(${idx},this.value)" oninput="setCartItemQty(${idx},this.value)" style="width:40px;height:22px;border:1px solid rgba(255,255,255,0.1);border-radius:4px;text-align:center;font-size:0.72rem;font-weight:700;color:#fff;background:rgba(255,255,255,0.04);outline:none;padding:0;" onfocus="this.style.borderColor='#ff6b35'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'">
                <button onclick="setCartItemQtyByIdx(${idx},1)" style="width:20px;height:20px;border-radius:4px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);color:#fff;font-size:0.78rem;cursor:pointer;display:flex;align-items:center;justify-content:center;font-weight:700;padding:0;line-height:1;">+</button>
            </div>
            <button onclick="removeFromCartByIdx(${idx})" style="background:none;border:none;color:rgba(255,255,255,0.35);font-size:1rem;cursor:pointer;flex-shrink:0;padding:2px;line-height:1;transition:color 0.15s;" title="Remove" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='rgba(255,255,255,0.35)'">&times;</button>
        </div>`;
    });
    cl.innerHTML = html;
}

function closeCheckout() {
    document.getElementById('checkoutModal').style.display = 'none';
    document.body.style.overflow = '';
    selectedVariant = null;
    cartItems = [];
    s1Photos = [];
    _detailVariants = [];
    currentStep = 1;
}

function backToProductDetail() {
    document.getElementById('checkoutModal').style.display = 'none';
    if (currentProductId) {
        const pd = document.getElementById('productDetailModal');
        if (pd) pd.style.display = 'flex';
    }
}

document.getElementById('checkoutModal').addEventListener('click', function(e) {
    if (e.target === this) closeCheckout();
});

function setStep(n) {
    currentStep = n;
    [1,2,3].forEach(i => {
        document.getElementById('checkout-step' + i).style.display = (i === n) ? 'block' : 'none';
    });
    document.getElementById('checkout-success').style.display = 'none';

    [1,2,3].forEach(i => {
        const el = document.getElementById('si-' + i);
        el.classList.remove('active', 'done');
        if (i < n) el.classList.add('done');
        else if (i === n) el.classList.add('active');
    });
    ['12','23'].forEach(key => {
        document.getElementById('sl-' + key).classList.toggle('done', n > parseInt(key[0]));
    });
}

function syncCartQtys() {
    cartItems.forEach((ci, idx) => {
        if (ci.variant) {
            const vel = document.getElementById('vcard-qty-' + ci.variant.id);
            if (vel) { const n = parseInt(vel.value); if (!isNaN(n) && n >= 1) ci.qty = n; }
        }
        const el = document.getElementById('cart-qty-' + idx);
        if (el) {
            const n = parseInt(el.value);
            if (!isNaN(n) && n >= 1) ci.qty = n;
        }
    });
}

function goToStep(n) {
    if (n === 2) {
        syncCartQtys();
        if (!cartItems.length) {
            showToast('Empty Cart', 'Please add at least one variant to your order.', 'error');
            return;
        }
        fillSummary();
    }
    if (n === 3) {
        let total = 0;
        cartItems.forEach(ci => {
            const p = parseFloat(ci.variant ? ci.variant.price : 0);
            if (p > 0) total += p * ci.qty;
        });
        const el = document.getElementById('step3Total');
        if (el) el.textContent = total > 0 ? '\u20b1' + total.toLocaleString('en-PH', {minimumFractionDigits:2}) : 'Price TBD';
    }
    setStep(n);
}

function fillSummary() {
    document.getElementById('sumProduct').textContent = currentProductName;
    const sumImg = document.getElementById('sumImg');
    if (!cartItems.length) {
        document.getElementById('sumVariant').innerHTML = '';
        document.getElementById('sumQtyPrice').textContent = '';
        sumImg.style.display = 'none';
        return;
    }
    const firstWithImg = cartItems.find(ci => ci.variant && ci.variant.img);
    if (firstWithImg) {
        sumImg.src = firstWithImg.variant.img;
        sumImg.style.display = 'block';
    } else if (currentProductBaseImg) {
        sumImg.src = '../' + currentProductBaseImg;
        sumImg.style.display = 'block';
    } else {
        sumImg.style.display = 'none';
    }

    let totalPrice = 0;
    let hasPrice = false;
    let linesHtml = '';
    cartItems.forEach(ci => {
        const v = ci.variant;
        const pn = parseFloat(v ? v.price : 0);
        if (pn > 0) { totalPrice += pn * ci.qty; hasPrice = true; }
        let label = escHtml(v ? v.name : 'Custom Order');
        if (v && v.size) {
            label += ' <span style="color:rgba(255,255,255,0.4);font-weight:400;">· Size: ' + escHtml(v.size) + ' · Gender: ' + escHtml(v.gender === 'female' ? 'Female' : 'Male') + '</span>';
        }
        label += ' <span style="color:rgba(255,255,255,0.4);font-weight:400;">&times;' + ci.qty + '</span>';
        if (pn > 0) label += ' <span style="color:#ff6b35;">&#8369;' + (pn * ci.qty).toLocaleString('en-PH',{minimumFractionDigits:2}) + '</span>';
        linesHtml += '<div style="font-size:0.78rem;color:#fff;margin-bottom:2px;">' + label + '</div>';
    });
    document.getElementById('sumVariant').innerHTML = linesHtml;
    document.getElementById('sumQtyPrice').textContent = hasPrice
        ? cartItems.length + ' item' + (cartItems.length > 1 ? 's' : '') + '  ·  Total: ₱' + totalPrice.toLocaleString('en-PH', {minimumFractionDigits:2})
        : cartItems.length + ' item' + (cartItems.length > 1 ? 's' : '') + ' (price to be confirmed)';
}

function placeOrder() {
    syncCartQtys();
    if (!cartItems.length) { showToast('Empty Cart', 'Please add items to your order first.', 'error'); return; }
    const btn = document.getElementById('placeOrderBtn');
    btn.disabled = true;
    btn.textContent = 'Placing...';

    const fd = new FormData();
    fd.append('action', 'add_to_cart_multi');
    fd.append('csrf_token', csrfToken);
    fd.append('product_id', currentProductId);
    fd.append('product_name', currentProductName);
    fd.append('variant_items', JSON.stringify(cartItems.map(ci => ({
        variant_id:   ci.variant ? ci.variant.id   : null,
        variant_name: ci.variant ? ci.variant.name : '',
        size:         ci.variant ? ci.variant.size : '',
        gender:       ci.variant ? ci.variant.gender : currentProductGender,
        price:        ci.variant ? ci.variant.price : 0,
        qty:          ci.qty
    }))));
    fd.append('payment_mode', 'GCash');
    fd.append('gcash_reference', '');
    fd.append('down_payment_amount', '0');
    fd.append('order_notes', document.getElementById('checkoutNotes').value.trim());

    fetch('customer_shop.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showOrderSuccess();
            } else {
                showToast('Error', data.error || 'Failed to place order. Please try again.', 'error');
                btn.disabled = false;
                btn.textContent = 'Place Order ✓';
            }
        })
        .catch(() => {
            showToast('Error', 'Something went wrong. Please try again.', 'error');
            btn.disabled = false;
            btn.textContent = 'Place Order ✓';
        });
}

function showOrderSuccess() {
    [1,2,3].forEach(i => document.getElementById('checkout-step' + i).style.display = 'none');
    document.getElementById('checkout-success').style.display = 'block';
    [1,2,3].forEach(i => {
        const el = document.getElementById('si-' + i);
        el.classList.remove('active');
        el.classList.add('done');
    });
    setTimeout(() => { document.getElementById('successProgress').style.width = '100%'; }, 50);
    setTimeout(() => { window.location.href = 'customer_orders.php'; }, 2600);
}

let _lastProductIds = <?= json_encode(array_column($products, 'product_id')) ?>;

const jsCatEmojis = {
    'T-shirt': ['👕', '#FFF6F6'], 'Sublimation': ['👕', '#FFF6F6'], 'shirt': ['👕', '#FFF6F6'],
    'Photo': ['🖼️', '#F5F8FF'], 'Printing': ['🖨️', '#F5F8FF'],
    'Mug': ['☕', '#F3FBF3'], 'Tumbler': ['☕', '#F3FBF3'],
    'Gift': ['🎁', '#FFFBF0'], 'Custom': ['🎨', '#FFFBF0'],
    'Tarpaulin': ['🏷️', '#FAF6FF'], 'Sticker': ['🏷️', '#FAF6FF'], 'Banner': ['🏷️', '#FAF6FF'],
    'Fan': ['🌀', '#F5F8FF'], 'Electric': ['🌀', '#F5F8FF'],
};
function getJsCatStyle(name) {
    if (!name) return ['📦', '#F9F9F9'];
    for (const key in jsCatEmojis) {
        if (name.toLowerCase().includes(key.toLowerCase())) {
            return jsCatEmojis[key];
        }
    }
    return ['📦', '#F9F9F9'];
}

function filterCategory(category, btn) {
    document.querySelectorAll('.pill-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const searchInput = document.getElementById('navbarSearchInput');
    const query = category === 'all' ? '' : category;
    if (searchInput) {
        searchInput.value = query;
    }
    _currentPage = 1;
    updatePagination();
}

function buildProductCard(p) {
    const vc = parseInt(p.variant_count) || 0;
    let imgPath = p.img || '';
    if (imgPath.startsWith('../')) imgPath = imgPath.substring(3);
    const catName = p.category_name || '';
    const catStyle = getJsCatStyle(catName);
    
    const varBadge = vc > 0 ? `<span class="product-variant-badge">${vc} variant${vc > 1 ? 's' : ''}</span>` : '';
    const imgHtml = imgPath
        ? `<img src="../${escHtml(imgPath)}" alt="${escHtml(p.product_name)}">` 
        : `<span style="font-size:64px;">${catStyle[0]}</span>`;
        
    const name = escHtml(p.product_name);
    const desc = escHtml((p.description || '').replace(/[\r\n]+/g, ' '));
    const nameAttr = escAttr(p.product_name);
    const descAttr = escAttr((p.description || '').replace(/[\r\n]+/g, ' '));
    const catAttr = escAttr(catName);
    
    const priceNum = parseFloat(p.price || 0);
    const priceHtml = priceNum > 0 
        ? `<div class="product-price-tag">\u20B1${priceNum.toLocaleString('en-PH',{minimumFractionDigits:2})} PHP</div>` 
        : `<div class="product-price-tag">Inquire for price</div>`;
        
    const encodedName = encodeURIComponent(p.product_name);

    const btnHtml = vc > 0
        ? `<button class="btn-add-cart" onclick="event.stopPropagation(); openAlbum(${p.product_id},'${nameAttr}','${descAttr}',${vc},'${catAttr}',${priceNum},'${escAttr(imgPath)}')">Order now</button>`
        : `<button class="btn-add-cart" onclick="event.stopPropagation(); quickOrder(${p.product_id},'${nameAttr}','${descAttr}','${catAttr}',${priceNum},'${escAttr(imgPath)}')">Order now</button>`;

    return `<div class="product-card" onclick="openAlbum(${p.product_id},'${nameAttr}','${descAttr}',${vc},'${catAttr}',${priceNum},'${escAttr(imgPath)}')">
        <div class="product-img" style="background:${catStyle[1]};">${imgHtml}${varBadge}</div>
        <div class="product-info">
            <span class="product-category-tag" style="display:none;">${escHtml(catName)}</span>
            <p class="product-service-type" style="display:none;">${desc}</p>
            <h3>${name}</h3>
            ${priceHtml}
            <div class="product-actions">
                ${btnHtml}
                <a href="customer_messages.php?inquire=${encodedName}" class="btn-inquire" onclick="event.stopPropagation();">Inquire</a>
                <button class="btn-heart" onclick="event.stopPropagation(); this.innerHTML=this.innerHTML==='♡'?'\\u2665':'\\u2661'; this.style.color=this.innerHTML==='\\u2665'?'#000':'#999';">&#9825;</button>
            </div>
        </div>
    </div>`;
}

function updatePagination() {
    const query = (document.getElementById('navbarSearchInput')?.value || '').toLowerCase().trim();
    const cards = document.querySelectorAll('.product-card');
    
    const visibleCards = [];
    cards.forEach(card => {
        const productName = card.querySelector('h3').textContent.toLowerCase();
        const productDesc = card.querySelector('.product-service-type')?.textContent.toLowerCase() || '';
        const categoryTag = card.querySelector('.product-category-tag')?.textContent.toLowerCase() || '';
        
        if (productName.includes(query) || productDesc.includes(query) || categoryTag.includes(query)) {
            visibleCards.push(card);
        } else {
            card.style.display = 'none';
        }
    });

    const totalItems = visibleCards.length;
    const totalPages = Math.max(1, Math.ceil(totalItems / _itemsPerPage));
    
    if (_currentPage > totalPages) {
        _currentPage = totalPages;
    }
    
    const startIndex = (_currentPage - 1) * _itemsPerPage;
    const endIndex = startIndex + _itemsPerPage;
    
    visibleCards.forEach((card, idx) => {
        if (idx >= startIndex && idx < endIndex) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
    
    const infoEl = document.getElementById('paginationInfo');
    if (infoEl) {
        infoEl.textContent = `${_currentPage} / ${totalPages}`;
    }
    
    const btn = document.getElementById('btnPaginationNext');
    if (btn) {
        if (totalPages <= 1) {
            btn.style.display = 'none';
        } else {
            btn.style.display = 'inline-flex';
            if (_currentPage < totalPages) {
                btn.textContent = 'Next \u203A';
            } else {
                btn.textContent = '\u2039 Prev';
            }
        }
    }
}

function handlePaginationNext() {
    const cards = document.querySelectorAll('.product-card');
    const query = (document.getElementById('navbarSearchInput')?.value || '').toLowerCase().trim();
    const visibleCount = Array.from(cards).filter(card => {
        const productName = card.querySelector('h3').textContent.toLowerCase();
        const productDesc = card.querySelector('.product-service-type')?.textContent.toLowerCase() || '';
        const categoryTag = card.querySelector('.product-category-tag')?.textContent.toLowerCase() || '';
        return productName.includes(query) || productDesc.includes(query) || categoryTag.includes(query);
    }).length;
    
    const totalPages = Math.max(1, Math.ceil(visibleCount / _itemsPerPage));
    
    if (_currentPage < totalPages) {
        _currentPage++;
    } else {
        _currentPage = 1;
    }
    updatePagination();
    document.getElementById('productsSection').scrollIntoView({behavior:'smooth'});
}

if (typeof handleNavbarSearch === 'function') {
    const originalSearch = handleNavbarSearch;
    handleNavbarSearch = function(query) {
        _currentPage = 1;
        updatePagination();
    };
}

function pollProducts() {
    fetch('poll_api.php?action=products')
        .then(r => r.json())
        .then(data => {
            const newIds = data.products.map(p => p.product_id).join(',');
            const oldIds = _lastProductIds.join(',');
            if (newIds !== oldIds) {
                const oldSet = new Set(_lastProductIds.map(String));
                const added = data.products.filter(p => !oldSet.has(String(p.product_id)));
                const grid = document.querySelector('.products-grid');
                const empty = document.querySelector('.empty-state');
                if (data.products.length === 0) {
                    if (grid) grid.remove();
                    if (!empty) document.querySelector('.main-content').insertAdjacentHTML('beforeend',
                        '<div class="empty-state"><svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="20" height="20" rx="2"/><path d="M12 8v8m-4-4h8"/></svg><h3>No products available yet</h3><p>Check back soon for new products and services!</p></div>');
                } else {
                    if (empty) empty.remove();
                    if (grid) grid.innerHTML = data.products.map(buildProductCard).join('');
                    else document.querySelector('.main-content').insertAdjacentHTML('beforeend',
                        '<div class="products-grid">' + data.products.map(buildProductCard).join('') + '</div>');
                }
                if (added.length > 0) showToast('New Product', added.length + ' new product' + (added.length > 1 ? 's' : '') + ' available!', 'info');
                _lastProductIds = data.products.map(p => p.product_id);
                updatePagination();
            }
        })
        .catch(() => {});
}

window.onload = function() {
    updatePagination();
};

setInterval(pollProducts, 5000);
</script>
<?php include 'chatbot_widget.php'; ?>
</body>
</html>

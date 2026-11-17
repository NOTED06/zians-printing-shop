<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once __DIR__ . '/../security_headers.php';
require_once '../db_conn.php';
require_once __DIR__ . '/../size_chart_helper.php';
require_once 'check_customer.php';
require_once '../inventory_helper.php';
require_once '../upload_helper.php';
require_once '../csrf_helper.php';
require_once '../schedule_helper.php';
require_once '../admin_notification_helper.php';
require_once '../order_write_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
}

$customerId = $_SESSION['user_id'];
$customerName = $_SESSION['username'] ?? 'Customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    header('Content-Type: application/json');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $normalizedPhone = preg_replace('/\D+/', '', $phone);

    if ($firstName === '' || $lastName === '' || $email === '') {
        echo json_encode(['success' => false, 'message' => 'First name, last name and email are required.']);
        exit;
    }
    if ($password !== '' && strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
        exit;
    }
    if ($password !== $confirmPassword) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit;
    }
    if ($phone !== '' && strlen($normalizedPhone) < 7) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid phone number.']);
        exit;
    }

    $emailCheck = $conn->prepare('SELECT customer_id FROM customers WHERE email = ? AND customer_id <> ? LIMIT 1');
    $emailCheck->bind_param('si', $email, $customerId);
    $emailCheck->execute();
    if ($emailCheck->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'That email is already registered.']);
        exit;
    }

    if ($phone !== '') {
        $phoneCheck = $conn->prepare('SELECT customer_id, phone FROM customers WHERE customer_id <> ? AND phone IS NOT NULL AND phone <> ""');
        $phoneCheck->bind_param('i', $customerId);
        $phoneCheck->execute();
        $phoneResult = $phoneCheck->get_result();
        while ($existingCustomer = $phoneResult->fetch_assoc()) {
            if (preg_replace('/\D+/', '', (string)$existingCustomer['phone']) === $normalizedPhone) {
                echo json_encode(['success' => false, 'message' => 'That phone number is already registered.']);
                exit;
            }
        }
        $adminPhoneResult = $conn->query('SELECT phone FROM admins WHERE phone IS NOT NULL AND phone <> ""');
        while ($existingAdmin = $adminPhoneResult->fetch_assoc()) {
            if (preg_replace('/\D+/', '', (string)$existingAdmin['phone']) === $normalizedPhone) {
                echo json_encode(['success' => false, 'message' => 'That phone number is already used by an administrator.']);
                exit;
            }
        }
        $phone = $normalizedPhone;
    }

    if ($password !== '') {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $profileStmt = $conn->prepare('UPDATE customers SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, password = ? WHERE customer_id = ?');
        $profileStmt->bind_param('ssssssi', $firstName, $lastName, $email, $phone, $address, $hashedPassword, $customerId);
    } else {
        $profileStmt = $conn->prepare('UPDATE customers SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ? WHERE customer_id = ?');
        $profileStmt->bind_param('sssssi', $firstName, $lastName, $email, $phone, $address, $customerId);
    }

    if (!$profileStmt->execute()) {
        if ($conn->errno === 1062) {
            echo json_encode(['success' => false, 'message' => 'That email or phone number is already registered.']);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Unable to update your profile. Please try again.']);
        exit;
    }

    $_SESSION['email'] = $email;
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
    exit;
}

$profile = ['username' => $customerName, 'first_name' => '', 'last_name' => '', 'email' => $_SESSION['email'] ?? '', 'phone' => '', 'address' => ''];
$profileStmt = $conn->prepare('SELECT username, first_name, last_name, email, phone, address FROM customers WHERE customer_id = ? LIMIT 1');
$profileStmt->bind_param('i', $customerId);
$profileStmt->execute();
$profileResult = $profileStmt->get_result();
if ($profileResult && ($profileRow = $profileResult->fetch_assoc())) {
    $profile = array_merge($profile, $profileRow);
}

if (isset($_GET['get_size_chart'])) {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    try {
        $productId = (int)$_GET['get_size_chart'];
        if ($productId < 1) throw new InvalidArgumentException('Invalid product ID.');
        $chartStmt = $conn->prepare("SELECT DISTINCT size FROM products WHERE record_type='variant' AND product_id = ? AND is_active = 1 AND TRIM(COALESCE(size, '')) <> '' ORDER BY size");
        if (!$chartStmt) throw new RuntimeException('Unable to prepare size-chart query: ' . $conn->error);
        if (!$chartStmt->bind_param('i', $productId) || !$chartStmt->execute()) {
            throw new RuntimeException('Unable to query product sizes: ' . $chartStmt->error);
        }
        $chartResult = $chartStmt->get_result();
        $chart = [];
        while ($row = $chartResult->fetch_assoc()) {
            $measurements = getStaticProductSizeChartRow((string)$row['size']);
            if ($measurements) $chart[] = $measurements;
        }
        echo json_encode($chart);
    } catch (Throwable $error) {
        error_log('Customer size chart request failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to load the size guide. Verify the database setup and review the PHP error log.']);
    }
    exit;
}

if (isset($_GET['get_variants'])) {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    try {
        $pid = (int)$_GET['get_variants'];
        if ($pid < 1) throw new InvalidArgumentException('Invalid product ID.');
        $stmt = $conn->prepare("SELECT * FROM products WHERE record_type='variant' AND product_id = ? AND is_active = 1 ORDER BY variant_id ASC");
        if (!$stmt) throw new RuntimeException('Unable to prepare variant query: ' . $conn->error);
        if (!$stmt->bind_param('i', $pid) || !$stmt->execute()) {
            throw new RuntimeException('Unable to load product variants: ' . $stmt->error);
        }
        $result = $stmt->get_result();
        $variants = [];
        while ($row = $result->fetch_assoc()) $variants[] = $row;
        echo json_encode($variants);
    } catch (Throwable $error) {
        error_log('Customer product variants request failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to load product sizes. Verify the database setup and review the PHP error log.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart_multi') {
    header('Content-Type: application/json');
    $pid   = (int)$_POST['product_id'];
    $pname = trim($_POST['product_name'] ?? '');
    $variantItems = json_decode($_POST['variant_items'] ?? '[]', true);
    if (!is_array($variantItems) || empty($variantItems)) {
        echo json_encode(['success' => false, 'error' => 'No items selected']);
        exit;
    }
    $productStmt = $conn->prepare("SELECT p.working_days, p.price AS base_price, LOWER(COALESCE(pc.category_name, '')) AS category_name FROM products p LEFT JOIN products pc ON pc.record_type='category' AND pc.category_id = p.category_id WHERE p.record_type='product' AND p.product_id = ? AND p.is_active = 1 LIMIT 1");
    $productStmt->bind_param('i', $pid);
    $productStmt->execute();
    $product = $productStmt->get_result()->fetch_assoc();
    if (!$product) {
        echo json_encode(['success' => false, 'error' => 'The selected product is no longer available.']);
        exit;
    }
    $isJerseySublimation = strpos($product['category_name'] ?? '', 'jersey sublimation') !== false;
    $isJerseyProduct = strpos($product['category_name'] ?? '', 'jersey') !== false
        || strpos($product['category_name'] ?? '', 'sublimation') !== false;
    $estimatedFinishDate = calculateEstimatedFinishDate($conn, date('Y-m-d'), (int)($product['working_days'] ?? 3));
    $paymentMode = 'GCash';
    $gcashRef    = trim($_POST['gcash_reference'] ?? '');
    $downPayment = (float)($_POST['down_payment_amount'] ?? 0);
    $orderNotes  = trim($_POST['order_notes'] ?? '');
    $cartTotal = 0;
    foreach ($variantItems as $item) {
        $itemPrice = max(0, (float)($item['price'] ?? 0));
        $itemQty = max(1, (int)($item['qty'] ?? 1));
        $cartTotal += $itemPrice * $itemQty;
    }
    $downPayment = max(0, $downPayment);
    $downPayment = $cartTotal > 0 ? min($downPayment, $cartTotal) : 0;
    if ($cartTotal > 0 && $downPayment < ($cartTotal / 2)) {
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
    foreach ($variantItems as $itemIndex => $item) {
        $variantId   = isset($item['variant_id']) && $item['variant_id'] !== null ? (int)$item['variant_id'] : null;
        $variantName = trim($item['variant_name'] ?? '');
        $designVariantName = $variantName;
        $jerseyType = $isJerseyProduct ? trim($item['jersey_type'] ?? '') : '';
        if ($jerseyType !== '') $variantName .= ' - ' . $jerseyType;
        $size        = trim($item['size'] ?? '');
        $measurements = is_array($item['measurements'] ?? null) ? $item['measurements'] : [];
        $lowerMeasurementsAllowed = $isJerseySublimation && $jerseyType === 'Upper + Lower Jersey';
        $upperMeasurementLimits = [
                'body_height' => ['label' => 'H', 'name' => 'Height', 'unit' => 'cm', 'min' => 100, 'max' => 200],
                'body_weight' => ['label' => 'W', 'name' => 'Weight', 'unit' => 'kg', 'min' => 20, 'max' => 125],
                'shoulder' => ['label' => 'Sh', 'name' => 'Shoulder', 'unit' => 'cm', 'min' => 20, 'max' => 56],
                'top_length' => ['label' => 'L', 'name' => 'Top length', 'unit' => 'cm', 'min' => 30, 'max' => 86],
                'waist' => ['label' => 'Wa', 'name' => 'Waist', 'unit' => 'cm', 'min' => 40, 'max' => 134],
            ];
        $lowerMeasurementLimits = [
            'lower_waist' => ['label' => 'Lower waist', 'name' => 'Lower waist', 'unit' => 'cm', 'min' => 40, 'max' => 150],
            'hip' => ['label' => 'Hip/seat', 'name' => 'Hip/seat', 'unit' => 'cm', 'min' => 50, 'max' => 160],
            'inseam' => ['label' => 'Inseam', 'name' => 'Inseam', 'unit' => 'cm', 'min' => 20, 'max' => 100],
            'outseam' => ['label' => 'Outseam', 'name' => 'Outseam', 'unit' => 'cm', 'min' => 30, 'max' => 130],
        ];
        $hasUpperMeasurements = false;
        foreach (array_keys($upperMeasurementLimits) as $key) {
            if (isset($measurements[$key]) && trim((string)$measurements[$key]) !== '') $hasUpperMeasurements = true;
        }
        $hasLowerMeasurements = false;
        foreach (array_keys($lowerMeasurementLimits) as $key) {
            if (!$lowerMeasurementsAllowed) {
                unset($measurements[$key]);
                continue;
            }
            if (isset($measurements[$key]) && trim((string)$measurements[$key]) !== '') $hasLowerMeasurements = true;
        }

        $measurementNotes = [];
        if ($hasUpperMeasurements) {
            foreach ($upperMeasurementLimits as $key => $limit) {
                $rawValue = trim((string)($measurements[$key] ?? ''));
                if (!is_numeric($rawValue) || (float)$rawValue < $limit['min'] || (float)$rawValue > $limit['max']) {
                    $conn->rollback();
                    http_response_code(422);
                    echo json_encode(['success' => false, 'error' => $limit['name'] . ' must be between ' . $limit['min'] . ' and ' . $limit['max'] . ' ' . $limit['unit'] . '.']);
                    exit;
                }
                $measurements[$key] = (float)$rawValue;
                $formattedValue = rtrim(rtrim(number_format((float)$rawValue, 1, '.', ''), '0'), '.');
                $measurementNotes[] = $limit['label'] . '=' . $formattedValue . $limit['unit'];
            }
            $availableSizes = [];
            $availableStmt = $conn->prepare("SELECT DISTINCT size FROM products WHERE record_type='variant' AND product_id = ? AND is_active = 1 AND quantity > 0 AND LOWER(variant_name) = LOWER(?)");
            $availableStmt->bind_param('is', $pid, $designVariantName);
            $availableStmt->execute();
            $availableResult = $availableStmt->get_result();
            while ($availableRow = $availableResult->fetch_assoc()) $availableSizes[] = $availableRow['size'];
            $recommendedSize = recommendStaticProductSize($measurements, $availableSizes);
            if (!$recommendedSize) {
                $conn->rollback();
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'We could not determine an in-stock size for these measurements. Please select an available size or contact the shop.']);
                exit;
            }
            $size .= ($size !== '' ? ' | ' : '') . 'Fit: ' . $recommendedSize . ' | Measurements: ' . implode(' ', $measurementNotes);
            if (strlen($size) > 100) {
                $conn->rollback();
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'The selected size and measurements are too long to save.']);
                exit;
            }
        }
        $lowerMeasurementNotes = [];
        if ($hasLowerMeasurements) {
            foreach ($lowerMeasurementLimits as $key => $limit) {
                $rawValue = trim((string)($measurements[$key] ?? ''));
                if (!is_numeric($rawValue) || (float)$rawValue < $limit['min'] || (float)$rawValue > $limit['max']) {
                    $conn->rollback();
                    http_response_code(422);
                    echo json_encode(['success' => false, 'error' => $limit['name'] . ' must be between ' . $limit['min'] . ' and ' . $limit['max'] . ' ' . $limit['unit'] . '.']);
                    exit;
                }
                $formattedValue = rtrim(rtrim(number_format((float)$rawValue, 1, '.', ''), '0'), '.');
                $lowerMeasurementNotes[] = $limit['label'] . '=' . $formattedValue . $limit['unit'];
            }
        }
        $itemOrderNotes = $orderNotes;
        if ($lowerMeasurementNotes) {
            $itemOrderNotes = trim($itemOrderNotes . ($itemOrderNotes !== '' ? "\n" : '') . 'Lower jersey measurements: ' . implode(', ', $lowerMeasurementNotes));
        }
        $gender = strtolower(trim((string)($item['gender'] ?? 'male')));
        if (!in_array($gender, ['male', 'female'], true)) $gender = 'male';
        if ($size !== '') $variantName .= ' - Gender: ' . ucfirst($gender);
        $qty         = max(1, (int)($item['qty'] ?? 1));
        $price       = (float)($item['price'] ?? 0);
        if ($variantId !== null && $variantId > 0) {
            $priceColumn = $jerseyType === 'Upper + Lower Jersey' ? 'upper_lower_price' : 'upper_price';
            $priceResult = $conn->query("SELECT `$priceColumn` AS selected_price, price FROM products WHERE record_type='variant' AND variant_id = " . $variantId . " AND product_id = " . $pid . " LIMIT 1");
            if ($priceResult && ($priceRow = $priceResult->fetch_assoc())) {
                $price = (float)$priceRow['selected_price'];
                if ($price <= 0) $price = (float)$priceRow['price'];
            }
        }
        if ($price <= 0) $price = max(0, (float)($product['base_price'] ?? 0));
        if (!reserveInventory($conn, $pid, $variantId, $qty)) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Not enough stock available for one or more selected items.']);
            exit;
        }
        $itemPayment = $itemIndex === 0 ? $downPayment : 0;
        try {
            createOrderItem($conn, $headerId, [
                'product_id' => $pid, 'variant_id' => $variantId, 'product_name' => $pname,
                'variant_name' => $variantName, 'size' => $size, 'quantity' => $qty,
                'price' => $price, 'image' => $imagePath, 'order_notes' => $itemOrderNotes,
                'paid_amount' => $itemPayment,
            ]);
        } catch (Throwable $error) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Unable to save the order. Please try again.']);
            exit;
        }
    }
    $conn->commit();

    $totalItems = count($variantItems);
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
    header('Content-Type: application/json');
    $pid = (int)$_POST['product_id'];
    $pname = $_POST['product_name'];
    $variantId = isset($_POST['variant_id']) ? (int)$_POST['variant_id'] : null;
    $variantName = trim($_POST['variant_name'] ?? '');
    $size = trim($_POST['size'] ?? '');
    $qty = (int)$_POST['quantity'];
    $price = (float)$_POST['price'];
    $paymentMode    = 'GCash';
    $gcashRef       = trim($_POST['gcash_reference'] ?? '');
    $downPayment    = (float)($_POST['down_payment_amount'] ?? 0);
    $orderNotes     = trim($_POST['order_notes'] ?? '');

    $productStmt = $conn->prepare("SELECT working_days FROM products WHERE record_type='product' AND product_id = ? AND is_active = 1 LIMIT 1");
    $productStmt->bind_param('i', $pid);
    $productStmt->execute();
    $product = $productStmt->get_result()->fetch_assoc();
    if (!$product) {
        echo json_encode(['success' => false, 'error' => 'The selected product is no longer available.']);
        exit;
    }
    $estimatedFinishDate = calculateEstimatedFinishDate($conn, date('Y-m-d'), (int)($product['working_days'] ?? 3));
    
    
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

$favoriteProducts = [];
$favoriteRes = $conn->query("SELECT p.*, pc.category_name,
        (SELECT CASE WHEN COUNT(NULLIF(pv.image, '')) > 0 THEN COUNT(DISTINCT NULLIF(pv.image, '')) ELSE COUNT(*) END
         FROM products pv WHERE pv.record_type='variant' AND pv.product_id = p.product_id AND pv.is_active = 1) AS variant_count
        FROM products p
        LEFT JOIN products pc ON pc.record_type='category' AND pc.category_id = p.category_id
        WHERE p.record_type='product' AND p.is_active = 1
            AND EXISTS (SELECT 1 FROM orders co WHERE co.product_id = p.product_id AND co.customer_id = " . (int)$customerId . " AND co.status <> 'Cancelled')
        ORDER BY (SELECT MAX(co2.date_created) FROM orders co2 WHERE co2.product_id = p.product_id AND co2.customer_id = " . (int)$customerId . " AND co2.status <> 'Cancelled') DESC
        LIMIT 4");
if ($favoriteRes) {
        while ($r = $favoriteRes->fetch_assoc()) $favoriteProducts[] = $r;
}
$favoriteProductIds = array_map('intval', array_column($favoriteProducts, 'product_id'));

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
    <title>Products - Zian's PrintShop</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/customer.css?v=<?= time() ?>">
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content">

<section class="hero">
    <div class="hero-main">
        <div class="hero-eyebrow">Quality Prints & Fast Turnaround</div>
        <h1 class="hero-h1">Your One-Stop<br>Printing Solution</h1>
        <div class="hero-btns" style="margin-top: 16px;">
            <button class="btn-white" onclick="document.getElementById('productsSection').scrollIntoView({behavior:'smooth'})">Shop now</button>
        </div>
    </div>
    <div class="hero-img-side">
        <img src="../img/hero_rack.png?v=<?= time() ?>" alt="Zian's PrintShop Custom Shirts" class="hero-rack-img">
    </div>
</section>

<div class="section" id="productsSection" style="padding: 10px 40px 60px;">

    <div class="shop-toolbar">
        <div>
            <span class="shop-toolbar-kicker">Browse the collection</span>
            <h2 class="shop-toolbar-title">Find something worth making</h2>
        </div>
        <div class="shop-category-rail" role="list" aria-label="Product categories">
            <button type="button" class="shop-category-pill active" onclick="filterCategory('all', this)">All products</button>
            <?php foreach ($categories as $category): ?>
                <?php if ((int)$category['product_count'] > 0): ?>
                    <button type="button" class="shop-category-pill" onclick="filterCategory(<?= htmlspecialchars(json_encode($category['category_name']), ENT_QUOTES) ?>, this)">
                        <?= htmlspecialchars($category['category_name']) ?>
                        <span><?= (int)$category['product_count'] ?></span>
                    </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!empty($favoriteProducts)): ?>
        <section class="favorites-section" aria-labelledby="favoritesTitle">
            <div class="favorites-heading">
                <div>
                    <span class="shop-toolbar-kicker">Made for you</span>
                    <h2 class="shop-toolbar-title" id="favoritesTitle">Your Favorites</h2>
                </div>
                <span class="favorites-note">Based on your recent orders</span>
            </div>
            <div class="products-grid favorites-grid">
                <?php foreach ($favoriteProducts as $p):
                    $_favoriteImg = $p['img'] ?? '';
                    if (strpos($_favoriteImg, '../') === 0) $_favoriteImg = substr($_favoriteImg, 3);
                    $_favoriteName = str_replace(["\r\n", "\r", "\n"], ' ', htmlspecialchars($p['product_name'], ENT_QUOTES));
                    $_favoriteDesc = str_replace(["\r\n", "\r", "\n"], ' ', htmlspecialchars($p['description'] ?? '', ENT_QUOTES));
                    $_favoriteCategory = htmlspecialchars($p['category_name'] ?? '', ENT_QUOTES);
                    $favoriteCatStyle = getCatStyle($p['category_name'] ?? '', $catEmojis);
                ?>
                    <div class="product-card" onclick="openAlbum(<?= $p['product_id'] ?>, '<?= $_favoriteName ?>', '<?= $_favoriteDesc ?>', <?= $p['variant_count'] ?>, '<?= $_favoriteCategory ?>', <?= (float)($p['price'] ?? 0) ?>, '<?= htmlspecialchars($_favoriteImg, ENT_QUOTES) ?>', '<?= htmlspecialchars($p['gender'] ?? 'unisex', ENT_QUOTES) ?>')">
                        <div class="product-img" style="background:<?= $favoriteCatStyle[1] ?>;">
                            <?php if (!empty($_favoriteImg)): ?>
                                <img src="../<?= htmlspecialchars($_favoriteImg) ?>" alt="<?= htmlspecialchars($p['product_name']) ?>">
                            <?php else: ?>
                                <span style="font-size:64px;"><?= $favoriteCatStyle[0] ?></span>
                            <?php endif; ?>
                            <span class="product-variant-badge">Favorite</span>
                        </div>
                        <div class="product-info">
                            <span class="product-category-tag" style="display:none;"><?= $_favoriteCategory ?></span>
                            <p class="product-service-type" style="display:none;"><?= htmlspecialchars($p['description'] ?? '') ?></p>
                            <h3><?= htmlspecialchars($p['product_name']) ?></h3>
                            <div class="product-lead-time"><?= ($p['gender'] ?? '') === 'printed_products' ? 'Printed Products' : ucfirst($p['gender'] ?? 'unisex') . ' sizing' ?></div>
                            <?php if (!empty($p['price']) && $p['price'] > 0): ?>
                                <div class="product-price-tag">₱<?= number_format((float)$p['price'], 2) ?> PHP</div>
                            <?php else: ?>
                                <div class="product-price-tag">Inquire for price</div>
                            <?php endif; ?>
                            <div class="product-lead-time">Estimated completion: <?= (int)($p['working_days'] ?? 3) ?> working day<?= (int)($p['working_days'] ?? 3) === 1 ? '' : 's' ?></div>
                            <div class="product-actions">
                                <button class="btn-add-cart" onclick="event.stopPropagation(); openAlbum(<?= $p['product_id'] ?>, '<?= $_favoriteName ?>', '<?= $_favoriteDesc ?>', <?= $p['variant_count'] ?>, '<?= $_favoriteCategory ?>', <?= (float)($p['price'] ?? 0) ?>, '<?= htmlspecialchars($_favoriteImg, ENT_QUOTES) ?>', '<?= htmlspecialchars($p['gender'] ?? 'unisex', ENT_QUOTES) ?>')">Order again</button>
                                <a href="customer_messages.php?inquire=<?= urlencode($p['product_name']) ?>" class="btn-inquire" onclick="event.stopPropagation();">Inquire</a>
                                <button class="btn-heart is-favorite" aria-label="Favorite product" onclick="event.stopPropagation();">♥</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

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
            <div class="product-card" onclick="openAlbum(<?= $p['product_id'] ?>, '<?= $_jsName ?>', '<?= $_jsDesc ?>', <?= $p['variant_count'] ?>, '<?= $_jsCategory ?>', <?= (float)($p['price'] ?? 0) ?>, '<?= htmlspecialchars($_pImg, ENT_QUOTES) ?>', '<?= htmlspecialchars($p['gender'] ?? 'unisex', ENT_QUOTES) ?>')">
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
                    <div class="product-lead-time"><?= ($p['gender'] ?? '') === 'printed_products' ? 'Printed Products' : ucfirst($p['gender'] ?? 'unisex') . ' sizing' ?></div>
                    <?php if (!empty($p['price']) && $p['price'] > 0): ?>
                        <div class="product-price-tag">₱<?= number_format((float)$p['price'], 2) ?> PHP</div>
                    <?php else: ?>
                        <div class="product-price-tag">Inquire for price</div>
                    <?php endif; ?>
                    <div class="product-lead-time">Estimated completion: <?= (int)($p['working_days'] ?? 3) ?> working day<?= (int)($p['working_days'] ?? 3) === 1 ? '' : 's' ?></div>
                    <div class="product-actions">
                        <button class="btn-add-cart" onclick="event.stopPropagation(); openAlbum(<?= $p['product_id'] ?>, '<?= $_jsName ?>', '<?= $_jsDesc ?>', <?= $p['variant_count'] ?>, '<?= $_jsCategory ?>', <?= (float)($p['price'] ?? 0) ?>, '<?= htmlspecialchars($_pImg, ENT_QUOTES) ?>', '<?= htmlspecialchars($p['gender'] ?? 'unisex', ENT_QUOTES) ?>')">Order now</button>
                        <a href="customer_messages.php?inquire=<?= urlencode($p['product_name']) ?>" class="btn-inquire" onclick="event.stopPropagation();">Inquire</a>
                        <button class="btn-heart<?= in_array((int)$p['product_id'], $favoriteProductIds, true) ? ' is-favorite' : '' ?>" onclick="event.stopPropagation(); this.innerHTML=this.innerHTML==='♡'?'♥':'♡'; this.style.color=this.innerHTML==='♥'?'#ff6b35':'#999';" aria-label="Favorite product"><?= in_array((int)$p['product_id'], $favoriteProductIds, true) ? '♥' : '♡' ?></button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<footer class="site-footer" style="background:transparent; border-top:1px solid rgba(255,255,255,0.06); color:rgba(255,255,255,0.45); padding:30px 20px; font-weight:500;">
    &copy; 2026 <span style="color:#ff6b35;">Zian's PrintShop</span> — Toril, Davao City. All rights reserved.
</footer>

</div>

<div id="profileSettingsModal" class="profile-settings-modal" aria-hidden="true">
    <div class="profile-settings-card" role="dialog" aria-modal="true" aria-labelledby="profileSettingsTitle">
        <button type="button" class="profile-settings-close" onclick="closeProfileSettings()" aria-label="Close profile settings">&times;</button>
        <div class="profile-settings-heading">
            <span class="profile-settings-kicker">Your account</span>
            <h2 id="profileSettingsTitle">Profile Settings</h2>
            <p>Keep your contact details up to date for easier ordering.</p>
        </div>
        <form id="profileSettingsForm" class="profile-settings-form" onsubmit="saveProfileSettings(event)">
            <div class="profile-settings-grid">
                <label>First Name<input type="text" name="first_name" value="<?= htmlspecialchars($profile['first_name'], ENT_QUOTES) ?>" required></label>
                <label>Last Name<input type="text" name="last_name" value="<?= htmlspecialchars($profile['last_name'], ENT_QUOTES) ?>" required></label>
                <label>Email<input type="email" name="email" value="<?= htmlspecialchars($profile['email'], ENT_QUOTES) ?>" required></label>
                <label>Phone<input type="text" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '', ENT_QUOTES) ?>" placeholder="09XX-XXX-XXXX"></label>
                <label>Address<input type="text" name="address" value="<?= htmlspecialchars($profile['address'] ?? '', ENT_QUOTES) ?>" placeholder="Toril, Davao City"></label>
            </div>
            <div class="profile-settings-password">
                <div class="profile-settings-section-label">Change password <span>Optional</span></div>
                <div class="profile-settings-grid">
                    <label>New Password<input type="password" name="password" minlength="6" placeholder="Leave blank to keep current"></label>
                    <label>Confirm Password<input type="password" name="confirm_password" minlength="6" placeholder="Re-enter new password"></label>
                </div>
            </div>
            <div class="profile-settings-actions">
                <button type="button" class="profile-settings-cancel" onclick="closeProfileSettings()">Cancel</button>
                <button type="submit" class="profile-settings-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="checkoutModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(4px);">
    <div class="co-modal">
        
        
        <div class="co-header">
            <div class="co-header-top">
                <span class="co-product-meta" id="modalProductMeta"></span>
                <button onclick="closeCheckout()" class="co-close-btn" title="Close">&times;</button>
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
            
            <input type="hidden" id="checkoutNotes" value="">
            
            <div class="co-btn-row">
                <button onclick="backToProductDetail()" class="co-btn co-btn-secondary">&larr; Back</button>
                <button onclick="goToStep(3)" class="co-btn co-btn-primary">Continue &rarr;</button>
            </div>
        </div>

        
        <div id="checkout-step3" style="display:none;">
            <input type="hidden" id="checkoutGcashRef" value="">
            <input type="hidden" id="checkoutAmount" value="0">

            <div class="co-gcash-card">
                <div class="co-payment-field">
                    <label class="co-payment-label">Pickup Method</label>
                    <div class="co-fixed-select">Store Pickup</div>
                    <div class="co-payment-help">Pick up your order at Zian's PrintShop (Daliao, Toril District, Davao City, Philippines, 8000).</div>
                </div>

                <div class="co-payment-field">
                    <label class="co-payment-label">Payment Method</label>
                    <div class="co-fixed-select">GCash</div>
                </div>

                <div class="co-down-payment">
                    <div class="co-payment-label">Payment Option</div>
                    <div class="co-payment-choice">
                        <label class="co-choice-option">
                            <input type="radio" name="payment_type" value="down" checked onchange="setPaymentType(this.value)">
                            <span>Down Payment</span>
                        </label>
                        <label class="co-choice-option">
                            <input type="radio" name="payment_type" value="full" onchange="setPaymentType(this.value)">
                            <span>Full Payment</span>
                        </label>
                    </div>
                    <label class="co-payment-label" for="checkoutDownPayment">Amount to Pay</label>
                    <div class="co-amount-input">
                        <span>&#8369;</span>
                        <input type="number" id="checkoutDownPayment" min="0" step="0.01" value="" inputmode="decimal" oninput="updatePaymentSummary()">
                    </div>
                    <div class="co-payment-help" id="paymentOptionHelp">Suggested down payment is 50% of the total. This is the minimum; you can enter a higher amount.</div>
                </div>

                <div class="co-gcash-logo-wrap">
                    <img src="../download.jpg" alt="GCash" class="co-gcash-svg">
                    <span class="co-gcash-coming">Integration coming soon</span>
                </div>

                <div class="co-gcash-total-row">
                    <span class="co-gcash-total-label">Order Total</span>
                    <span class="co-gcash-total-val" id="step3Total">&#8369;0.00</span>
                </div>

                <div class="co-gcash-total-row co-gcash-subtotal-row">
                    <span class="co-gcash-total-label">Down Payment</span>
                    <span class="co-gcash-total-val" id="step3DownPayment">&#8369;0.00</span>
                </div>

                <div class="co-gcash-total-row co-gcash-balance-row">
                    <span class="co-gcash-total-label">Remaining Balance</span>
                    <span class="co-gcash-total-val" id="step3Balance">&#8369;0.00</span>
                </div>

                <div class="co-gcash-note">
                    GCash payment integration will be available soon. Place your order now and pay the down payment using the shop's GCash instructions.
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

            
            <div id="pdImgPanel" style="flex:1; position:relative; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:2rem 1.5rem 1.5rem; box-sizing:border-box; background:#fdf5f5; border-radius:20px 0 0 20px; border-right:1px solid rgba(0,0,0,0.06); min-width:0;">

                
                <div id="pdMainImgWrap" onclick="openDetailImgFull()" style="width:100%; max-width:340px; aspect-ratio:1; border-radius:16px; overflow:hidden; background:#ffffff; display:flex; align-items:center; justify-content:center; cursor:zoom-in; position:relative; border:1px solid rgba(0,0,0,0.06); box-shadow:0 12px 32px rgba(0,0,0,0.06);">
                    <button id="pdNavPrev" class="pd-nav-btn" onclick="event.stopPropagation();prevDetailVariant()" style="display:none; left:10px;" title="Previous">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <button id="pdNavNext" class="pd-nav-btn" onclick="event.stopPropagation();nextDetailVariant()" style="display:none; right:10px;" title="Next">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                    <img id="pdMainImg" src="" alt="" style="width:88%; height:88%; object-fit:contain; display:none; transition:transform 0.4s ease;" onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
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
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;margin-bottom:0.45rem;">
                        <div style="font-size:0.6rem;font-weight:800;color:#111111;text-transform:uppercase;letter-spacing:1.5px;">Size</div>
                        <label class="pd-select-all"><input type="checkbox" id="pdSelectAllSizes" onchange="toggleSelectAllSizes(this.checked)"> Select all</label>
                    </div>
                    <div id="pdGenderOptions" style="display:flex; gap:6px; margin:0 0 0.6rem;">
                        <button type="button" class="pd-gender-btn active" data-gender="male" onclick="setProductGender('male')">Male</button>
                        <button type="button" class="pd-gender-btn" data-gender="female" onclick="setProductGender('female')">Female</button>
                    </div>
                    <div id="pdSizeChartHint" style="display:none;margin-bottom:0.45rem;font-size:0.62rem;line-height:1.35;color:#666;"></div>
                    <div id="pdSizesGrid" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                    <button type="button" id="pdAddToOrderBtn" class="pd-add-order-btn" onclick="addSelectedSizesToOrder()">Add to Order</button>
                    <button type="button" id="pdSizeGuideBtn" class="pd-size-guide-btn" onclick="openSizeGuide()">Optional: size guide for exact measurements</button>
                    <div id="pdSizeGuideStatus" style="min-height:14px;text-align:center;color:#16805a;font-size:0.62rem;"></div>
                </div>

                <div id="pdJerseyTypeContainer" style="display:none;margin-bottom:1rem;">
                    <div style="font-size:0.6rem;font-weight:800;color:#111;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:0.45rem;">Jersey Type</div>
                    <div class="pd-jersey-options">
                        <button type="button" class="pd-jersey-btn active" data-jersey-type="Upper Jersey Only" onclick="selectJerseyType(this)">Upper Only</button>
                        <button type="button" class="pd-jersey-btn" data-jersey-type="Upper + Lower Jersey" onclick="selectJerseyType(this)">Upper + Lower</button>
                    </div>
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

<div id="sizeGuideModal" class="size-guide-overlay" role="dialog" aria-modal="true" aria-labelledby="sizeGuideTitle" onclick="if(event.target===this)closeSizeGuide()">
    <section class="size-guide-card">
        <div class="size-guide-header">
            <div>
                <h2 id="sizeGuideTitle">Size Guide</h2>
                <p id="sizeGuideProductName"></p>
            </div>
            <button type="button" class="size-guide-close" onclick="closeSizeGuide()" aria-label="Close size guide">&times;</button>
        </div>
        <p class="size-guide-note">Optional: choose your body measurements below if you want a closer fit. The live recommendation compares them with this product's size chart, but sizing can also be chosen normally without entering details.</p>
        <div class="size-guide-section">
            <h3>Available product sizes</h3>
            <div class="size-guide-table-wrap">
                <table class="size-guide-table" id="sizeGuideTable">
                    <thead id="sizeGuideTableHead"><tr><th>Size</th><th>Stock</th></tr></thead>
                    <tbody id="sizeGuideAvailableSizes"><tr><td colspan="2">Choose a size from the product page.</td></tr></tbody>
                </table>
            </div>
            <p class="size-guide-caption" id="sizeGuideChartCaption">Chart values are the recommended customer measurements for each size.</p>
        </div>
        <div class="size-guide-section">
            <h3>Body measurements <span>(enter all five for a size recommendation)</span></h3>
            <div class="size-guide-fields">
                <label>Height <span>cm · 100–200</span><input type="number" id="sizeGuideHeight" min="100" max="200" step="any" placeholder="e.g. 168" oninput="limitSizeMeasurement(this);handleSizeMeasurementInput()"></label>
                <label>Weight <span>kg · 20–125</span><input type="number" id="sizeGuideWeight" min="20" max="125" step="any" placeholder="e.g. 62" oninput="limitSizeMeasurement(this);handleSizeMeasurementInput()"></label>
            </div>
        </div>
        <div class="size-guide-section">
            <h3>Other body measurements <span>(cm)</span></h3>
            <div class="size-guide-fields size-guide-fields-three">
                <label>Shoulder <span>cm · 20–56</span><input type="number" id="sizeGuideShoulder" min="20" max="56" step="any" placeholder="e.g. 42" oninput="limitSizeMeasurement(this);handleSizeMeasurementInput()"></label>
                <label>Top length <span>cm · 30–86</span><input type="number" id="sizeGuideLength" min="30" max="86" step="any" placeholder="e.g. 68" oninput="limitSizeMeasurement(this);handleSizeMeasurementInput()"></label>
                <label>Waist <span>cm · 40–134</span><input type="number" id="sizeGuideWaist" min="40" max="134" step="any" placeholder="e.g. 92" oninput="limitSizeMeasurement(this);handleSizeMeasurementInput()"></label>
            </div>
        </div>
        <div id="sizeGuideLowerSection" class="size-guide-section" style="display:none;">
            <h3>Lower-part measurements <span>(jersey Upper + Lower only · cm)</span></h3>
            <p class="size-guide-caption">Measure your body or a well-fitting pair of shorts. These details are sent to the shop for lower-part fit checking; the automatic size recommendation uses the upper jersey chart.</p>
            <div class="size-guide-fields" style="margin-top:10px;">
                <label>Lower waist <span>Measure around · 40–150 cm</span><input type="number" id="sizeGuideLowerWaist" min="40" max="150" step="any" placeholder="e.g. 82" oninput="limitSizeMeasurement(this);handleSizeMeasurementInput()"></label>
                <label>Hip / seat <span>Widest point · 50–160 cm</span><input type="number" id="sizeGuideHip" min="50" max="160" step="any" placeholder="e.g. 98" oninput="limitSizeMeasurement(this);handleSizeMeasurementInput()"></label>
                <label>Inseam <span>Crotch to hem · 20–100 cm</span><input type="number" id="sizeGuideInseam" min="20" max="100" step="any" placeholder="e.g. 25" oninput="limitSizeMeasurement(this);handleSizeMeasurementInput()"></label>
                <label>Outseam <span>Waistband to hem · 30–130 cm</span><input type="number" id="sizeGuideOutseam" min="30" max="130" step="any" placeholder="e.g. 48" oninput="limitSizeMeasurement(this);handleSizeMeasurementInput()"></label>
            </div>
        </div>
        <div id="sizeGuideRecommendation" class="size-guide-recommendation" aria-live="polite">Select all five measurements to see your recommended size.</div>
        <div id="sizeGuideSavedMessage" class="size-guide-saved" aria-live="polite"></div>
        <div class="size-guide-actions">
            <button type="button" class="size-guide-cancel" onclick="closeSizeGuide()">Cancel</button>
            <button type="button" class="size-guide-save" onclick="saveSizeGuideMeasurements()">Save sizing</button>
        </div>
    </section>
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
.co-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
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
.co-payment-field { margin-bottom: 0.7rem; }
.co-payment-label {
    display: block;
    color: rgba(255,255,255,0.72);
    font-size: 0.68rem;
    font-weight: 700;
    margin-bottom: 0.3rem;
}
.co-fixed-select {
    min-height: 34px;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    padding: 0 0.7rem;
    background: #18181c;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 7px;
    color: #fff;
    font-size: 0.76rem;
    font-weight: 600;
}
.co-payment-help {
    color: rgba(255,255,255,0.42);
    font-size: 0.62rem;
    line-height: 1.4;
    margin-top: 0.28rem;
}
.co-down-payment {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 9px;
    padding: 0.65rem;
    margin: 0.25rem 0 0.85rem;
}
.co-payment-choice {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.4rem;
    margin-bottom: 0.65rem;
}
.co-choice-option {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    min-height: 32px;
    padding: 0 0.55rem;
    box-sizing: border-box;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 7px;
    color: rgba(255,255,255,0.72);
    font-size: 0.65rem;
    font-weight: 600;
    cursor: pointer;
}
.co-choice-option:has(input:checked) {
    border-color: #ff6b35;
    background: rgba(255,107,53,0.12);
    color: #fff;
}
.co-choice-option input {
    accent-color: #ff6b35;
    margin: 0;
}
.co-amount-input {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    height: 36px;
    padding: 0 0.65rem;
    background: #f3f4f6;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    color: #6b7280;
    font-size: 0.82rem;
    font-weight: 700;
}
.co-amount-input:focus-within { border-color: #9ca3af; box-shadow: 0 0 0 2px rgba(156,163,175,0.18); }
.co-amount-input input {
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    color: #374151;
    font: inherit;
}
.co-amount-input input::placeholder { color: #9ca3af; }
.co-amount-input input:disabled { color: #9ca3af; cursor: not-allowed; }
.co-amount-input input::-webkit-inner-spin-button,
.co-amount-input input::-webkit-outer-spin-button { opacity: 0.7; }
.co-subtotal-row { margin-bottom: 0.25rem; }
.co-balance-row {
    border-top: 1px solid rgba(255,255,255,0.12);
    padding-top: 0.7rem;
    margin-top: 0.2rem;
}
.co-balance-row .co-gcash-total-val { color: #ffb08f; }
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

.pd-modal-content {
    background: #0e0e10;
    border-radius: 20px;
    width: 94%;
    max-width: 860px;
    overflow: hidden;
    position: relative;
    border: 1px solid rgba(255,255,255,0.07);
    box-shadow: 0 30px 80px rgba(0,0,0,0.85);
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.pd-divider {
    width: 100%;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255,107,53,0.6) 30%, rgba(255,107,53,0.9) 50%, rgba(255,107,53,0.6) 70%, transparent);
    box-shadow: 0 0 8px rgba(255,107,53,0.35);
    border-radius: 1px;
    margin: 0.75rem 0;
}

.pd-thumb {
    width: 50px; height: 50px; border-radius: 8px; overflow: hidden;
    border: 2px solid rgba(255,255,255,0.07); transition: all 0.2s ease;
    cursor: pointer; flex-shrink: 0;
    background: #121214;
    display: flex; align-items: center; justify-content: center;
}
.pd-thumb img { width:100%; height:100%; object-fit:cover; }
.pd-thumb.active {
    border-color: #ff6b35;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255,107,53,0.3);
}
.pd-thumb:hover { border-color: rgba(255,107,53,0.4); }

.pd-nav-btn {
    position: absolute; top: 50%; transform: translateY(-50%);
    z-index: 4; background: rgba(0,0,0,0.45); backdrop-filter: blur(8px);
    border: 1px solid rgba(255,255,255,0.08); border-radius: 50%;
    width: 34px; height: 34px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    transition: all 0.22s; opacity: 0; padding: 0; outline: none;
}
#pdMainImgWrap:hover .pd-nav-btn { opacity: 1; }
.pd-nav-btn:hover {
    background: #ff6b35; border-color: transparent;
    box-shadow: 0 4px 14px rgba(255,107,53,0.5);
}

.pd-zoom-hint-badge {
    position: absolute; top: 10px; right: 10px;
    background: rgba(0,0,0,0.55); backdrop-filter: blur(8px);
    color: rgba(255,255,255,0.8); font-size: 0.6rem; font-weight: 700;
    padding: 4px 8px; border-radius: 6px; pointer-events: none;
    border: 1px solid rgba(255,255,255,0.07); z-index: 3;
    display: flex; align-items: center; gap: 4px;
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
.pd-size-btn.disabled,
.pd-size-btn:disabled {
    color: #b8b8b8;
    background: #f2f2f2;
    border-color: #e5e5e5;
    cursor: not-allowed;
    opacity: 0.7;
}
.pd-select-all { color:#666; font-size:0.62rem; font-weight:700; cursor:pointer; text-transform:none; letter-spacing:0; }
.pd-select-all input { accent-color:#ff6b35; vertical-align:middle; margin:0 2px 0 0; }
.pd-add-order-btn { width:100%; margin-top:0.65rem; padding:8px 12px; border:1.5px solid #111; border-radius:999px; background:#fff; color:#111; font:700 0.72rem inherit; cursor:pointer; transition:all .18s; }
.pd-add-order-btn:hover { background:#ff6b35; border-color:#ff6b35; color:#fff; }
.pd-size-guide-btn { display:block; margin:0.55rem auto 0; padding:3px 8px; border:0; background:transparent; color:#c94f2d; font-family:inherit; font-size:0.68rem; font-weight:600; text-decoration:underline; text-underline-offset:3px; cursor:pointer; }
.pd-size-guide-btn:hover { color:#111; }
.pd-gender-btn {
    flex: 1;
    border: 1px solid #d3d7dd;
    background: #fff;
    color: #111827;
    border-radius: 8px;
    font: inherit;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 7px 8px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.pd-gender-btn.active {
    background: #111827;
    border-color: #111827;
    color: #fff;
}
.pd-gender-btn:hover {
    border-color: #ff6b35;
    color: #ff6b35;
}
.size-guide-overlay { display:none; position:fixed; inset:0; z-index:1050; align-items:center; justify-content:center; padding:18px; box-sizing:border-box; background:rgba(12,16,20,.58); backdrop-filter:blur(4px); }
.size-guide-card { width:min(100%,520px); max-height:min(88vh,760px); overflow-y:auto; padding:22px; box-sizing:border-box; border-radius:18px; background:#fff; color:#20242a; box-shadow:0 24px 70px rgba(0,0,0,.25); }
.size-guide-header { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:8px; }
.size-guide-header h2 { margin:0; font-size:1.25rem; line-height:1.25; }
.size-guide-header p { margin:4px 0 0; color:#c94f2d; font-size:.76rem; font-weight:600; }
.size-guide-close { width:32px; height:32px; flex:none; border:0; border-radius:50%; background:#f2f3f5; color:#333; font-size:1.3rem; line-height:1; cursor:pointer; }
.size-guide-note,.size-guide-caption { margin:0; color:#717782; font-size:.72rem; line-height:1.5; }
.size-guide-section { margin-top:18px; }
.size-guide-section h3 { margin:0 0 9px; font-size:.87rem; font-weight:700; }
.size-guide-section h3 span { color:#89909a; font-size:.72rem; font-weight:500; }
.size-guide-table-wrap { overflow-x:auto; border:1px solid #e7e9ed; border-radius:9px; }
.size-guide-table { width:100%; border-collapse:collapse; font-size:.74rem; }
.size-guide-table.has-chart { min-width:660px; }
.size-guide-table th,.size-guide-table td { padding:9px 11px; border-bottom:1px solid #eceef1; text-align:left; white-space:nowrap; }
.size-guide-table th { background:#f7f8fa; font-weight:700; }
.size-guide-table tr:last-child td { border-bottom:0; }
.size-guide-caption { margin-top:7px; }
.size-guide-fields { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
.size-guide-fields-three { grid-template-columns:repeat(3,minmax(0,1fr)); }
.size-guide-fields label { display:flex; flex-direction:column; gap:5px; color:#464c55; font-size:.7rem; font-weight:600; }
.size-guide-fields label span { color:#89909a; font-size:.64rem; font-weight:500; }
.size-guide-fields input,.size-guide-fields select { width:100%; min-width:0; height:39px; padding:0 10px; box-sizing:border-box; border:1px solid #dfe2e7; border-radius:8px; background:#fff; color:#20242a; font-family:inherit; font-size:.78rem; font-weight:500; outline:none; }
.size-guide-fields input:focus,.size-guide-fields select:focus { border-color:#ff6b35; box-shadow:0 0 0 3px rgba(255,107,53,.12); }
.size-guide-saved { min-height:16px; margin-top:10px; color:#16805a; font-size:.69rem; }
.size-guide-recommendation { margin-top:15px; padding:12px; border:1px solid #f1d1c5; border-radius:10px; background:#fff8f5; color:#626873; font-size:.76rem; line-height:1.5; }
.size-guide-recommendation strong { color:#c94f2d; font-size:.93rem; }
.size-guide-recommendation button { margin-left:8px; padding:6px 10px; border:0; border-radius:7px; background:#ff6b35; color:#fff; font:inherit; font-weight:700; cursor:pointer; }
.size-guide-actions { display:flex; justify-content:flex-end; gap:9px; margin-top:8px; }
.size-guide-actions button { padding:9px 15px; border-radius:9px; font-family:inherit; font-size:.75rem; font-weight:600; cursor:pointer; }
.size-guide-cancel { border:1px solid #e0e2e6; background:#fff; color:#4f5661; }
.size-guide-save { border:1px solid #ff6b35; background:#ff6b35; color:#fff; }
.pd-jersey-options { display:grid;grid-template-columns:1fr 1fr;gap:6px; }
.pd-jersey-btn { padding:7px 8px;border:1.5px solid #ddd;border-radius:7px;background:#fff;color:#333;font:700 0.65rem inherit;cursor:pointer; }
.pd-jersey-btn.active { border-color:#ff6b35;background:#fff3ed;color:#ea580c; }

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

@media (max-width: 768px) {
    #pdImgPanel { border-radius: 20px 20px 0 0 !important; }
    #pdMainImgWrap { max-width: 100% !important; }
    .pd-nav-btn { opacity: 0.95; }
    .pd-info-card-overlay {
        width: 100%;
        margin-top: 1.5rem;
    }
}
@media (max-width: 600px) {
    .size-guide-overlay { align-items:flex-start; overflow-y:auto; padding:12px; }
    .size-guide-card { width:100%; max-height:calc(100vh - 24px); max-height:calc(100dvh - 24px); padding:18px; margin:auto 0; }
}
</style>

<script>

let currentProductId = '';
let currentProductName = '';
let currentProductCategory = '';
let currentProductBaseImg = '';
let currentProductBasePrice = 0;
let currentProductGender = 'unisex';
let currentSizeMeasurements = {};
let currentSizeChart = [];
let currentSizeRecommendation = '';
let selectedVariant = null;
let cartItems = [];          
let _detailCurrentIdx = 0;   
let currentStep = 1;
let currentPaymentType = 'down';
let s1Photos = [];
let _detailVariants = [];
let _detailDesigns = [];
let currentDesignKey = '';
let currentJerseyType = 'Upper Jersey Only';

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}
function escAttr(str) {
    return (str || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
}
function getVariantPrice(variant) {
    if (!variant) return 0;
    if (currentJerseyType === 'Upper + Lower Jersey' && parseFloat(variant.upper_lower_price) > 0) {
        return parseFloat(variant.upper_lower_price);
    }
    if (currentJerseyType === 'Upper Jersey Only' && parseFloat(variant.upper_price) > 0) {
        return parseFloat(variant.upper_price);
    }
    const variantPrice = parseFloat(variant.price);
    return variantPrice > 0 ? variantPrice : currentProductBasePrice;
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
    currentProductGender = (gender === 'female') ? 'female' : 'male';
    if (selectedVariant) selectedVariant.gender = currentProductGender;
    const genderButtons = document.querySelectorAll('.pd-gender-btn');
    genderButtons.forEach(button => {
        const isActive = button.dataset.gender === currentProductGender;
        button.classList.toggle('active', isActive);
    });
    const hint = document.getElementById('pdSizeChartHint');
    if (hint) {
        const message = currentProductGender === 'female'
            ? 'Female sizing is selected. Use the exact measurements only if needed for a closer fit.'
            : 'Male sizing is selected. Use the exact measurements only if needed for a closer fit.';
        hint.textContent = message;
        hint.style.display = 'block';
    }
}

function openAlbum(productId, productName, productDesc, variantCount, category, basePrice, baseImg, gender = 'unisex') {
    currentProductId = productId;
    currentProductName = productName;
    currentProductCategory = category || '';
    currentProductBaseImg = baseImg || '';
    currentProductBasePrice = parseFloat(basePrice) || 0;
    currentProductGender = (gender === 'female') ? 'female' : (gender === 'male' ? 'male' : 'male');
    currentSizeMeasurements = {};
    currentSizeChart = [];
    currentSizeRecommendation = '';
    document.getElementById('pdSizeGuideStatus').textContent = '';
    selectedVariant = null;
    cartItems = [];
    _detailCurrentIdx = 0;
    s1Photos = [];
    _detailVariants = [];
    _detailDesigns = [];
    currentJerseyType = 'Upper Jersey Only';
    const jerseyContainer = document.getElementById('pdJerseyTypeContainer');
    if (jerseyContainer) {
        jerseyContainer.style.display = (category || '').toLowerCase().includes('jersey sublimation') ? 'block' : 'none';
        document.querySelectorAll('.pd-jersey-btn').forEach((button, index) => button.classList.toggle('active', index === 0));
    }

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
    const sizeChartHint = document.getElementById('pdSizeChartHint');
    if (sizeChartHint) {
        const message = currentProductGender === 'female'
            ? 'Female sizing selected. Use the exact measurements only if needed for a closer fit.'
            : 'Male sizing selected. Use the exact measurements only if needed for a closer fit.';
        sizeChartHint.textContent = message;
        sizeChartHint.style.display = 'block';
    }
    const genderButtons = document.querySelectorAll('.pd-gender-btn');
    genderButtons.forEach(button => {
        button.classList.toggle('active', button.dataset.gender === currentProductGender);
    });
    document.getElementById('pdSelectAllSizes').checked = false;
    updateAddToOrderLabel();
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
        fetch('customer_dashboard.php?get_variants=' + productId)
            .then(r => r.json())
            .then(variants => {
                renderDetailVariants(variants);
            })
            .catch(() => { document.getElementById('pdThumbStrip').innerHTML = ''; });
        fetch('customer_dashboard.php?get_size_chart=' + encodeURIComponent(productId))
            .then(response => response.json())
            .then(chart => {
                if (String(currentProductId) !== String(productId) || !Array.isArray(chart)) return;
                currentSizeChart = chart;
                if (selectedVariant) selectDetailVariant(selectedVariant.id);
                renderSizeGuideAvailableSizes();
            })
            .catch(() => { currentSizeChart = []; });
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
    if (_detailDesigns.length < 2) return;
    const currentIndex = _detailDesigns.findIndex(v => getVariantDesignKey(v) === currentDesignKey);
    const previousIndex = (currentIndex - 1 + _detailDesigns.length) % _detailDesigns.length;
    selectDetailVariant(_detailDesigns[previousIndex].variant_id);
}

function nextDetailVariant() {
    if (_detailDesigns.length < 2) return;
    const currentIndex = _detailDesigns.findIndex(v => getVariantDesignKey(v) === currentDesignKey);
    const nextIndex = (currentIndex + 1) % _detailDesigns.length;
    selectDetailVariant(_detailDesigns[nextIndex].variant_id);
}

function getVariantDesignKey(variant) {
    return (variant && variant.image ? variant.image : '') + '|' + (variant && variant.variant_name ? variant.variant_name : '');
}

function renderDetailVariants(variants) {
    _detailVariants = variants;
    const designMap = new Map();
    variants.forEach(v => {
        const designKey = (v.image || '') + '|' + (v.variant_name || '');
        if (!designMap.has(designKey)) designMap.set(designKey, v);
    });
    const designs = Array.from(designMap.values());
    _detailDesigns = designs;
    _detailCurrentIdx = 0;
    currentDesignKey = variants.length ? getVariantDesignKey(variants[0]) : '';
    document.getElementById('pdThumbStrip').style.display = 'flex';
    document.getElementById('pdVariantSwatches').style.display = 'none';
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
    if (prevBtn) prevBtn.style.display = designs.length > 1 ? 'flex' : 'none';
    if (nextBtn) nextBtn.style.display = designs.length > 1 ? 'flex' : 'none';

    
    const dotsWrap = document.getElementById('pdDotsWrap');
    if (dotsWrap) {
        dotsWrap.style.display = designs.length > 1 ? 'flex' : 'none';
        dotsWrap.innerHTML = '';
    }

    
    let thumbHtml = '';
    designs.forEach(v => {
        const imgSrc = v.image ? '../' + v.image : '';
        const isBase = v.variant_id === 'base';
        const vidAttr = isBase ? "'base'" : v.variant_id;
        const hasSizesForDesign = variants.some(item => getVariantDesignKey(item) === getVariantDesignKey(v) && item.size);
        const thumbAction = hasSizesForDesign ? `selectDetailVariant(${vidAttr})` : `togglePdCartItem(${vidAttr})`;
        thumbHtml += `<div class="pd-thumb" id="pdt-${v.variant_id}" onclick="${thumbAction}" title="${escHtml(v.variant_name)}">`
            + (imgSrc ? `<img src="${escHtml(imgSrc)}" alt="${escHtml(v.variant_name)}">` : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="hsl(214 32% 40%)" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>')
            + '</div>';
    });
    document.getElementById('pdThumbStrip').innerHTML = thumbHtml;

    
    const sizesContainer = document.getElementById('pdSizesContainer');
    const sizesGrid = document.getElementById('pdSizesGrid');
    renderAvailableSizes();

    
    const swatchEl = document.getElementById('pdVariantSwatches');
    let sw = '<div style="font-size:0.68rem; font-weight:700; color:rgba(255,255,255,0.5); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.5rem;">Select Design</div><div class="pd-color-swatch-wrap">';
    designs.forEach(v => {
        const imgSrc = v.image ? '../' + v.image : '';
        const isBase = v.variant_id === 'base';
        const vidAttr = isBase ? "'base'" : v.variant_id;
        sw += `<div class="pd-color-swatch" id="pds-${v.variant_id}" onclick="togglePdCartItem(${vidAttr})" title="${escHtml(v.variant_name)}"><div class="pd-color-swatch-inner">`
            + (imgSrc ? `<img src="${escHtml(imgSrc)}" alt="${escHtml(v.variant_name)}">` : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="hsl(214 32% 40%)" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>')
            + `</div>`
            + '</div>';
    });
    sw += '</div>';
    swatchEl.innerHTML = '';
    swatchEl.style.display = 'none';

    
    const pdCart = document.getElementById('pdCartSection');
    if (pdCart) pdCart.style.display = 'block';

    
    cartItems = [];
    renderPdInlineCart();

    const initialVariant = variants.find(v => parseInt(v.quantity) > 0) || variants[0];
    selectDetailVariant(initialVariant.variant_id);
}

function renderAvailableSizes() {
    const sizesContainer = document.getElementById('pdSizesContainer');
    const sizesGrid = document.getElementById('pdSizesGrid');
    const designVariants = _detailVariants.filter(v => getVariantDesignKey(v) === currentDesignKey);
    const uniqueSizes = [...new Set(designVariants.map(v => v.size).filter(Boolean))];
    if (!uniqueSizes.length) {
        sizesContainer.style.display = 'none';
        sizesGrid.innerHTML = '';
        return;
    }
    sizesContainer.style.display = 'block';
    sizesGrid.innerHTML = uniqueSizes.map(size => {
        const match = designVariants.find(v => v.size === size);
        const stock = parseInt(match.quantity);
        const unavailable = Number.isFinite(stock) && stock <= 0;
        const vidAttr = match.variant_id === 'base' ? "'base'" : match.variant_id;
        return `<button class="pd-size-btn${unavailable ? ' disabled' : ''}" id="pdsz-${size}" ${unavailable ? 'disabled' : `onclick="event.stopPropagation();selectDetailVariant(${vidAttr});document.getElementById('pdSelectAllSizes').checked=false"`}>${size.toUpperCase()}</button>`;
    }).join('');
    updateAddToOrderLabel();
}

function updateAddToOrderLabel() {
    const button = document.getElementById('pdAddToOrderBtn');
    if (!button) return;
    button.textContent = document.getElementById('pdSelectAllSizes')?.checked ? 'Add All Sizes to Order' : 'Add to Order';
}

function renderSizeGuideAvailableSizes() {
    const body = document.getElementById('sizeGuideAvailableSizes');
    const head = document.getElementById('sizeGuideTableHead');
    const table = document.getElementById('sizeGuideTable');
    const caption = document.getElementById('sizeGuideChartCaption');
    if (!body) return;
    const variants = _detailVariants.filter(variant => variant.size && getVariantDesignKey(variant) === currentDesignKey);
    const uniqueSizes = [...new Set(variants.map(variant => variant.size))];
    if (!uniqueSizes.length) {
        head.innerHTML = '<tr><th>Size</th><th>Stock</th></tr>';
        table.classList.remove('has-chart');
        caption.textContent = 'No size options are configured for this design.';
        body.innerHTML = '<tr><td colspan="2">No sizes available.</td></tr>';
        return;
    }
    const hasChart = uniqueSizes.some(size => currentSizeChart.some(row => row.size === size));
    if (hasChart) {
        head.innerHTML = '<tr><th>Size</th><th>Height (cm)</th><th>Weight (kg)</th><th>Shoulder (cm)</th><th>Top length (cm)</th><th>Waist (cm)</th><th>Stock</th></tr>';
        table.classList.add('has-chart');
        caption.textContent = 'Chart values are the recommended customer measurements for each size. Scroll horizontally to see all measurements.';
    } else {
        head.innerHTML = '<tr><th>Size</th><th>Stock</th></tr>';
        table.classList.remove('has-chart');
        caption.textContent = 'No static reference is configured for these size labels. The fixed chart supports Small, Medium, Large, and XL.';
    }
    body.innerHTML = uniqueSizes.map(size => {
        const variant = variants.find(item => item.size === size);
        const stock = Math.max(0, parseInt(variant.quantity, 10) || 0);
        const chartRow = currentSizeChart.find(item => item.size === size);
        const chartValue = (field, unit) => chartRow && chartRow[field] !== undefined && chartRow[field] !== '' ? `${chartRow[field]}${unit}` : '—';
        const stockLabel = stock > 0 ? `${stock} available` : 'Out of stock';
        return hasChart
            ? `<tr><td>${escHtml(size)}</td><td>${chartValue('height_range', ' cm')}</td><td>${chartValue('weight_range', ' kg')}</td><td>${chartValue('shoulder_cm', ' cm')}</td><td>${chartValue('top_length_cm', ' cm')}</td><td>${chartValue('waist_cm', ' cm')}</td><td>${stockLabel}</td></tr>`
            : `<tr><td>${escHtml(size)}</td><td>${stockLabel}</td></tr>`;
    }).join('');
}

function openSizeGuide() {
    document.getElementById('sizeGuideProductName').textContent = currentProductName;
    document.getElementById('sizeGuideSavedMessage').textContent = '';
    const lowerSection = document.getElementById('sizeGuideLowerSection');
    if (lowerSection) {
        lowerSection.style.display = currentProductCategory.toLowerCase().includes('jersey sublimation')
            && currentJerseyType === 'Upper + Lower Jersey' ? 'block' : 'none';
    }
    const guideNote = document.getElementById('sizeGuideProductName');
    if (guideNote) {
        guideNote.textContent = 'Optional sizing help for exact body measurements';
    }
    const fieldMap = {
        body_height: 'sizeGuideHeight',
        body_weight: 'sizeGuideWeight',
        shoulder: 'sizeGuideShoulder',
        top_length: 'sizeGuideLength',
        waist: 'sizeGuideWaist',
        lower_waist: 'sizeGuideLowerWaist',
        hip: 'sizeGuideHip',
        inseam: 'sizeGuideInseam',
        outseam: 'sizeGuideOutseam'
    };
    Object.entries(fieldMap).forEach(([key, id]) => {
        document.getElementById(id).value = currentSizeMeasurements[key] || '';
    });
    renderSizeGuideAvailableSizes();
    calculateRecommendedSize();
    document.getElementById('sizeGuideModal').style.display = 'flex';
    document.querySelector('#sizeGuideModal .size-guide-card').scrollTop = 0;
    const requestedProductId = currentProductId;
    fetch('customer_dashboard.php?get_size_chart=' + encodeURIComponent(requestedProductId))
        .then(response => response.json())
        .then(chart => {
            if (String(currentProductId) !== String(requestedProductId) || !Array.isArray(chart)) return;
            currentSizeChart = chart;
            renderSizeGuideAvailableSizes();
            calculateRecommendedSize();
        })
        .catch(() => {
            currentSizeChart = [];
            renderSizeGuideAvailableSizes();
            calculateRecommendedSize();
        });
}

function handleSizeMeasurementInput() {
    currentSizeMeasurements = {};
    currentSizeRecommendation = '';
    document.getElementById('pdSizeGuideStatus').textContent = '';
    document.getElementById('sizeGuideSavedMessage').textContent = '';
    calculateRecommendedSize();
}

function limitSizeMeasurement(input) {
    const value = input.value.trim();
    if (!value) {
        input.setCustomValidity('');
        return;
    }
    const numericValue = Number(value);
    const maximum = Number(input.max);
    if (Number.isFinite(numericValue) && numericValue > maximum) {
        input.value = input.max;
        input.setCustomValidity('');
        return;
    }
    const parts = value.split('.');
    const integerDigits = (parts[0] || '').replace('-', '');
    if (integerDigits.length > 3) {
        input.value = input.max;
        input.setCustomValidity('');
    } else {
        input.setCustomValidity('');
    }
}

function closeSizeGuide() {
    document.getElementById('sizeGuideModal').style.display = 'none';
}

function saveSizeGuideMeasurements() {
    const upperFields = {
        body_height: 'sizeGuideHeight',
        body_weight: 'sizeGuideWeight',
        shoulder: 'sizeGuideShoulder',
        top_length: 'sizeGuideLength',
        waist: 'sizeGuideWaist'
    };
    const lowerFields = {
        lower_waist: 'sizeGuideLowerWaist',
        hip: 'sizeGuideHip',
        inseam: 'sizeGuideInseam',
        outseam: 'sizeGuideOutseam'
    };
    const nextMeasurements = {};
    const collectMeasurements = (fields) => {
        const entries = Object.entries(fields).map(([key, id]) => [key, document.getElementById(id)]);
        const started = entries.some(([, input]) => input && input.value.trim() !== '');
        if (!started) return false;
        for (const [key, input] of entries) {
            input.required = true;
            if (!input.checkValidity()) {
                input.reportValidity();
                return null;
            }
            if (input.value.trim() !== '') nextMeasurements[key] = input.value.trim();
        }
        return true;
    };
    const upperResult = collectMeasurements(upperFields);
    if (upperResult === null) return;
    const lowerVisible = document.getElementById('sizeGuideLowerSection')?.style.display !== 'none';
    const lowerResult = lowerVisible ? collectMeasurements(lowerFields) : false;
    if (lowerResult === null) return;
    if (!upperResult && !lowerResult) {
        document.getElementById('sizeGuideSavedMessage').textContent = 'Enter upper-body or lower-part measurements to save sizing.';
        return;
    }
    if (upperResult) {
        calculateRecommendedSize();
        if (!currentSizeRecommendation) {
            document.getElementById('sizeGuideSavedMessage').textContent = 'A size recommendation is not available for these measurements and sizes.';
            return;
        }
    }
    currentSizeMeasurements = nextMeasurements;
    if (upperResult) currentSizeRecommendation = document.getElementById('sizeGuideRecommendation').dataset.recommendedSize || currentSizeRecommendation;
    const savedMessage = document.getElementById('sizeGuideSavedMessage');
    savedMessage.textContent = upperResult
        ? 'Measurements saved. Recommended size: ' + currentSizeRecommendation + '. Lower-part measurements, if entered, will also be sent with your order.'
        : 'Lower-part measurements saved. They will be sent with your jersey order for fit checking.';
    document.getElementById('pdSizeGuideStatus').textContent = upperResult ? 'Fit: ' + currentSizeRecommendation + ' ✓' : 'Lower sizing saved ✓';
    closeSizeGuide();
}

function calculateRecommendedSize() {
    const result = document.getElementById('sizeGuideRecommendation');
    if (!result) return;
    currentSizeRecommendation = '';
    result.dataset.recommendedSize = '';
    const inputFields = {
        height_cm: 'sizeGuideHeight',
        weight_kg: 'sizeGuideWeight',
        shoulder_cm: 'sizeGuideShoulder',
        top_length_cm: 'sizeGuideLength',
        waist_cm: 'sizeGuideWaist'
    };
    const fieldLabels = { height_cm: 'Height', weight_kg: 'Weight', shoulder_cm: 'Shoulder', top_length_cm: 'Top length', waist_cm: 'Waist' };
    const values = {};
    for (const [field, id] of Object.entries(inputFields)) {
        const input = document.getElementById(id);
        if (input.value.trim() === '') {
            result.textContent = 'Enter all five measurements to see your recommended size.';
            return;
        }
        if (!input.checkValidity()) {
            result.textContent = input.validationMessage || `${fieldLabels[field]} must be between ${input.min} and ${input.max}.`;
            return;
        }
        const value = parseFloat(input.value);
        if (!Number.isFinite(value) || value <= 0) {
            result.textContent = 'Enter valid positive measurements to see your recommended size.';
            return;
        }
        values[field] = value;
    }
    if (!currentSizeChart.length) {
        result.textContent = 'No fixed reference is available for these size labels. Recommendations are supported from Small through 5XL.';
        return;
    }
    const inStockVariants = _detailVariants.filter(variant =>
        variant.size && getVariantDesignKey(variant) === currentDesignKey && parseInt(variant.quantity, 10) > 0
    );
    const sizes = [...new Set(inStockVariants.map(variant => variant.size))];
    const matches = sizes.map(size => {
        const chartRow = currentSizeChart.find(row => row.size === size);
        if (!chartRow) return null;
        let totalDifference = 0;
        for (const field of Object.keys(inputFields)) {
            const target = parseFloat(chartRow[field]);
            if (!Number.isFinite(target) || target <= 0) return null;
            if (field === 'height_cm' || field === 'weight_kg') {
                const prefix = field === 'height_cm' ? 'height' : 'weight';
                const minimum = parseFloat(chartRow[prefix + '_min']);
                const maximum = parseFloat(chartRow[prefix + '_max']);
                if (!Number.isFinite(minimum) || !Number.isFinite(maximum)) return null;
                const outsideRange = values[field] < minimum ? minimum - values[field] : values[field] > maximum ? values[field] - maximum : 0;
                totalDifference += outsideRange / target;
            } else {
                totalDifference += Math.abs(values[field] - target) / target;
            }
        }
        return { size, score: totalDifference / Object.keys(inputFields).length, variant: inStockVariants.find(item => item.size === size) };
    }).filter(Boolean).sort((first, second) => first.score - second.score);
    if (!matches.length) {
        result.textContent = 'No in-stock sizes have a complete chart for this design.';
        return;
    }
    const best = matches[0];
    currentSizeRecommendation = best.size;
    currentSizeMeasurements = {
        body_height: String(values.height_cm),
        body_weight: String(values.weight_kg),
        shoulder: String(values.shoulder_cm),
        top_length: String(values.top_length_cm),
        waist: String(values.waist_cm)
    };
    result.dataset.recommendedSize = best.size;
    result.innerHTML = `Closest size-chart match: <strong>${escHtml(best.size)}</strong> <button type="button" onclick="selectRecommendedSize(${Number(best.variant.variant_id)})">Select ${escHtml(best.size)}</button>`;
}

function selectRecommendedSize(variantId) {
    saveSizeGuideMeasurements();
    if (!currentSizeRecommendation) return;
    selectDetailVariant(variantId);
    closeSizeGuide();
}

function toggleSelectAllSizes(checked) {
    document.querySelectorAll('#pdSizesGrid .pd-size-btn:not(:disabled)').forEach(button => button.classList.toggle('active', checked));
    updateAddToOrderLabel();
}

function addSelectedSizesToOrder() {
    const selectAll = document.getElementById('pdSelectAllSizes')?.checked;
    const designVariants = _detailVariants.filter(v => getVariantDesignKey(v) === currentDesignKey && parseInt(v.quantity) > 0);
    const variantsToAdd = selectAll ? designVariants : (selectedVariant ? [_detailVariants.find(v => v.variant_id == selectedVariant.id)] : []);
    variantsToAdd.filter(Boolean).forEach(v => {
        const existing = cartItems.find(item => item.variant && item.variant.id == v.variant_id && item.variant.gender === currentProductGender);
        if (!existing) {
            cartItems.push({ variant: { id: v.variant_id, name: v.variant_name, size: v.size || '', gender: currentProductGender, price: getVariantPrice(v), img: v.image ? '../' + v.image : '', designKey: getVariantDesignKey(v), jerseyType: currentJerseyType }, qty: 1 });
        }
    });
    renderPdInlineCart();
    const button = document.getElementById('pdOrderNowBtn');
    if (button) button.textContent = cartItems.length ? `Order Now (${cartItems.length} item${cartItems.length > 1 ? 's' : ''})` : 'Order Now';
}

function selectJerseyType(button) {
    currentJerseyType = button.dataset.jerseyType || 'Upper Jersey Only';
    document.querySelectorAll('.pd-jersey-btn').forEach(option => option.classList.remove('active'));
    button.classList.add('active');
    cartItems.forEach(item => {
        if (item.variant) {
            item.variant.jerseyType = currentJerseyType;
            const source = _detailVariants.find(variant => variant.variant_id == item.variant.id);
            if (source) item.variant.price = getVariantPrice(source);
        }
    });
    if (selectedVariant) {
        const source = _detailVariants.find(variant => variant.variant_id == selectedVariant.id);
        if (source) selectedVariant.price = getVariantPrice(source);
    }
    if (selectedVariant) document.getElementById('pdBasePrice').textContent = '\u20B1' + getVariantPrice(selectedVariant).toLocaleString('en-PH', {minimumFractionDigits:2});
    renderPdInlineCart();
}

function selectDetailVariant(variantId) {
    const v = _detailVariants.find(x => x.variant_id == variantId);
    if (!v) return;
    const idx = _detailVariants.indexOf(v);
    if (idx >= 0) _detailCurrentIdx = idx;
    const imgSrc = v.image ? '../' + v.image : '';
    const isBase = v.variant_id === 'base';
    currentDesignKey = getVariantDesignKey(v);
    selectedVariant = { id: isBase ? null : v.variant_id, name: v.variant_name, size: v.size || '', gender: currentProductGender, price: getVariantPrice(v), img: imgSrc, designKey: getVariantDesignKey(v) };

    renderAvailableSizes();
    calculateRecommendedSize();

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
    const pn = getVariantPrice(v);
    const qty = v.quantity !== null && v.quantity !== undefined ? parseInt(v.quantity) : null;
    let info = `<strong style="color:#fff">${escHtml(v.variant_name)}</strong>`;
    if (v.size) info += `<span style="color:rgba(255,255,255,0.4)"> &middot; Size: ${escHtml(v.size.toUpperCase())}</span>`;
    const sizeFit = v.size ? currentSizeChart.find(row => row.size === v.size) : null;
    if (sizeFit) {
        info += `<div style="margin-top:5px;color:#6b7280;font-size:0.65rem;line-height:1.5;">Fit reference: Height ${escHtml(sizeFit.height_range)} cm · Weight ${escHtml(sizeFit.weight_range)} kg · Shoulder ${escHtml(String(sizeFit.shoulder_cm))} cm · Length ${escHtml(String(sizeFit.top_length_cm))} cm · Waist ${escHtml(String(sizeFit.waist_cm))} cm</div>`;
    }
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
    const idx = cartItems.findIndex(ci => ci.variant && ci.variant.id == matchId && ci.variant.gender === currentProductGender);
    if (idx >= 0) {
        cartItems.splice(idx, 1);
        const sw = document.getElementById('pds-' + variantId);
        if (sw) sw.classList.remove('active');
    } else {
        const v = _detailVariants.find(x => x.variant_id == variantId);
        if (!v) return;
        const imgSrc = v.image ? '../' + v.image : '';
        cartItems.push({ variant: { id: isBase ? null : v.variant_id, name: v.variant_name, size: v.size || '', gender: currentProductGender, price: getVariantPrice(v), img: imgSrc, designKey: getVariantDesignKey(v), jerseyType: currentJerseyType }, qty: 1 });
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
        const meta = [v && v.size ? v.size.toUpperCase() : '', v && v.gender ? (v.gender === 'female' ? 'Female' : 'Male') : '', v && v.jerseyType ? v.jerseyType : '', pn > 0 ? '\u20b1'+pn.toLocaleString('en-PH',{minimumFractionDigits:2}) : ''].filter(Boolean).join(' \u00b7 ');
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
        if (_detailVariants.length > 0) {
            showToast('No Items Selected', 'Add a size or design to your order first.', 'error');
            return;
        }

        cartItems = [{
            variant: {
                id: null,
                name: currentProductName,
                size: '',
                price: currentProductBasePrice,
                img: currentProductBaseImg ? '../' + currentProductBaseImg : '',
                jerseyType: currentJerseyType
            },
            qty: 1
        }];
    }

    closeProductDetail();

    document.getElementById('modalProductMeta').textContent = currentProductCategory || 'Printing Service';
    document.getElementById('checkoutNotes').value = '';
    document.getElementById('checkoutGcashRef').value = '';
    document.getElementById('checkoutAmount').value = '';
    currentPaymentType = 'down';
    document.querySelector('input[name="payment_type"][value="down"]').checked = true;
    document.getElementById('checkoutDownPayment').disabled = false;
    document.getElementById('checkoutDownPayment').value = '';

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
    currentSizeMeasurements = {};
    currentSizeChart = [];
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
        const price = getVariantPrice(v);
        const priceStr = price > 0 ? '&#8369;' + price.toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2}) : '';
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
        cartItems.push({ variant: { id: isBase ? null : v.variant_id, name: v.variant_name, size: v.size || '', price: getVariantPrice(v), img: imgSrc, jerseyType: currentJerseyType }, qty: 1 });
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
    _detailDesigns = [];
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
        const downPaymentInput = document.getElementById('checkoutDownPayment');
        if (currentPaymentType === 'down' && downPaymentInput && downPaymentInput.value.trim() === '') {
            downPaymentInput.value = (getCheckoutOrderTotal() / 2).toFixed(2);
        }
        updatePaymentSummary();
    }
    setStep(n);
}

function setPaymentType(type) {
    currentPaymentType = type === 'full' ? 'full' : 'down';
    const input = document.getElementById('checkoutDownPayment');
    const help = document.getElementById('paymentOptionHelp');
    if (currentPaymentType === 'full') {
        if (input) {
            input.disabled = true;
            input.value = '';
        }
        if (help) help.textContent = 'Full payment selected. The full order total will be paid now.';
    } else {
        if (input) {
            input.disabled = false;
            input.value = (getCheckoutOrderTotal() / 2).toFixed(2);
        }
        if (help) help.textContent = 'Suggested: 50% down payment. This is the minimum; you can enter a higher amount. The remaining balance is due upon pickup.';
    }
    updatePaymentSummary();
}

function getCheckoutOrderTotal() {
    return cartItems.reduce((total, item) => {
        const price = parseFloat(item.variant ? item.variant.price : 0);
        return total + (price > 0 ? price * item.qty : 0);
    }, 0);
}

function updatePaymentSummary() {
    const total = getCheckoutOrderTotal();

    const input = document.getElementById('checkoutDownPayment');
    const inputIsEmpty = input && input.value.trim() === '';

    let downPayment = 0;
    if (currentPaymentType === 'full') {
        downPayment = total;
    } else {
        downPayment = inputIsEmpty ? 0 : (input ? parseFloat(input.value) : 0);
    }

    if (!Number.isFinite(downPayment) || downPayment < 0) downPayment = 0;
    if (total > 0) downPayment = Math.min(downPayment, total);
    if (input && total > 0) {
        input.min = (Math.ceil(total * 50) / 100).toFixed(2);
        input.max = total.toFixed(2);
    }
    if (currentPaymentType !== 'full' && total > 0 && Math.abs(downPayment - total) < 0.005) {
        currentPaymentType = 'full';
        const fullPaymentOption = document.querySelector('input[name="payment_type"][value="full"]');
        if (fullPaymentOption) fullPaymentOption.checked = true;
        if (input) input.disabled = true;
        const help = document.getElementById('paymentOptionHelp');
        if (help) help.textContent = 'Full payment selected. The full order total will be paid now.';
        downPayment = total;
    }
    if (input && currentPaymentType !== 'full' && (!inputIsEmpty && parseFloat(input.value) !== downPayment)) {
        input.value = downPayment.toFixed(2);
    }
    if (input && currentPaymentType === 'full') {
        input.value = total.toFixed(2);
    }

    const balance = Math.max(0, total - downPayment);
    const totalEl = document.getElementById('step3Total');
    const downEl = document.getElementById('step3DownPayment');
    const balanceEl = document.getElementById('step3Balance');
    if (totalEl) totalEl.textContent = total > 0 ? '\u20b1' + total.toLocaleString('en-PH', {minimumFractionDigits:2}) : 'Price TBD';
    if (downEl) downEl.textContent = '\u20b1' + downPayment.toLocaleString('en-PH', {minimumFractionDigits:2});
    if (balanceEl) balanceEl.textContent = '\u20b1' + balance.toLocaleString('en-PH', {minimumFractionDigits:2});
    document.getElementById('checkoutAmount').value = downPayment.toFixed(2);
    const placeOrderButton = document.getElementById('placeOrderBtn');
    if (placeOrderButton) {
        placeOrderButton.disabled = total > 0 && downPayment < (total / 2);
        placeOrderButton.title = placeOrderButton.disabled ? 'Enter at least 50% down payment to continue.' : '';
    }
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

function previewPhotos(input, storeRef, previewId, placeholderId) {
    
    if (!input.files || !input.files.length) return;
    for (let i = 0; i < input.files.length; i++) s1Photos.push(input.files[i]);
    input.value = '';
    renderPhotoPreview();
}

function renderPhotoPreview() {
    const placeholder = document.getElementById('s1Placeholder');
    const preview = document.getElementById('s1Preview');
    const dz = document.getElementById('s1DropZone');

    if (!s1Photos.length) {
        placeholder.style.display = 'block';
        preview.style.display = 'none';
        preview.innerHTML = '';
        dz.style.borderColor = '#2a2a2a';
        return;
    }
    placeholder.style.display = 'none';
    preview.style.display = 'flex';
    dz.style.borderColor = '#ff6b35';
    preview.innerHTML = '';

    s1Photos.forEach((f, i) => {
        const url = URL.createObjectURL(f);
        const wrap = document.createElement('div');
        wrap.className = 'photo-thumb';
        wrap.innerHTML = `<img src="${url}"><button type="button" onclick="event.stopPropagation();removePhoto(${i})">&times;</button>`;
        preview.appendChild(wrap);
    });

    const addBtn = document.createElement('div');
    addBtn.style.cssText = 'width:62px;height:62px;border:2px dashed hsl(214 32% 80%);border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:hsl(215 16% 55%);font-size:1.4rem;flex-shrink:0;';
    addBtn.textContent = '+';
    addBtn.onclick = (e) => { e.stopPropagation(); document.getElementById('s1PhotoInput').click(); };
    addBtn.onmouseover = () => { addBtn.style.borderColor='#ff6b35'; addBtn.style.color='#ff6b35'; };
    addBtn.onmouseout  = () => { addBtn.style.borderColor='#333'; addBtn.style.color='#555'; };
    preview.appendChild(addBtn);
}

function removePhoto(idx) {
    s1Photos.splice(idx, 1);
    renderPhotoPreview();
}

function dzDragOver(e, dzId) {
    e.preventDefault();
    const dz = document.getElementById(dzId);
    dz.style.borderColor = '#ff6b35';
    dz.style.background = 'rgba(255,107,53,0.04)';
}
function dzDragLeave(dzId) {
    const dz = document.getElementById(dzId);
    dz.style.borderColor = s1Photos.length ? '#ff6b35' : '#2a2a2a';
    dz.style.background = 'transparent';
}
function dzDrop(e, dzId) {
    e.preventDefault();
    dzDragLeave(dzId);
    const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
    files.forEach(f => s1Photos.push(f));
    if (files.length) renderPhotoPreview();
}

function placeOrder() {
    syncCartQtys();
    if (!cartItems.length) { showToast('Empty Cart', 'Please add items to your order first.', 'error'); return; }
    updatePaymentSummary();
    const btn = document.getElementById('placeOrderBtn');
    btn.disabled = true;
    btn.textContent = 'Placing...';

    const fd = new FormData();
    fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
    fd.append('action', 'add_to_cart_multi');
    fd.append('product_id', currentProductId);
    fd.append('product_name', currentProductName);
    fd.append('variant_items', JSON.stringify(cartItems.map(ci => ({
        variant_id:   ci.variant ? ci.variant.id   : null,
        variant_name: ci.variant ? ci.variant.name : '',
        jersey_type: ci.variant ? ci.variant.jerseyType : '',
        size:         ci.variant ? ci.variant.size : '',
        gender:       ci.variant ? ci.variant.gender : currentProductGender,
        measurements: currentSizeMeasurements,
        recommended_size: currentSizeRecommendation,
        price:        ci.variant ? ci.variant.price : 0,
        qty:          ci.qty
    }))));
    fd.append('payment_mode', 'GCash');
    fd.append('gcash_reference', document.getElementById('checkoutGcashRef').value.trim());
    fd.append('down_payment_amount', document.getElementById('checkoutAmount').value || '0');
    fd.append('order_notes', document.getElementById('checkoutNotes').value.trim());
    s1Photos.forEach(f => fd.append('ref_photos[]', f));

    fetch('customer_dashboard.php', { method: 'POST', body: fd })
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
    ['12','23'].forEach(key => document.getElementById('sl-' + key).classList.add('done'));
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
    document.querySelectorAll('.shop-category-pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const searchInput = document.getElementById('navbarSearchInput');
    const query = category === 'all' ? '' : category;
    if (searchInput) {
        searchInput.value = query;
    }
    handleNavbarSearch(query);
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
    const genderAttr = escAttr(p.gender || 'unisex');
    
    const priceNum = parseFloat(p.price || 0);
    const priceHtml = priceNum > 0 
        ? `<div class="product-price-tag">\u20B1${priceNum.toLocaleString('en-PH',{minimumFractionDigits:2})} PHP</div>` 
        : `<div class="product-price-tag">Inquire for price</div>`;
    const workingDays = Math.max(1, parseInt(p.working_days) || 3);
    const leadTimeHtml = `<div class="product-lead-time">Estimated completion: ${workingDays} working day${workingDays === 1 ? '' : 's'}</div>`;
        
    const encodedName = encodeURIComponent(p.product_name);

    const btnHtml = `<button class="btn-add-cart" onclick="event.stopPropagation(); openAlbum(${p.product_id},'${nameAttr}','${descAttr}',${vc},'${catAttr}',${priceNum},'${escAttr(imgPath)}','${genderAttr}')">Order now</button>`;

    return `<div class="product-card" onclick="openAlbum(${p.product_id},'${nameAttr}','${descAttr}',${vc},'${catAttr}',${priceNum},'${escAttr(imgPath)}','${genderAttr}')">
        <div class="product-img" style="background:${catStyle[1]};">${imgHtml}${varBadge}</div>
        <div class="product-info">
            <span class="product-category-tag" style="display:none;">${escHtml(catName)}</span>
            <p class="product-service-type" style="display:none;">${desc}</p>
            <h3>${name}</h3>
            ${priceHtml}
            ${leadTimeHtml}
            <div class="product-actions">
                ${btnHtml}
                <a href="customer_messages.php?inquire=${encodedName}" class="btn-inquire" onclick="event.stopPropagation();">Inquire</a>
                <button class="btn-heart" onclick="event.stopPropagation(); this.innerHTML=this.innerHTML==='♡'?'\\u2665':'\\u2661'; this.style.color=this.innerHTML==='\\u2665'?'#000':'#999';">&#9825;</button>
            </div>
        </div>
    </div>`;
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
            }
        })
        .catch(() => {});
}

setInterval(pollProducts, 5000);

function openProfileSettings() {
    const modal = document.getElementById('profileSettingsModal');
    if (!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    const firstInput = modal.querySelector('input');
    if (firstInput) firstInput.focus();
}

function closeProfileSettings() {
    const modal = document.getElementById('profileSettingsModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

function saveProfileSettings(event) {
    event.preventDefault();
    const form = event.target;
    const button = form.querySelector('.profile-settings-save');
    const formData = new FormData(form);
    formData.append('csrf_token', <?= json_encode(csrf_token()) ?>);
    formData.append('action', 'update_profile');
    button.disabled = true;
    button.textContent = 'Saving...';

    fetch('customer_dashboard.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Profile Updated', data.message, 'success');
                closeProfileSettings();
                setTimeout(() => window.location.reload(), 500);
            } else {
                showToast('Unable to Save', data.message, 'error');
            }
        })
        .catch(() => showToast('Unable to Save', 'Something went wrong. Please try again.', 'error'))
        .finally(() => {
            button.disabled = false;
            button.textContent = 'Save Changes';
        });
}

document.getElementById('profileSettingsModal')?.addEventListener('click', event => {
    if (event.target.id === 'profileSettingsModal') closeProfileSettings();
});

if (new URLSearchParams(window.location.search).get('settings') === '1') {
    openProfileSettings();
}

</script>
<?php include 'chatbot_widget.php'; ?>
</body>
</html>

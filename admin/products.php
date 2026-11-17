<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../size_chart_helper.php';
require_once __DIR__ . '/check_admin.php';
require_once __DIR__ . '/../upload_helper.php';
require_once __DIR__ . '/../csrf_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_product') {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    try {
        $id = (int)($_POST['product_id'] ?? 0);
        $name = trim($_POST['product_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $gender = in_array($_POST['gender'] ?? 'unisex', ['male', 'female', 'unisex', 'printed_products'], true) ? $_POST['gender'] : 'unisex';
        $quantity = min(999, max(0, (int)($_POST['quantity'] ?? 0)));
        $workingDays = max(1, (int)($_POST['working_days'] ?? 3));
        $isActive = (int)($_POST['is_active'] ?? 1);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        $removeImg = ($_POST['remove_img'] ?? '') === '1';
        $oldPriceStmt = $conn->prepare("SELECT price FROM products WHERE record_type = 'product' AND product_id = ? LIMIT 1");
        if (!$oldPriceStmt) throw new RuntimeException('Unable to read the current product price.');
        $oldPriceStmt->bind_param('i', $id);
        $oldPriceStmt->execute();
        $oldProduct = $oldPriceStmt->get_result()->fetch_assoc();
        if (!$oldProduct) throw new RuntimeException('The product could not be found. Refresh the page and try again.');
        $oldPrice = (float)$oldProduct['price'];
        $updatedVariantPrices = 0;

        if (isset($_FILES['img']) && $_FILES['img']['error'] === UPLOAD_ERR_OK && is_valid_image_upload($_FILES['img']['tmp_name'], $_FILES['img']['name'])) {
            $uploadDir = '../img/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $ext = strtolower(pathinfo($_FILES['img']['name'], PATHINFO_EXTENSION));
            $filename = 'product_' . $id . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($_FILES['img']['tmp_name'], $uploadDir . $filename)) {
                throw new RuntimeException('Unable to save the uploaded product image.');
            }
            $img = 'img/uploads/' . $filename;
            $stmt = $conn->prepare("UPDATE products SET category_id=?, product_name=?, description=?, unit=?, gender=?, quantity=?, working_days=?, is_active=?, price=?, img=? WHERE record_type='product' AND product_id=?");
            $stmt->bind_param('issssiiidsi', $categoryId, $name, $desc, $unit, $gender, $quantity, $workingDays, $isActive, $price, $img, $id);
        } elseif ($removeImg) {
            $oldRow = $conn->query("SELECT img FROM products WHERE record_type='product' AND product_id = " . $id)->fetch_assoc();
            $stmt = $conn->prepare("UPDATE products SET category_id=?, product_name=?, description=?, unit=?, gender=?, quantity=?, working_days=?, is_active=?, price=?, img='' WHERE record_type='product' AND product_id=?");
            $stmt->bind_param('issssiiidi', $categoryId, $name, $desc, $unit, $gender, $quantity, $workingDays, $isActive, $price, $id);
            if (!empty($oldRow['img']) && strpos($oldRow['img'], 'img/uploads/') === 0) {
                $oldPath = __DIR__ . '/../' . $oldRow['img'];
                if (is_file($oldPath)) unlink($oldPath);
            }
        } else {
            $stmt = $conn->prepare("UPDATE products SET category_id=?, product_name=?, description=?, unit=?, gender=?, quantity=?, working_days=?, is_active=?, price=? WHERE record_type='product' AND product_id=?");
            $stmt->bind_param('issssiiidi', $categoryId, $name, $desc, $unit, $gender, $quantity, $workingDays, $isActive, $price, $id);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to save the product changes.');
        }
        if (abs($price - $oldPrice) >= 0.005) {
            $variantPriceStmt = $conn->prepare("UPDATE products
                SET price = CASE WHEN price = ? THEN ? ELSE price END,
                    upper_price = CASE WHEN upper_price = ? THEN ? ELSE upper_price END,
                    upper_lower_price = CASE WHEN upper_lower_price = ? THEN ? ELSE upper_lower_price END
                WHERE record_type = 'variant' AND product_id = ?");
            if (!$variantPriceStmt) throw new RuntimeException('Product saved, but variant prices could not be synchronized.');
            $variantPriceStmt->bind_param('ddddddi', $oldPrice, $price, $oldPrice, $price, $oldPrice, $price, $id);
            if (!$variantPriceStmt->execute()) throw new RuntimeException('Product saved, but variant prices could not be synchronized.');
            $updatedVariantPrices = max(0, $variantPriceStmt->affected_rows);
        }
    } catch (Throwable $error) {
        error_log('Product update failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to save product changes. Please check that the product table has the required columns and review the PHP error log.']);
        exit;
    }
    echo json_encode(['success' => true, 'variant_prices_updated' => $updatedVariantPrices]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_product') {
    $name = trim($_POST['product_name']);
    $desc = trim($_POST['description']);
    $unit = trim($_POST['unit'] ?? '');
    $gender = in_array($_POST['gender'] ?? 'unisex', ['male', 'female', 'unisex', 'printed_products'], true) ? $_POST['gender'] : 'unisex';
    $quantity = min(999, max(0, (int)($_POST['quantity'] ?? 0)));
    $workingDays = max(1, (int)($_POST['working_days'] ?? 3));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $img = '';

    if (isset($_FILES['img']) && $_FILES['img']['error'] === 0 && is_valid_image_upload($_FILES['img']['tmp_name'], $_FILES['img']['name'])) {
        $uploadDir = '../img/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $ext = pathinfo($_FILES['img']['name'], PATHINFO_EXTENSION);
        $filename = 'product_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['img']['tmp_name'], $uploadDir . $filename);
        $img = 'img/uploads/' . $filename;
    }

    $nextProductId = (int)$conn->query("SELECT COALESCE(MAX(entity_id), 0) + 1 AS next_id FROM products WHERE record_type='product'")->fetch_assoc()['next_id'];
    $stmt = $conn->prepare("INSERT INTO products (record_type, entity_id, category_id, product_id, product_name, description, unit, gender, quantity, working_days, price, img, created_at) VALUES ('product', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param('iiissssiids', $nextProductId, $categoryId, $nextProductId, $name, $desc, $unit, $gender, $quantity, $workingDays, $price, $img);
    $stmt->execute();
    header('Location: products.php?msg=added');
    exit;
}

if (isset($_GET['get_size_chart'])) {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    try {
        $productId = (int)$_GET['get_size_chart'];
        if ($productId < 1) throw new InvalidArgumentException('Invalid product ID.');
        $stmt = $conn->prepare("SELECT DISTINCT size FROM products WHERE record_type='variant' AND product_id = ? AND TRIM(COALESCE(size, '')) <> '' ORDER BY size");
        if (!$stmt) throw new RuntimeException('Unable to prepare size-chart query: ' . $conn->error);
        $stmt->bind_param('i', $productId);
        if (!$stmt->execute()) throw new RuntimeException('Unable to query product sizes: ' . $stmt->error);
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $measurements = getStaticProductSizeChartRow((string)$row['size']);
            if ($measurements) $rows[] = $measurements;
        }
        echo json_encode($rows);
    } catch (Throwable $error) {
        error_log('Static size chart request failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to load the static size guide. Verify the database setup and review the PHP error log.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_variant') {
    header('Content-Type: application/json');
    $productId = (int)$_POST['product_id'];
    $variantName = trim($_POST['variant_name']);
    $basePrice = (float)($_POST['price'] ?? 0);
    $upperPrice = max(0, (float)($_POST['upper_price'] ?? $basePrice));
    $upperLowerPrice = max(0, (float)($_POST['upper_lower_price'] ?? $basePrice));
    $productRow = $conn->query("SELECT pc.category_name FROM products p LEFT JOIN products pc ON pc.record_type='category' AND pc.category_id = p.category_id WHERE p.record_type='product' AND p.product_id = " . $productId)->fetch_assoc();
    $isJersey = strtolower(trim($productRow['category_name'] ?? '')) === 'jersey sublimation';
    $isSash = strpos(strtolower(trim($productRow['category_name'] ?? '')), 'sash') !== false;
    $sizeOptions = json_decode($_POST['size_options'] ?? '[]', true);
    if (!is_array($sizeOptions) || empty($sizeOptions)) {
        $sizeOptions = array_map(function ($size) use ($basePrice, $upperPrice, $upperLowerPrice) {
            return ['size' => $size, 'price' => $basePrice, 'upper_price' => $upperPrice, 'upper_lower_price' => $upperLowerPrice, 'quantity' => 0];
        }, json_decode($_POST['sizes'] ?? '[]', true) ?: []);
    }
    $sizeOptions = array_values(array_filter($sizeOptions, function ($option) use ($isSash) {
        return is_array($option) && ($isSash || trim($option['size'] ?? '') !== '');
    }));
    if (empty($sizeOptions) && $isSash) {
        $sizeOptions = [['size' => '', 'price' => $basePrice, 'quantity' => 0]];
    }
    if (empty($sizeOptions)) {
        echo json_encode(['success' => false, 'error' => 'Select at least one size']);
        exit;
    }
    $duplicateCheck = $conn->prepare("SELECT variant_id FROM products WHERE record_type='variant' AND product_id = ? AND LOWER(variant_name) = LOWER(?) AND LOWER(TRIM(size)) = LOWER(TRIM(?)) AND is_active = 1 LIMIT 1");
    if (!$duplicateCheck) {
        echo json_encode(['success' => false, 'error' => 'Unable to check existing variant sizes.']);
        exit;
    }
    foreach ($sizeOptions as $option) {
        $candidateSize = trim((string)($option['size'] ?? ''));
        if ($candidateSize === '') continue;
        $duplicateCheck->bind_param('iss', $productId, $variantName, $candidateSize);
        $duplicateCheck->execute();
        if ($duplicateCheck->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => $candidateSize . ' has already been created for this variant.']);
            exit;
        }
    }
    $img = '';

    if (isset($_FILES['variant_img']) && $_FILES['variant_img']['error'] === 0 && is_valid_image_upload($_FILES['variant_img']['tmp_name'], $_FILES['variant_img']['name'])) {
        $uploadDir = '../img/uploads/variants/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['variant_img']['name'], PATHINFO_EXTENSION));
        $filename = 'variant_' . $productId . '_' . time() . '_' . mt_rand(100,999) . '.' . $ext;
        move_uploaded_file($_FILES['variant_img']['tmp_name'], $uploadDir . $filename);
        $img = 'img/uploads/variants/' . $filename;
    }
    if ($img === '') {
        $imageStmt = $conn->prepare("SELECT image FROM products WHERE record_type='variant' AND product_id = ? AND LOWER(variant_name) = LOWER(?) AND image <> '' ORDER BY variant_id ASC LIMIT 1");
        if ($imageStmt) {
            $imageStmt->bind_param('is', $productId, $variantName);
            $imageStmt->execute();
            $imageRow = $imageStmt->get_result()->fetch_assoc();
            $img = $imageRow['image'] ?? '';
        }
    }

    $stmt = $conn->prepare("INSERT INTO products (record_type, entity_id, product_id, variant_id, variant_name, size, price, upper_price, upper_lower_price, quantity, image) VALUES ('variant', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => $conn->error]);
        exit;
    }
    $variantIds = [];
    foreach ($sizeOptions as $option) {
        $size = trim($option['size']);
        $price = max(0, (float)($option['price'] ?? ($isJersey ? $upperPrice : $basePrice)));
        $optionUpperPrice = max(0, (float)($option['upper_price'] ?? ($isJersey ? $upperPrice : $price)));
        $optionUpperLowerPrice = max(0, (float)($option['upper_lower_price'] ?? ($isJersey ? $upperLowerPrice : $price)));
        $quantity = min(999, max(0, (int)($option['quantity'] ?? 0)));
        $nextVariantId = (int)$conn->query("SELECT COALESCE(MAX(entity_id), 0) + 1 AS next_id FROM products WHERE record_type='variant'")->fetch_assoc()['next_id'];
        $stmt->bind_param('iiissdddis', $nextVariantId, $productId, $nextVariantId, $variantName, $size, $price, $optionUpperPrice, $optionUpperLowerPrice, $quantity, $img);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
            exit;
        }
        $variantIds[] = $nextVariantId;
    }
    echo json_encode(['success' => true, 'variant_id' => $variantIds[0], 'variant_ids' => $variantIds]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_product_photos') {
    header('Content-Type: application/json');
    $productId = (int)($_POST['product_id'] ?? 0);
    $product = $conn->query("SELECT product_name, price FROM products WHERE record_type='product' AND product_id = " . $productId)->fetch_assoc();
    $files = $_FILES['design_photos'] ?? null;
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $added = 0;

    if (!$productId || !$product || !$files || !is_array($files['name'])) {
        echo json_encode(['success' => false, 'error' => 'No photos selected']);
        exit;
    }

    $uploadDir = '../img/uploads/variants/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    foreach ($files['name'] as $index => $originalName) {
        if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!is_valid_image_upload($files['tmp_name'][$index], $originalName)) continue;

        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $variantName = trim(preg_replace('/[-_]+/', ' ', $baseName));
        $variantName = $variantName !== '' ? $variantName : 'Design ' . ($added + 1);
        $filename = 'variant_' . $productId . '_' . time() . '_' . mt_rand(1000, 9999) . '_' . $index . '.' . $ext;
        if (!move_uploaded_file($files['tmp_name'][$index], $uploadDir . $filename)) continue;

        $imagePath = 'img/uploads/variants/' . $filename;
        $price = (float)($product['price'] ?? 0);
        $nextVariantId = (int)$conn->query("SELECT COALESCE(MAX(entity_id), 0) + 1 AS next_id FROM products WHERE record_type='variant'")->fetch_assoc()['next_id'];
        $stmt = $conn->prepare("INSERT INTO products (record_type, entity_id, product_id, variant_id, variant_name, size, price, quantity, image) VALUES ('variant', ?, ?, ?, ?, '', ?, 0, ?)");
        $stmt->bind_param('iiisds', $nextVariantId, $productId, $nextVariantId, $variantName, $price, $imagePath);
        $stmt->execute();
        $added++;
    }

    echo json_encode(['success' => $added > 0, 'added' => $added, 'error' => $added ? '' : 'No valid photos were uploaded']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_variant') {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    try {
        $variantId = (int)($_POST['variant_id'] ?? 0);
        if ($variantId < 1) throw new InvalidArgumentException('Invalid variant. Refresh the album and try again.');
        $price = max(0, (float)($_POST['price'] ?? 0));
        $upperPrice = max(0, (float)($_POST['upper_price'] ?? $price));
        $upperLowerPrice = max(0, (float)($_POST['upper_lower_price'] ?? $price));
        $quantity = min(999, max(0, (int)($_POST['quantity'] ?? 0)));
        $stmt = $conn->prepare("UPDATE products SET price = ?, upper_price = ?, upper_lower_price = ?, quantity = ? WHERE record_type='variant' AND variant_id = ?");
        if (!$stmt) throw new RuntimeException('Could not prepare the variant stock update: ' . $conn->error);
        $stmt->bind_param('dddii', $price, $upperPrice, $upperLowerPrice, $quantity, $variantId);
        if (!$stmt->execute()) throw new RuntimeException('Could not save the variant stock: ' . $stmt->error);
        echo json_encode(['success' => true]);
    } catch (Throwable $error) {
        error_log('Variant stock update failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to save stock or price. ' . $error->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_variant') {
    header('Content-Type: application/json');
    $variantId = (int)$_POST['variant_id'];
    $referenceStmt = $conn->prepare('SELECT COUNT(*) AS total FROM orders WHERE variant_id = ?');
    $referenceStmt->bind_param('i', $variantId);
    $referenceStmt->execute();
    $orderReferences = (int)$referenceStmt->get_result()->fetch_assoc()['total'];
    if ($orderReferences > 0) {
        $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE record_type='variant' AND variant_id = ?");
        $stmt->bind_param('i', $variantId);
        $stmt->execute();
        echo json_encode(['success' => true, 'soft_deleted' => true, 'message' => 'Variant deactivated because it is used by an existing order.']);
    } else {
        $stmt = $conn->prepare("DELETE FROM products WHERE record_type='variant' AND variant_id = ?");
        $stmt->bind_param('i', $variantId);
        $stmt->execute();
        echo json_encode(['success' => $stmt->affected_rows > 0]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_variant_status') {
    header('Content-Type: application/json');
    $variantId = (int)($_POST['variant_id'] ?? 0);
    $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
    $stmt = $conn->prepare("UPDATE products SET is_active = ? WHERE record_type='variant' AND variant_id = ?");
    $stmt->bind_param('ii', $isActive, $variantId);
    echo json_encode(['success' => $stmt->execute()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_product_status') {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    try {
        $productId = (int)($_POST['product_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
        if ($productId < 1) throw new InvalidArgumentException('Invalid product.');
        $stmt = $conn->prepare("UPDATE products SET is_active = ? WHERE record_type='product' AND product_id = ?");
        if (!$stmt) throw new RuntimeException('Unable to prepare product status update: ' . $conn->error);
        $stmt->bind_param('ii', $isActive, $productId);
        if (!$stmt->execute()) throw new RuntimeException('Unable to update product status: ' . $stmt->error);
        echo json_encode(['success' => true, 'is_active' => $isActive]);
    } catch (Throwable $error) {
        error_log('Product status update failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $error->getMessage()]);
    }
    exit;
}

if (isset($_GET['get_variants'])) {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    try {
        $pid = (int)$_GET['get_variants'];
        if ($pid < 1) throw new InvalidArgumentException('Invalid product ID.');
        $stmt = $conn->prepare("SELECT * FROM products WHERE record_type='variant' AND product_id = ? ORDER BY variant_id ASC");
        if (!$stmt) throw new RuntimeException('Unable to prepare variant query: ' . $conn->error);
        if (!$stmt->bind_param('i', $pid) || !$stmt->execute()) {
            throw new RuntimeException('Unable to load product variants: ' . $stmt->error);
        }
        $result = $stmt->get_result();
        $variants = [];
        while ($row = $result->fetch_assoc()) $variants[] = $row;
        echo json_encode($variants);
    } catch (Throwable $error) {
        error_log('Product variants request failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to load product sizes. Verify that the correct database is imported and review the PHP error log.']);
    }
    exit;
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $referenceStmt = $conn->prepare('SELECT COUNT(*) AS total FROM orders WHERE product_id = ?');
    $referenceStmt->bind_param('i', $id);
    $referenceStmt->execute();
    $orderReferences = (int)$referenceStmt->get_result()->fetch_assoc()['total'];
    if ($orderReferences > 0) {
        $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE record_type='product' AND product_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        header('Location: products.php?msg=deactivated');
    } else {
        $conn->begin_transaction();
        $stmt = $conn->prepare("DELETE FROM products WHERE record_type='variant' AND product_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt = $conn->prepare("DELETE FROM products WHERE record_type='product' AND product_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $conn->commit();
        header('Location: products.php?msg=deleted');
    }
    exit;
}

$conn->query("INSERT INTO products (record_type,entity_id,category_id,category_name) SELECT 'category',n,n,'Campaign T-Shirt' FROM (SELECT COALESCE(MAX(entity_id),0)+1 n FROM products WHERE record_type='category') x WHERE NOT EXISTS (SELECT 1 FROM products WHERE record_type='category' AND category_name='Campaign T-Shirt')");
$conn->query("UPDATE products SET category_name = 'Jersey Sublimation' WHERE record_type='category' AND category_name = 'Jersey Sublation'");
$conn->query("INSERT INTO products (record_type,entity_id,category_id,category_name) SELECT 'category',n,n,'Jersey Sublimation' FROM (SELECT COALESCE(MAX(entity_id),0)+1 n FROM products WHERE record_type='category') x WHERE NOT EXISTS (SELECT 1 FROM products WHERE record_type='category' AND category_name='Jersey Sublimation')");
$conn->query("INSERT INTO products (record_type,entity_id,category_id,category_name) SELECT 'category',n,n,'Printed Products' FROM (SELECT COALESCE(MAX(entity_id),0)+1 n FROM products WHERE record_type='category') x WHERE NOT EXISTS (SELECT 1 FROM products WHERE record_type='category' AND category_name='Printed Products')");
$categories = [];
$catRes = $conn->query("SELECT * FROM products WHERE record_type='category' ORDER BY category_name ASC");
while ($r = $catRes->fetch_assoc()) $categories[] = $r;

$productsRes = $conn->query("SELECT p.*, pc.category_name, (SELECT CASE WHEN COUNT(NULLIF(pv.image, '')) > 0 THEN COUNT(DISTINCT NULLIF(pv.image, '')) ELSE COUNT(*) END FROM products pv WHERE pv.record_type='variant' AND pv.product_id = p.product_id AND pv.is_active = 1) as variant_count FROM products p LEFT JOIN products pc ON pc.record_type='category' AND p.category_id = pc.category_id WHERE p.record_type='product' ORDER BY p.product_id DESC");
$products = [];
while ($r = $productsRes->fetch_assoc()) {
    $products[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - Zian's PrintShop Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/admin.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    body { font-family: 'Inter', sans-serif; }
    .variant-size-option { padding: 0.45rem 0.65rem; border: 1px solid #e5e7eb; border-radius: 0.5rem; background: #fff; color: #4b5563; font-size: 0.7rem; font-weight: 600; cursor: pointer; }
    .variant-size-option:hover, .variant-size-option.selected { border-color: #ff6b35; background: #fff3ed; color: #ea580c; }
    .variant-size-option:disabled, .variant-size-option:disabled:hover { border-color: #e5e7eb; background: #e5e7eb; color: #9ca3af; cursor: not-allowed; opacity: 0.7; text-decoration: line-through; }
    </style>
</head>
<body class="bg-gray-50 admin-page">

<?php include 'sidebar.php'; ?>

<main class="ml-64 p-8 min-h-screen">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Products</h1>
            <p class="text-gray-500 text-sm mt-1">Manage your printing services, products & album variants</p>
        </div>
        <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="px-5 py-2.5 rounded-lg text-white text-sm font-semibold" style="background:#ff6b35; box-shadow:0 4px 14px rgba(255,107,53,0.35);">
            + Add Product
        </button>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <script>document.addEventListener('DOMContentLoaded', () => showToast('<?= $_GET['msg'] === 'added' ? 'Product Added' : ($_GET['msg'] === 'deactivated' ? 'Product Deactivated' : 'Product Deleted') ?>', '<?= $_GET['msg'] === 'added' ? 'Product has been added successfully.' : ($_GET['msg'] === 'deactivated' ? 'Product is still kept for existing order history.' : 'Product has been deleted successfully.') ?>', 'success'));</script>
    <?php endif; ?>

    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <?php if (empty($products)): ?>
            <div class="col-span-full text-center py-16 text-gray-400">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="20" height="20" rx="2"/><path d="M12 8v8m-4-4h8"/></svg>
                <p class="text-lg font-medium">No products yet</p>
                <p class="text-sm">Add your first product to get started</p>
            </div>
        <?php else: ?>
            <?php foreach ($products as $p): ?>
            <div class="bg-white rounded-xl overflow-hidden stat-card">
                <?php if (!empty($p['img'])): ?>
                    <div class="h-40 bg-gray-100 overflow-hidden relative">
                        <img src="../<?= htmlspecialchars($p['img']) ?>" alt="<?= htmlspecialchars($p['product_name']) ?>" class="w-full h-full object-cover">
                        <?php if ($p['variant_count'] > 0): ?>
                        <div class="absolute top-2 right-2 px-2 py-1 rounded-full text-xs font-bold text-white" style="background:#ff6b35;">
                            <?= $p['variant_count'] ?> variant<?= $p['variant_count'] > 1 ? 's' : '' ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="h-40 bg-gray-100 flex items-center justify-center relative">
                        <svg class="w-12 h-12 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                        <?php if ($p['variant_count'] > 0): ?>
                        <div class="absolute top-2 right-2 px-2 py-1 rounded-full text-xs font-bold text-white" style="background:#ff6b35;">
                            <?= $p['variant_count'] ?> variant<?= $p['variant_count'] > 1 ? 's' : '' ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="p-4">
                    <?php if (!empty($p['category_name'])): ?>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-orange-50 text-orange-600 mb-1 inline-block"><?= htmlspecialchars($p['category_name']) ?></span>
                    <?php endif; ?>
                    <h3 class="font-semibold text-gray-900"><?= htmlspecialchars($p['product_name']) ?></h3>
                    <p class="text-xs text-gray-500 mt-1"><?= ($p['gender'] ?? '') === 'printed_products' ? 'Printed Products' : ucfirst($p['gender'] ?? 'unisex') . ' sizing' ?></p>
                    <p class="text-sm text-gray-500 mt-1 line-clamp-2"><?= htmlspecialchars($p['description'] ?? 'No description') ?></p>
                    <?php if (!empty($p['price']) && $p['price'] > 0): ?>
                    <p class="text-sm font-bold mt-1" style="color:#ff6b35;">₱<?= number_format($p['price'], 2) ?></p>
                    <?php endif; ?>
                    <p class="text-xs text-gray-500 mt-1"><?= (int)($p['working_days'] ?? 3) ?> working day<?= (int)($p['working_days'] ?? 3) === 1 ? '' : 's' ?></p>
                    <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-100">
                        <span class="text-xs px-2 py-1 rounded-full <?= $p['is_active'] ? 'bg-green-50 text-green-600' : 'bg-gray-100 text-gray-500' ?>">
                            <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                        <div class="flex items-center gap-3">
                            <button onclick="openEditModal(<?= $p['product_id'] ?>, <?= htmlspecialchars(json_encode($p['product_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($p['description'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($p['unit'] ?? ''), ENT_QUOTES) ?>, <?= (int)$p['is_active'] ?>, <?= htmlspecialchars(json_encode($p['img'] ?? ''), ENT_QUOTES) ?>, <?= (int)($p['category_id'] ?? 0) ?>, <?= (float)($p['price'] ?? 0) ?>, <?= (int)($p['quantity'] ?? 0) ?>, <?= (int)($p['working_days'] ?? 3) ?>, <?= htmlspecialchars(json_encode($p['gender'] ?? 'unisex'), ENT_QUOTES) ?>)" class="text-xs font-semibold text-blue-500 hover:text-blue-700">
                                <svg class="inline w-3.5 h-3.5 mr-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Edit
                            </button>
                            <button onclick="openAlbumModal(<?= $p['product_id'] ?>, '<?= htmlspecialchars($p['product_name'], ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($p['category_name'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($p['gender'] ?? 'unisex'), ENT_QUOTES) ?>)" class="text-xs font-semibold hover:opacity-80" style="color:#ff6b35;">
                                <svg class="inline w-3.5 h-3.5 mr-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/></svg>
                                Album
                            </button>
                            <button onclick="toggleProductStatus(<?= (int)$p['product_id'] ?>, <?= $p['is_active'] ? 0 : 1 ?>)" class="text-xs font-semibold <?= $p['is_active'] ? 'text-red-400 hover:text-red-600' : 'text-green-500 hover:text-green-700' ?>">
                                <?= $p['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </button>
                            <a href="javascript:void(0)" onclick="showConfirm({title:'Delete or Deactivate Product?',message:'Products used by existing orders will be deactivated and kept for history. Unused products may be permanently deleted.',type:'danger',confirmText:'Continue',onConfirm:()=>{window.location='products.php?delete=<?= $p['product_id'] ?>';}})" class="text-xs text-red-400 hover:text-red-600">Delete / Deactivate</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<div id="addModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center">
    <div class="bg-white rounded-2xl w-full max-w-lg p-8 mx-4 shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-900">Add New Product</h2>
            <button onclick="document.getElementById('addModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <form method="POST" action="products.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="add_product">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select name="category_id" required class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm bg-white">
                        <option value="0">-- Select Category --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Product Name</label>
                    <input type="text" name="product_name" required placeholder="e.g., T-shirt Sublimation, Tarpaulin Print, Sticker" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Gender / Size Group</label>
                    <select name="gender" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm bg-white">
                        <option value="unisex">Unisex / General</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="printed_products">Printed Products</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2" placeholder="e.g., Full sublimation print, vibrant colors, all sizes available" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm resize-none"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Price (₱)</label>
                        <input type="number" name="price" step="0.01" min="0" placeholder="0.00" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Working Days</label>
                        <input type="number" name="working_days" min="1" max="30" value="3" required class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                        <div style="display:flex;align-items:center;gap:4px;">
                            <button type="button" onclick="adjQty('newQty',-1)" style="width:32px;height:38px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:1.1rem;cursor:pointer;flex-shrink:0;">-</button>
                            <input type="number" name="quantity" id="newQty" value="0" min="0" max="999" oninput="limitQuantityInput(this)" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm text-center" style="min-width:0;">
                            <button type="button" onclick="adjQty('newQty',1)" style="width:32px;height:38px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:1.1rem;cursor:pointer;flex-shrink:0;">+</button>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference / Cover Photo</label>
                    <input type="file" name="img" accept="image/*" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-orange-50 file:text-orange-600 hover:file:bg-orange-100">
                    <p class="text-xs text-gray-400 mt-1">Main reference photo. Add more color/design photos via Album after saving.</p>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" class="flex-1 py-2.5 px-4 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 px-4 rounded-lg text-sm font-semibold text-white" style="background:#ff6b35;">Add Product</button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center">
    <div class="bg-white rounded-2xl w-full max-w-lg p-8 mx-4 shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-900">Edit Product</h2>
            <button onclick="document.getElementById('editModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <input type="hidden" id="editProductId">
        <input type="hidden" id="editProductActive" value="1">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select id="editProductCategory" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm bg-white">
                    <option value="0">-- Select Category --</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Product Name</label>
                <input type="text" id="editProductName" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Gender / Size Group</label>
                <select id="editProductGender" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm bg-white">
                    <option value="unisex">Unisex / General</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="printed_products">Printed Products</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea id="editProductDesc" rows="2" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm resize-none"></textarea>
            </div>
                <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price (₱)</label>
                    <input type="number" id="editProductPrice" step="0.01" min="0" placeholder="0.00" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                    <p class="mt-1 text-xs text-gray-400">Variant sizes still using the old base price will update too. Custom size prices are preserved.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Working Days</label>
                    <input type="number" id="editProductWorkingDays" min="1" max="30" value="3" required class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <button type="button" onclick="adjQty('editProductQty',-1)" style="width:32px;height:38px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:1.1rem;cursor:pointer;flex-shrink:0;">-</button>
                        <input type="number" id="editProductQty" value="0" min="0" max="999" oninput="limitQuantityInput(this)" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm text-center" style="min-width:0;">
                        <button type="button" onclick="adjQty('editProductQty',1)" style="width:32px;height:38px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:1.1rem;cursor:pointer;flex-shrink:0;">+</button>
                    </div>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Reference / Cover Photo</label>
                <div id="editImgPreview" class="mb-2" style="display:none;">
                    <img id="editImgThumb" class="w-20 h-20 object-cover rounded-lg border border-gray-200">
                </div>
                <input type="file" id="editProductImg" accept="image/*" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-orange-50 file:text-orange-600 hover:file:bg-orange-100">
                <input type="hidden" id="editRemoveImg" value="0">
                <button type="button" id="removeProductImgBtn" onclick="removeProductImage()" class="mt-2 text-xs font-semibold text-red-500 hover:text-red-700" style="display:none;">Remove current photo</button>
                <p class="text-xs text-gray-400 mt-1">Leave empty to keep the current image</p>
                <button type="button" onclick="openEditProductAlbum()" class="mt-2 px-3 py-2 rounded-lg text-xs font-semibold text-orange-600 bg-orange-50 hover:bg-orange-100">+ Add More Design Photos</button>
            </div>
        </div>
        <div class="flex gap-3 mt-6">
            <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="flex-1 py-2.5 px-4 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
            <button type="button" onclick="saveProduct()" id="saveProductBtn" class="flex-1 py-2.5 px-4 rounded-lg text-sm font-semibold text-white" style="background:#ff6b35;">Save Changes</button>
        </div>
    </div>
</div>

<div id="albumModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center">
    <div class="bg-white rounded-2xl w-full max-w-3xl p-8 mx-4 shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="text-xl font-bold text-gray-900">Product Album</h2>
                <p id="albumProductName" class="text-sm mt-0.5" style="color:#ff6b35; font-weight:600;"></p>
            </div>
            <button onclick="closeAlbumModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>

        
        <div class="bg-gray-50 rounded-xl p-5 mb-6 border border-gray-100">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Add New Variant</h3>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <input type="hidden" id="albumProductId">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Choose Existing Variant</label>
                    <select id="existingVariantSelect" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm bg-white">
                        <option value="">Create a new variant</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Color / Variant Name</label>
                    <input type="text" id="variantName" placeholder="e.g., Red, Blue, White" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Size</label>
                    <input type="hidden" id="variantSize" value="[]">
                    <div class="flex flex-wrap gap-1.5" id="variantSizeOptions">
                        <button type="button" class="variant-size-option" data-size="Small" onclick="toggleVariantSize('Small', this)">Small</button>
                        <button type="button" class="variant-size-option" data-size="Medium" onclick="toggleVariantSize('Medium', this)">Medium</button>
                        <button type="button" class="variant-size-option" data-size="Large" onclick="toggleVariantSize('Large', this)">Large</button>
                        <button type="button" class="variant-size-option" data-size="XL" onclick="toggleVariantSize('XL', this)">XL</button>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Choose an existing variant to add sizes to it. Sizes already on that variant are grayed out.</p>
                </div>
                <div class="col-span-2 md:col-span-3">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Price and Stock per Size</label>
                    <div id="variantSizeConfig" class="space-y-2">
                        <p class="text-xs text-gray-400">Select sizes above to set each price and stock quantity.</p>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Reference Photo</label>
                    <input type="file" id="variantImg" accept="image/*" class="w-full text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-orange-50 file:text-orange-600">
                </div>
            </div>
            <button onclick="addVariant()" id="addVariantBtn" class="mt-3 px-5 py-2 rounded-lg text-white text-sm font-semibold" style="background:#ff6b35;">
                + Add Variant
            </button>
            <button type="button" onclick="openSizeChartEditor()" class="mt-3 ml-2 px-4 py-2 rounded-lg border border-orange-200 bg-white text-orange-600 text-sm font-semibold hover:bg-orange-50">
                View Static Size Guide
            </button>
        </div>

        <div class="flex items-center justify-between gap-4 bg-orange-50 rounded-xl p-4 mb-6 border border-orange-100">
            <div>
                <h3 class="text-sm font-semibold text-gray-700">Add More Photos</h3>
                <p class="text-xs text-gray-500 mt-1">Upload multiple designs for this product. Customers can choose them when ordering.</p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <input type="file" id="productDesignPhotos" accept="image/*" multiple class="hidden">
                <button type="button" onclick="document.getElementById('productDesignPhotos').click()" class="px-4 py-2 rounded-lg text-white text-xs font-semibold" style="background:#ff6b35;">Choose Photos</button>
                <button type="button" onclick="uploadProductPhotos()" id="uploadProductPhotosBtn" class="px-4 py-2 rounded-lg border border-orange-200 text-orange-600 text-xs font-semibold bg-white" disabled>Upload</button>
            </div>
        </div>

        
        <div id="variantsGrid" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        </div>
        <div id="noVariants" class="text-center py-10 text-gray-400" style="display:none;">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/></svg>
            <p class="text-sm font-medium">No variants yet</p>
            <p class="text-xs mt-1">Add photos & sizes for this product</p>
        </div>
    </div>
</div>

<div id="sizeChartAdminModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center bg-black/60 p-4">
    <div class="w-full max-w-5xl max-h-[88vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
        <div class="mb-4 flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-bold text-gray-900">Static Size Guide</h2>
                <p id="sizeChartAdminProductName" class="mt-1 text-sm font-semibold" style="color:#ff6b35;"></p>
                <p class="mt-2 max-w-3xl text-xs leading-relaxed text-gray-500">Fixed apparel reference measurements are shown here and used by the customer size guide. These values are not editable per product.</p>
            </div>
            <button type="button" onclick="closeSizeChartEditor()" class="text-2xl text-gray-400 hover:text-gray-700" aria-label="Close size chart">&times;</button>
        </div>
        <div class="overflow-x-auto rounded-lg border border-gray-200">
            <table class="w-full min-w-[760px] text-left text-xs">
                <thead class="bg-gray-50 text-gray-600">
                    <tr><th class="px-3 py-3">Size</th><th class="px-3 py-3">Height (cm)</th><th class="px-3 py-3">Weight (kg)</th><th class="px-3 py-3">Shoulder (cm)</th><th class="px-3 py-3">Top length (cm)</th><th class="px-3 py-3">Waist (cm)</th></tr>
                </thead>
                <tbody id="sizeChartAdminRows" class="divide-y divide-gray-100"></tbody>
            </table>
        </div>
        <div id="sizeChartAdminStatus" class="mt-3 min-h-5 text-xs text-gray-500" aria-live="polite"></div>
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" onclick="closeSizeChartEditor()" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600">Close</button>
        </div>
    </div>
</div>

<script>
const csrfToken = <?= json_encode(csrf_token()) ?>;

let currentAlbumProductId = null;
let currentAlbumIsJersey = false;
let currentAlbumIsSash = false;
let existingAlbumVariants = [];

let currentAlbumGender = 'unisex';

async function openSizeChartEditor() {
    const productId = currentAlbumProductId || document.getElementById('albumProductId').value;
    const modal = document.getElementById('sizeChartAdminModal');
    const rows = document.getElementById('sizeChartAdminRows');
    const status = document.getElementById('sizeChartAdminStatus');
    if (!productId) return;
    document.getElementById('sizeChartAdminProductName').textContent = document.getElementById('albumProductName').textContent;
    rows.innerHTML = '<tr><td colspan="6" class="px-3 py-5 text-center text-gray-400">Loading sizes...</td></tr>';
    status.textContent = '';
    modal.classList.remove('hidden');

    try {
        const readJson = async response => {
            const body = await response.text();
            try { return JSON.parse(body); }
            catch (error) { throw new Error('The server returned an invalid response. Check the PHP error log.'); }
        };
        const [variantsResult, chartResult] = await Promise.all([
            fetch('products.php?get_variants=' + encodeURIComponent(productId)).then(readJson),
            fetch('products.php?get_size_chart=' + encodeURIComponent(productId)).then(readJson).catch(error => ({ success: false, error: error.message }))
        ]);
        if (!Array.isArray(variantsResult)) throw new Error('Unable to load product sizes. Refresh the page and try again.');
        const variants = variantsResult;
        const chart = Array.isArray(chartResult) ? chartResult : [];
        if (!Array.isArray(chartResult)) status.textContent = chartResult.error || 'Size chart is unavailable. Check the database setup.';
        else status.textContent = 'Static reference values are available from Small through 5XL.';
        const sizes = [...new Set(variants.map(variant => (variant.size || '').trim()).filter(Boolean))];
        if (!sizes.length) {
            rows.innerHTML = '<tr><td colspan="6" class="px-3 py-5 text-center text-gray-400">This product has no sized variants.</td></tr>';
            return;
        }
        const chartBySize = new Map(chart.map(entry => [entry.size, entry]));
        rows.innerHTML = sizes.map(size => {
            const values = chartBySize.get(size);
            const cells = values
                ? [`${values.height_range} cm`, `${values.weight_range} kg`, `${values.shoulder_cm} cm`, `${values.top_length_cm} cm`, `${values.waist_cm} cm`]
                : ['—', '—', '—', '—', '—'];
            return `<tr><th class="whitespace-nowrap px-3 py-3 font-semibold text-gray-700">${escHtml(size)}</th>${cells.map(value => `<td class="whitespace-nowrap px-3 py-3 text-gray-700">${escHtml(value)}</td>`).join('')}</tr>`;
        }).join('');
    } catch (error) {
        rows.innerHTML = '<tr><td colspan="6" class="px-3 py-5 text-center text-red-500">Unable to load product sizes. Refresh the page and try again.</td></tr>';
        status.textContent = error.message || 'Unable to load size chart.';
    }
}

function closeSizeChartEditor() {
    document.getElementById('sizeChartAdminModal').classList.add('hidden');
}

function renderGenderSizeOptions(gender) {
    const options = gender === 'male'
        ? ['37cm (Small)', '38cm (Small)', '39cm (Medium)', '40cm (Medium)', '41cm (Large)', '42cm (Large)', '43cm (XL)', '44cm (XL)', '46cm (2XL)', '47cm (2XL)', '48cm (3XL)', '49cm (3XL)', '50cm (4XL)', '52cm (5XL)', '54cm (6XL)', '56cm (7XL)', '58cm (8XL)', '60cm (9XL)']
        : ['Small', 'Medium', 'Large', 'XL', '2XL', '3XL', '4XL', '5XL'];
    document.getElementById('variantSizeOptions').innerHTML = options.map(size => `<button type="button" class="variant-size-option" data-size="${size}" onclick="toggleVariantSize('${size}', this)">${size}</button>`).join('');
}

function openAlbumModal(productId, productName, categoryName, gender = 'unisex') {
    currentAlbumProductId = productId;
    existingAlbumVariants = [];
    currentAlbumGender = gender || 'unisex';
    currentAlbumIsJersey = (categoryName || '').trim().toLowerCase() === 'jersey sublimation';
    currentAlbumIsSash = (categoryName || '').trim().toLowerCase().includes('sash');
    document.getElementById('variantSizeOptions').style.display = currentAlbumIsSash ? 'none' : 'flex';
    renderGenderSizeOptions(currentAlbumGender);
    document.getElementById('variantSize').value = '[]';
    document.getElementById('variantSizeConfig').innerHTML = currentAlbumIsSash
        ? '<div class="grid grid-cols-2 gap-2"><input type="number" id="sash-price" step="0.01" min="0" placeholder="Price" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm"><input type="number" id="sash-stock" min="0" max="999" oninput="limitQuantityInput(this)" placeholder="Stock" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm"></div>'
        : '<p class="text-xs text-gray-400">Select sizes above to set each price and stock quantity.</p>';
    document.getElementById('albumProductId').value = productId;
    document.getElementById('albumProductName').textContent = productName;
    document.getElementById('variantName').value = '';
    document.getElementById('variantName').readOnly = false;
    document.getElementById('existingVariantSelect').value = '';
    document.getElementById('variantSize').value = '[]';
    document.getElementById('addVariantBtn').textContent = '+ Add Variant';
    document.getElementById('albumModal').classList.remove('hidden');
    loadVariants(productId);
}

function closeAlbumModal() {
    document.getElementById('albumModal').classList.add('hidden');
    location.reload();
}

document.getElementById('productDesignPhotos').addEventListener('change', function() {
    const uploadBtn = document.getElementById('uploadProductPhotosBtn');
    uploadBtn.disabled = !this.files.length;
    uploadBtn.textContent = this.files.length ? `Upload ${this.files.length} Photo${this.files.length > 1 ? 's' : ''}` : 'Upload';
});

document.getElementById('albumModal').addEventListener('click', function(e) {
    if (e.target === this) closeAlbumModal();
});

document.getElementById('existingVariantSelect').addEventListener('change', syncVariantSelection);
document.getElementById('variantName').addEventListener('input', function() {
    document.getElementById('existingVariantSelect').value = '';
    syncVariantSelection();
});

function uploadProductPhotos() {
    const input = document.getElementById('productDesignPhotos');
    const btn = document.getElementById('uploadProductPhotosBtn');
    if (!input.files.length || !currentAlbumProductId) return;

    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'add_product_photos');
    fd.append('product_id', currentAlbumProductId);
    Array.from(input.files).forEach(file => fd.append('design_photos[]', file));

    btn.disabled = true;
    btn.textContent = 'Uploading...';
    fetch('products.php', { method: 'POST', body: fd })
        .then(async response => {
            const raw = await response.text();
            const start = raw.indexOf('{');
            const end = raw.lastIndexOf('}');
            if (start < 0 || end < start) {
                throw new Error(response.redirected ? 'Your admin session expired. Refresh the page and try again.' : 'Unexpected server response.');
            }
            return JSON.parse(raw.slice(start, end + 1));
        })
        .then(data => {
            if (data.success) {
                showToast('Photos Added', data.added + ' design photo' + (data.added > 1 ? 's' : '') + ' added to this product.', 'success');
                input.value = '';
                btn.textContent = 'Upload';
                loadVariants(currentAlbumProductId);
            } else {
                showToast('Upload Error', data.error || 'No valid photos were uploaded.', 'error');
                btn.textContent = 'Upload';
            }
            btn.disabled = true;
        })
        .catch(() => {
            showToast('Upload Error', 'Something went wrong while uploading the photos.', 'error');
            btn.disabled = false;
            btn.textContent = 'Upload';
        });
}

function loadVariants(productId) {
    const grid = document.getElementById('variantsGrid');
    const noMsg = document.getElementById('noVariants');
    grid.innerHTML = '<div class="col-span-full text-center py-6 text-gray-400 text-sm">Loading...</div>';

    fetch('products.php?get_variants=' + productId)
        .then(r => r.json())
        .then(variants => {
            if (String(currentAlbumProductId) !== String(productId)) return;
            existingAlbumVariants = Array.isArray(variants) ? variants : [];
            populateExistingVariantSelect();
            refreshVariantSizeAvailability();
            if (variants.length === 0) {
                grid.innerHTML = '';
                noMsg.style.display = 'block';
                return;
            }
            noMsg.style.display = 'none';
            const albumVariants = [...variants].sort((first, second) => {
                const nameOrder = (first.variant_name || '').localeCompare(second.variant_name || '', undefined, { numeric: true, sensitivity: 'base' });
                if (nameOrder !== 0) return nameOrder;
                const sizeOrder = getAlbumSizeRank(first.size) - getAlbumSizeRank(second.size);
                return sizeOrder || Number(first.variant_id) - Number(second.variant_id);
            });
            grid.innerHTML = albumVariants.map(v => `
                <div class="bg-gray-50 rounded-xl overflow-hidden border border-gray-100 hover:border-orange-200 transition-all group">
                    <div class="h-32 bg-gray-100 overflow-hidden">
                        ${v.image ? `<img src="../${escHtml(v.image)}" class="w-full h-full object-cover">` :
                        `<div class="h-full flex items-center justify-center"><svg class="w-8 h-8 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div>`}
                    </div>
                    <div class="p-3">
                        <h4 class="text-sm font-semibold text-gray-900 truncate">${escHtml(v.variant_name)}</h4>
                        ${v.size ? `<p class="text-xs text-gray-500 mt-0.5">Size: ${escHtml(v.size)}</p>` : ''}
                        <div class="grid grid-cols-2 gap-1.5 mt-2">
                            <input type="number" id="variant-edit-price-${v.variant_id}" value="${Number(v.price || 0)}" min="0" step="0.01" placeholder="Price" class="w-full px-2 py-1 border border-gray-200 rounded text-xs">
                            <input type="number" id="variant-edit-qty-${v.variant_id}" value="${Math.min(999, parseInt(v.quantity) || 0)}" min="0" max="999" oninput="limitQuantityInput(this)" placeholder="Stock" class="w-full px-2 py-1 border border-gray-200 rounded text-xs">
                        </div>
                        <div class="grid grid-cols-2 gap-1.5 mt-1.5" ${currentAlbumIsJersey ? '' : 'style="display:none;"'}>
                            <input type="number" id="variant-edit-upper-${v.variant_id}" value="${Number(v.upper_price || v.price || 0)}" min="0" step="0.01" placeholder="Upper" class="w-full px-2 py-1 border border-gray-200 rounded text-xs">
                            <input type="number" id="variant-edit-upper-lower-${v.variant_id}" value="${Number(v.upper_lower_price || v.price || 0)}" min="0" step="0.01" placeholder="Upper + Lower" class="w-full px-2 py-1 border border-gray-200 rounded text-xs">
                        </div>
                        <button type="button" onclick="saveVariant(${v.variant_id})" class="mt-2 w-full px-2 py-1 rounded text-xs font-semibold text-white" style="background:#ff6b35;">Save Stock / Price</button>
                        <button type="button" onclick="toggleVariantStatus(${v.variant_id}, ${v.is_active ? 0 : 1})" class="mt-1 w-full px-2 py-1 rounded text-xs font-semibold ${v.is_active ? 'text-red-600 bg-red-50' : 'text-green-600 bg-green-50'}">${v.is_active ? 'Deactivate Variant' : 'Activate Variant'}</button>
                        <div class="flex items-center justify-end mt-2">
                            <button onclick="deleteVariant(${v.variant_id})" class="text-xs text-red-400 hover:text-red-600 opacity-0 group-hover:opacity-100 transition-opacity" title="Delete variant">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');
        });
}

function getAlbumSizeRank(size) {
    const normalized = String(size || '').toLowerCase();
    const match = normalized.match(/(?:^|[^a-z0-9])(small|medium|large|5xl|4xl|3xl|2xl|xxl|xl|s|m|l)(?:$|[^a-z0-9])/);
    const rankBySize = { small: 0, s: 0, medium: 1, m: 1, large: 2, l: 2, xl: 3, '2xl': 4, xxl: 4, '3xl': 5, '4xl': 6, '5xl': 7 };
    return match ? (rankBySize[match[1]] ?? 99) : 99;
}

function addVariant() {
    const name = document.getElementById('variantName').value.trim();
    const sizes = getSelectedVariantSizes();
    if (currentAlbumIsSash) {
        const sashPrice = document.getElementById('sash-price')?.value || '0';
        const sashStock = document.getElementById('sash-stock')?.value || '0';
        if (!name) { alert('Enter a sash name'); return; }
        const sashForm = new FormData();
        sashForm.append('csrf_token', csrfToken);
        sashForm.append('action', 'add_variant');
        sashForm.append('product_id', currentAlbumProductId);
        sashForm.append('variant_name', name);
        sashForm.append('price', sashPrice);
        sashForm.append('size_options', JSON.stringify([{ size: '', price: sashPrice, quantity: sashStock }]));
        const imgInput = document.getElementById('variantImg');
        if (imgInput.files.length > 0) sashForm.append('variant_img', imgInput.files[0]);
        fetch('products.php', { method: 'POST', body: sashForm })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('variantName').value = '';
                    document.getElementById('sash-price').value = '';
                    document.getElementById('sash-stock').value = '';
                    imgInput.value = '';
                    loadVariants(currentAlbumProductId);
                    showToast('Sash Added', 'The sash price has been saved.', 'success');
                } else showToast('Error', data.error || 'Unable to add sash.', 'error');
            })
            .catch(() => showToast('Error', 'Something went wrong while adding the sash.', 'error'));
        return;
    }
    const sizeOptions = sizes.map(size => ({
        size,
        price: document.getElementById('variant-price-' + size).value || '0',
        upper_price: document.getElementById('variant-price-' + size).value || '0',
        upper_lower_price: currentAlbumIsJersey ? (document.getElementById('variant-upper-lower-price-' + size).value || '0') : (document.getElementById('variant-price-' + size).value || '0'),
        quantity: document.getElementById('variant-quantity-' + size).value || '0'
    }));
    const imgInput = document.getElementById('variantImg');
    const btn = document.getElementById('addVariantBtn');

    if (!name) { alert('Enter a variant name'); return; }
    if (!sizes.length) { alert('Select at least one size'); return; }

    btn.disabled = true;
    btn.textContent = 'Adding...';

    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'add_variant');
    fd.append('product_id', currentAlbumProductId);
    fd.append('variant_name', name);
    fd.append('sizes', JSON.stringify(sizes));
    fd.append('size_options', JSON.stringify(sizeOptions));
    fd.append('price', '0');
    if (imgInput.files.length > 0) {
        fd.append('variant_img', imgInput.files[0]);
    }

    fetch('products.php', { method: 'POST', body: fd })
        .then(response => response.text())
        .then(raw => {
            let data;
            try {
                data = JSON.parse(raw);
            } catch (error) {
                throw new Error(raw.slice(0, 300));
            }
            if (data.success) {
                document.getElementById('variantName').value = '';
                document.getElementById('existingVariantSelect').value = '';
                document.getElementById('variantName').readOnly = false;
                document.getElementById('variantSize').value = '[]';
                document.querySelectorAll('.variant-size-option').forEach(option => option.classList.remove('selected'));
                renderVariantSizeConfig([]);
                syncVariantSelection();
                imgInput.value = '';
                loadVariants(currentAlbumProductId);
                showToast('Variant Added', 'New variant has been added to the album.', 'success');
            } else {
                showToast('Error', data.error || 'Failed to add variant.', 'error');
            }
            btn.disabled = false;
            btn.textContent = '+ Add Variant';
        })
        .catch(error => {
            showToast('Error', error.message || 'Something went wrong.', 'error');
            btn.disabled = false;
            btn.textContent = '+ Add Variant';
        });
}

function saveVariant(variantId) {
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'update_variant');
    fd.append('variant_id', variantId);
    fd.append('price', document.getElementById('variant-edit-price-' + variantId).value || '0');
    fd.append('upper_price', document.getElementById('variant-edit-upper-' + variantId)?.value || document.getElementById('variant-edit-price-' + variantId).value || '0');
    fd.append('upper_lower_price', document.getElementById('variant-edit-upper-lower-' + variantId)?.value || document.getElementById('variant-edit-price-' + variantId).value || '0');
    const quantityInput = document.getElementById('variant-edit-qty-' + variantId);
    limitQuantityInput(quantityInput);
    fd.append('quantity', quantityInput.value || '0');
    fetch('products.php', { method: 'POST', body: fd })
        .then(async response => {
            const body = await response.text();
            let data;
            try { data = JSON.parse(body); }
            catch (error) { throw new Error(response.redirected ? 'Your admin session expired. Refresh the page and log in again.' : 'The server returned an invalid response. Check the PHP error log.'); }
            if (!response.ok || !data.success) throw new Error(data.error || data.message || 'Unable to update this variant.');
            return data;
        })
        .then(data => {
            showToast('Variant Updated', 'Stock and price have been saved.', 'success');
            loadVariants(currentAlbumProductId);
        })
        .catch(error => showToast('Error', error.message || 'Something went wrong while updating the variant.', 'error'));
}

function toggleVariantStatus(variantId, isActive) {
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'toggle_variant_status');
    fd.append('variant_id', variantId);
    fd.append('is_active', isActive);
    fetch('products.php', { method: 'POST', body: fd })
        .then(response => response.json())
        .then(data => {
            if (!data.success) throw new Error('Unable to update variant status.');
            loadVariants(currentAlbumProductId);
            showToast('Variant Updated', isActive ? 'Variant activated.' : 'Variant deactivated.', 'success');
        })
        .catch(error => showToast('Error', error.message, 'error'));
}

function toggleProductStatus(productId, isActive) {
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'toggle_product_status');
    fd.append('product_id', productId);
    fd.append('is_active', isActive);
    fetch('products.php', { method: 'POST', body: fd })
        .then(async response => {
            const body = await response.text();
            let data;
            try { data = JSON.parse(body); }
            catch (error) { throw new Error(response.redirected ? 'Your admin session expired. Please log in again.' : 'The server returned an invalid response. Check the PHP error log.'); }
            if (!response.ok || !data.success) throw new Error(data.error || 'Unable to update product status.');
            return data;
        })
        .then(data => {
            window.location.reload();
        })
        .catch(error => showToast('Error', error.message, 'error'));
}

function deleteVariant(variantId) {
    showConfirm({
        title: 'Delete Variant?',
        message: 'This variant will be permanently removed from the album.',
        type: 'danger',
        confirmText: 'Delete',
        onConfirm: () => { _doDeleteVariant(variantId); }
    });
}
function _doDeleteVariant(variantId) {
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'delete_variant');
    fd.append('variant_id', variantId);
    fetch('products.php', { method: 'POST', body: fd })
        .then(async response => {
            const body = await response.text();
            try {
                return JSON.parse(body);
            } catch (error) {
                throw new Error(response.redirected
                    ? 'Your admin session expired. Please log in again.'
                    : 'The server returned an invalid response. Check the PHP error log.');
            }
        })
        .then(data => {
            if (data.success) {
                loadVariants(currentAlbumProductId);
                showToast('Variant Updated', data.soft_deleted ? 'Variant was deactivated to preserve order history.' : 'Variant has been removed from the album.', 'success');
            } else {
                showToast('Error', 'Failed to delete variant.', 'error');
            }
        });
}

function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function adjQty(inputId, delta) {
    const el = document.getElementById(inputId);
    if (!el) return;
    el.value = Math.min(999, Math.max(0, (parseInt(el.value) || 0) + delta));
}

function limitQuantityInput(input) {
    if (!input) return;
    const value = parseInt(input.value, 10);
    input.value = Number.isFinite(value) ? Math.min(999, Math.max(0, value)) : 0;
}

function getSelectedVariantSizes() {
    try {
        return JSON.parse(document.getElementById('variantSize').value || '[]');
    } catch (error) {
        return [];
    }
}

function populateExistingVariantSelect() {
    const select = document.getElementById('existingVariantSelect');
    const selectedName = select.value;
    const names = [...new Set(existingAlbumVariants.map(variant => (variant.variant_name || '').trim()).filter(Boolean))];
    select.innerHTML = '<option value="">Create a new variant</option>';
    names.forEach(name => {
        const option = document.createElement('option');
        option.value = name;
        option.textContent = name;
        select.appendChild(option);
    });
    if (names.includes(selectedName)) select.value = selectedName;
    syncVariantSelection();
}

function syncVariantSelection() {
    const select = document.getElementById('existingVariantSelect');
    const nameInput = document.getElementById('variantName');
    const addButton = document.getElementById('addVariantBtn');
    if (select.value) {
        nameInput.value = select.value;
        nameInput.readOnly = true;
        addButton.textContent = '+ Add Sizes to Variant';
    } else {
        nameInput.readOnly = false;
        addButton.textContent = '+ Add Variant';
    }
    refreshVariantSizeAvailability();
}

function refreshVariantSizeAvailability() {
    const name = document.getElementById('variantName')?.value.trim().toLocaleLowerCase();
    const existingSizes = new Set(existingAlbumVariants
        .filter(variant => (variant.variant_name || '').trim().toLocaleLowerCase() === name && name)
        .map(variant => (variant.size || '').trim().toLocaleLowerCase()));
    const selectedSizes = getSelectedVariantSizes();
    const remainingSizes = [];
    document.querySelectorAll('#variantSizeOptions .variant-size-option').forEach(button => {
        const alreadyExists = existingSizes.has((button.dataset.size || '').trim().toLocaleLowerCase());
        button.disabled = alreadyExists;
        button.title = alreadyExists ? 'This size has already been created for this variant.' : '';
        if (alreadyExists) {
            button.classList.remove('selected');
        } else if (selectedSizes.includes(button.dataset.size)) {
            remainingSizes.push(button.dataset.size);
        }
    });
    if (remainingSizes.length !== selectedSizes.length) {
        document.getElementById('variantSize').value = JSON.stringify(remainingSizes);
        renderVariantSizeConfig(remainingSizes);
    }
}

function toggleVariantSize(size, button) {
    if (button.disabled) return;
    const sizes = getSelectedVariantSizes();
    const index = sizes.indexOf(size);
    if (index >= 0) {
        sizes.splice(index, 1);
        button.classList.remove('selected');
    } else {
        sizes.push(size);
        button.classList.add('selected');
    }
    document.getElementById('variantSize').value = JSON.stringify(sizes);
    renderVariantSizeConfig(sizes);
}

function renderVariantSizeConfig(sizes) {
    const config = document.getElementById('variantSizeConfig');
    if (!sizes.length) {
        config.innerHTML = '<p class="text-xs text-gray-400">Select sizes above to set each price and stock quantity.</p>';
        return;
    }
    config.innerHTML = sizes.map(size => `
        <div class="grid ${currentAlbumIsJersey ? 'grid-cols-4' : 'grid-cols-3'} gap-2 items-center">
            <div class="text-xs font-semibold text-gray-700">${size}</div>
            <input type="number" id="variant-price-${size}" step="0.01" min="0" placeholder="${currentAlbumIsJersey ? 'Upper Price' : 'Price'}" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-xs">
            ${currentAlbumIsJersey ? `<input type="number" id="variant-upper-lower-price-${size}" step="0.01" min="0" placeholder="Upper + Lower" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-xs">` : ''}
            <input type="number" id="variant-quantity-${size}" min="0" max="999" value="0" oninput="limitQuantityInput(this)" placeholder="Stock" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-xs">
        </div>`).join('');
}

function openEditModal(id, name, desc, unit, isActive, img, categoryId, price, quantity, workingDays, gender) {
    document.getElementById('editProductId').value = id;
    document.getElementById('editProductActive').value = isActive ? '1' : '0';
    document.getElementById('editProductCategory').value = categoryId || 0;
    document.getElementById('editProductName').value = name;
    document.getElementById('editProductDesc').value = desc;
    document.getElementById('editProductGender').value = gender || 'unisex';
    document.getElementById('editProductPrice').value = price > 0 ? price : '';
    document.getElementById('editProductQty').value = quantity || 0;
    document.getElementById('editProductWorkingDays').value = Math.max(1, Number(workingDays || 3));
    document.getElementById('editProductImg').value = '';
    document.getElementById('editRemoveImg').value = '0';

    const preview = document.getElementById('editImgPreview');
    const thumb = document.getElementById('editImgThumb');
    const removeBtn = document.getElementById('removeProductImgBtn');
    if (img) {
        thumb.src = '../' + img;
        preview.style.display = 'block';
        removeBtn.style.display = 'inline-block';
    } else {
        preview.style.display = 'none';
        removeBtn.style.display = 'none';
    }

    document.getElementById('editModal').classList.remove('hidden');
}

function removeProductImage() {
    document.getElementById('editRemoveImg').value = '1';
    document.getElementById('editImgPreview').style.display = 'none';
    document.getElementById('removeProductImgBtn').style.display = 'none';
    document.getElementById('editProductImg').value = '';
}

function openEditProductAlbum() {
    const productId = document.getElementById('editProductId').value;
    const productName = document.getElementById('editProductName').value.trim();
    const categorySelect = document.getElementById('editProductCategory');
    const categoryName = categorySelect?.options[categorySelect.selectedIndex]?.text || '';
    if (!productId) return;
    document.getElementById('editModal').classList.add('hidden');
    openAlbumModal(productId, productName, categoryName, document.getElementById('editProductGender').value);
}

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});

function saveProduct() {
    const btn = document.getElementById('saveProductBtn');
    const name = document.getElementById('editProductName').value.trim();
    if (!name) { alert('Product name is required'); return; }

    btn.disabled = true;
    btn.textContent = 'Saving...';

    const fd = new FormData();
    limitQuantityInput(document.getElementById('editProductQty'));
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'edit_product');
    fd.append('product_id', document.getElementById('editProductId').value);
    fd.append('category_id', document.getElementById('editProductCategory').value);
    fd.append('product_name', name);
    fd.append('description', document.getElementById('editProductDesc').value.trim());
    fd.append('gender', document.getElementById('editProductGender').value);
    fd.append('price', document.getElementById('editProductPrice').value || '0');
    fd.append('quantity', document.getElementById('editProductQty').value || '0');
    fd.append('working_days', document.getElementById('editProductWorkingDays').value || '3');
    fd.append('is_active', document.getElementById('editProductActive').value || '1');
    fd.append('remove_img', document.getElementById('editRemoveImg').value);

    const imgInput = document.getElementById('editProductImg');
    if (imgInput.files.length > 0) {
        fd.append('img', imgInput.files[0]);
    }

    fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editModal').classList.add('hidden');
                const updatedCount = Number(data.variant_prices_updated || 0);
                const message = updatedCount
                    ? `Product updated. ${updatedCount} variant size${updatedCount === 1 ? '' : 's'} updated to the new base price.`
                    : 'Product updated. Variant sizes with custom prices were left unchanged.';
                showToast('Product Updated', message, 'success');
                setTimeout(() => location.reload(), 600);
            } else {
                showToast('Error', data.error || 'Failed to update product.', 'error');
            }
            btn.disabled = false;
            btn.textContent = 'Save Changes';
        })
        .catch(error => {
            showToast('Error', error.message || 'Something went wrong.', 'error');
            btn.disabled = false;
            btn.textContent = 'Save Changes';
        });
}
</script>
</body>
</html>

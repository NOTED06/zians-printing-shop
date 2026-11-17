<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once __DIR__ . '/../security_headers.php';
require_once '../db_conn.php';
require_once 'check_customer.php';
require_once '../upload_helper.php';
require_once '../csrf_helper.php';
require_once '../admin_notification_helper.php';
require_once '../size_chart_helper.php';
require_once '../order_write_helper.php';

$customerId = $_SESSION['user_id'];
$customerName = $_SESSION['username'] ?? 'Customer';

$productsList = [];
$prodRes = $conn->query("SELECT product_id, product_name, img FROM products WHERE record_type='product' AND is_active = 1 ORDER BY product_name ASC");
if ($prodRes) { while ($r = $prodRes->fetch_assoc()) $productsList[] = $r; }

$inquireProduct = isset($_GET['inquire']) ? trim($_GET['inquire']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();
    header('Content-Type: application/json');

    if ($_POST['action'] === 'send_message') {
        $message = trim($_POST['message'] ?? '');
        
        
        $imagePaths = [];
        if (isset($_FILES['photos'])) {
            $fileCount = count($_FILES['photos']['name']);
            $allowed = ['jpg','jpeg','png','gif','webp'];
            for ($i = 0; $i < $fileCount; $i++) {
                if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK && is_valid_image_upload($_FILES['photos']['tmp_name'][$i], $_FILES['photos']['name'][$i])) {
                    $ext = strtolower(pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed)) {
                        $filename = 'msg_cust_' . $customerId . '_' . time() . '_' . mt_rand(1000,9999) . '_' . $i . '.' . $ext;
                        $dest = '../uploads/messages/' . $filename;
                        if (move_uploaded_file($_FILES['photos']['tmp_name'][$i], $dest)) {
                            $imagePaths[] = 'uploads/messages/' . $filename;
                        }
                    }
                }
            }
        }
        $imagePath = implode(',', $imagePaths);
        
        if (empty($message) && empty($imagePath)) { echo json_encode(['success' => false]); exit; }
        $stmt = $conn->prepare("INSERT INTO messages (customer_id, sender, message, image, sent_at) VALUES (?, 'customer', ?, ?, NOW())");
        $stmt->bind_param('iss', $customerId, $message, $imagePath);
        $stmt->execute();
        
        
        require_once '../notifications_helper.php';
        $custName = $_SESSION['username'] ?? 'Customer';
        $notifMsg = !empty($message) ? $message : '\xF0\x9F\x93\xB7 Sent a photo';
        $preview = mb_substr($notifMsg, 0, 60) . (mb_strlen($notifMsg) > 60 ? '...' : '');
        createNotification($conn, 'admin', 0, 'message', 'New Message', $custName . ': ' . $preview, 'messages.php');
        
        echo json_encode(['success' => true, 'image' => $imagePath]);
        exit;
    }

    if ($_POST['action'] === 'fetch_messages') {
        $msgs = [];
        $res = $conn->query("SELECT cm.* FROM messages cm WHERE cm.record_type = 'chat' AND cm.customer_id = $customerId ORDER BY cm.sent_at ASC");
        while ($r = $res->fetch_assoc()) $msgs[] = $r;
        echo json_encode($msgs);
        exit;
    }

    
    if ($_POST['action'] === 'pay_confirm') {
        $messageId = (int)($_POST['message_id'] ?? 0);
        $payType = $_POST['payment_type'] ?? '';
        if ($messageId > 0 && in_array($payType, ['down', 'full'])) {
            
            $chk = $conn->prepare("SELECT id FROM messages WHERE id = ? AND customer_id = ? AND record_type = 'chat' AND payment_status IS NOT NULL");
            $chk->bind_param('ii', $messageId, $customerId);
            $chk->execute();
            $chkRes = $chk->get_result();
            if ($chkRes->num_rows === 0) {
                $msgRow = $conn->prepare("SELECT message FROM messages WHERE id = ? AND customer_id = ?");
                $msgRow->bind_param('ii', $messageId, $customerId);
                $msgRow->execute();
                $msgData = $msgRow->get_result()->fetch_assoc();
                if ($msgData && strpos($msgData['message'], '__PAY__:') === 0) {
                    $pj = json_decode(substr($msgData['message'], 8), true);
                    if ($pj) {
                        $ins = $conn->prepare("UPDATE messages SET payment_product_name = ?, payment_description = ?, down_payment = ?, full_price = ?, gcash_link = ?, payment_status = 'pending' WHERE id = ? AND customer_id = ? AND record_type = 'chat'");
                        $bfName = $pj['name'] ?? '';
                        $bfDesc = $pj['desc'] ?? '';
                        $bfDown = (float)($pj['down'] ?? 0);
                        $bfFull = (float)($pj['full'] ?? 0);
                        $bfLink = $pj['link'] ?? '';
                        $ins->bind_param('ssddsii', $bfName, $bfDesc, $bfDown, $bfFull, $bfLink, $messageId, $customerId);
                        $ins->execute();
                    }
                }
            }

            
            $stmt = $conn->prepare("UPDATE messages SET payment_type = ?, payment_status = 'paid', paid_at = NOW() WHERE id = ? AND customer_id = ? AND record_type = 'chat'");
            $stmt->bind_param('sii', $payType, $messageId, $customerId);
            $stmt->execute();

            
            $mpStmt = $conn->prepare("SELECT payment_product_name AS product_name, payment_description AS description, down_payment, full_price FROM messages WHERE id = ? AND customer_id = ? AND record_type = 'chat'");
            $mpStmt->bind_param('ii', $messageId, $customerId);
            $mpStmt->execute();
            $mp = $mpStmt->get_result()->fetch_assoc();

            $label = $payType === 'down' ? 'Down Payment' : 'Full Payment';
            $amtPaid = $payType === 'down' ? (float)$mp['down_payment'] : (float)$mp['full_price'];
            $prodName = $mp['product_name'] ?? 'Custom Order';

            echo json_encode(['success' => true, 'label' => $label, 'amount' => number_format($amtPaid, 2), 'product' => $prodName]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    
    if ($_POST['action'] === 'pay_balance') {
        $messageId = (int)($_POST['message_id'] ?? 0);
        $orderId = (int)($_POST['order_id'] ?? 0);

        
        $msgCheck = $conn->prepare("SELECT id, message FROM messages WHERE id = ? AND customer_id = ?");
        $msgCheck->bind_param('ii', $messageId, $customerId);
        $msgCheck->execute();
        $msgRow = $msgCheck->get_result()->fetch_assoc();
        if (!$msgRow || strpos($msgRow['message'], '__BAL__:') !== 0) {
            echo json_encode(['success' => false]);
            exit;
        }

        $balJson = json_decode(substr($msgRow['message'], 8), true);
        $remaining = (float)($balJson['remaining'] ?? 0);
        $fullPrice = (float)($balJson['full'] ?? 0);
        $prodName = $balJson['name'] ?? 'Order';
        $batchId = trim((string)($balJson['batch_id'] ?? ''));

        if ($remaining <= 0 || !empty($balJson['balance_paid'])) {
            echo json_encode(['success' => false]);
            exit;
        }

        
        $conn->begin_transaction();
        if ($batchId !== '') {
            $updOrder = $conn->prepare('UPDATE orders SET down_payment_amount = price * quantity, updated_at = NOW() WHERE batch_id = ? AND customer_id = ?');
            $updOrder->bind_param('si', $batchId, $customerId);
        } else {
            $cardOrderId = (int)($balJson['order_id'] ?? $orderId);
            $updOrder = $conn->prepare('UPDATE orders SET down_payment_amount = price * quantity, updated_at = NOW() WHERE id = ? AND customer_id = ?');
            $updOrder->bind_param('ii', $cardOrderId, $customerId);
        }
        if (!$updOrder || !$updOrder->execute() || $updOrder->affected_rows < 1) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Could not update the order payment. Please contact the shop.']);
            exit;
        }

        $balJson['balance_paid'] = true;
        $balJson['remaining'] = 0;
        $newBalMsg = '__BAL__:' . json_encode($balJson);
        $updMsg = $conn->prepare("UPDATE messages SET message = ? WHERE id = ? AND customer_id = ?");
        $updMsg->bind_param('sii', $newBalMsg, $messageId, $customerId);
        if (!$updMsg->execute()) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Payment was not recorded. Please try again.']);
            exit;
        }
        $conn->commit();

        require_once '../notifications_helper.php';
        $custName = $_SESSION['username'] ?? 'Customer';
        $fmtAmt = number_format($remaining, 2);

        
        createNotification($conn, 'admin', 0, 'payment', '💰 Balance Paid', $custName . ' paid remaining balance ₱' . $fmtAmt . ' via GCash for ' . $prodName . '. Fully paid!', 'messages.php?customer_id=' . $customerId);

        echo json_encode(['success' => true, 'amount' => $fmtAmt, 'product' => $prodName]);
        exit;
    }

    
    if ($_POST['action'] === 'create_custom_order') {
        $messageId = (int)($_POST['message_id'] ?? 0);
        $orderNotes = trim($_POST['order_notes'] ?? '');
        $selectedSize = trim($_POST['selected_size'] ?? '');
        $skipCustomization = ($_POST['skip_customization'] ?? '') === '1';
        $measurements = [
            'body_height' => trim($_POST['body_height'] ?? ''),
            'body_weight' => trim($_POST['body_weight'] ?? ''),
            'shoulder' => trim($_POST['shoulder'] ?? ''),
            'top_length' => trim($_POST['top_length'] ?? ''),
            'waist' => trim($_POST['waist'] ?? ''),
        ];
        $payType = $_POST['payment_type'] ?? '';

        $measurementLimits = [
            'body_height' => [100, 250],
            'body_weight' => [20, 300],
            'shoulder' => [20, 100],
            'top_length' => [30, 150],
            'waist' => [40, 200],
        ];
        if (!$skipCustomization && !getStaticProductSizeChartRow($selectedSize)) {
            echo json_encode(['success' => false, 'error' => 'Please select a valid size.']);
            exit;
        }
        foreach ($measurementLimits as $field => [$minimum, $maximum]) {
            if ($skipCustomization) break;
            if (!is_numeric($measurements[$field]) || (float)$measurements[$field] < $minimum || (float)$measurements[$field] > $maximum) {
                echo json_encode(['success' => false, 'error' => 'Please enter valid values for all body measurements.']);
                exit;
            }
            $measurements[$field] = number_format((float)$measurements[$field], 1, '.', '');
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

        
        $mpStmt = $conn->prepare("SELECT payment_product_name AS product_name, payment_description AS description, down_payment, full_price, custom_order_id FROM messages WHERE id = ? AND customer_id = ? AND record_type = 'chat'");
        $mpStmt->bind_param('ii', $messageId, $customerId);
        $mpStmt->execute();
        $mp = $mpStmt->get_result()->fetch_assoc();

        if (!$mp) { echo json_encode(['success' => false]); exit; }
        if (!empty($mp['custom_order_id'])) {
            echo json_encode(['success' => false, 'error' => 'Customization details have already been submitted for this payment.']);
            exit;
        }

        require_once '../notifications_helper.php';
        $custName = $_SESSION['username'] ?? 'Customer';
        $label = $payType === 'down' ? 'Down Payment' : 'Full Payment';
        $amtPaid = $payType === 'down' ? (float)$mp['down_payment'] : (float)$mp['full_price'];
        $fullPrice = (float)$mp['full_price'];
        $prodName = $mp['product_name'] ?? 'Custom Order';
        $desc = $mp['description'] ?? '';

        
        $batchId = bin2hex(random_bytes(8));
        $paymentMode = 'GCash';
        $downAmt = $amtPaid;
        $sizingNote = sprintf(
            "Size: %s | Measurements: Height %s cm, Weight %s kg, Shoulder %s cm, Top length %s cm, Waist %s cm",
            $selectedSize,
            $measurements['body_height'],
            $measurements['body_weight'],
            $measurements['shoulder'],
            $measurements['top_length'],
            $measurements['waist']
        );
        $notesParts = $skipCustomization ? [] : [$sizingNote];
        if ($orderNotes !== '') $notesParts[] = 'Customization notes: ' . $orderNotes;
        if ($desc !== '' && $orderNotes === '') $notesParts[] = $desc;
        $notes = implode("\n", $notesParts);
        try {
            $customOrderHeaderId = createOrderHeader($conn, [
                'customer_id' => $customerId, 'payment_mode' => $paymentMode,
                'estimated_finish_date' => $estimatedFinishDate ?? null,
                'status' => 'Processing', 'batch_id' => $batchId,
            ]);
            $customOrderId = createOrderItem($conn, $customOrderHeaderId, [
                'product_id' => 0, 'product_name' => $prodName, 'size' => $selectedSize,
                'quantity' => 1, 'price' => $fullPrice, 'image' => $imagePath,
                'order_notes' => $notes, 'paid_amount' => $downAmt,
            ]);
        } catch (Throwable $error) {
            echo json_encode(['success' => false, 'error' => 'Unable to save customization details. Please try again.']);
            exit;
        }
        $linkStmt = $conn->prepare("UPDATE messages SET custom_order_id = ? WHERE id = ? AND customer_id = ? AND record_type = 'chat'");
        $linkStmt->bind_param('iii', $customOrderId, $messageId, $customerId);
        $linkStmt->execute();

        $fmtAmt = number_format($amtPaid, 2);

        
        createNotification($conn, 'admin', 0, 'payment', '💰 Payment Received', $custName . ' paid ' . $label . ' ₱' . $fmtAmt . ' via GCash for ' . $prodName, 'messages.php?customer_id=' . $customerId);
        createNotification($conn, 'admin', 0, 'order', '📦 New Order', $custName . ' — custom order for ' . $prodName . ' (GCash ' . $label . ')', 'orders.php');
        notifyAdminsOfNewOrder($conn, $custName . ' placed a custom order for ' . $prodName . '.');
        createNotification($conn, 'customer', $customerId, 'order', 'Order Placed', 'Your customized order for ' . $prodName . ' has been placed successfully.', 'customer_orders.php');

        echo json_encode(['success' => true, 'label' => $label, 'amount' => $fmtAmt, 'product' => $prodName]);
        exit;
    }

    
    if ($_POST['action'] === 'get_product_album') {
        $pid = (int)$_POST['product_id'];
            $product = $conn->query("SELECT product_id, product_name, img FROM products WHERE record_type = 'product' AND product_id = $pid")->fetch_assoc();
        $variants = [];
        $vRes = $conn->query("SELECT variant_id, variant_name, size, price, image FROM products WHERE record_type = 'variant' AND product_id = $pid AND is_active = 1 ORDER BY variant_id ASC");
        if ($vRes) { while ($v = $vRes->fetch_assoc()) $variants[] = $v; }
        echo json_encode(['product' => $product, 'variants' => $variants]);
        exit;
    }

    
    if ($_POST['action'] === 'send_album_photo') {
        $imgPath = trim($_POST['image_path'] ?? '');
        $msg = trim($_POST['message'] ?? '');
        if (!empty($imgPath) && (strpos($imgPath, 'uploads/') === 0 || preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $imgPath))) {
            $stmt = $conn->prepare("INSERT INTO messages (customer_id, sender, message, image, sent_at) VALUES (?, 'customer', ?, ?, NOW())");
            $stmt->bind_param('iss', $customerId, $msg, $imgPath);
            $stmt->execute();
            require_once '../notifications_helper.php';
            $custName = $_SESSION['username'] ?? 'Customer';
            $notifMsg = !empty($msg) ? $msg : '\xF0\x9F\x93\xB7 Sent a photo';
            $preview = mb_substr($notifMsg, 0, 60) . (mb_strlen($notifMsg) > 60 ? '...' : '');
            createNotification($conn, 'admin', 0, 'message', 'New Message', $custName . ': ' . $preview, 'messages.php');
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Zian's PrintShop</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/customer.css?v=<?= time() ?>">
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main-content" style="display:flex; flex-direction:column; height:calc(100vh - 100px); margin-top:0; padding:1.5rem 1.5rem 0; box-sizing:border-box; max-width:1200px; margin-left:auto; margin-right:auto; width:100%;">
    <div class="page-header" style="flex-shrink:0; display:block !important;">
        <h1>Messages</h1>
        <p>Chat with Zian's PrintShop about your orders or inquiries</p>
    </div>

    <div class="chat-box" style="flex:1; display:flex; flex-direction:column; min-height:0;">
        <div class="messages-area" id="messagesArea" style="flex:1; overflow-y:auto;">
            <div class="no-messages" id="noMessages">No messages yet. Start a conversation!</div>
        </div>
        <div style="position:relative;">
            <div id="mentionDropdown" style="display:none; position:absolute; bottom:100%; left:1rem; right:1rem; max-height:200px; overflow-y:auto; background:#fff; border:1px solid hsl(214 32% 91%); border-radius:10px; margin-bottom:4px; z-index:10;"></div>
            
            <div id="photoPreviewStrip" style="display:none; padding:0.4rem 1rem 0;"></div>
            <div class="chat-input-area">
                <input type="file" id="photoInput" accept="image/*" multiple style="display:none;" onchange="previewMsgPhoto(this)">
                <button type="button" onclick="document.getElementById('photoInput').click()" style="background:none; border:1px solid hsl(214 32% 88%); border-radius:10px; width:40px; height:40px; display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0; transition:all 0.2s;" onmouseover="this.style.borderColor='#ff6b35'; this.style.background='rgba(255,107,53,0.1)'" onmouseout="this.style.borderColor='hsl(214 32% 88%)'; this.style.background='none'" title="Attach photo">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                </button>
                <input type="text" id="msgInput" placeholder="Type @ to mention a product..." autocomplete="off">
                <button class="btn-send" onclick="sendMessage()">Send</button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = <?= json_encode(csrf_token()) ?>;

const area = document.getElementById('messagesArea');
const input = document.getElementById('msgInput');
const dropdown = document.getElementById('mentionDropdown');
let isSending = false;
let pendingPhotos = [];

const products = <?= json_encode($productsList) ?>;

window._payCards = window._payCards || [];

function renderPaymentCard(data) {
    const idx = window._payCards.length;
    window._payCards.push(data);
    const name = data.name || '';
    const desc = data.desc || '';
    const down = parseFloat(data.down || 0).toFixed(2);
    const full = parseFloat(data.full || 0).toFixed(2);
    const link = data.link || '';
    const pStatus = data.payment_status || null;
    const pType = data.payment_type || null;
    const isPaid = pStatus === 'paid';
    const customizationPending = isPaid && !data.custom_order_id;
    const paidLabel = pType === 'full' ? 'Full Payment' : 'Down Payment';

    let btnHtml = '';
    if (isPaid) {
        btnHtml = `<div style="display:flex;align-items:center;justify-content:center;gap:6px;padding:10px;background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:10px;color:#fff;font-weight:700;font-size:0.78rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20,6 9,17 4,12"/></svg>
            ${paidLabel} Sent
        </div>`;
        if (customizationPending) {
            btnHtml += `<button onclick="openCustomizeOrderFromCard(${idx})" style="display:block;width:100%;margin-top:8px;padding:10px;background:#fff7ed;color:#c2410c;font-weight:800;font-size:0.75rem;border:1px solid #fdba74;border-radius:10px;cursor:pointer;font-family:inherit;">Complete customization</button>`;
        }
    } else if (link) {
        btnHtml = `<button onclick="openPayConfirm(${idx})" style="display:block;width:100%;padding:9px;background:linear-gradient(135deg,#007DFE,#0056b3);color:#fff;font-weight:700;font-size:0.78rem;border-radius:10px;text-align:center;border:none;cursor:pointer;font-family:inherit;">Pay via GCash →</button>`;
    }

    return `<div style="max-width:320px;">
        <div style="border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.13);">
            <div style="background:linear-gradient(135deg,#001a4d,#002b80);padding:12px 14px;display:flex;align-items:center;gap:10px;">
                <img src="../download.jpg" alt="GCash" style="height:22px;width:auto;filter:brightness(0) invert(1);">
                <span style="color:#fff;font-weight:700;font-size:0.8rem;">Payment Request</span>
                ${isPaid ? '<span style="margin-left:auto;background:#22c55e;color:#fff;font-size:0.6rem;font-weight:700;padding:2px 8px;border-radius:20px;">PAID</span>' : ''}
            </div>
            <div style="background:#fff;padding:14px;">
                <div style="font-weight:700;font-size:0.9rem;color:#111;margin-bottom:2px;">${name}</div>
                ${desc ? `<div style="color:#6b7280;font-size:0.76rem;margin-bottom:10px;">${desc}</div>` : ''}
                <div style="display:flex;gap:8px;margin-bottom:${btnHtml ? '12px' : '0'};">
                    <div style="flex:1;background:${isPaid && pType === 'down' ? 'linear-gradient(135deg,#d1fae5,#a7f3d0)' : '#f0f7ff'};border-radius:10px;padding:10px;text-align:center;">
                        <div style="display:flex;align-items:center;justify-content:center;gap:4px;margin-bottom:4px;">
                            <img src="../download.jpg" style="height:12px;width:auto;vertical-align:middle;">
                            <span style="font-size:0.6rem;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Down Payment</span>
                        </div>
                        <div style="font-size:1rem;font-weight:800;color:${isPaid && pType === 'down' ? '#22c55e' : '#007DFE'};">₱${down}</div>
                    </div>
                    <div style="flex:1;background:${isPaid && pType === 'full' ? 'linear-gradient(135deg,#d1fae5,#a7f3d0)' : '#fff7ed'};border-radius:10px;padding:10px;text-align:center;">
                        <div style="font-size:0.6rem;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Full Price</div>
                        <div style="font-size:1rem;font-weight:800;color:${isPaid && pType === 'full' ? '#22c55e' : '#ff6b35'};">₱${full}</div>
                    </div>
                </div>
                ${btnHtml}
            </div>
        </div>
    </div>`;
}

window._balCards = [];

function renderBalanceCard(data) {
    const idx = window._balCards.length;
    window._balCards.push(data);
    const name = data.name || 'Order';
    const full = parseFloat(data.full || 0).toFixed(2);
    const downPaid = parseFloat(data.down_paid || 0).toFixed(2);
    const remaining = parseFloat(data.remaining || 0).toFixed(2);
    const isPaid = data.balance_paid === true;

    let headerBadge = isPaid
        ? '<span style="margin-left:auto;background:#22c55e;color:#fff;font-size:0.58rem;font-weight:800;padding:2px 8px;border-radius:20px;">FULLY PAID</span>'
        : '<span style="margin-left:auto;background:#fbbf24;color:#78350f;font-size:0.58rem;font-weight:800;padding:2px 8px;border-radius:20px;">READY FOR PICKUP</span>';

    let balanceTileBg = isPaid ? 'linear-gradient(135deg,#d1fae5,#a7f3d0)' : 'linear-gradient(135deg,#fef3c7,#fde68a)';
    let balanceColor = isPaid ? '#22c55e' : '#d97706';
    let balanceLabel = isPaid ? 'Balance Paid' : 'Remaining Balance';
    let balanceLabelColor = isPaid ? '#047857' : '#92400e';

    let btnsHtml = '';
    if (isPaid) {
        btnsHtml = `<div style="display:flex;align-items:center;justify-content:center;gap:6px;padding:10px;background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:10px;color:#fff;font-weight:700;font-size:0.78rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20,6 9,17 4,12"/></svg>
            Remaining Balance Paid via GCash
        </div>`;
    } else {
        btnsHtml = `<div style="display:flex;">
                    <button onclick="openBalancePayment(${idx})" style="width:100%;padding:10px;background:linear-gradient(135deg,#007DFE,#0056b3);border-radius:10px;text-align:center;border:none;cursor:pointer;font-family:inherit;">
                        <img src="../download.jpg" alt="GCash" style="height:16px;width:auto;filter:brightness(0) invert(1);margin-bottom:3px;display:block;margin-left:auto;margin-right:auto;">
                        <div style="color:#fff;font-weight:700;font-size:0.65rem;">Pay Remaining Balance</div>
                    </button>
                </div>`;
    }

    return `<div style="max-width:320px;">
        <div style="border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.13);">
            <div style="background:linear-gradient(135deg,#7c3aed,#5b21b6);padding:12px 14px;display:flex;align-items:center;gap:10px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                <span style="color:#fff;font-weight:700;font-size:0.8rem;">Remaining Balance</span>
                ${headerBadge}
            </div>
            <div style="background:#fff;padding:14px;">
                <div style="font-weight:700;font-size:0.9rem;color:#111;margin-bottom:2px;">📦 ${name}</div>
                <div style="color:#6b7280;font-size:0.72rem;margin-bottom:10px;">${isPaid ? 'Balance has been fully settled. Thank you!' : 'Your order is ready! Please settle the remaining balance.'}</div>
                <div style="display:flex;gap:8px;margin-bottom:12px;">
                    <div style="flex:1;background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-radius:10px;padding:10px;text-align:center;">
                        <div style="font-size:0.58rem;color:#047857;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Down Payment Paid</div>
                        <div style="font-size:0.95rem;font-weight:800;color:#22c55e;">₱${downPaid}</div>
                    </div>
                    <div style="flex:1;background:${balanceTileBg};border-radius:10px;padding:10px;text-align:center;">
                        <div style="font-size:0.58rem;color:${balanceLabelColor};font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">${balanceLabel}</div>
                        <div style="font-size:0.95rem;font-weight:800;color:${balanceColor};">₱${remaining}</div>
                    </div>
                </div>
                <div style="font-size:0.68rem;color:#6b7280;font-weight:600;text-align:center;margin-bottom:10px;">Total: ₱${full}</div>
                ${btnsHtml}
            </div>
        </div>
    </div>`;
}

function openPayConfirm(idx) {
    const d = window._payCards[idx];
    if (!d) return;
    if (d.payment_status === 'paid') return;
    const name = d.name || '';
    const desc = d.desc || '';
    const down = parseFloat(d.down || 0).toFixed(2);
    const full = parseFloat(d.full || 0).toFixed(2);
    const link = d.link || '';
    const msgId = d.message_id || 0;
    document.getElementById('pcName').textContent = name;
    document.getElementById('pcDesc').textContent = desc;
    document.getElementById('pcDesc').style.display = desc ? 'block' : 'none';
    document.getElementById('pcDown').textContent = '₱' + down;
    document.getElementById('pcFull').textContent = '₱' + full;
    
    window._currentPayConfirm = { idx, link, msgId };
    document.getElementById('payConfirmModal').style.display = 'flex';
}

function confirmPayment(type) {
    const c = window._currentPayConfirm;
    if (!c) return;
    const d = window._payCards[c.idx];
    const amt = type === 'down' ? parseFloat(d.down || 0) : parseFloat(d.full || 0);
    const label = type === 'down' ? 'Down Payment' : 'Full Price';
    const name = d.name || 'Order';
    
    window._currentPayConfirm.chosenType = type;
    window._currentPayConfirm.chosenAmt = amt;
    
    document.getElementById('payConfirmModal').style.display = 'none';
    document.getElementById('gcashProcessName').textContent = name;
    document.getElementById('gcashProcessLabel').textContent = type === 'down' ? 'Down Payment' : 'Full Payment';
    document.getElementById('gcashProcessAmt').textContent = '₱' + amt.toFixed(2);
    
    document.getElementById('gcashProcessDone').disabled = true;
    document.getElementById('gcashProcessDone').style.opacity = '0.5';
    document.getElementById('gcashProcessStatus').textContent = 'Processing payment...';
    document.getElementById('gcashProcessCheck').style.display = 'none';
    document.getElementById('gcashProcessSpinner').style.display = 'block';
    document.getElementById('gcashProcessModal').style.display = 'flex';
    
    setTimeout(() => {
        document.getElementById('gcashProcessSpinner').style.display = 'none';
        document.getElementById('gcashProcessCheck').style.display = 'flex';
        document.getElementById('gcashProcessStatus').textContent = 'Payment successful!';
        document.getElementById('gcashProcessStatus').style.color = '#22c55e';
        document.getElementById('gcashProcessDone').disabled = false;
        document.getElementById('gcashProcessDone').style.opacity = '1';
    }, 2000);
}

function finishPayment() {
    const c = window._currentPayConfirm;
    if (!c) return;
    const btn = document.getElementById('gcashProcessDone');
    btn.disabled = true;
    btn.textContent = 'Sending...';
    
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'pay_confirm');
    fd.append('message_id', c.msgId);
    fd.append('payment_type', c.chosenType);
    fetch('customer_messages.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window._payCards[c.idx].payment_status = 'paid';
                window._payCards[c.idx].payment_type = c.chosenType;
                
                document.getElementById('gcashProcessStatus').textContent = '✅ Payment successful!';
                document.getElementById('gcashProcessStatus').style.color = '#22c55e';
                btn.textContent = 'Done ✓';
                setTimeout(() => {
                    document.getElementById('gcashProcessModal').style.display = 'none';
                    openCustomizeOrder(data.product, data.label, data.amount, c.msgId, c.chosenType);
                }, 800);
            }
        })
        .catch(() => { btn.textContent = 'Done ✓'; btn.disabled = false; });
}

window._customRefPhotos = [];
window._customDrafts = {};

function openCustomizeOrderFromCard(idx) {
    const data = window._payCards[idx];
    if (!data) return;
    const amount = data.payment_type === 'down' ? data.down : data.full;
    const label = data.payment_type === 'full' ? 'Full Payment' : 'Down Payment';
    openCustomizeOrder(data.name || 'Custom Order', label, parseFloat(amount || 0).toFixed(2), data.message_id, data.payment_type);
}

function openCustomizeOrder(product, label, amount, messageId, payType) {
    const draft = window._customDrafts[messageId] || {};
    document.getElementById('custOrdProduct').textContent = product;
    document.getElementById('custOrdPayLabel').textContent = label + ' — ₱' + amount;
    document.getElementById('custOrdNotes').value = draft.notes || '';
    window._customRefPhotos = draft.photos || [];
    document.getElementById('custOrdPhotoPreview').innerHTML = '';
    document.getElementById('custOrdPhotoInput').value = '';
    document.getElementById('custOrdSize').value = draft.size || '';
    ['custOrdHeight', 'custOrdWeight', 'custOrdShoulder', 'custOrdTopLength', 'custOrdWaist'].forEach(id => {
        document.getElementById(id).value = draft[id] || '';
    });
    document.getElementById('custOrdSizeGuide').style.display = draft.guideOpen ? 'block' : 'none';
    renderCustomPhotoPreview();
    document.getElementById('custOrdSubmitBtn').disabled = false;
    document.getElementById('custOrdSubmitBtn').innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20,6 9,17 4,12"/></svg> Place Order';
    window._customOrderData = { messageId, payType, product, label, amount };
    document.getElementById('customizeOrderModal').style.display = 'flex';
}

function saveCustomDraft() {
    const data = window._customOrderData;
    if (!data) return;
    window._customDrafts[data.messageId] = {
        notes: document.getElementById('custOrdNotes').value,
        size: document.getElementById('custOrdSize').value,
        custOrdHeight: document.getElementById('custOrdHeight').value,
        custOrdWeight: document.getElementById('custOrdWeight').value,
        custOrdShoulder: document.getElementById('custOrdShoulder').value,
        custOrdTopLength: document.getElementById('custOrdTopLength').value,
        custOrdWaist: document.getElementById('custOrdWaist').value,
        guideOpen: document.getElementById('custOrdSizeGuide').style.display !== 'none',
        photos: window._customRefPhotos.slice()
    };
}

function closeCustomizeOrder() {
    saveCustomDraft();
    document.getElementById('customizeOrderModal').style.display = 'none';
}

function toggleCustomSizeGuide() {
    const guide = document.getElementById('custOrdSizeGuide');
    guide.style.display = guide.style.display === 'none' ? 'block' : 'none';
    saveCustomDraft();
}

function handleCustomPhotos(input) {
    const files = Array.from(input.files);
    const allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    files.forEach(f => {
        if (allowed.includes(f.type) && window._customRefPhotos.length < 5) {
            window._customRefPhotos.push(f);
        }
    });
    input.value = '';
    renderCustomPhotoPreview();
}

function renderCustomPhotoPreview() {
    const container = document.getElementById('custOrdPhotoPreview');
    container.innerHTML = '';
    window._customRefPhotos.forEach((f, i) => {
        const url = URL.createObjectURL(f);
        const wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;display:inline-block;margin:0 6px 6px 0;';
        wrap.innerHTML = '<img src="' + url + '" style="width:64px;height:64px;border-radius:10px;object-fit:cover;border:2px solid #e5e7eb;">' +
            '<button onclick="removeCustomPhoto(' + i + ')" style="position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;background:#ef4444;color:#fff;border:none;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;">&times;</button>';
        container.appendChild(wrap);
    });
}

function removeCustomPhoto(idx) {
    window._customRefPhotos.splice(idx, 1);
    renderCustomPhotoPreview();
}

function submitCustomOrder(skipCustomization = false) {
    const data = window._customOrderData;
    if (!data) return;
    const btn = document.getElementById('custOrdSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<div style="width:16px;height:16px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin 0.6s linear infinite;"></div> Placing Order...';

    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'create_custom_order');
    fd.append('message_id', data.messageId);
    fd.append('payment_type', data.payType);
    fd.append('order_notes', document.getElementById('custOrdNotes').value.trim());
    fd.append('skip_customization', skipCustomization ? '1' : '0');
    if (!skipCustomization) {
        fd.append('selected_size', document.getElementById('custOrdSize').value);
        fd.append('body_height', document.getElementById('custOrdHeight').value);
        fd.append('body_weight', document.getElementById('custOrdWeight').value);
        fd.append('shoulder', document.getElementById('custOrdShoulder').value);
        fd.append('top_length', document.getElementById('custOrdTopLength').value);
        fd.append('waist', document.getElementById('custOrdWaist').value);
    }
    window._customRefPhotos.forEach(f => fd.append('ref_photos[]', f));

    fetch('customer_messages.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                delete window._customDrafts[data.messageId];
                btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20,6 9,17 4,12"/></svg> Order Placed!';
                btn.style.background = 'linear-gradient(135deg,#22c55e,#16a34a)';
                showToast('Order Placed', res.product + ' — ' + res.label + ' ₱' + res.amount, 'success');
                setTimeout(() => {
                    window._customOrderData = null;
                    closeCustomizeOrder();
                    fetchMessages();
                }, 1000);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20,6 9,17 4,12"/></svg> Place Order';
                showToast('Error', res.error || 'Failed to place order.', 'error');
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20,6 9,17 4,12"/></svg> Place Order';
            showToast('Error', 'Connection error.', 'error');
        });
}

function closePayConfirm() {
    document.getElementById('payConfirmModal').style.display = 'none';
}

function openBalancePayment(idx) {
    const d = window._balCards[idx];
    if (!d || d.balance_paid) return;
    const name = d.name || 'Order';
    const remaining = parseFloat(d.remaining || 0);
    window._currentBalPayment = { idx, msgId: d.message_id, orderId: d.order_id, name, remaining };
    
    document.getElementById('gcashProcessName').textContent = name;
    document.getElementById('gcashProcessLabel').textContent = 'Remaining Balance';
    document.getElementById('gcashProcessAmt').textContent = '₱' + remaining.toFixed(2);
    document.getElementById('gcashProcessDone').disabled = true;
    document.getElementById('gcashProcessDone').style.opacity = '0.5';
    document.getElementById('gcashProcessStatus').textContent = 'Processing payment...';
    document.getElementById('gcashProcessStatus').style.color = '#6b7280';
    document.getElementById('gcashProcessCheck').style.display = 'none';
    document.getElementById('gcashProcessSpinner').style.display = 'block';
    
    document.getElementById('gcashProcessDone').onclick = finishBalancePayment;
    document.getElementById('gcashProcessModal').style.display = 'flex';
    setTimeout(() => {
        document.getElementById('gcashProcessSpinner').style.display = 'none';
        document.getElementById('gcashProcessCheck').style.display = 'flex';
        document.getElementById('gcashProcessStatus').textContent = 'Payment successful!';
        document.getElementById('gcashProcessStatus').style.color = '#22c55e';
        document.getElementById('gcashProcessDone').disabled = false;
        document.getElementById('gcashProcessDone').style.opacity = '1';
    }, 2000);
}

function finishBalancePayment() {
    const c = window._currentBalPayment;
    if (!c) return;
    const btn = document.getElementById('gcashProcessDone');
    btn.disabled = true;
    btn.textContent = 'Sending...';
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'pay_balance');
    fd.append('message_id', c.msgId);
    fd.append('order_id', c.orderId);
    fetch('customer_messages.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window._balCards[c.idx].balance_paid = true;
                document.getElementById('gcashProcessStatus').textContent = '✅ Balance ₱' + data.amount + ' paid for ' + data.product;
                document.getElementById('gcashProcessStatus').style.color = '#22c55e';
                btn.textContent = 'Done ✓';
                setTimeout(() => {
                    document.getElementById('gcashProcessModal').style.display = 'none';
                    
                    btn.onclick = finishPayment;
                    btn.textContent = 'Done ✓';
                    fetchMessages();
                }, 1200);
            } else {
                btn.textContent = 'Done ✓';
                btn.disabled = false;
            }
        })
        .catch(() => { btn.textContent = 'Done ✓'; btn.disabled = false; });
}

function formatMentions(text) {
    return text.replace(/@([\w\s&.'-]+?)(?=\s@|\s*$|[.!?,])/g, function(match, name) {
        const prod = products.find(p => p.product_name.toLowerCase() === name.trim().toLowerCase());
        let imgTag = '';
        if (prod && prod.img) {
            imgTag = '<img src="../' + prod.img + '" style="width:18px;height:18px;border-radius:4px;object-fit:cover;vertical-align:middle;margin-right:3px;">';
        }
        return '<span class="mention-tag" style="background:rgba(255,107,53,0.2); color:#ff8c5a; padding:1px 6px; border-radius:4px; font-weight:600; font-size:0.85em; cursor:pointer; display:inline-flex; align-items:center; gap:2px;" onclick="renderProductCard(\'' + name.replace(/'/g, "\\'") + '\')" title="Click to view album">' + imgTag + '@' + name + '</span>';
    });
}

function renderProductCard(productName) {
    const prod = products.find(p => p.product_name.toLowerCase() === productName.trim().toLowerCase());
    if (!prod) return;
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'get_product_album');
    fd.append('product_id', prod.product_id);
    fetch('customer_messages.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.product) return;
            const overlay = document.createElement('div');
            overlay.id = 'albumOverlay';
            overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:1100;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(3px);';
            overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };
            let h = '<div style="background:#fff;border:1px solid hsl(214 32% 91%);border-radius:16px;padding:1.5rem;width:90%;max-width:550px;max-height:80vh;overflow-y:auto;position:relative;">';
            h += '<button onclick="this.closest(\'div[style*=fixed]\').remove()" style="position:absolute;top:0.8rem;right:1rem;background:none;border:none;font-size:1.3rem;color:hsl(215 16% 47%);cursor:pointer;">&times;</button>';
            h += '<div style="display:flex;align-items:center;gap:0.8rem;margin-bottom:0.5rem;">';
            if (data.product.img) h += '<img src="../' + data.product.img + '" style="width:50px;height:50px;border-radius:10px;object-fit:cover;">';
            h += '<div><h3 style="font-size:1.1rem;font-weight:700;color:#fff;">' + data.product.product_name + '</h3>';
            h += '<span style="font-size:0.75rem;color:hsl(215 16% 47%);">' + data.variants.length + ' variant' + (data.variants.length !== 1 ? 's' : '') + ' &mdash; Click a photo to add to chat</span></div></div>';
            if (data.variants.length > 0) {
                h += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:0.8rem;margin-top:0.8rem;">';
                data.variants.forEach(v => {
                    const safeImg = (v.image || '').replace(/'/g, "\\'");
                    const safeName = (v.variant_name || '').replace(/'/g, "\\'");
                    h += '<div style="background:hsl(210 20% 97%);border:1px solid hsl(214 32% 91%);border-radius:12px;overflow:hidden;cursor:pointer;transition:all 0.2s;" onmouseover="this.style.borderColor=\'#ff6b35\';this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 4px 12px rgba(255,107,53,0.2)\'" onmouseout="this.style.borderColor=\'hsl(214 32% 91%)\';this.style.transform=\'none\';this.style.boxShadow=\'none\'" onclick="sendAlbumPhoto(\'' + safeImg + '\', \'' + safeName + '\')">';
                    if (v.image) h += '<img src="../' + v.image + '" style="width:100%;height:110px;object-fit:cover;">';
                    else h += '<div style="width:100%;height:110px;background:hsl(210 20% 96%);display:flex;align-items:center;justify-content:center;color:hsl(215 16% 55%);font-size:0.75rem;">No image</div>';
                    h += '<div style="padding:0.5rem;"><div style="font-size:0.78rem;font-weight:600;color:#ddd;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + v.variant_name + '</div>';
                    if (v.size) h += '<div style="font-size:0.68rem;color:hsl(215 16% 47%);">' + v.size + '</div>';
                    h += '<div style="margin-top:0.4rem;display:flex;align-items:center;gap:4px;color:#ff6b35;font-size:0.72rem;font-weight:600;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14"/><path d="M5 12h14"/></svg> Add to Chat</div>';
                    h += '</div></div>';
                });
                h += '</div>';
            } else {
                h += '<p style="text-align:center;color:hsl(215 16% 47%);font-size:0.85rem;padding:1rem 0;">No album variants for this product.</p>';
            }
            h += '</div>';
            overlay.innerHTML = h;
            document.body.appendChild(overlay);
        });
}

// Add a variant album photo to the chat input preview
function sendAlbumPhoto(imagePath, variantName) {
    if (!imagePath) return;
    // Close the album popup
    const overlay = document.getElementById('albumOverlay');
    if (overlay) overlay.remove();
    
    // Fetch the image as a File and add to pendingPhotos
    fetch('../' + imagePath)
        .then(r => r.blob())
        .then(blob => {
            const ext = imagePath.split('.').pop();
            const fname = (variantName || 'album_photo') + '.' + ext;
            const file = new File([blob], fname, { type: blob.type });
            pendingPhotos.push(file);
            renderPhotoPreview();
            showToast('Photo Added', 'Album photo added to input. Press Send when ready.', 'success');
        })
        .catch(() => showToast('Error', 'Failed to load photo.', 'error'));
}

function renderImageBubble(imgPath) {
    if (!imgPath) return '';
    return imgPath.split(',').map(function(p) {
        p = p.trim();
        if (!p) return '';
        return '<a href="../' + p + '" target="_blank" style="display:inline-block;margin:0 4px 4px 0;"><img src="../' + p + '" style="max-width:220px; max-height:180px; border-radius:10px; object-fit:cover; cursor:pointer;" loading="lazy"></a>';
    }).join('');
}

function previewMsgPhoto(input) {
    if (input.files && input.files.length > 0) {
        for (let i = 0; i < input.files.length; i++) {
            pendingPhotos.push(input.files[i]);
        }
        renderPhotoPreview();
    }
}

function renderPhotoPreview() {
    const strip = document.getElementById('photoPreviewStrip');
    if (pendingPhotos.length === 0) { strip.style.display = 'none'; strip.innerHTML = ''; return; }
    strip.style.display = 'block';
    strip.innerHTML = '<div style="display:flex; flex-wrap:wrap; gap:0.4rem; align-items:center;">' +
        pendingPhotos.map(function(f, i) {
            const url = URL.createObjectURL(f);
            return '<div style="position:relative; display:inline-flex; align-items:center; gap:0.3rem; background:hsl(210 20% 96%); border:1px solid hsl(214 32% 91%); border-radius:10px; padding:4px 8px;">' +
                '<img src="' + url + '" style="height:40px; width:40px; object-fit:cover; border-radius:8px;">' +
                '<span style="max-width:70px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:0.7rem; color:hsl(215 16% 47%);">' + f.name + '</span>' +
                '<button type="button" onclick="removePhoto(' + i + ')" style="background:none; border:none; color:hsl(215 16% 47%); cursor:pointer; font-size:1rem; line-height:1;">&times;</button>' +
                '</div>';
        }).join('') +
        '<button type="button" onclick="clearPhotoPreview()" style="background:none; border:none; color:#ff4444; cursor:pointer; font-size:0.7rem; margin-left:4px;">Clear all</button>' +
        '</div>';
}

function removePhoto(idx) {
    pendingPhotos.splice(idx, 1);
    renderPhotoPreview();
    if (pendingPhotos.length === 0) document.getElementById('photoInput').value = '';
}

function clearPhotoPreview() {
    pendingPhotos = [];
    document.getElementById('photoInput').value = '';
    document.getElementById('photoPreviewStrip').style.display = 'none';
    document.getElementById('photoPreviewStrip').innerHTML = '';
}

function fetchMessages() {
    if (isSending) return;
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'fetch_messages');
    fetch('customer_messages.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(msgs => {
            if (!msgs || msgs.length === 0) {
                area.innerHTML = '<div class="no-messages" id="noMessages">No messages yet. Start a conversation!</div>';
                return;
            }
            area.innerHTML = '';
            msgs.forEach(m => {
                const div = document.createElement('div');
                div.className = 'msg ' + (m.sender === 'customer' ? 'msg-customer' : 'msg-admin');
                const time = new Date(m.sent_at).toLocaleString('en-US', { hour: 'numeric', minute: '2-digit', month: 'short', day: 'numeric' });
                // Payment card detection
                if (m.message && m.message.startsWith('__PAY__:')) {
                    try {
                        const payData = JSON.parse(m.message.substring(8));
                        // Attach DB tracking info for pay confirmation
                        payData.message_id = m.id;
                        payData.payment_status = m.payment_status || null;
                                        payData.payment_type = m.payment_type || null;
                                        payData.custom_order_id = m.custom_order_id || null;
                        // Reset bubble styling for payment card
                        div.style.background = 'none';
                        div.style.padding = '0';
                        div.style.boxShadow = 'none';
                        let cardHtml = renderPaymentCard(payData);
                        cardHtml += '<div class="msg-time" style="text-align:left;margin-top:4px;">' + time + '</div>';
                        div.innerHTML = cardHtml;
                    } catch(e) {
                        div.innerHTML = '<span style="font-size:0.75rem;color:#9ca3af;">[payment request]</span>';
                    }
                } else if (m.message && m.message.startsWith('__BAL__:')) {
                    try {
                        const balData = JSON.parse(m.message.substring(8));
                        balData.message_id = m.id;
                        div.style.background = 'none';
                        div.style.padding = '0';
                        div.style.boxShadow = 'none';
                        let cardHtml = renderBalanceCard(balData);
                        cardHtml += '<div class="msg-time" style="text-align:left;margin-top:4px;">' + time + '</div>';
                        div.innerHTML = cardHtml;
                    } catch(e) {
                        div.innerHTML = '<span style="font-size:0.75rem;color:#9ca3af;">[balance notice]</span>';
                    }
                } else {
                    let html = '';
                    if (m.image) html += renderImageBubble(m.image);
                    if (m.message) html += formatMentions(m.message);
                    html += '<div class="msg-time">' + time + '</div>';
                    div.innerHTML = html;
                }
                area.appendChild(div);
            });
            area.scrollTop = area.scrollHeight;
        })
        .catch(() => {});
}

function sendMessage() {
    const msg = input.value.trim();
    if (!msg && pendingPhotos.length === 0) return;
    input.value = '';
    dropdown.style.display = 'none';
    isSending = true;

    const noMsg = document.getElementById('noMessages');
    if (noMsg) noMsg.remove();

    const div = document.createElement('div');
    div.className = 'msg msg-customer';
    const now = new Date().toLocaleString('en-US', { hour: 'numeric', minute: '2-digit', month: 'short', day: 'numeric' });
    let previewHtml = '';
    if (pendingPhotos.length > 0) {
        pendingPhotos.forEach(function(f) {
            const localUrl = URL.createObjectURL(f);
            previewHtml += '<a href="' + localUrl + '" target="_blank" style="display:inline-block;margin:0 4px 4px 0;"><img src="' + localUrl + '" style="max-width:220px; max-height:180px; border-radius:10px; object-fit:cover; cursor:pointer;" loading="lazy"></a>';
        });
    }
    if (msg) previewHtml += formatMentions(msg);
    previewHtml += '<div class="msg-time">' + now + '</div>';
    div.innerHTML = previewHtml;
    area.appendChild(div);
    area.scrollTop = area.scrollHeight;

    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'send_message');
    fd.append('message', msg);
    pendingPhotos.forEach(function(f) { fd.append('photos[]', f); });
    
    clearPhotoPreview();
    
    fetch('customer_messages.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(() => { isSending = false; input.focus(); showToast('Message Sent', 'Your message has been sent.', 'success'); })
        .catch(() => { isSending = false; input.focus(); showToast('Error', 'Failed to send message.', 'error'); });
}

// @mention autocomplete
input.addEventListener('input', function() {
    const val = this.value;
    const atIdx = val.lastIndexOf('@');
    if (atIdx === -1 || atIdx < val.lastIndexOf(' ') - 0) {
        // Check if @ is the latest "word"
        const afterLastSpace = val.substring(val.lastIndexOf(' ') + 1);
        if (!afterLastSpace.startsWith('@')) { dropdown.style.display = 'none'; return; }
    }
    const query = val.substring(atIdx + 1).toLowerCase();
    const matches = products.filter(p => p.product_name.toLowerCase().includes(query));
    if (matches.length === 0 || query.length === 0 && val.charAt(val.length - 1) !== '@') {
        if (val.charAt(val.length - 1) === '@') {
            // Show all products
            showDropdown(products, atIdx);
        } else {
            dropdown.style.display = 'none';
        }
        return;
    }
    showDropdown(matches, atIdx);
});

function showDropdown(items, atIdx) {
    dropdown.innerHTML = '';
    items.forEach(p => {
        const item = document.createElement('div');
        item.style.cssText = 'padding:10px 14px; cursor:pointer; font-size:0.85rem; color:#ddd; border-bottom:1px solid #2a2a2a; display:flex; align-items:center; gap:10px;';
        let imgHtml = '';
        if (p.img) {
            imgHtml = '<img src="../' + p.img + '" style="width:32px;height:32px;border-radius:8px;object-fit:cover;flex-shrink:0;">';
        } else {
            imgHtml = '<div style="width:32px;height:32px;border-radius:8px;background:hsl(210 20% 93%);flex-shrink:0;display:flex;align-items:center;justify-content:center;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="hsl(214 32% 60%)" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div>';
        }
        item.innerHTML = imgHtml + '<div><span style="color:#ff6b35; font-weight:600;">@</span>' + p.product_name + '</div>';
        item.addEventListener('mouseenter', () => item.style.background = '#252525');
        item.addEventListener('mouseleave', () => item.style.background = 'transparent');
        item.addEventListener('click', () => {
            input.value = input.value.substring(0, atIdx) + '@' + p.product_name + ' ';
            dropdown.style.display = 'none';
            input.focus();
        });
        dropdown.appendChild(item);
    });
    dropdown.style.display = items.length ? 'block' : 'none';
}

input.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); sendMessage(); }
    if (e.key === 'Escape') dropdown.style.display = 'none';
});

document.addEventListener('click', function(e) {
    if (!dropdown.contains(e.target) && e.target !== input) dropdown.style.display = 'none';
});

// Handle ?inquire= param — auto-fill price inquiry
const inquireProduct = '<?= addslashes($inquireProduct) ?>';
if (inquireProduct) {
    input.value = 'Hi, I would like to inquire about the price customization of @' + inquireProduct + '. Can you provide pricing details?';
    input.focus();
}

fetchMessages();
setInterval(fetchMessages, 5000);
</script>

<div id="payConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:3000;align-items:center;justify-content:center;backdrop-filter:blur(6px);padding:1rem;" onclick="if(event.target===this)closePayConfirm()">
    <div style="width:100%;max-width:380px;border-radius:20px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,0.35);">
        <div style="background:linear-gradient(135deg,#001a4d,#002b80);padding:1.4rem 1.4rem 1.1rem;text-align:center;position:relative;">
            <button onclick="closePayConfirm()" style="position:absolute;top:0.9rem;right:1rem;background:none;border:none;color:rgba(255,255,255,0.5);font-size:1.4rem;cursor:pointer;line-height:1;">&times;</button>
            <img src="../download.jpg" alt="GCash" style="height:36px;width:auto;filter:brightness(0) invert(1);margin-bottom:0.6rem;display:block;margin-left:auto;margin-right:auto;">
            <div id="pcName" style="color:#fff;font-weight:800;font-size:1rem;margin-bottom:0.15rem;"></div>
            <div id="pcDesc" style="color:rgba(255,255,255,0.55);font-size:0.72rem;display:none;"></div>
        </div>
        <div style="background:#fff;padding:1.3rem 1.4rem;">
            <div style="display:flex;gap:0.7rem;margin-bottom:1rem;">
                <div style="flex:1;background:linear-gradient(135deg,#e8f4ff,#d0eaff);border-radius:14px;padding:1rem;text-align:center;">
                    <div style="display:flex;align-items:center;justify-content:center;gap:4px;margin-bottom:6px;">
                        <img src="../download.jpg" style="height:14px;width:auto;">
                        <span style="font-size:0.6rem;color:#4a90d9;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">Down Payment</span>
                    </div>
                    <div id="pcDown" style="font-size:1.25rem;font-weight:900;color:#007DFE;"></div>
                </div>
                <div style="flex:1;background:linear-gradient(135deg,#fff4ed,#ffe8d6);border-radius:14px;padding:1rem;text-align:center;">
                    <div style="font-size:0.6rem;color:#d4662a;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px;">Full Price</div>
                    <div id="pcFull" style="font-size:1.25rem;font-weight:900;color:#ff6b35;"></div>
                </div>
            </div>
            <div style="font-size:0.72rem;font-weight:700;color:#374151;text-align:center;margin-bottom:0.7rem;text-transform:uppercase;letter-spacing:0.04em;">Choose payment option</div>
            <button onclick="confirmPayment('down')" style="display:flex;width:100%;align-items:center;justify-content:center;gap:8px;padding:12px;background:linear-gradient(135deg,#007DFE,#0056b3);color:#fff;font-weight:800;font-size:0.85rem;border:none;border-radius:12px;cursor:pointer;font-family:inherit;margin-bottom:0.5rem;">
                <img src="../download.jpg" alt="" style="height:18px;width:auto;filter:brightness(0) invert(1);">
                Pay Down Payment
            </button>
            <button onclick="confirmPayment('full')" style="display:flex;width:100%;align-items:center;justify-content:center;gap:8px;padding:12px;background:linear-gradient(135deg,#ff6b35,#e55a2b);color:#fff;font-weight:800;font-size:0.85rem;border:none;border-radius:12px;cursor:pointer;font-family:inherit;margin-bottom:0.7rem;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M9 10h6M9 14h6"/></svg>
                Pay Full Price
            </button>
            <button onclick="closePayConfirm()" style="display:block;width:100%;padding:10px;background:none;color:#6b7280;font-weight:600;font-size:0.82rem;border:1px solid #e5e7eb;border-radius:12px;cursor:pointer;font-family:inherit;">Cancel</button>
        </div>
    </div>
</div>

<div id="gcashProcessModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:3100;align-items:center;justify-content:center;backdrop-filter:blur(8px);padding:1rem;">
    <div style="width:100%;max-width:380px;border-radius:20px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,0.4);">
        
        <div style="background:linear-gradient(135deg,#001a4d,#002b80);padding:1.5rem 1.4rem 1.2rem;text-align:center;">
            <img src="../download.jpg" alt="GCash" style="height:40px;width:auto;filter:brightness(0) invert(1);margin-bottom:0.5rem;display:block;margin-left:auto;margin-right:auto;">
            <div style="color:rgba(255,255,255,0.5);font-size:0.65rem;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;">Payment Processing</div>
        </div>
        
        <div style="background:#fff;padding:1.5rem 1.4rem;text-align:center;">
            
            <div id="gcashProcessName" style="font-weight:700;font-size:0.95rem;color:#111;margin-bottom:0.3rem;"></div>
            <div id="gcashProcessLabel" style="font-size:0.72rem;color:#6b7280;font-weight:600;margin-bottom:1rem;"></div>
            
            <div style="background:linear-gradient(135deg,#f0f7ff,#dbeafe);border-radius:16px;padding:1.2rem;margin-bottom:1.2rem;">
                <div style="font-size:0.6rem;color:#4a90d9;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;">Amount to Pay</div>
                <div id="gcashProcessAmt" style="font-size:2rem;font-weight:900;color:#007DFE;"></div>
            </div>
            
            <div style="margin-bottom:1rem;">
                <div id="gcashProcessSpinner" style="display:block;">
                    <div style="width:44px;height:44px;border:4px solid #e5e7eb;border-top-color:#007DFE;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto;"></div>
                </div>
                <div id="gcashProcessCheck" style="display:none;width:52px;height:52px;background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:50%;align-items:center;justify-content:center;margin:0 auto;">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20,6 9,17 4,12"/></svg>
                </div>
            </div>
            <div id="gcashProcessStatus" style="font-size:0.82rem;font-weight:600;color:#6b7280;margin-bottom:1.2rem;">Processing payment...</div>
            
            <button id="gcashProcessDone" onclick="finishPayment()" disabled style="display:flex;width:100%;align-items:center;justify-content:center;gap:8px;padding:13px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;font-weight:800;font-size:0.9rem;border:none;border-radius:12px;cursor:pointer;font-family:inherit;opacity:0.5;transition:opacity 0.3s;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20,6 9,17 4,12"/></svg>
                Done ✓
            </button>
        </div>
    </div>
</div>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>

<div id="customizeOrderModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:3200;align-items:center;justify-content:center;backdrop-filter:blur(6px);padding:1rem;" onclick="if(event.target===this)closeCustomizeOrder()">
    <div style="width:100%;max-width:400px;border-radius:20px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,0.35);max-height:90vh;display:flex;flex-direction:column;">
        
        <div style="background:linear-gradient(135deg,#001a4d,#002b80);padding:1.3rem 1.4rem 1rem;text-align:center;position:relative;flex-shrink:0;">
            <button onclick="closeCustomizeOrder()" style="position:absolute;top:0.9rem;right:1rem;background:none;border:none;color:rgba(255,255,255,0.5);font-size:1.4rem;cursor:pointer;line-height:1;" aria-label="Save and close customization">&times;</button>
            <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:0.4rem;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5"><polyline points="20,6 9,17 4,12"/></svg>
                <span style="color:#22c55e;font-weight:700;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.06em;">Payment Confirmed</span>
            </div>
            <div id="custOrdProduct" style="color:#fff;font-weight:800;font-size:1rem;margin-bottom:0.2rem;"></div>
            <div id="custOrdPayLabel" style="color:rgba(255,255,255,0.55);font-size:0.72rem;font-weight:600;"></div>
        </div>
        
        <div style="background:#fff;padding:1.3rem 1.4rem;overflow-y:auto;flex:1;">
            <div style="font-weight:700;font-size:0.82rem;color:#374151;margin-bottom:0.6rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ff6b35" stroke-width="2.5" style="vertical-align:-2px;margin-right:4px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Customize Your Order
            </div>
            <div style="font-size:0.7rem;color:#9ca3af;margin-bottom:1rem;">Add details so we can make your order just right.</div>

            
            <div style="border:1px solid #e5e7eb;border-radius:12px;padding:11px;margin-bottom:1rem;background:#fafafa;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:7px;">
                    <label for="custOrdSize" style="font-size:0.72rem;font-weight:700;color:#374151;">Size and measurements</label>
                    <button type="button" onclick="toggleCustomSizeGuide()" style="border:0;background:none;color:#007DFE;font-size:0.65rem;font-weight:700;cursor:pointer;padding:0;">View guide</button>
                </div>
                <select id="custOrdSize" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:9px 10px;font:inherit;font-size:0.78rem;background:#fff;box-sizing:border-box;">
                    <option value="">Select your size</option>
                    <?php foreach (['Small', 'Medium', 'Large', 'XL', '2XL', '3XL', '4XL', '5XL'] as $size): ?>
                        <option value="<?= htmlspecialchars($size) ?>"><?= htmlspecialchars($size) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="custOrdSizeGuide" style="display:none;overflow-x:auto;margin:8px 0;">
                    <table style="width:100%;border-collapse:collapse;font-size:0.6rem;color:#4b5563;background:#fff;">
                        <thead><tr style="background:#f3f4f6;"><th style="padding:6px;text-align:left;">Size</th><th style="padding:6px;text-align:left;">Height</th><th style="padding:6px;text-align:left;">Weight</th><th style="padding:6px;text-align:left;">Shoulder</th><th style="padding:6px;text-align:left;">Top</th><th style="padding:6px;text-align:left;">Waist</th></tr></thead>
                        <tbody>
                            <?php foreach (['Small', 'Medium', 'Large', 'XL', '2XL', '3XL', '4XL', '5XL'] as $size): $chart = getStaticProductSizeChartRow($size); ?>
                                <tr><td style="padding:6px;border-top:1px solid #f0f0f0;font-weight:700;"><?= htmlspecialchars($size) ?></td><td style="padding:6px;border-top:1px solid #f0f0f0;"><?= $chart['height_range'] ?> cm</td><td style="padding:6px;border-top:1px solid #f0f0f0;"><?= $chart['weight_range'] ?> kg</td><td style="padding:6px;border-top:1px solid #f0f0f0;"><?= $chart['shoulder_cm'] ?> cm</td><td style="padding:6px;border-top:1px solid #f0f0f0;"><?= $chart['top_length_cm'] ?> cm</td><td style="padding:6px;border-top:1px solid #f0f0f0;"><?= $chart['waist_cm'] ?> cm</td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:8px;">
                    <input id="custOrdHeight" type="number" min="100" max="250" step="0.1" placeholder="Height (cm)" aria-label="Height in centimeters" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:8px;font:inherit;font-size:0.72rem;">
                    <input id="custOrdWeight" type="number" min="20" max="300" step="0.1" placeholder="Weight (kg)" aria-label="Weight in kilograms" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:8px;font:inherit;font-size:0.72rem;">
                    <input id="custOrdShoulder" type="number" min="20" max="100" step="0.1" placeholder="Shoulder (cm)" aria-label="Shoulder measurement in centimeters" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:8px;font:inherit;font-size:0.72rem;">
                    <input id="custOrdTopLength" type="number" min="30" max="150" step="0.1" placeholder="Top length (cm)" aria-label="Top length in centimeters" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:8px;font:inherit;font-size:0.72rem;">
                    <input id="custOrdWaist" type="number" min="40" max="200" step="0.1" placeholder="Waist (cm)" aria-label="Waist measurement in centimeters" style="width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:8px;font:inherit;font-size:0.72rem;grid-column:1 / -1;">
                </div>
                <div style="font-size:0.61rem;color:#9ca3af;margin-top:6px;">Enter all measurements so we can verify your selected size.</div>
            </div>

            
            <label style="display:block;font-size:0.72rem;font-weight:700;color:#374151;margin-bottom:0.4rem;">
                📷 Reference Photos <span style="color:#9ca3af;font-weight:500;">(optional, max 5)</span>
            </label>
            <div id="custOrdPhotoPreview" style="margin-bottom:0.5rem;min-height:10px;"></div>
            <label style="display:flex;align-items:center;justify-content:center;gap:6px;padding:10px;border:2px dashed #d1d5db;border-radius:12px;cursor:pointer;margin-bottom:1rem;transition:border-color 0.2s;" onmouseenter="this.style.borderColor='#007DFE'" onmouseleave="this.style.borderColor='#d1d5db'">
                <input id="custOrdPhotoInput" type="file" accept="image/*" multiple style="display:none;" onchange="handleCustomPhotos(this)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                <span style="font-size:0.75rem;color:#6b7280;font-weight:600;">Upload Photos</span>
            </label>

            
            <label for="custOrdNotes" style="display:block;font-size:0.72rem;font-weight:700;color:#374151;margin-bottom:0.4rem;">
                📝 Order Notes <span style="color:#9ca3af;font-weight:500;">(optional)</span>
            </label>
            <textarea id="custOrdNotes" rows="3" placeholder="Describe your preferred design, colors, sizes, text, or special instructions..." style="width:100%;border:2px solid #e5e7eb;border-radius:12px;padding:10px 12px;font-family:inherit;font-size:0.8rem;resize:vertical;outline:none;transition:border-color 0.2s;box-sizing:border-box;" onfocus="this.style.borderColor='#007DFE'" onblur="this.style.borderColor='#e5e7eb'"></textarea>
            <div style="font-size:0.62rem;color:#9ca3af;margin-top:3px;margin-bottom:1.2rem;">e.g. "Blue background, bold text 'TEAM ZIAN', size Large"</div>

            
            <button id="custOrdSubmitBtn" onclick="submitCustomOrder()" style="display:flex;width:100%;align-items:center;justify-content:center;gap:8px;padding:13px;background:linear-gradient(135deg,#ff6b35,#e55a2b);color:#fff;font-weight:800;font-size:0.88rem;border:none;border-radius:12px;cursor:pointer;font-family:inherit;transition:opacity 0.2s;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20,6 9,17 4,12"/></svg>
                Place Order
            </button>
            <button onclick="document.getElementById('custOrdNotes').value='';window._customRefPhotos=[];submitCustomOrder(true);" style="display:block;width:100%;padding:10px;background:none;color:#6b7280;font-weight:600;font-size:0.78rem;border:1px solid #e5e7eb;border-radius:12px;cursor:pointer;font-family:inherit;margin-top:0.5rem;">Skip — Place Without Customizing</button>
        </div>
    </div>
</div>
<?php include 'chatbot_widget.php'; ?>
</body>
</html>

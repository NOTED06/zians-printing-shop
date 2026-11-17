<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once '../db_conn.php';
require_once '../upload_helper.php';
require_once '../csrf_helper.php';

if (!isset($_SESSION['admin_id']) || ($_SESSION['type'] ?? '') !== 'admin') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
        exit;
    }
    header('Location: ../admin_login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
}

$customersRes = $conn->query("SELECT DISTINCT c.customer_id, c.username, c.email FROM messages cm JOIN customers c ON cm.customer_id = c.customer_id WHERE cm.record_type = 'chat' ORDER BY (SELECT MAX(sent_at) FROM messages WHERE record_type = 'chat' AND customer_id = c.customer_id) DESC");
$customers = [];
while ($r = $customersRes->fetch_assoc()) {
    $customers[] = $r;
}

$productsList = [];
$prodRes = $conn->query("SELECT p.product_id, p.product_name, p.img, pc.category_name FROM products p LEFT JOIN products pc ON pc.record_type = 'category' AND pc.category_id = p.category_id WHERE p.record_type = 'product' AND p.is_active = 1 ORDER BY pc.category_name ASC, p.product_name ASC");
if ($prodRes) { while ($r = $prodRes->fetch_assoc()) $productsList[] = $r; }

$selectedId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : ($customers[0]['customer_id'] ?? 0);
$messages = [];
if ($selectedId > 0) {
    $msgRes = $conn->query("SELECT cm.* FROM messages cm WHERE cm.record_type = 'chat' AND cm.customer_id = $selectedId ORDER BY cm.sent_at ASC");
    while ($r = $msgRes->fetch_assoc()) {
        $messages[] = $r;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'send_reply') {
        $cid = (int)$_POST['customer_id'];
        $msg = trim($_POST['message'] ?? '');
        
        
        $imagePaths = [];
        if (isset($_FILES['photos'])) {
            $fileCount = count($_FILES['photos']['name']);
            $allowed = ['jpg','jpeg','png','gif','webp'];
            for ($i = 0; $i < $fileCount; $i++) {
                    if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK && is_valid_image_upload($_FILES['photos']['tmp_name'][$i], $_FILES['photos']['name'][$i])) {
                    $ext = strtolower(pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed)) {
                        $filename = 'msg_admin_' . time() . '_' . mt_rand(1000,9999) . '_' . $i . '.' . $ext;
                        $dest = '../uploads/messages/' . $filename;
                        if (move_uploaded_file($_FILES['photos']['tmp_name'][$i], $dest)) {
                            $imagePaths[] = 'uploads/messages/' . $filename;
                        }
                    }
                }
            }
        }
        $imagePath = implode(',', $imagePaths);
        
        if ($cid > 0 && (!empty($msg) || !empty($imagePath))) {
            $stmt = $conn->prepare("INSERT INTO messages (customer_id, message, image, sender, sent_at) VALUES (?, ?, ?, 'admin', NOW())");
            $stmt->bind_param('iss', $cid, $msg, $imagePath);
            $stmt->execute();
            $newMsgId = $conn->insert_id;
            
            
            if (strpos($msg, '__PAY__:') === 0) {
                $payJson = json_decode(substr($msg, 8), true);
                if ($payJson) {
                    $pStmt = $conn->prepare("UPDATE messages SET payment_product_name = ?, payment_description = ?, down_payment = ?, full_price = ?, gcash_link = ?, payment_status = 'pending' WHERE id = ? AND record_type = 'chat'");
                    $pName = $payJson['name'] ?? '';
                    $pDesc = $payJson['desc'] ?? '';
                    $pDown = (float)($payJson['down'] ?? 0);
                    $pFull = (float)($payJson['full'] ?? 0);
                    $pLink = $payJson['link'] ?? '';
                    $pStmt->bind_param('ssddsi', $pName, $pDesc, $pDown, $pFull, $pLink, $newMsgId);
                    $pStmt->execute();
                }
            }
            
            
            require_once '../notifications_helper.php';
            $notifMsg = !empty($msg) ? $msg : '📷 Sent a photo';
            if (strpos($msg, '__PAY__:') === 0) $notifMsg = '💰 Payment request sent';
            $preview = mb_substr($notifMsg, 0, 60) . (mb_strlen($notifMsg) > 60 ? '...' : '');
            createNotification($conn, 'customer', $cid, 'message', 'Admin Reply', 'Admin: ' . $preview, 'customer_messages.php');
            
            echo json_encode(['success' => true, 'image' => $imagePath]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    if ($_POST['action'] === 'fetch_messages') {
        $cid = (int)$_POST['customer_id'];
        $msgs = [];
        if ($cid > 0) {
            $res = $conn->query("SELECT cm.* FROM messages cm WHERE cm.record_type = 'chat' AND cm.customer_id = $cid ORDER BY cm.sent_at ASC");
            while ($r = $res->fetch_assoc()) $msgs[] = $r;
        }
        echo json_encode($msgs);
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
        $cid = (int)$_POST['customer_id'];
        $imgPath = trim($_POST['image_path'] ?? '');
        $msg = trim($_POST['message'] ?? '');
        
        if ($cid > 0 && !empty($imgPath) && (strpos($imgPath, 'uploads/') === 0 || preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $imgPath))) {
            $stmt = $conn->prepare("INSERT INTO messages (customer_id, message, image, sender, sent_at) VALUES (?, ?, ?, 'admin', NOW())");
            $stmt->bind_param('iss', $cid, $msg, $imgPath);
            $stmt->execute();
            require_once '../notifications_helper.php';
            $notifMsg = !empty($msg) ? $msg : '\xF0\x9F\x93\xB7 Sent a photo';
            $preview = mb_substr($notifMsg, 0, 60) . (mb_strlen($notifMsg) > 60 ? '...' : '');
            createNotification($conn, 'customer', $cid, 'message', 'Admin Reply', 'Admin: ' . $preview, 'customer_messages.php');
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
    <title>Messages - Zian's PrintShop Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/admin.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50 admin-page">

<?php include 'sidebar.php'; ?>

<main class="ml-64 p-8 min-h-screen">
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">Messages</h1>
        <p class="text-gray-500 text-sm mt-1">Chat with your customers</p>
    </div>

    <div class="grid grid-cols-3 gap-6" style="height: calc(100vh - 180px);">
        
        <div class="bg-white rounded-xl stat-card overflow-hidden">
            <div class="p-4 border-b border-gray-100">
                <h3 class="font-semibold text-gray-900">Conversations</h3>
            </div>
            <div class="overflow-auto" style="max-height: calc(100vh - 260px);">
                <?php if (empty($customers)): ?>
                    <p class="text-gray-400 text-sm text-center py-8">No messages yet</p>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                    <a href="messages.php?customer_id=<?= $c['customer_id'] ?>" class="block px-4 py-3 border-b border-gray-50 hover:bg-orange-50 transition-all <?= $selectedId == $c['customer_id'] ? 'bg-orange-50 border-l-3' : '' ?>" style="<?= $selectedId == $c['customer_id'] ? 'border-left:3px solid #ff6b35' : '' ?>">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full flex items-center justify-center text-white text-xs font-bold" style="background:#ff6b35;">
                                <?= strtoupper(substr($c['username'] ?? 'U', 0, 1)) ?>
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($c['username'] ?: 'Unknown') ?></div>
                                <div class="text-xs text-gray-400"><?= htmlspecialchars($c['email'] ?? '') ?></div>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        
        <div class="col-span-2 bg-white rounded-xl stat-card flex flex-col overflow-hidden">
            <div class="p-4 border-b border-gray-100">
                <h3 class="font-semibold text-gray-900">
                    <?php
                    $selName = 'Select a conversation';
                    foreach ($customers as $c) {
                        if ($c['customer_id'] == $selectedId) {
                            $selName = htmlspecialchars($c['username'] ?: 'Customer');
                            break;
                        }
                    }
                    echo $selName;
                    ?>
                </h3>
            </div>
            <div class="flex-1 overflow-auto p-4 space-y-3" id="chatMessages" style="max-height: calc(100vh - 340px);">
                <?php foreach ($messages as $m): ?>
                <?php
                $rawMsg = $m['message'] ?? '';
                $isPayCard = (strpos($rawMsg, '__PAY__:') === 0);
                $isBalCard = (strpos($rawMsg, '__BAL__:') === 0);
                $payData = $isPayCard ? (@json_decode(substr($rawMsg, 8), true) ?? []) : [];
                $balData = $isBalCard ? (@json_decode(substr($rawMsg, 8), true) ?? []) : [];
                ?>
                <div class="flex <?= $m['sender'] === 'admin' ? 'justify-end' : 'justify-start' ?>">
                <?php if ($isPayCard && !empty($payData)): ?>
                    <?php
                    $pName = htmlspecialchars($payData['name'] ?? '');
                    $pDesc = htmlspecialchars($payData['desc'] ?? '');
                    $pDown = number_format((float)($payData['down'] ?? 0), 2);
                    $pFull = number_format((float)($payData['full'] ?? 0), 2);
                    $pLink = htmlspecialchars($payData['link'] ?? '');
                    $pStatus = $m['payment_status'] ?? 'pending';
                    $pType = $m['payment_type'] ?? '';
                    $isPaid = ($pStatus === 'paid');
                    $timeStr = date('h:i A', strtotime($m['sent_at']));
                    $downBg = ($isPaid && $pType === 'down') ? 'linear-gradient(135deg,#d1fae5,#a7f3d0)' : '#f0f7ff';
                    $fullBg = ($isPaid && $pType === 'full') ? 'linear-gradient(135deg,#d1fae5,#a7f3d0)' : '#fff7ed';
                    ?>
                    <div style="max-width:340px;">
                        <div style="border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.13);">
                            <div style="background:linear-gradient(135deg,#001a4d,#002b80);padding:12px 14px;display:flex;align-items:center;gap:10px;">
                                <img src="../download.jpg" alt="GCash" style="height:22px;width:auto;filter:brightness(0) invert(1);">
                                <span style="color:#fff;font-weight:700;font-size:0.8rem;">Payment Request</span>
                                <?php if ($isPaid): ?><span style="margin-left:auto;background:#10b981;color:#fff;font-size:0.6rem;font-weight:800;padding:2px 8px;border-radius:20px;letter-spacing:0.04em;">PAID</span><?php endif; ?>
                            </div>
                            <div style="background:#fff;padding:14px;">
                                <div style="font-weight:700;font-size:0.9rem;color:#111;margin-bottom:2px;"><?= $pName ?></div>
                                <?php if ($pDesc): ?><div style="color:#6b7280;font-size:0.76rem;margin-bottom:10px;"><?= $pDesc ?></div><?php endif; ?>
                                <div style="display:flex;gap:8px;margin-bottom:12px;">
                                    <div style="flex:1;background:<?= $downBg ?>;border-radius:10px;padding:10px;text-align:center;">
                                        <div style="display:flex;align-items:center;justify-content:center;gap:4px;margin-bottom:4px;">
                                            <img src="../download.jpg" style="height:12px;width:auto;">
                                            <span style="font-size:0.6rem;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Down Payment</span>
                                        </div>
                                        <div style="font-size:1rem;font-weight:800;color:#007DFE;">₱<?= $pDown ?></div>
                                    </div>
                                    <div style="flex:1;background:<?= $fullBg ?>;border-radius:10px;padding:10px;text-align:center;">
                                        <div style="font-size:0.6rem;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Full Price</div>
                                        <div style="font-size:1rem;font-weight:800;color:#ff6b35;">₱<?= $pFull ?></div>
                                    </div>
                                </div>
                                <?php if ($isPaid): ?>
                                <div style="padding:9px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-weight:700;font-size:0.78rem;border-radius:10px;text-align:center;"><?= $pType === 'down' ? 'Down Payment Received ✓' : 'Full Payment Received ✓' ?></div>
                                <?php elseif ($pLink): ?>
                                <div style="padding:9px;background:#fef3c7;color:#92400e;font-weight:700;font-size:0.78rem;border-radius:10px;text-align:center;">⏳ Awaiting Payment</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="font-size:0.65rem;color:#9ca3af;margin-top:4px;text-align:<?= $m['sender'] === 'admin' ? 'right' : 'left' ?>;"><?= $timeStr ?></div>
                    </div>
                <?php elseif ($isBalCard && !empty($balData)): ?>
                    <?php
                    $bName = htmlspecialchars($balData['name'] ?? 'Order');
                    $bFull = number_format((float)($balData['full'] ?? 0), 2);
                    $bDownPaid = number_format((float)($balData['down_paid'] ?? 0), 2);
                    $bRemaining = number_format((float)($balData['remaining'] ?? 0), 2);
                    $bIsPaid = !empty($balData['balance_paid']);
                    $timeStr = date('h:i A', strtotime($m['sent_at']));
                    $bBadge = $bIsPaid ? '<span style="margin-left:auto;background:#22c55e;color:#fff;font-size:0.58rem;font-weight:800;padding:2px 8px;border-radius:20px;">FULLY PAID</span>' : '<span style="margin-left:auto;background:#fbbf24;color:#78350f;font-size:0.58rem;font-weight:800;padding:2px 8px;border-radius:20px;">READY FOR PICKUP</span>';
                    $bBalTileBg = $bIsPaid ? 'linear-gradient(135deg,#d1fae5,#a7f3d0)' : 'linear-gradient(135deg,#fef3c7,#fde68a)';
                    $bBalColor = $bIsPaid ? '#22c55e' : '#d97706';
                    $bBalLabel = $bIsPaid ? 'Balance Paid' : 'Remaining';
                    $bBalLabelColor = $bIsPaid ? '#047857' : '#92400e';
                    $bDescText = $bIsPaid ? 'Customer has fully settled the remaining balance via GCash.' : 'Order is ready for pickup. Customer has remaining balance.';
                    ?>
                    <div style="max-width:340px;">
                        <div style="border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.13);">
                            <div style="background:linear-gradient(135deg,#7c3aed,#5b21b6);padding:12px 14px;display:flex;align-items:center;gap:10px;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                                <span style="color:#fff;font-weight:700;font-size:0.8rem;">Remaining Balance</span>
                                <?= $bBadge ?>
                            </div>
                            <div style="background:#fff;padding:14px;">
                                <div style="font-weight:700;font-size:0.9rem;color:#111;margin-bottom:2px;">📦 <?= $bName ?></div>
                                <div style="color:#6b7280;font-size:0.72rem;margin-bottom:10px;"><?= $bDescText ?></div>
                                <div style="display:flex;gap:8px;margin-bottom:12px;">
                                    <div style="flex:1;background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-radius:10px;padding:10px;text-align:center;">
                                        <div style="font-size:0.58rem;color:#047857;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Down Payment Paid</div>
                                        <div style="font-size:0.95rem;font-weight:800;color:#22c55e;">₱<?= $bDownPaid ?></div>
                                    </div>
                                    <div style="flex:1;background:<?= $bBalTileBg ?>;border-radius:10px;padding:10px;text-align:center;">
                                        <div style="font-size:0.58rem;color:<?= $bBalLabelColor ?>;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;"><?= $bBalLabel ?></div>
                                        <div style="font-size:0.95rem;font-weight:800;color:<?= $bBalColor ?>;">₱<?= $bRemaining ?></div>
                                    </div>
                                </div>
                                <div style="font-size:0.68rem;color:#6b7280;font-weight:600;text-align:center;margin-bottom:10px;">Total: ₱<?= $bFull ?></div>
                                <?php if ($bIsPaid): ?>
                                <div style="padding:9px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-weight:700;font-size:0.78rem;border-radius:10px;text-align:center;">Remaining Balance Paid via GCash ✓</div>
                                <?php else: ?>
                                <div style="display:flex;gap:8px;">
                                    <div style="flex:1;padding:9px;background:linear-gradient(135deg,#007DFE,#0056b3);border-radius:10px;text-align:center;">
                                        <div style="color:#fff;font-weight:700;font-size:0.68rem;">💳 GCash</div>
                                    </div>
                                    <div style="flex:1;padding:9px;background:linear-gradient(135deg,#f3f4f6,#e5e7eb);border-radius:10px;text-align:center;">
                                        <div style="color:#374151;font-weight:700;font-size:0.68rem;">🏪 At Store</div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="font-size:0.65rem;color:#9ca3af;margin-top:4px;text-align:right;"><?= $timeStr ?></div>
                    </div>
                <?php else: ?>
                    <div class="max-w-md px-4 py-2.5 rounded-2xl text-sm <?= $m['sender'] === 'admin' ? 'text-white' : 'bg-gray-100 text-gray-800' ?>" style="<?= $m['sender'] === 'admin' ? 'background:#ff6b35' : '' ?>">
                        <?php if (!empty($m['image'])): ?>
                        <?php foreach (explode(',', $m['image']) as $img): $img = trim($img); if (!empty($img)): ?>
                        <a href="../<?= htmlspecialchars($img) ?>" target="_blank" style="display:inline-block;margin:0 4px 4px 0;"><img src="../<?= htmlspecialchars($img) ?>" style="max-width:220px; max-height:180px; border-radius:10px; object-fit:cover; cursor:pointer;" loading="lazy"></a>
                        <?php endif; endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($m['message'])): ?>
                        <?php
                        $msgText = htmlspecialchars($m['message']);
                        $msgText = preg_replace_callback('/@([\w\s&.\'\-]+?)(?=\s@|\s*$|[.!?,])/', function($match) use ($productsList) {
                            $prodName = $match[1];
                            $imgTag = '';
                            foreach ($productsList as $p) {
                                if (strtolower(trim($p['product_name'])) === strtolower(trim($prodName))) {
                                    if (!empty($p['img'])) {
                                        $imgTag = '<img src="../' . htmlspecialchars($p['img']) . '" style="width:18px;height:18px;border-radius:4px;object-fit:cover;vertical-align:middle;margin-right:3px;">';
                                    }
                                    break;
                                }
                            }
                            return '<span class="mention-tag" style="background:rgba(255,107,53,0.15); color:#ff6b35; padding:1px 6px; border-radius:4px; font-weight:600; font-size:0.85em; cursor:pointer; display:inline-flex; align-items:center; gap:2px;" onclick="renderProductCard(\'' . addslashes($prodName) . '\')" title="Click to view album">' . $imgTag . '@' . $prodName . '</span>';
                        }, $msgText);
                        echo $msgText;
                        ?>
                        <?php endif; ?>
                        <div class="text-xs mt-1 <?= $m['sender'] === 'admin' ? 'text-orange-100' : 'text-gray-400' ?>"><?= date('h:i A', strtotime($m['sent_at'])) ?></div>
                    </div>
                <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($selectedId > 0): ?>
            <div class="p-4 border-t border-gray-100">
                
                <div id="photoPreviewStrip" style="display:none; margin-bottom:0.5rem;"></div>
                <form onsubmit="sendReply(event)" class="flex gap-3 items-center" style="position:relative;">
                    <input type="file" id="photoInput" accept="image/*" multiple style="display:none;" onchange="previewMsgPhoto(this)">
                    <button type="button" onclick="document.getElementById('photoInput').click()" class="flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-xl border border-gray-200 hover:border-orange-400 hover:bg-orange-50 transition-all" title="Attach photo">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-gray-400"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                    </button>
                    <button type="button" onclick="openPayModal()" class="flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-xl border border-gray-200 hover:border-blue-400 hover:bg-blue-50 transition-all" title="Send payment request">
                        <img src="../download.jpg" alt="GCash" style="height:22px;width:auto;object-fit:contain;">
                    </button>
                    
                    <div id="mentionDropdown" style="display:none; position:absolute; bottom:100%; left:0; right:0; max-height:260px; overflow-y:auto; background:#fff; border:1px solid #e5e7eb; border-radius:12px; margin-bottom:6px; z-index:20; box-shadow:0 8px 30px rgba(0,0,0,0.12);"></div>
                    <input type="text" id="replyInput" placeholder="Type @ to mention a product..." class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-orange-400" autocomplete="off">
                    <button type="submit" class="px-6 py-2.5 rounded-xl text-white text-sm font-semibold" style="background:#ff6b35;">Send</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<div id="payModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(4px);" onclick="if(event.target===this)closePayModal()">
<div style="background:#fff;border-radius:18px;width:94%;max-width:440px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,0.25);">
    
    <div style="background:linear-gradient(135deg,#001a4d,#002b80);padding:1.1rem 1.4rem;display:flex;align-items:center;gap:12px;">
        <img src="../download.jpg" alt="GCash" style="height:30px;width:auto;object-fit:contain;filter:brightness(0) invert(1);">
        <div>
            <div style="color:#fff;font-weight:700;font-size:0.92rem;">Payment Request</div>
            <div style="color:rgba(255,255,255,0.5);font-size:0.65rem;">Send payment details to customer</div>
        </div>
        <button onclick="closePayModal()" style="margin-left:auto;background:none;border:none;color:rgba(255,255,255,0.6);font-size:1.3rem;cursor:pointer;line-height:1;">&times;</button>
    </div>
    
    <div style="padding:1.25rem 1.4rem;display:flex;flex-direction:column;gap:0.9rem;">
        
        <div>
            <label style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;display:flex;align-items:center;gap:6px;margin-bottom:5px;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#ff6b35" stroke-width="2.5"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                Product / Order Name
            </label>
            <select id="payName" style="width:100%;padding:9px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:0.82rem;outline:none;font-family:inherit;box-sizing:border-box;background:#fff;transition:border-color 0.2s;" onfocus="this.style.borderColor='#007DFE'" onblur="this.style.borderColor='#e5e7eb'">
                <option value="">-- Select Product --</option>
                <?php $paymentCategory = null; foreach ($productsList as $product): ?>
                    <?php $productCategory = $product['category_name'] ?: 'Other'; if ($productCategory !== $paymentCategory): ?>
                        <?php if ($paymentCategory !== null): ?></optgroup><?php endif; ?>
                        <optgroup label="<?= htmlspecialchars($productCategory) ?>">
                        <?php $paymentCategory = $productCategory; ?>
                    <?php endif; ?>
                    <option value="<?= htmlspecialchars($product['product_name']) ?>"><?= htmlspecialchars($product['product_name']) ?></option>
                <?php endforeach; ?>
                <?php if ($paymentCategory !== null): ?></optgroup><?php endif; ?>
            </select>
        </div>
        
        <div>
            <label style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;display:flex;align-items:center;gap:6px;margin-bottom:5px;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#ff6b35" stroke-width="2.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Description
            </label>
            <textarea id="payDesc" placeholder="Order details, instructions..." rows="2" style="width:100%;padding:9px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:0.82rem;outline:none;font-family:inherit;resize:none;box-sizing:border-box;transition:border-color 0.2s;" onfocus="this.style.borderColor='#007DFE'" onblur="this.style.borderColor='#e5e7eb'"></textarea>
        </div>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.7rem;">
            
            <div>
                <label style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;display:flex;align-items:center;gap:5px;margin-bottom:5px;">
                    <img src="../download.jpg" alt="GCash" style="height:14px;width:auto;object-fit:contain;">
                    Down Payment
                </label>
                <div style="position:relative;">
                    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#007DFE;font-weight:700;font-size:0.85rem;">₱</span>
                    <input id="payDown" type="number" min="0" step="0.01" placeholder="0.00" style="width:100%;padding:9px 12px 9px 26px;border:1px solid #e5e7eb;border-radius:10px;font-size:0.82rem;outline:none;font-family:inherit;box-sizing:border-box;transition:border-color 0.2s;" onfocus="this.style.borderColor='#007DFE'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
            </div>
            
            <div>
                <label style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;display:flex;align-items:center;gap:5px;margin-bottom:5px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ff6b35" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M9 10h6M9 14h6"/></svg>
                    Full Price
                </label>
                <div style="position:relative;">
                    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#ff6b35;font-weight:700;font-size:0.85rem;">₱</span>
                    <input id="payFull" type="number" min="0" step="0.01" placeholder="0.00" style="width:100%;padding:9px 12px 9px 26px;border:1px solid #e5e7eb;border-radius:10px;font-size:0.82rem;outline:none;font-family:inherit;box-sizing:border-box;transition:border-color 0.2s;" onfocus="this.style.borderColor='#ff6b35'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
            </div>
        </div>
        
        <div>
            <label style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;display:flex;align-items:center;gap:6px;margin-bottom:5px;">
                <img src="../download.jpg" alt="GCash" style="height:14px;width:auto;object-fit:contain;">
                GCash Payment Link <span style="font-weight:400;color:#9ca3af;text-transform:none;font-size:0.6rem;">(optional)</span>
            </label>
            <input id="payLink" type="url" placeholder="https://gcash.me/..." style="width:100%;padding:9px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:0.82rem;outline:none;font-family:inherit;box-sizing:border-box;transition:border-color 0.2s;" onfocus="this.style.borderColor='#007DFE'" onblur="this.style.borderColor='#e5e7eb'">
        </div>
        
        <div style="display:flex;gap:0.6rem;margin-top:0.2rem;">
            <button onclick="closePayModal()" style="flex:1;padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-size:0.82rem;font-weight:600;cursor:pointer;font-family:inherit;color:#6b7280;transition:all 0.2s;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">Cancel</button>
            <button onclick="sendPayRequest()" style="flex:2;padding:10px;border:none;border-radius:10px;background:linear-gradient(135deg,#007DFE,#0056b3);font-size:0.82rem;font-weight:700;cursor:pointer;font-family:inherit;color:#fff;display:flex;align-items:center;justify-content:center;gap:6px;transition:opacity 0.2s;" onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'">
                <img src="../download.jpg" alt="" style="height:16px;width:auto;filter:brightness(0) invert(1);">
                Send Payment Request
            </button>
        </div>
    </div>
</div>
</div>

<script>
const chat = document.getElementById('chatMessages');
const selectedId = '<?= $selectedId ?>';
const replyInput = document.getElementById('replyInput');
const dropdown = document.getElementById('mentionDropdown');
let isSending = false;
let pendingPhotos = [];

const products = <?= json_encode($productsList) ?>;

if (chat) chat.scrollTop = chat.scrollHeight;

function formatMentions(text) {
    return text.replace(/@([\w\s&.'\-]+?)(?=\s@|\s*$|[.!?,])/g, function(match, name) {
        // Find matching product to show image
        const prod = products.find(p => p.product_name.toLowerCase() === name.trim().toLowerCase());
        let imgTag = '';
        if (prod && prod.img) {
            imgTag = '<img src="../' + prod.img + '" style="width:18px;height:18px;border-radius:4px;object-fit:cover;vertical-align:middle;margin-right:3px;">';
        }
        return '<span style="background:rgba(255,107,53,0.15); color:#ff6b35; padding:1px 6px; border-radius:4px; font-weight:600; font-size:0.85em; display:inline-flex; align-items:center; gap:2px;">' + imgTag + '@' + name + '</span>';
    });
}

// Format mentions in server-rendered HTML (used by PHP initial render)
function formatMentionsStatic(text) {
    return formatMentions(text);
}

function renderImageBubble(imgPath) {
    if (!imgPath) return '';
    return imgPath.split(',').map(function(p) {
        p = p.trim();
        if (!p) return '';
        return '<a href="../' + p + '" target="_blank" style="display:inline-block;margin:0 4px 4px 0;"><img src="../' + p + '" style="max-width:220px; max-height:180px; border-radius:10px; object-fit:cover; cursor:pointer;" loading="lazy"></a>';
    }).join('');
}

// Render product album card (shown when @mention is clicked)
function renderProductCard(productName) {
    const prod = products.find(p => p.product_name.toLowerCase() === productName.trim().toLowerCase());
    if (!prod) return;
    
    // Fetch album variants
    const fd = new FormData();
    fd.append('action', 'get_product_album');
    fd.append('product_id', prod.product_id);
    fetch('messages.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.product) return;
            // Build album popup
            const overlay = document.createElement('div');
            overlay.id = 'albumOverlay';
            overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1100;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(3px);';
            overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };
            
            let albumHtml = '<div style="background:#fff;border-radius:16px;padding:1.5rem;width:90%;max-width:550px;max-height:80vh;overflow-y:auto;position:relative;">';
            albumHtml += '<button onclick="this.closest(\'div[style*=fixed]\').remove()" style="position:absolute;top:0.8rem;right:1rem;background:none;border:none;font-size:1.3rem;color:#999;cursor:pointer;">&times;</button>';
            albumHtml += '<div style="display:flex;align-items:center;gap:0.8rem;margin-bottom:0.5rem;">';
            if (data.product.img) {
                albumHtml += '<img src="../' + data.product.img + '" style="width:50px;height:50px;border-radius:10px;object-fit:cover;">';
            }
            albumHtml += '<div><h3 style="font-size:1.1rem;font-weight:700;color:#111;">' + data.product.product_name + '</h3>';
            albumHtml += '<span style="font-size:0.75rem;color:#888;">' + data.variants.length + ' variant' + (data.variants.length !== 1 ? 's' : '') + ' &mdash; Click a photo to add to chat</span></div></div>';
            
            if (data.variants.length > 0) {
                albumHtml += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:0.8rem;margin-top:0.8rem;">';
                data.variants.forEach(v => {
                    const safeImg = (v.image || '').replace(/'/g, "\\'");
                    const safeName = (v.variant_name || '').replace(/'/g, "\\'");
                    albumHtml += '<div style="background:#f9f9f9;border:1px solid #eee;border-radius:12px;overflow:hidden;cursor:pointer;transition:all 0.2s;" onmouseover="this.style.borderColor=\'#ff6b35\';this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 4px 12px rgba(255,107,53,0.15)\'" onmouseout="this.style.borderColor=\'#eee\';this.style.transform=\'none\';this.style.boxShadow=\'none\'" onclick="sendAlbumPhoto(\'' + safeImg + '\', \'' + safeName + '\')">';
                    if (v.image) {
                        albumHtml += '<img src="../' + v.image + '" style="width:100%;height:110px;object-fit:cover;">';
                    } else {
                        albumHtml += '<div style="width:100%;height:110px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;color:#bbb;font-size:0.75rem;">No image</div>';
                    }
                    albumHtml += '<div style="padding:0.5rem;"><div style="font-size:0.78rem;font-weight:600;color:#333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + v.variant_name + '</div>';
                    if (v.size) albumHtml += '<div style="font-size:0.68rem;color:#888;">' + v.size + '</div>';
                    albumHtml += '<div style="margin-top:0.4rem;display:flex;align-items:center;gap:4px;color:#ff6b35;font-size:0.72rem;font-weight:600;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14"/><path d="M5 12h14"/></svg> Add to Chat</div>';
                    albumHtml += '</div></div>';
                });
                albumHtml += '</div>';
            } else {
                albumHtml += '<p style="text-align:center;color:#999;font-size:0.85rem;padding:1rem 0;">No album variants for this product.</p>';
            }
            albumHtml += '</div>';
            
            overlay.innerHTML = albumHtml;
            document.body.appendChild(overlay);
        });
}

function sendAlbumPhoto(imagePath, variantName) {
    if (!imagePath || !selectedId || selectedId === '0') return;
    
    const overlay = document.getElementById('albumOverlay');
    if (overlay) overlay.remove();
    
    
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
            return '<div style="position:relative; display:inline-flex; align-items:center; gap:0.3rem; background:#f9f9f9; border:1px solid #e5e7eb; border-radius:10px; padding:4px 8px;">' +
                '<img src="' + url + '" style="height:40px; width:40px; object-fit:cover; border-radius:8px;">' +
                '<span class="text-xs text-gray-500" style="max-width:70px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' + f.name + '</span>' +
                '<button type="button" onclick="removePhoto(' + i + ')" class="text-gray-400 hover:text-red-500" style="background:none; border:none; cursor:pointer; font-size:1rem; line-height:1;">&times;</button>' +
                '</div>';
        }).join('') +
        '<button type="button" onclick="clearPhotoPreview()" class="text-xs text-red-400 hover:text-red-600" style="background:none; border:none; cursor:pointer; margin-left:4px;">Clear all</button>' +
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

function renderMessage(msg, sender, imagePath) {
    const wrapper = document.createElement('div');
    wrapper.className = 'flex ' + (sender === 'admin' ? 'justify-end' : 'justify-start');
    const bubble = document.createElement('div');
    bubble.className = 'max-w-md px-4 py-2.5 rounded-2xl text-sm ' + (sender === 'admin' ? 'text-white' : 'bg-gray-100 text-gray-800');
    if (sender === 'admin') bubble.style.background = '#ff6b35';
    const time = document.createElement('div');
    time.className = 'text-xs mt-1 ' + (sender === 'admin' ? 'text-orange-100' : 'text-gray-400');
    time.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    let html = '';
    if (imagePath) html += renderImageBubble(imagePath);
    if (msg) html += formatMentions(msg);
    bubble.innerHTML = html;
    bubble.appendChild(time);
    wrapper.appendChild(bubble);
    return wrapper;
}

function makeClickableMentions(container) {
    container.querySelectorAll('span[style*="color:#ff6b35"]').forEach(span => {
        const text = span.textContent.trim();
        if (text.startsWith('@')) {
            span.style.cursor = 'pointer';
            span.title = 'Click to view product album';
            span.addEventListener('click', () => {
                renderProductCard(text.substring(1));
            });
        }
    });
}

function renderPaymentCard(data, sender) {
    const name = data.name || '';
    const desc = data.desc || '';
    const down = parseFloat(data.down || 0).toFixed(2);
    const full = parseFloat(data.full || 0).toFixed(2);
    const link = data.link || '';
    const pStatus = data.payment_status || 'pending';
    const pType = data.payment_type || '';
    const isPaid = pStatus === 'paid';
    const gcashLogoStyle = 'height:12px;width:auto;vertical-align:middle;';
    const downBg = (isPaid && pType === 'down') ? 'linear-gradient(135deg,#d1fae5,#a7f3d0)' : '#f0f7ff';
    const fullBg = (isPaid && pType === 'full') ? 'linear-gradient(135deg,#d1fae5,#a7f3d0)' : '#fff7ed';
    const paidBadge = isPaid ? `<span style="margin-left:auto;background:#10b981;color:#fff;font-size:0.6rem;font-weight:800;padding:2px 8px;border-radius:20px;letter-spacing:0.04em;">PAID</span>` : '';
    let bottomBar = '';
    if (isPaid) {
        const label = pType === 'down' ? 'Down Payment Received ✓' : 'Full Payment Received ✓';
        bottomBar = `<div style="padding:9px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-weight:700;font-size:0.78rem;border-radius:10px;text-align:center;">${label}</div>`;
    } else if (link) {
        bottomBar = `<div style="padding:9px;background:#fef3c7;color:#92400e;font-weight:700;font-size:0.78rem;border-radius:10px;text-align:center;">⏳ Awaiting Payment</div>`;
    }
    return `<div style="max-width:340px;">
        <div style="border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.13);">
            <div style="background:linear-gradient(135deg,#001a4d,#002b80);padding:12px 14px;display:flex;align-items:center;gap:10px;">
                <img src="../download.jpg" alt="GCash" style="height:22px;width:auto;filter:brightness(0) invert(1);">
                <span style="color:#fff;font-weight:700;font-size:0.8rem;">Payment Request</span>
                ${paidBadge}
            </div>
            <div style="background:#fff;padding:14px;">
                <div style="font-weight:700;font-size:0.9rem;color:#111;margin-bottom:2px;">${name}</div>
                ${desc ? `<div style="color:#6b7280;font-size:0.76rem;margin-bottom:10px;">${desc}</div>` : ''}
                <div style="display:flex;gap:8px;margin-bottom:12px;">
                    <div style="flex:1;background:${downBg};border-radius:10px;padding:10px;text-align:center;">
                        <div style="display:flex;align-items:center;justify-content:center;gap:4px;margin-bottom:4px;">
                            <img src="../download.jpg" style="${gcashLogoStyle}">
                            <span style="font-size:0.6rem;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Down Payment</span>
                        </div>
                        <div style="font-size:1rem;font-weight:800;color:#007DFE;">₱${down}</div>
                    </div>
                    <div style="flex:1;background:${fullBg};border-radius:10px;padding:10px;text-align:center;">
                        <div style="font-size:0.6rem;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Full Price</div>
                        <div style="font-size:1rem;font-weight:800;color:#ff6b35;">₱${full}</div>
                    </div>
                </div>
                ${bottomBar}
            </div>
        </div>
    </div>`;
}

function renderBalanceCard(data) {
    const name = data.name || 'Order';
    const full = parseFloat(data.full || 0).toFixed(2);
    const downPaid = parseFloat(data.down_paid || 0).toFixed(2);
    const remaining = parseFloat(data.remaining || 0).toFixed(2);
    const isPaid = data.balance_paid === true;

    let headerBadge = isPaid
        ? '<span style="margin-left:auto;background:#22c55e;color:#fff;font-size:0.58rem;font-weight:800;padding:2px 8px;border-radius:20px;">FULLY PAID</span>'
        : '<span style="margin-left:auto;background:#fbbf24;color:#78350f;font-size:0.58rem;font-weight:800;padding:2px 8px;border-radius:20px;">READY FOR PICKUP</span>';
    let balTileBg = isPaid ? 'linear-gradient(135deg,#d1fae5,#a7f3d0)' : 'linear-gradient(135deg,#fef3c7,#fde68a)';
    let balColor = isPaid ? '#22c55e' : '#d97706';
    let balLabel = isPaid ? 'Balance Paid' : 'Remaining';
    let balLabelColor = isPaid ? '#047857' : '#92400e';
    let descText = isPaid ? 'Customer has fully settled the remaining balance via GCash.' : 'Order is ready for pickup. Customer has remaining balance.';
    let bottomBar = isPaid
        ? `<div style="padding:9px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-weight:700;font-size:0.78rem;border-radius:10px;text-align:center;">Remaining Balance Paid via GCash \u2713</div>`
        : `<div style="display:flex;gap:8px;"><div style="flex:1;padding:9px;background:linear-gradient(135deg,#007DFE,#0056b3);border-radius:10px;text-align:center;"><div style="color:#fff;font-weight:700;font-size:0.68rem;">\ud83d\udcb3 GCash</div></div><div style="flex:1;padding:9px;background:linear-gradient(135deg,#f3f4f6,#e5e7eb);border-radius:10px;text-align:center;"><div style="color:#374151;font-weight:700;font-size:0.68rem;">\ud83c\udfea At Store</div></div></div>`;

    return `<div style="max-width:340px;">
        <div style="border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.13);">
            <div style="background:linear-gradient(135deg,#7c3aed,#5b21b6);padding:12px 14px;display:flex;align-items:center;gap:10px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                <span style="color:#fff;font-weight:700;font-size:0.8rem;">Remaining Balance</span>
                ${headerBadge}
            </div>
            <div style="background:#fff;padding:14px;">
                <div style="font-weight:700;font-size:0.9rem;color:#111;margin-bottom:2px;">\ud83d\udce6 ${name}</div>
                <div style="color:#6b7280;font-size:0.72rem;margin-bottom:10px;">${descText}</div>
                <div style="display:flex;gap:8px;margin-bottom:12px;">
                    <div style="flex:1;background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-radius:10px;padding:10px;text-align:center;">
                        <div style="font-size:0.58rem;color:#047857;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">Down Payment Paid</div>
                        <div style="font-size:0.95rem;font-weight:800;color:#22c55e;">\u20b1${downPaid}</div>
                    </div>
                    <div style="flex:1;background:${balTileBg};border-radius:10px;padding:10px;text-align:center;">
                        <div style="font-size:0.58rem;color:${balLabelColor};font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;">${balLabel}</div>
                        <div style="font-size:0.95rem;font-weight:800;color:${balColor}">\u20b1${remaining}</div>
                    </div>
                </div>
                <div style="font-size:0.68rem;color:#6b7280;font-weight:600;text-align:center;margin-bottom:10px;">Total: \u20b1${full}</div>
                ${bottomBar}
            </div>
        </div>
    </div>`;
}

function openPayModal() {
    document.getElementById('payName').value = '';
    document.getElementById('payDesc').value = '';
    document.getElementById('payDown').value = '';
    document.getElementById('payFull').value = '';
    document.getElementById('payLink').value = '';
    const m = document.getElementById('payModal');
    m.style.display = 'flex';
    setTimeout(() => document.getElementById('payName').focus(), 50);
}

function closePayModal() {
    document.getElementById('payModal').style.display = 'none';
}

function sendPayRequest() {
    const name = document.getElementById('payName').value.trim();
    const desc = document.getElementById('payDesc').value.trim();
    const down = parseFloat(document.getElementById('payDown').value) || 0;
    const full = parseFloat(document.getElementById('payFull').value) || 0;
    const link = document.getElementById('payLink').value.trim();

    if (!name) {
        document.getElementById('payName').focus();
        showToast('Missing Product', 'Please select the product or order before sending the payment request.', 'error');
        return;
    }

    if (down <= 0 && full <= 0) {
        showToast('Missing Price', 'Please enter at least one amount for the down payment or full price.', 'error');
        return;
    }

    const payload = '__PAY__:' + JSON.stringify({ name, desc, down, full, link });
    const fd = new FormData();
    fd.append('action', 'send_reply');
    fd.append('customer_id', selectedId);
    fd.append('message', payload);
    closePayModal();
    fetch('messages.php', { method: 'POST', body: fd })
        .then(async (r) => {
            const text = await r.text();
            try {
                const data = text ? JSON.parse(text) : {};
                if (!r.ok || !data.success) {
                    throw new Error((data && data.error) || 'Failed to send payment request.');
                }
                return data;
            } catch (e) {
                if (text && text.includes('<!DOCTYPE html>')) {
                    throw new Error('Session expired. Please log in again.');
                }
                throw e;
            }
        })
        .then(() => { fetchMessages(); showToast('Sent', 'Payment request sent to customer.', 'success'); })
        .catch((err) => showToast('Error', err.message || 'Failed to send payment request.', 'error'));
}

function fetchMessages() {
    if (isSending || !selectedId || selectedId === '0') return;
    const fd = new FormData();
    fd.append('action', 'fetch_messages');
    fd.append('customer_id', selectedId);
    fetch('messages.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(msgs => {
            if (!msgs || !chat) return;
            chat.innerHTML = '';
            msgs.forEach(m => {
                const wrapper = document.createElement('div');
                wrapper.className = 'flex ' + (m.sender === 'admin' ? 'justify-end' : 'justify-start');
                const timeStr = new Date(m.sent_at).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                
                if (m.message && m.message.startsWith('__PAY__:')) {
                    try {
                        const payData = JSON.parse(m.message.substring(8));
                        payData.payment_status = m.payment_status || 'pending';
                        payData.payment_type = m.payment_type || '';
                        const cardDiv = document.createElement('div');
                        cardDiv.innerHTML = renderPaymentCard(payData, m.sender);
                        const inner = cardDiv.firstChild;
                        
                        const timeEl = document.createElement('div');
                        timeEl.style.cssText = 'font-size:0.65rem;color:#9ca3af;margin-top:4px;text-align:' + (m.sender === 'admin' ? 'right' : 'left') + ';';
                        timeEl.textContent = timeStr;
                        inner.appendChild(timeEl);
                        wrapper.appendChild(inner);
                    } catch(e) {
                        wrapper.innerHTML = '<span style="font-size:0.75rem;color:#9ca3af;">[payment request]</span>';
                    }
                } else if (m.message && m.message.startsWith('__BAL__:')) {
                    try {
                        const balData = JSON.parse(m.message.substring(8));
                        const cardDiv = document.createElement('div');
                        cardDiv.innerHTML = renderBalanceCard(balData);
                        const inner = cardDiv.firstChild;
                        const timeEl = document.createElement('div');
                        timeEl.style.cssText = 'font-size:0.65rem;color:#9ca3af;margin-top:4px;text-align:right;';
                        timeEl.textContent = timeStr;
                        inner.appendChild(timeEl);
                        wrapper.appendChild(inner);
                    } catch(e) {
                        wrapper.innerHTML = '<span style="font-size:0.75rem;color:#9ca3af;">[balance notice]</span>';
                    }
                } else {
                    const bubble = document.createElement('div');
                    bubble.className = 'max-w-md px-4 py-2.5 rounded-2xl text-sm ' + (m.sender === 'admin' ? 'text-white' : 'bg-gray-100 text-gray-800');
                    if (m.sender === 'admin') bubble.style.background = '#ff6b35';
                    let html = '';
                    if (m.image) html += renderImageBubble(m.image);
                    if (m.message) html += formatMentions(m.message);
                    html += '<div class="text-xs mt-1 ' + (m.sender === 'admin' ? 'text-orange-100' : 'text-gray-400') + '">' + timeStr + '</div>';
                    bubble.innerHTML = html;
                    makeClickableMentions(bubble);
                    wrapper.appendChild(bubble);
                }
                chat.appendChild(wrapper);
            });
            chat.scrollTop = chat.scrollHeight;
        })
        .catch(() => {});
}

if (replyInput) {
    replyInput.addEventListener('input', function() {
        const val = this.value;
        const atIdx = val.lastIndexOf('@');
        if (atIdx === -1) { dropdown.style.display = 'none'; return; }
        const afterLastSpace = val.substring(val.lastIndexOf(' ', atIdx - 1) + 1);
        if (!afterLastSpace.startsWith('@') && val.charAt(atIdx - 1) !== undefined && val.charAt(atIdx - 1) !== ' ' && atIdx !== 0) {
            dropdown.style.display = 'none'; return;
        }
        const query = val.substring(atIdx + 1).toLowerCase();
        let matches;
        if (query.length === 0 && val.charAt(val.length - 1) === '@') {
            matches = products;
        } else {
            matches = products.filter(p => p.product_name.toLowerCase().includes(query));
        }
        if (matches.length === 0) { dropdown.style.display = 'none'; return; }
        showMentionDropdown(matches, atIdx);
    });

    replyInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && dropdown.style.display !== 'none') {
            
        }
        if (e.key === 'Escape') dropdown.style.display = 'none';
    });
}

function showMentionDropdown(items, atIdx) {
    dropdown.innerHTML = '';
    items.forEach(p => {
        const item = document.createElement('div');
        item.style.cssText = 'padding:10px 14px; cursor:pointer; font-size:0.85rem; color:#333; border-bottom:1px solid #f0f0f0; display:flex; align-items:center; gap:10px; transition:background 0.15s;';
        let imgHtml = '';
        if (p.img) {
            imgHtml = '<img src="../' + p.img + '" style="width:32px;height:32px;border-radius:8px;object-fit:cover;flex-shrink:0;">';
        } else {
            imgHtml = '<div style="width:32px;height:32px;border-radius:8px;background:#f0f0f0;flex-shrink:0;display:flex;align-items:center;justify-content:center;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div>';
        }
        item.innerHTML = imgHtml + '<div><span style="font-weight:600;color:#ff6b35;">@</span>' + p.product_name + '</div>';
        item.addEventListener('mouseenter', () => item.style.background = '#fff7ed');
        item.addEventListener('mouseleave', () => item.style.background = 'transparent');
        item.addEventListener('click', () => {
            replyInput.value = replyInput.value.substring(0, atIdx) + '@' + p.product_name + ' ';
            dropdown.style.display = 'none';
            replyInput.focus();
        });
        dropdown.appendChild(item);
    });
    dropdown.style.display = items.length ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
    if (dropdown && !dropdown.contains(e.target) && e.target !== replyInput) dropdown.style.display = 'none';
});

function sendReply(e) {
    e.preventDefault();
    const msg = replyInput.value.trim();
    if (!msg && pendingPhotos.length === 0) return;
    replyInput.value = '';
    dropdown.style.display = 'none';
    isSending = true;

    
    if (pendingPhotos.length > 0) {
        const wrapper = document.createElement('div');
        wrapper.className = 'flex justify-end';
        const bubble = document.createElement('div');
        bubble.className = 'max-w-md px-4 py-2.5 rounded-2xl text-sm text-white';
        bubble.style.background = '#ff6b35';
        let html = '';
        pendingPhotos.forEach(function(f) {
            const localUrl = URL.createObjectURL(f);
            html += '<a href="' + localUrl + '" target="_blank" style="display:inline-block;margin:0 4px 4px 0;"><img src="' + localUrl + '" style="max-width:220px; max-height:180px; border-radius:10px; object-fit:cover; cursor:pointer;" loading="lazy"></a>';
        });
        if (msg) html += formatMentions(msg);
        html += '<div class="text-xs mt-1 text-orange-100">' + new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) + '</div>';
        bubble.innerHTML = html;
        makeClickableMentions(bubble);
        wrapper.appendChild(bubble);
        chat.appendChild(wrapper);
    } else {
        const el = renderMessage(msg, 'admin', null);
        makeClickableMentions(el);
        chat.appendChild(el);
    }
    chat.scrollTop = chat.scrollHeight;

    const fd = new FormData();
    fd.append('action', 'send_reply');
    fd.append('customer_id', selectedId);
    fd.append('message', msg);
    pendingPhotos.forEach(function(f) { fd.append('photos[]', f); });
    
    clearPhotoPreview();

    fetch('messages.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(() => { isSending = false; replyInput.focus(); showToast('Message Sent', 'Your reply has been sent.', 'success'); })
        .catch(() => { isSending = false; replyInput.focus(); showToast('Error', 'Failed to send message.', 'error'); });
}

if (chat) makeClickableMentions(chat);

if (selectedId && selectedId !== '0') {
    setInterval(fetchMessages, 5000);
}
</script>
</body>
</html>

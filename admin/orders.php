<?php
require_once __DIR__ . '/../session_bootstrap.php';
ini_set('display_errors', '0');
require_once __DIR__ . '/../security_headers.php';
require_once '../db_conn.php';
require_once __DIR__ . '/../size_chart_helper.php';
require_once 'check_admin.php';
require_once '../csrf_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_estimate') {
    require_csrf_token();
    header('Content-Type: application/json');
    $orderId = (int)($_POST['order_id'] ?? 0);
    $batchId = trim((string)($_POST['batch_id'] ?? ''));
    $completionDate = trim((string)($_POST['completion_date'] ?? ''));

    if ($orderId > 0) {
        $estimateStmt = $conn->prepare('SELECT id, order_id, customer_id, batch_id, date_created, status FROM orders WHERE id = ? LIMIT 1');
        $estimateStmt->bind_param('i', $orderId);
    } elseif ($batchId !== '') {
        $estimateStmt = $conn->prepare('SELECT id, order_id, customer_id, batch_id, date_created, status FROM orders WHERE batch_id = ? ORDER BY id ASC LIMIT 1');
        $estimateStmt->bind_param('s', $batchId);
    } else {
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    $estimateStmt->execute();
    $order = $estimateStmt->get_result()->fetch_assoc();

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }
    if (in_array($order['status'], ['Completed', 'Cancelled'], true)) {
        echo json_encode(['success' => false, 'message' => 'Completion estimates cannot be changed for completed or cancelled orders.']);
        exit;
    }
    $parsedCompletionDate = DateTime::createFromFormat('!Y-m-d', $completionDate);
    if ($completionDate === '' || !$parsedCompletionDate || $parsedCompletionDate->format('Y-m-d') !== $completionDate) {
        echo json_encode(['success' => false, 'message' => 'Enter a valid completion date.']);
        exit;
    }
    if ($parsedCompletionDate < new DateTimeImmutable('today')) {
        echo json_encode(['success' => false, 'message' => 'Completion date cannot be in the past.']);
        exit;
    }

    if (!empty($order['batch_id'])) {
        $stmt = $conn->prepare('UPDATE orders SET estimated_working_days = NULL, estimated_finish_date = ?, updated_at = NOW() WHERE batch_id = ?');
        $stmt->bind_param('ss', $completionDate, $order['batch_id']);
    } else {
        $stmt = $conn->prepare('UPDATE orders SET estimated_working_days = NULL, estimated_finish_date = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $completionDate, $order['id']);
    }
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Unable to update the order estimate.']);
        exit;
    }
    echo json_encode(['success' => true, 'estimated_finish_date' => $completionDate]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    require_csrf_token();
    header('Content-Type: application/json');
    $id = (int)$_POST['order_id'];
    $status = $_POST['status'];
    $allowed = ['In Cart','Processing','Production','Ready for Pickup','Completed','Cancelled'];
    if (!in_array($status, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }
    
    $orderInfoStmt = $conn->prepare('SELECT order_id, customer_id, product_name, variant_name, batch_id FROM orders WHERE id = ? LIMIT 1');
    $orderInfoStmt->bind_param('i', $id);
    $orderInfoStmt->execute();
    $orderInfo = $orderInfoStmt->get_result()->fetch_assoc();
    if (!$orderInfo) {
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }
    $currentStatusRow = ['status' => $conn->query("SELECT status FROM orders WHERE id = $id")->fetch_assoc()['status'] ?? ''];
    $statusChanged = $currentStatusRow && $currentStatusRow['status'] !== $status;
    $statusFlow = ['In Cart', 'Processing', 'Production', 'Ready for Pickup', 'Completed'];
    if ($currentStatusRow && $currentStatusRow['status'] === 'Completed') {
        echo json_encode(['success' => false, 'message' => 'Completed orders cannot be changed']);
        exit;
    }
    if ($currentStatusRow && in_array($currentStatusRow['status'], $statusFlow, true) && in_array($status, $statusFlow, true)
        && array_search($status, $statusFlow, true) < array_search($currentStatusRow['status'], $statusFlow, true)) {
        echo json_encode(['success' => false, 'message' => 'Order status cannot move backwards']);
        exit;
    }
    $batchId = $orderInfo['batch_id'] ?? null;
    $headerId = (int)$orderInfo['order_id'];

    $scheduledRelease = !empty($_POST['scheduled_release']) ? $_POST['scheduled_release'] : null;
    if ($status === 'Completed' && ($_POST['payment_completed'] ?? '') === '1') {
        if ($batchId) {
            $paidStmt = $conn->prepare('UPDATE orders SET down_payment_amount = price * quantity WHERE batch_id = ?');
            $paidStmt->bind_param('s', $batchId);
        } else {
            $paidStmt = $conn->prepare('UPDATE orders SET down_payment_amount = price * quantity WHERE id = ?');
            $paidStmt->bind_param('i', $id);
        }
        if (!$paidStmt || !$paidStmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Unable to update order payment.']);
            exit;
        }
    }
    if ($scheduledRelease && $batchId) {
        $stmt = $conn->prepare('UPDATE orders SET status = ?, scheduled_release = ?, updated_at = NOW() WHERE batch_id = ?');
        $stmt->bind_param('sss', $status, $scheduledRelease, $batchId);
    } elseif ($scheduledRelease) {
        $stmt = $conn->prepare('UPDATE orders SET status = ?, scheduled_release = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('ssi', $status, $scheduledRelease, $id);
    } elseif ($batchId) {
        $stmt = $conn->prepare('UPDATE orders SET status = ?, updated_at = NOW() WHERE batch_id = ?');
        $stmt->bind_param('ss', $status, $batchId);
    } else {
        $stmt = $conn->prepare('UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $status, $id);
    }
    if (!$stmt || !$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Unable to update order status.']);
        exit;
    }
    
    
    try {
    if ($orderInfo) {
        require_once '../notifications_helper.php';
        $varLabel = !empty($orderInfo['variant_name']) ? ' (' . $orderInfo['variant_name'] . ')' : '';
        createNotification($conn, 'customer', $orderInfo['customer_id'], 'status', 'Order ' . $status, 'Your order for ' . $orderInfo['product_name'] . $varLabel . ' is now ' . $status, 'customer_orders.php');

        
        if ($status === 'Completed') {
            $custId = (int)$orderInfo['customer_id'];
            $pName = $orderInfo['product_name'];
            $celebMsg = "🎉🎊 Congratulations! Your order for {$pName} is now COMPLETED!\n\n" .
                "Thank you so much for choosing Zian's PrintShop! We truly appreciate your trust in us. 🧡\n\n" .
                "We hope you love your order! If you need anything else, don't hesitate to reach out. We'd love to work with you again! 🙌\n\n" .
                "— Zian's PrintShop Team ✨";
            $celebStmt = $conn->prepare("INSERT INTO messages (customer_id, sender, message, sent_at) VALUES (?, 'admin', ?, NOW())");
            $celebStmt->bind_param('is', $custId, $celebMsg);
            $celebStmt->execute();

            createNotification($conn, 'customer', $custId, 'status', '🎉 Order Completed!', 'Your order for ' . $pName . ' is done! Thank you for choosing Zian\'s PrintShop!', 'customer_orders.php');
        }

        
        if ($status === 'Ready for Pickup') {
            $custId = (int)$orderInfo['customer_id'];
            $batchIdForBalance = $orderInfo['batch_id'] ?? '';
            if ($batchIdForBalance !== '') {
                $financialStmt = $conn->prepare('SELECT product_name, SUM(price * quantity) AS full_price, SUM(down_payment_amount) AS down_paid FROM orders WHERE customer_id = ? AND batch_id = ?');
                $financialStmt->bind_param('is', $custId, $batchIdForBalance);
            } else {
                $financialStmt = $conn->prepare('SELECT product_name, SUM(price * quantity) AS full_price, SUM(down_payment_amount) AS down_paid FROM orders WHERE customer_id = ? AND id = ?');
                $financialStmt->bind_param('ii', $custId, $id);
            }
            $financialStmt->execute();
            $finRow = $financialStmt->get_result()->fetch_assoc();
            if ($finRow) {
                $fullPrice = (float)$finRow['full_price'];
                $downPaid = min($fullPrice, (float)$finRow['down_paid']);
                $remaining = max(0, $fullPrice - $downPaid);
                if ($downPaid > 0 && $remaining > 0) {
                    $pName = $finRow['product_name'] ?: $orderInfo['product_name'];

                    
                    $existingBalanceMessages = $conn->prepare("SELECT message FROM messages WHERE customer_id = ? AND sender = 'admin' AND message LIKE '__BAL__:%' ORDER BY id DESC");
                    $existingBalanceMessages->bind_param('i', $custId);
                    $existingBalanceMessages->execute();
                    $balanceNoticeExists = false;
                    $existingResult = $existingBalanceMessages->get_result();
                    while ($existing = $existingResult->fetch_assoc()) {
                        $existingData = json_decode(substr($existing['message'], 8), true);
                        if (!$existingData) continue;
                        $sameBatch = $batchIdForBalance !== '' && ($existingData['batch_id'] ?? '') === $batchIdForBalance;
                        $sameOrder = $batchIdForBalance === '' && (int)($existingData['order_id'] ?? 0) === $id;
                        if ($sameBatch || $sameOrder) {
                            $balanceNoticeExists = true;
                            break;
                        }
                    }

                    if (!$balanceNoticeExists) {
                        $balJson = json_encode([
                            'name' => $pName,
                            'full' => $fullPrice,
                            'down_paid' => $downPaid,
                            'remaining' => $remaining,
                            'order_id' => $id,
                            'batch_id' => $batchIdForBalance
                        ]);
                        $balMsg = '__BAL__:' . $balJson;
                        $balStmt = $conn->prepare("INSERT INTO messages (customer_id, sender, message, sent_at) VALUES (?, 'admin', ?, NOW())");
                        $balStmt->bind_param('is', $custId, $balMsg);
                        $balStmt->execute();

                        $fmtRemaining = number_format($remaining, 2);
                        $plainMessage = 'Your order for ' . $pName . ' is ready for pickup. Your down payment of ₱' . number_format($downPaid, 2) . ' has been received. The remaining balance is ₱' . $fmtRemaining . '. You can pay the balance via GCash in this chat or at the store when you pick up your order.';
                        $plainStmt = $conn->prepare("INSERT INTO messages (customer_id, sender, message, sent_at) VALUES (?, 'admin', ?, NOW())");
                        $plainStmt->bind_param('is', $custId, $plainMessage);
                        $plainStmt->execute();

                        createNotification($conn, 'customer', $custId, 'payment', '💳 Remaining Balance', 'Your order for ' . $pName . ' is ready for pickup! Remaining balance: ₱' . $fmtRemaining . '. Pay via GCash or at the store.', 'customer_messages.php');
                    }
                }
            }
        }

        
        require_once '../classes/HttpSmsGateway.php';
        $smsGateway = new HttpSmsGateway($conn);
        $customer = $conn->query("SELECT username, phone FROM customers WHERE customer_id = " . (int)$orderInfo['customer_id'])->fetch_assoc();
        $smsConf = $smsGateway->getConfiguration();
        
        if ($customer && !empty($customer['phone']) && !empty($smsConf['gateway_type'])) {
            $custPhone = $customer['phone'];
            $custName = $customer['username'];
            $prodName = $orderInfo['product_name'] . $varLabel;
            
            if ($status === 'Ready for Pickup') {
                $remaining = 0;
                if (!empty($orderInfo['batch_id'])) {
                    $smsFinStmt = $conn->prepare('SELECT SUM(price * quantity) AS full_price, SUM(down_payment_amount) AS down_paid FROM orders WHERE customer_id = ? AND batch_id = ?');
                    $smsFinStmt->bind_param('is', $orderInfo['customer_id'], $orderInfo['batch_id']);
                } else {
                    $smsFinStmt = $conn->prepare('SELECT SUM(price * quantity) AS full_price, SUM(down_payment_amount) AS down_paid FROM orders WHERE customer_id = ? AND id = ?');
                    $smsFinStmt->bind_param('ii', $orderInfo['customer_id'], $id);
                }
                $smsFinStmt->execute();
                $smsFinRow = $smsFinStmt->get_result()->fetch_assoc();
                if ($smsFinRow && (float)$smsFinRow['down_paid'] > 0) {
                    $remaining = max(0, (float)$smsFinRow['full_price'] - (float)$smsFinRow['down_paid']);
                }
                if ($remaining > 0) {
                    $smsMsg = "Hello {$custName}! Your order for {$prodName} is ready for pickup. Remaining balance: ₱" . number_format($remaining, 2) . ". - Zian's PrintShop";
                } else {
                    $smsMsg = "Hello {$custName}! Your order for {$prodName} is ready for pickup. - Zian's PrintShop";
                }
            } else if ($status === 'Completed') {
                $smsMsg = "Hello {$custName}! Your order for {$prodName} is completed. Thank you for choosing Zian's PrintShop! 🧡";
            } else {
                $smsMsg = "Hello {$custName}! Your order for {$prodName} is now {$status}. - Zian's PrintShop";
            }
            
            $smsGateway->sendSMS($custPhone, $smsMsg);
        }
    }
    } catch (Throwable $notificationError) {
        error_log('Order status notification failed: ' . $notificationError->getMessage());
    }
    
    echo json_encode(['success' => true]);
    exit;
}

$ordersRes = $conn->query("SELECT co.*, p.img AS product_image, c.username as customer_name, c.email as customer_email, c.phone as customer_phone, c.address as customer_address FROM orders co LEFT JOIN products p ON p.record_type='product' AND co.product_id = p.product_id LEFT JOIN customers c ON co.customer_id = c.customer_id ORDER BY co.date_created DESC");
$rawOrders = [];
while ($row = $ordersRes->fetch_assoc()) $rawOrders[] = $row;

$batchMap = [];
foreach ($rawOrders as $o) {
    $key = !empty($o['batch_id']) ? $o['batch_id'] : 'solo_' . $o['id'];
    if (!isset($batchMap[$key])) {
        $batchMap[$key] = [
            'first_id'            => $o['id'],
            'batch_id'            => $o['batch_id'] ?? null,
            'customer_name'       => $o['customer_name'],
            'customer_email'      => $o['customer_email'],
            'customer_phone'      => $o['customer_phone'],
            'customer_address'    => $o['customer_address'],
            'product_name'        => $o['product_name'],
            'product_image'       => $o['product_image'] ?? '',
            'image'               => $o['image'],
            'payment_mode'        => $o['payment_mode'],
            'gcash_reference'     => $o['gcash_reference'],
            'down_payment_amount' => $o['down_payment_amount'],
            'order_notes'         => $o['order_notes'],
            'status'              => $o['status'],
            'date_created'          => $o['date_created'],
            'scheduled_release'   => $o['scheduled_release'],
            'estimated_finish_date' => $o['estimated_finish_date'] ?? null,
            'items'               => [],
        ];
    }
    $fitDetails = parseOrderedFitDetails((string)($o['size'] ?? ''));
    $batchMap[$key]['items'][] = [
        'variant_name' => $o['variant_name'] ?? '',
        'size'         => $fitDetails['size'],
        'recommended_size' => $fitDetails['recommended_size'],
        'fit_measurements' => $fitDetails['measurements'],
        'quantity'     => $o['quantity'],
        'price'        => $o['price'],
        'payment_amount' => $o['down_payment_amount'],
    ];
}
$orders = array_values($batchMap);
$statusCounts = ['all' => count($orders), 'In Cart' => 0, 'Processing' => 0, 'Production' => 0, 'Ready for Pickup' => 0, 'Completed' => 0, 'Cancelled' => 0];
foreach ($orders as $order) {
    if (isset($statusCounts[$order['status']])) $statusCounts[$order['status']]++;
}
foreach ($orders as &$order) {
    $allItemsFullyPaid = !empty($order['items']);
    $paymentTotal = 0;
    foreach ($order['items'] as $item) {
        $itemTotal = (float)$item['price'] * (int)$item['quantity'];
        $itemPaid = (float)$item['payment_amount'];
        $paymentTotal += $itemPaid;
        if ($itemTotal <= 0 || $itemPaid < $itemTotal) $allItemsFullyPaid = false;
    }
    $groupTotal = 0;
    foreach ($order['items'] as $item) {
        $groupTotal += (float)$item['price'] * (int)$item['quantity'];
    }
    $order['payment_total'] = $groupTotal > 0 ? min($paymentTotal, $groupTotal) : $paymentTotal;
}
unset($order);

function getStatusBadge($status) {
    $map = [
        'In Cart'          => 'badge-incart',
        'Processing'       => 'badge-processing',
        'Production'       => 'badge-production',
        'Ready for Pickup' => 'badge-ready',
        'Completed'        => 'badge-completed',
        'Cancelled'        => 'badge-cancelled',
    ];
    return $map[$status] ?? 'badge-pending';
}

function isStatusBefore($status, $currentStatus) {
    $flow = ['In Cart', 'Processing', 'Production', 'Ready for Pickup', 'Completed'];
    $statusIndex = array_search($status, $flow, true);
    $currentIndex = array_search($currentStatus, $flow, true);
    return $statusIndex !== false && $currentIndex !== false && $statusIndex < $currentIndex;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - Zian's PrintShop Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/admin.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    body { font-family: 'Inter', sans-serif; }
    .orders-table-wrap { background: #fff; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04); overflow: hidden; border: 1px solid #f0f0f0; }
    .orders-table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .orders-table thead th { background: #f8fafc; padding: 12px 16px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; border-bottom: 2px solid #e2e8f0; text-align: left; white-space: nowrap; }
    .orders-table tbody tr { transition: all 0.15s ease; }
    .orders-table tbody tr.completed-row,
    .orders-table tbody tr.completed-row:hover { background: #f1f5f9; filter: grayscale(0.7); }
    .orders-table tbody tr.completed-row > td { color: #94a3b8; opacity: 0.78; }
    .orders-table tbody tr.completed-row .order-id-chip,
    .orders-table tbody tr.completed-row .customer-info .name,
    .orders-table tbody tr.completed-row .product-cell .prod-name,
    .orders-table tbody tr.completed-row .num-cell,
    .orders-table tbody tr.completed-row .price-cell,
    .orders-table tbody tr.completed-row .release-cell,
    .orders-table tbody tr.completed-row .date-cell { color: #94a3b8; }
    .orders-table tbody tr.completed-row .product-cell .variant-line,
    .orders-table tbody tr.completed-row .pmt-cell .pmt-amount { color: #a8b2bd; }
    .orders-table tbody tr.completed-row .pmt-cell .pmt-type,
    .orders-table tbody tr.completed-row .pmt-cell .pmt-ref,
    .orders-table tbody tr.completed-row .status-pill,
    .orders-table tbody tr.completed-row .action-select { opacity: 0.65; filter: grayscale(1); }
    .orders-table tbody tr.completed-row .action-select { background: #e2e8f0; color: #64748b; }
    .orders-table tbody tr.completed-row .details-button { color: #2563eb; opacity: 1; }
    .orders-table tbody tr:hover { background: #fafbff; }
    .orders-table tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 0.85rem; }
    .orders-table tbody tr:last-child td { border-bottom: none; }

    .order-id-chip { font-weight: 700; color: #1e293b; font-size: 0.82rem; }
    .order-id-chip .multi-badge { display: inline-block; background: #fff3ed; color: #ea580c; font-size: 0.6rem; font-weight: 700; padding: 1px 6px; border-radius: 8px; margin-left: 4px; vertical-align: middle; }
    .customer-info .name { font-weight: 600; color: #1e293b; font-size: 0.82rem; }
    .customer-info .email { color: #94a3b8; font-size: 0.72rem; }
    .product-cell .prod-name { font-weight: 600; color: #1e293b; }
    .product-variants-scroll { max-height: 116px; overflow-y: auto; padding-right: 5px; scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
    .product-variants-scroll::-webkit-scrollbar { width: 5px; }
    .product-variants-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; }
    .product-cell .variant-line { font-size: 0.72rem; color: #ea580c; margin-top: 2px; }
    .product-cell .variant-line .meta { color: #94a3b8; }
    .product-cell .ref-thumb { width: 36px; height: 36px; object-fit: cover; border-radius: 8px; border: 1.5px solid #e2e8f0; cursor: pointer; margin-top: 4px; transition: transform 0.15s; }
    .product-cell .ref-thumb:hover { transform: scale(1.15); border-color: #ff6b35; }
    .product-cell .ref-count { font-size: 0.6rem; color: #94a3b8; }

    .num-cell { font-weight: 600; color: #334155; text-align: center; }
    .price-cell { font-weight: 700; color: #1e293b; white-space: nowrap; }
    .pmt-cell .pmt-amount { font-weight: 700; color: #16a34a; font-size: 0.78rem; }
    .pmt-cell .pmt-ref { font-size: 0.65rem; color: #94a3b8; }
    .pmt-cell .pmt-type { font-size: 0.62rem; font-weight: 700; color: #64748b; margin-bottom: 2px; }
    .release-cell { font-size: 0.78rem; color: #64748b; }
    .date-cell { font-size: 0.78rem; color: #94a3b8; }
    .notes-chip { display: inline-flex; align-items: center; gap: 3px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 2px 8px; font-size: 0.65rem; color: #64748b; cursor: help; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 4px; }

    
    .status-pill { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
    .status-pill .status-dot { width: 7px; height: 7px; border-radius: 50%; }
    .status-pill.s-incart { background: #f1f5f9; color: #64748b; }
    .status-pill.s-incart .status-dot { background: #94a3b8; }
    .status-pill.s-processing { background: #eff6ff; color: #2563eb; }
    .status-pill.s-processing .status-dot { background: #3b82f6; }
    .status-pill.s-production { background: #fffbeb; color: #d97706; }
    .status-pill.s-production .status-dot { background: #f59e0b; }
    .status-pill.s-ready { background: #f5f3ff; color: #7c3aed; }
    .status-pill.s-ready .status-dot { background: #8b5cf6; }
    .status-pill.s-completed { background: #f0fdf4; color: #16a34a; }
    .status-pill.s-completed .status-dot { background: #22c55e; }
    .status-pill.s-cancelled { background: #fef2f2; color: #dc2626; }
    .status-pill.s-cancelled .status-dot { background: #ef4444; }

    .action-select { font-size: 0.75rem; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 5px 10px; background: #fff; cursor: pointer; transition: all 0.15s; font-weight: 500; color: #334155; font-family: inherit; }
    .action-select:focus { outline: none; border-color: #ff6b35; box-shadow: 0 0 0 3px rgba(255,107,53,0.12); }
    .action-select:hover { border-color: #cbd5e1; }
    .action-select:disabled { background: #f1f5f9; border-color: #e2e8f0; color: #94a3b8; cursor: not-allowed; opacity: 0.75; }
    .action-select option:disabled { color: #94a3b8; background: #f1f5f9; }

    .orders-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
    .orders-header h1 { font-size: 1.5rem; font-weight: 800; color: #0f172a; }
    .orders-header .subtitle { color: #94a3b8; font-size: 0.85rem; margin-top: 2px; }
    .orders-count { background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px 14px; border-radius: 10px; font-size: 0.8rem; font-weight: 600; color: #64748b; }
    .orders-view-controls { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
    .orders-status-filter { border: 1px solid #e2e8f0; border-radius: 10px; padding: 7px 32px 7px 12px; background: #fff; color: #475569; font-size: 0.78rem; font-weight: 600; font-family: inherit; cursor: pointer; }
    .orders-status-filter:focus { outline: none; border-color: #ff6b35; box-shadow: 0 0 0 3px rgba(255,107,53,0.12); }
    .orders-status-tabs { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 18px; }
    .orders-status-tab { border:1px solid #dbe4ef; background:#fff; color:#475569; border-radius:999px; padding:7px 13px; font-size:.72rem; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s ease; }
    .orders-status-tab:hover { border-color:#ffb08f; color:#ea580c; }
    .orders-status-tab.active { border-color:#ff6b35; background:#ff6b35; color:#fff; box-shadow:0 4px 10px rgba(255,107,53,.18); }
    .orders-status-tab-count { margin-left:3px; opacity:.8; }
    .orders-pagination { display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-wrap:wrap; background:#fff; border-top:1px solid #e2e8f0; padding:14px 16px; }
    .orders-pagination button { border:1px solid #e2e8f0; background:#fff; color:#475569; border-radius:8px; min-width:34px; height:34px; padding:0 10px; font-size:0.75rem; font-weight:700; cursor:pointer; transition:all 0.15s ease; }
    .orders-pagination button:hover:not(:disabled) { border-color:#cbd5e1; background:#f8fafc; }
    .orders-pagination button:disabled { opacity:0.45; cursor:not-allowed; }
    .orders-pagination button.is-active { background:#ff6b35; border-color:#ff6b35; color:#fff; box-shadow:0 8px 18px rgba(255,107,53,0.18); }
    .orders-pagination .page-info { color:#64748b; font-size:0.75rem; font-weight:600; margin-right:8px; }
    .status-breakdown { margin:0 0 18px; padding:14px 16px; background:#fff; border:1px solid #e2e8f0; border-radius:14px; }
    .status-breakdown-title { font-size:0.82rem; font-weight:800; color:#0f172a; margin-bottom:10px; }
    .status-breakdown-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:8px; }
    .status-breakdown-item { padding:9px 10px; border-radius:10px; background:#f8fafc; }
    .status-breakdown-name { font-size:0.68rem; font-weight:800; color:#1e293b; }
    .status-breakdown-text { font-size:0.64rem; line-height:1.35; color:#64748b; margin-top:3px; }
    @media (max-width: 900px) { .status-breakdown-grid { grid-template-columns:repeat(2,1fr); } }
    .details-button { font-size: 0.72rem; color: #2563eb; font-weight: 600; background: transparent; border: 0; padding: 2px 0; cursor: pointer; text-align: left; }
    .details-button:hover { color: #1d4ed8; text-decoration: underline; }
    .details-modal { display: none; position: fixed; inset: 0; z-index: 9999; align-items: center; justify-content: center; padding: 1rem; background: rgba(15,23,42,0.55); backdrop-filter: blur(4px); }
    .details-panel { width: 100%; max-width: 620px; max-height: 90vh; overflow-y: auto; background: #fff; border-radius: 18px; box-shadow: 0 24px 70px rgba(15,23,42,0.25); }
    .details-header { display: flex; align-items: center; justify-content: space-between; padding: 1.25rem 1.4rem; border-bottom: 1px solid #e2e8f0; }
    .details-title { font-size: 1.05rem; font-weight: 800; color: #0f172a; }
    .details-close { border: 0; background: #f1f5f9; color: #64748b; width: 30px; height: 30px; border-radius: 50%; cursor: pointer; font-size: 1.1rem; }
    .details-body { padding: 1.25rem 1.4rem 1.4rem; }
    .details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; margin-bottom: 1.1rem; }
    .details-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.7rem 0.8rem; }
    .details-label { color: #94a3b8; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.2rem; }
    .details-value { color: #1e293b; font-size: 0.8rem; font-weight: 600; word-break: break-word; }
    .details-section-title { color: #64748b; font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; margin: 1rem 0 0.45rem; }
    .details-items { border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
    .details-order-item { display: flex; justify-content: space-between; gap: 1rem; padding: 0.65rem 0.8rem; border-bottom: 1px solid #f1f5f9; font-size: 0.78rem; color: #334155; }
    .details-order-item:last-child { border-bottom: 0; }
    .details-payment { color: #16a34a; font-weight: 700; }
    @media (max-width: 540px) { .details-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="bg-gray-50 admin-page">

<?php include 'sidebar.php'; ?>

<main class="ml-64 p-8 min-h-screen">
    <div class="orders-header">
        <div>
            <h1>Orders</h1>
            <p class="subtitle">Manage all customer orders</p>
        </div>
        <div class="orders-view-controls">
            <div class="orders-count"><?= count($orders) ?> orders</div>
            <label>
                <span class="sr-only">Filter orders by status</span>
                <select id="orderStatusFilter" class="orders-status-filter" onchange="filterOrdersByStatus(this.value)">
                    <option value="all">All Orders</option>
                    <option value="In Cart">In Cart</option>
                    <option value="Processing">Processing</option>
                    <option value="Production">Production</option>
                    <option value="Ready for Pickup">Ready for Pickup</option>
                    <option value="Completed">Completed</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </label>
        </div>
    </div>

    <div class="orders-status-tabs" aria-label="Order status categories">
        <?php foreach (['all' => 'All', 'In Cart' => 'In Cart', 'Processing' => 'Processing', 'Production' => 'Production', 'Ready for Pickup' => 'Ready for Pickup', 'Completed' => 'Completed', 'Cancelled' => 'Cancelled'] as $statusValue => $statusLabel): ?>
            <button type="button" class="orders-status-tab<?= $statusValue === 'all' ? ' active' : '' ?>" data-status-tab="<?= htmlspecialchars($statusValue, ENT_QUOTES) ?>" onclick="selectOrderStatusTab(this.dataset.statusTab)">
                <?= htmlspecialchars($statusLabel) ?><span class="orders-status-tab-count">(<?= (int)$statusCounts[$statusValue] ?>)</span>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="orders-table-wrap">
        <table class="orders-table admin-table">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Product / Variants</th>
                    <th style="text-align:center;">Qty</th>
                    <th>Total Price</th>
                    <th>Payment</th>
                    <th>Release</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="10" class="text-center text-gray-400 py-8">No orders found</td></tr>
                <?php else: ?>
                    <?php foreach ($orders as $o):
                        $totalQty   = array_sum(array_column($o['items'], 'quantity'));
                        $totalPrice = array_sum(array_map(fn($it) => (float)$it['price'] * (int)$it['quantity'], $o['items']));
                        $isMulti    = count($o['items']) > 1;
                        $paymentAmount = (float)($o['payment_total'] ?? $o['down_payment_amount'] ?? 0);
                        $paymentType = $paymentAmount <= 0
                            ? 'No Payment'
                            : ($totalPrice > 0 && $paymentAmount >= $totalPrice ? 'Full Payment' : 'Down Payment');
                        $firstImg   = trim(explode(',', $o['image'] ?? '')[0] ?? '');
                        $allImgs    = array_filter(array_map('trim', explode(',', $o['image'] ?? '')));
                    ?>
                    <tr class="<?= $o['status'] === 'Completed' ? 'completed-row' : '' ?>" data-order-status="<?= htmlspecialchars($o['status'], ENT_QUOTES) ?>">
                        <td>
                            <span class="order-id-chip">ORD-<?= str_pad($o['first_id'], 3, '0', STR_PAD_LEFT) ?></span>
                            <?php if ($isMulti): ?><span class="order-id-chip"><span class="multi-badge"><?= count($o['items']) ?> items</span></span><?php endif; ?>
                        </td>
                        <td>
                            <div class="customer-info">
                                <div class="name"><?= htmlspecialchars($o['customer_name'] ?: 'Unknown') ?></div>
                                <div class="email"><?= htmlspecialchars($o['customer_email'] ?? '') ?></div>
                            </div>
                        </td>
                        <td>
                            <div class="product-cell">
                                <div class="prod-name"><?= htmlspecialchars($o['product_name']) ?></div>
                                <div class="product-variants-scroll">
                                    <?php foreach ($o['items'] as $it): ?>
                                        <div class="variant-line">
                                            <?= htmlspecialchars($it['variant_name'] ?: 'Custom') ?>
                                            <?php if (!empty($it['size'])): ?><span class="meta"> — <?= htmlspecialchars($it['size']) ?></span><?php endif; ?>
                                            <?php if (!empty($it['recommended_size'])): ?><span class="meta"> · Fit: <?= htmlspecialchars($it['recommended_size']) ?></span><?php endif; ?>
                                            <span class="meta"> ×<?= (int)$it['quantity'] ?></span>
                                            <?php if ($it['price'] > 0): ?><span class="meta"> @ ₱<?= number_format($it['price'],2) ?></span><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($firstImg): ?>
                                    <div style="display:flex;align-items:center;gap:4px;margin-top:4px;">
                                        <img src="../<?= htmlspecialchars($firstImg) ?>" class="ref-thumb" onclick="viewPhoto('../<?= htmlspecialchars($firstImg) ?>')">
                                        <?php if (count($allImgs) > 1): ?><span class="ref-count">+<?= count($allImgs)-1 ?> more</span><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="num-cell"><?= $totalQty ?></td>
                        <td class="price-cell"><?= $totalPrice > 0 ? '₱'.number_format($totalPrice,2) : '<span style="color:#cbd5e1;">—</span>' ?></td>
                        <td>
                            <div class="pmt-cell">
                            <?php if ($paymentAmount > 0): ?>
                                <div class="pmt-type"><?= $paymentType ?></div>
                                <div class="pmt-amount">₱<?= number_format($paymentAmount,2) ?></div>
                                <?php if (!empty($o['gcash_reference'])): ?>
                                    <div class="pmt-ref">Ref: <?= htmlspecialchars($o['gcash_reference']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="pmt-type"><?= $paymentType ?></div>
                            <?php endif; ?>
                            </div>
                        </td>
                        <td class="release-cell">
                            <?php if (!empty($o['estimated_finish_date'])): ?>
                                <div><?= date('M d, Y', strtotime($o['estimated_finish_date'])) ?></div>
                                <?php if (!empty($o['scheduled_release'])): ?><small>Release: <?= date('M d, Y', strtotime($o['scheduled_release'])) ?></small><?php endif; ?>
                            <?php elseif (!empty($o['scheduled_release'])): ?>
                                <?= date('M d, Y', strtotime($o['scheduled_release'])) ?>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $pillMap = ['In Cart'=>'s-incart','Processing'=>'s-processing','Production'=>'s-production','Ready for Pickup'=>'s-ready','Completed'=>'s-completed','Cancelled'=>'s-cancelled'];
                            $pillCls = $pillMap[$o['status']] ?? 's-incart';
                            ?>
                            <span class="status-pill <?= $pillCls ?>"><span class="status-dot"></span><?= htmlspecialchars($o['status']) ?></span>
                        </td>
                        <td class="date-cell"><?= date('M d, Y', strtotime($o['date_created'])) ?></td>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:4px;">
                            <?php
                            $detailsData = [
                                'orderId' => 'ORD-' . str_pad($o['first_id'], 3, '0', STR_PAD_LEFT),
                                'orderDbId' => (int)$o['first_id'],
                                'batchId' => $o['batch_id'] ?? null,
                                'status' => $o['status'],
                                'productName' => $o['product_name'],
                                'productImage' => $o['product_image'] ?? '',
                                'designImages' => $allImgs,
                                'estimatedFinishDate' => $o['estimated_finish_date'] ?? null,
                                'scheduledRelease' => $o['scheduled_release'] ?? null,
                                'customerName' => $o['customer_name'] ?: 'Unknown',
                                'customerEmail' => $o['customer_email'] ?? '',
                                'customerPhone' => $o['customer_phone'] ?? '',
                                'customerAddress' => $o['customer_address'] ?? '',
                                'paymentMode' => $o['payment_mode'] ?: 'GCash',
                                'paymentType' => $paymentType,
                                'paymentAmount' => $paymentAmount,
                                'totalPrice' => $totalPrice,
                                'orderNotes' => $o['order_notes'] ?? '',
                                'items' => $o['items'],
                            ];
                            ?>
                            <button type="button" class="details-button" onclick='openOrderDetails(<?= htmlspecialchars(json_encode($detailsData), ENT_QUOTES) ?>)'>View Details</button>
                                <select class="action-select status-select"
                                    data-order-id="<?= $o['first_id'] ?>"
                                    data-original="<?= htmlspecialchars($o['status']) ?>"
                                    data-payment-amount="<?= $paymentAmount ?>"
                                    data-total-price="<?= $totalPrice ?>"
                                    data-payment-mode="<?= htmlspecialchars(strtolower($o['payment_mode'] ?? ''), ENT_QUOTES) ?>"
                                    <?= in_array($o['status'], ['Completed', 'Cancelled'], true) ? 'disabled' : '' ?>
                                    onchange="updateStatus(this)">
                                <?php foreach (['Processing','Production','Ready for Pickup','Completed'] as $flowStatus): ?>
                                    <option value="<?= $flowStatus ?>" <?= $o['status'] === $flowStatus ? 'selected' : '' ?> <?= isStatusBefore($flowStatus, $o['status']) ? 'disabled' : '' ?>><?= $flowStatus ?></option>
                                <?php endforeach; ?>
                                <option value="Cancelled" <?= $o['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            <?php if (!empty($o['order_notes'])): ?>
                                <span title="<?= htmlspecialchars($o['order_notes']) ?>" class="notes-chip">📝 <?= htmlspecialchars(mb_substr($o['order_notes'],0,20)) ?>...</span>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="ordersPagination" class="orders-pagination" aria-live="polite"></div>
    </div>
</main>

<div id="orderDetailsModal" class="details-modal" onclick="if(event.target===this)closeOrderDetails()">
    <div class="details-panel">
        <div class="details-header">
            <div class="details-title">Order Details</div>
            <button type="button" class="details-close" onclick="closeOrderDetails()" title="Close">&times;</button>
        </div>
        <div id="orderDetailsBody" class="details-body"></div>
    </div>
</div>

<script>
const csrfToken = <?= json_encode(csrf_token()) ?>;
const estimateToday = <?= json_encode(date('Y-m-d')) ?>;

function openOrderDetails(order) {
    window._estimateOrder = order;
    const money = value => '\u20b1' + Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits: 2});
    const valueOrNA = value => escHtml(value || 'N/A');
    const formatDate = value => value ? new Date(value + 'T00:00:00').toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : 'Not set';
    const items = Array.isArray(order.items) ? order.items : [];
    const designImages = Array.isArray(order.designImages) ? order.designImages.filter(Boolean) : [];
    const imagePath = path => '../' + escHtml(String(path).replace(/^\.\.\
    const productImageHtml = order.productImage
        ? `<img src="${imagePath(order.productImage)}" alt="${valueOrNA(order.productName)}" style="width:180px;height:180px;object-fit:contain;border-radius:12px;border:1px solid #e2e8f0;background:#f8fafc;">`
        : '<div class="details-empty-image">No product image</div>';
    const designImagesHtml = designImages.length
        ? designImages.map(path => `<img src="${imagePath(path)}" alt="Customer design" onclick="viewPhoto('${imagePath(path)}')" style="width:110px;height:110px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0;cursor:pointer;">`).join('')
        : '<div class="details-empty-image">No design image uploaded</div>';
    const orderNotes = String(order.orderNotes || '');
    const measurementMatch = orderNotes.match(/^Size:\s*(.*?)\s*\|\s*Measurements:\s*([^\n]*)/i);
    const customerNoteMatch = orderNotes.match(/(?:^|\n)Customization notes:\s*([\s\S]*)/i);
    const measurementText = measurementMatch ? measurementMatch[2].trim() : '';
    const customerNote = customerNoteMatch ? customerNoteMatch[1].trim() : (measurementMatch ? '' : orderNotes.trim());
    const customizationHtml = measurementText || customerNote
        ? `<div class="details-section-title">Customization Details</div>
           ${measurementText ? `<div style="padding:12px 14px;border:1px solid #dbe4ef;border-radius:10px;background:#f8fafc;color:#334155;font-size:.78rem;line-height:1.6;margin-bottom:10px;"><div style="font-size:.65rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Selected size and measurements</div><div>${escHtml(measurementMatch[1])} &mdash; ${escHtml(measurementText)}</div></div>` : ''}
           ${customerNote ? `<div style="padding:12px 14px;border:1px solid #fed7aa;border-radius:10px;background:#fff7ed;color:#7c2d12;font-size:.8rem;line-height:1.5;white-space:pre-wrap;"><div style="font-size:.65rem;font-weight:800;color:#c2410c;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Customer note</div>${escHtml(customerNote)}</div>` : ''}`
        : '';
    const itemsHtml = items.length ? items.map(item => `
        <div class="details-order-item">
            <span>${escHtml(item.variant_name || 'Custom')}${item.size ? ` &mdash; Size: ${escHtml(item.size)}` : ''}${item.recommended_size ? `<div style="margin-top:3px;color:#7c3aed;font-size:.72rem;font-weight:700;">Customer fit recommendation: ${escHtml(item.recommended_size)}</div>` : ''}${item.fit_measurements ? `<div style="margin-top:2px;color:#64748b;font-size:.68rem;">Customer measurements: ${escHtml(item.fit_measurements)}</div>` : ''}<div style="margin-top:2px;font-size:.72rem;">Quantity: ${Number(item.quantity || 0)}</div></span>
            <strong>${Number(item.price || 0) > 0 ? money(Number(item.price) * Number(item.quantity || 0)) : 'Price TBD'}</strong>
        </div>`).join('') : '<div class="details-order-item">No item details available</div>';
    const paymentAmount = Number(order.paymentAmount || 0);
    const remainingBalance = Math.max(Number(order.totalPrice || 0) - paymentAmount, 0);
    const remainingBalanceText = order.paymentType === 'Down Payment' && remainingBalance > 0
        ? ` &middot; Remaining balance: ${money(remainingBalance)}`
        : '';
    const paymentText = paymentAmount > 0
        ? `${escHtml(order.paymentMode || 'GCash')} &middot; ${escHtml(order.paymentType)} &middot; ${money(paymentAmount)}${remainingBalanceText}`
        : escHtml(order.paymentType || 'No Payment');

    document.getElementById('orderDetailsBody').innerHTML = `
        <div class="details-grid">
            <div class="details-item"><div class="details-label">Order ID</div><div class="details-value">${valueOrNA(order.orderId)}</div></div>
            <div class="details-item"><div class="details-label">Status</div><div class="details-value">${valueOrNA(order.status)}</div></div>
            <div class="details-item"><div class="details-label">Product</div><div class="details-value">${valueOrNA(order.productName)}</div></div>
            <div class="details-item"><div class="details-label">Expected Finish</div><div class="details-value">${formatDate(order.estimatedFinishDate)}</div></div>
            <div class="details-item"><div class="details-label">Scheduled Release</div><div class="details-value">${formatDate(order.scheduledRelease)}</div></div>
            <div class="details-item"><div class="details-label">Order Total</div><div class="details-value">${money(order.totalPrice)}</div></div>
            <div class="details-item"><div class="details-label">Payment</div><div class="details-value details-payment">${paymentText}</div></div>
        </div>
        <div class="details-section-title">Product Image</div>
        <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;">${productImageHtml}</div>
        <div class="details-section-title">Design / Reference Image</div>
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;">${designImagesHtml}</div>
        <div class="details-section-title">Order Items</div>
        <div class="details-items">${itemsHtml}</div>
        ${customizationHtml}
        <div class="details-section-title">Registered Customer Details</div>
        <div class="details-grid">
            <div class="details-item"><div class="details-label">Name</div><div class="details-value">${valueOrNA(order.customerName)}</div></div>
            <div class="details-item"><div class="details-label">Email</div><div class="details-value">${valueOrNA(order.customerEmail)}</div></div>
            <div class="details-item"><div class="details-label">Phone</div><div class="details-value">${valueOrNA(order.customerPhone)}</div></div>
            <div class="details-item"><div class="details-label">Address</div><div class="details-value">${valueOrNA(order.customerAddress)}</div></div>
        </div>
        ${['Completed', 'Cancelled'].includes(order.status)
            ? `<div style="display:flex;justify-content:flex-end;margin-top:18px;color:#94a3b8;font-size:.72rem;font-weight:600;">Completion estimate locked for ${escHtml(order.status.toLowerCase())} orders.</div>`
            : `<div style="display:flex;justify-content:flex-end;margin-top:18px;"><button type="button" class="details-button" onclick="openEstimateEditor(window._estimateOrder)">Edit Completion Estimate</button></div>`}`;
    document.getElementById('orderDetailsModal').style.display = 'flex';
}

function openEstimateEditor(order) {
    const existingDate = String(order.estimatedFinishDate || '');
    const initialDate = existingDate >= estimateToday ? existingDate : estimateToday;
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:10000;display:flex;align-items:center;justify-content:center;padding:1rem;';
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:16px;padding:24px;width:400px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
            <h3 style="font-size:1.1rem;font-weight:700;color:#111827;margin:0 0 6px;">Edit Completion Estimate</h3>
            <p style="font-size:.8rem;color:#6b7280;margin:0 0 18px;">Choose the expected completion date for this order.</p>
            <label style="display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:5px;">Completion date</label>
            <input type="date" id="estimateCompletionDate" min="${estimateToday}" value="${initialDate}" style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;margin-bottom:20px;box-sizing:border-box;">
            <div style="display:flex;gap:10px;">
                <button type="button" onclick="this.closest('div[style*=fixed]').remove()" style="flex:1;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;color:#374151;font-weight:600;cursor:pointer;">Cancel</button>
                <button type="button" onclick='saveEstimate(${Number(order.orderDbId || 0)}, ${JSON.stringify(order.batchId || '')}, this)' style="flex:1.5;padding:10px;border:0;border-radius:8px;background:#ff6b35;color:#fff;font-weight:700;cursor:pointer;">Save Estimate</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
}

function saveEstimate(orderId, batchId, button) {
    const overlay = button.closest('div[style*=fixed]');
    const completionDate = overlay.querySelector('#estimateCompletionDate').value;
    if (!completionDate) {
        showToast('Missing estimate', 'Choose a completion date.', 'error');
        return;
    }
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('action', 'update_estimate');
    formData.append('order_id', orderId || 0);
    formData.append('batch_id', batchId || '');
    formData.append('completion_date', completionDate);
    button.disabled = true;
    button.textContent = 'Saving...';
    fetch('orders.php', {method:'POST', body:formData})
        .then(async response => {
            const data = await response.json().catch(() => null);
            if (!data || !data.success) throw new Error((data && data.message) || 'Unable to update estimate.');
            return data;
        })
        .then(data => {
            overlay.remove();
            showToast('Estimate Updated', 'The order completion estimate was updated.', 'success');
            setTimeout(() => window.location.reload(), 500);
        })
        .catch(error => {
            showToast('Update Failed', error.message, 'error');
            button.disabled = false;
            button.textContent = 'Save Estimate';
        });
}

function closeOrderDetails() {
    document.getElementById('orderDetailsModal').style.display = 'none';
}

const ORDER_PAGE_SIZE = 10;
const orderPageState = { all: 1 };

function getOrderRowsForStatus(status) {
    return Array.from(document.querySelectorAll('.orders-table tbody tr[data-order-status]')).filter(row => {
        return status === 'all' || row.dataset.orderStatus === status;
    });
}

function renderOrderPagination(status) {
    const rows = getOrderRowsForStatus(status);
    const totalPages = Math.max(1, Math.ceil(rows.length / ORDER_PAGE_SIZE));
    const currentPage = Math.min(Math.max(1, orderPageState[status] || 1), totalPages);
    orderPageState[status] = currentPage;

    const pagination = document.getElementById('ordersPagination');
    if (!pagination) return;

    if (rows.length === 0) {
        pagination.innerHTML = '<span class="page-info">No orders</span>';
        return;
    }

    const startIndex = (currentPage - 1) * ORDER_PAGE_SIZE;
    const endIndex = Math.min(startIndex + ORDER_PAGE_SIZE, rows.length);

    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + 4);
    if (endPage - startPage < 4) {
        startPage = Math.max(1, endPage - 4);
    }

    const pageButtons = [];
    for (let page = startPage; page <= endPage; page++) {
        pageButtons.push(`<button type="button" class="${page === currentPage ? 'is-active' : ''}" data-page="${page}" ${page === currentPage ? 'disabled' : ''}>${page}</button>`);
    }

    pagination.innerHTML = `
        <span class="page-info">Showing ${startIndex + 1}-${endIndex} of ${rows.length}</span>
        <button type="button" data-page="prev" ${currentPage === 1 ? 'disabled' : ''}>&laquo;</button>
        ${pageButtons.join('')}
        <button type="button" data-page="next" ${currentPage === totalPages ? 'disabled' : ''}>&raquo;</button>
    `;

    pagination.querySelectorAll('button[data-page]').forEach(button => {
        button.addEventListener('click', () => {
            const target = button.dataset.page;
            if (target === 'prev') {
                orderPageState[status] = Math.max(1, currentPage - 1);
            } else if (target === 'next') {
                orderPageState[status] = Math.min(totalPages, currentPage + 1);
            } else {
                orderPageState[status] = Number(target);
            }
            filterOrdersByStatus(status);
        });
    });
}

function filterOrdersByStatus(status) {
    document.querySelectorAll('[data-status-tab]').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.statusTab === status);
    });
    const statusFilter = document.getElementById('orderStatusFilter');
    if (statusFilter) statusFilter.value = status;
    const allRows = Array.from(document.querySelectorAll('.orders-table tbody tr[data-order-status]'));
    const filteredRows = allRows.filter(row => status === 'all' || row.dataset.orderStatus === status);
    const totalPages = Math.max(1, Math.ceil(filteredRows.length / ORDER_PAGE_SIZE));
    const currentPage = Math.min(Math.max(1, orderPageState[status] || 1), totalPages);
    orderPageState[status] = currentPage;

    const startIndex = (currentPage - 1) * ORDER_PAGE_SIZE;
    const endIndex = startIndex + ORDER_PAGE_SIZE;

    allRows.forEach(row => {
        const matches = status === 'all' || row.dataset.orderStatus === status;
        row.hidden = !matches;
        if (matches) {
            const rowIndex = filteredRows.indexOf(row);
            row.hidden = rowIndex < startIndex || rowIndex >= endIndex;
        }
    });

    renderOrderPagination(status);
}

function selectOrderStatusTab(status) {
    filterOrdersByStatus(status);
}

function lockCompletedStatuses() {
    const statusFlow = ['In Cart', 'Processing', 'Production', 'Ready for Pickup', 'Completed'];
    document.querySelectorAll('.status-select').forEach(select => {
        const currentIndex = statusFlow.indexOf(select.dataset.original || select.value);
        if (currentIndex < 0) return;
        Array.from(select.options).forEach(option => {
            const optionIndex = statusFlow.indexOf(option.value);
            option.disabled = optionIndex >= 0 && optionIndex < currentIndex;
        });
    });
}

function updateStatus(select) {
    const orderId = select.dataset.orderId;
    const status = select.value;
    const originalValue = select.getAttribute('data-original') || '';
    const paymentAmount = parseFloat(select.dataset.paymentAmount) || 0;
    const totalPrice = parseFloat(select.dataset.totalPrice) || 0;
    const hasRemainingBalance = totalPrice > 0 && paymentAmount < totalPrice;
    const remainingBalance = Math.max(totalPrice - paymentAmount, 0);
    
    select.value = originalValue;

    if (status === 'Ready for Pickup') {
        showReleaseDateModal(orderId, status);
        return;
    }

    if (status === 'Completed' && hasRemainingBalance) {
        showConfirm({
            title: 'Remaining Balance Required',
            message: 'This order still has a remaining balance of <strong>₱' + remainingBalance.toFixed(2) + '</strong>. Please confirm that the customer has already paid the remaining amount before proceeding.',
            type: 'warning',
            confirmText: 'Yes, Remaining Balance Received',
            onConfirm: () => { _doUpdateStatus(orderId, status, null, true); },
        });
        return;
    }

    showConfirm({
        title: 'Update Order Status?',
        message: 'Change order #' + orderId + ' status to <strong>' + status + '</strong>.',
        type: status === 'Cancelled' ? 'danger' : status === 'Completed' ? 'success' : 'warning',
        confirmText: 'Update',
        onConfirm: () => { _doUpdateStatus(orderId, status, null, false); },
    });
}

function showReleaseDateModal(orderId, status) {
    const today = new Date().toISOString().split('T')[0];
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;';
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:16px;padding:2rem;width:380px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <h3 style="font-size:1.1rem;font-weight:700;margin-bottom:0.5rem;">Set Ready for Pickup</h3>
            <p style="color:#6b7280;font-size:0.85rem;margin-bottom:1.25rem;">Order #${orderId} &mdash; Set an optional scheduled release/pickup date for the customer.</p>
            <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:0.4rem;">Scheduled Release Date <span style="color:#9ca3af;">(optional)</span></label>
            <input type="date" id="releaseDate" min="${today}" style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;outline:none;margin-bottom:1.25rem;">
            <div style="display:flex;gap:0.75rem;">
                <button onclick="this.closest('div[style*=fixed]').remove()" style="flex:1;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:0.85rem;font-weight:600;background:#f9fafb;cursor:pointer;color:#374151;">Cancel</button>
                <button onclick="_confirmReleaseDate(${orderId}, '${status}', this)" style="flex:2;padding:10px;background:linear-gradient(135deg,#ff6b35,#e55a2b);border:none;border-radius:8px;font-size:0.85rem;font-weight:700;color:#fff;cursor:pointer;">Confirm Ready for Pickup</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
}

function _confirmReleaseDate(orderId, status, btn) {
    const releaseDate = document.getElementById('releaseDate').value || null;
    btn.closest('div[style*=fixed]').remove();
    _doUpdateStatus(orderId, status, releaseDate, false);
}

function _doUpdateStatus(orderId, status, scheduledRelease, paymentCompleted) {
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('order_id', orderId);
    formData.append('status', status);
    formData.append('csrf_token', csrfToken);
    if (scheduledRelease) formData.append('scheduled_release', scheduledRelease);
    if (paymentCompleted) formData.append('payment_completed', '1');

    fetch('orders.php', { method: 'POST', body: formData })
        .then(async response => {
            const text = await response.text();
            let data;
            try {
                const jsonStart = text.indexOf('{');
                const jsonEnd = text.lastIndexOf('}');
                if (jsonStart < 0 || jsonEnd < jsonStart) throw new Error('No JSON response');
                data = JSON.parse(text.slice(jsonStart, jsonEnd + 1));
            } catch (error) {
                throw new Error(response.redirected ? 'Your admin session expired. Refresh the page and try again.' : 'The server returned an unexpected response.');
            }
            if (!response.ok) throw new Error(data.message || 'Unable to update order status.');
            return data;
        })
        .then(data => {
            if (data.success) {
                showToast('Status Updated', 'Order status changed to ' + status + '.', 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast('Error', data.message || 'Failed to update order status.', 'error');
            }
        })
        .catch(error => showToast('Error', error.message || 'Something went wrong.', 'error'));
}

function viewPhoto(src) {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:9999;display:flex;align-items:center;justify-content:center;cursor:pointer;';
    overlay.onclick = () => overlay.remove();
    const img = document.createElement('img');
    img.src = src;
    img.style.cssText = 'max-width:85%;max-height:85%;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.5);';
    overlay.appendChild(img);
    document.body.appendChild(overlay);
}

function playNewOrderSound() {
    try {
        const context = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, context.currentTime);
        oscillator.frequency.setValueAtTime(1175, context.currentTime + 0.12);
        gain.gain.setValueAtTime(0.0001, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.18, context.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.45);
        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + 0.45);
    } catch (error) {}
}

let _lastOrderData = null;
let _isUpdating = false;

function getStatusBadgeClass(status) {
    const map = { 'In Cart': 'badge-incart', 'Processing': 'badge-processing', 'Production': 'badge-production', 'Ready for Pickup': 'badge-ready', 'Completed': 'badge-completed', 'Cancelled': 'badge-cancelled' };
    return map[status] || 'badge-pending';
}

function escHtml(str) {
    const d = document.createElement('div'); d.textContent = str || ''; return d.innerHTML;
}

function parseOrderFitSize(storedSize) {
    const [size, fitDetails] = String(storedSize || '').split(' | Fit: ', 2);
    if (!fitDetails) return { size: size || '', recommended_size: '', fit_measurements: '' };
    const [recommendedSize, measurements] = fitDetails.split(' | Measurements: ', 2);
    return { size: size || '', recommended_size: recommendedSize || '', fit_measurements: measurements || '' };
}

function buildOrderRow(o) {
    const orderId = String(o.id).padStart(3, '0');
    const statusOpts = ['Processing','Production','Ready for Pickup','Completed','Cancelled'];
    const flowStatuses = ['Processing','Production','Ready for Pickup','Completed'];
    const currentStatusIndex = flowStatuses.indexOf(o.status);
    const optionsHtml = statusOpts.map(s => {
        const statusIndex = flowStatuses.indexOf(s);
        const disabled = statusIndex !== -1 && currentStatusIndex !== -1 && statusIndex < currentStatusIndex ? 'disabled' : '';
        return `<option value="${s}" ${o.status === s ? 'selected' : ''} ${disabled}>${s}</option>`;
    }).join('');
    const fitDetails = parseOrderFitSize(o.size || '');
    const variantHtml = o.variant_name ? `<div class="product-variants-scroll"><div class="variant-line">${escHtml(o.variant_name)}${fitDetails.size ? `<span class="meta"> &mdash; Size: ${escHtml(fitDetails.size)}</span>` : ''}${fitDetails.recommended_size ? `<div class="meta">Fit recommendation: ${escHtml(fitDetails.recommended_size)}</div>` : ''}</div></div>` : '';
    const imgs = o.image ? o.image.split(',').map(s=>s.trim()).filter(Boolean) : [];
    const imgHtml = imgs.length ? `<div style="display:flex;align-items:center;gap:4px;margin-top:4px;"><img src="../${escHtml(imgs[0])}" class="ref-thumb" onclick="viewPhoto('../${escHtml(imgs[0])}')" style="width:36px;height:36px;object-fit:cover;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;">${imgs.length > 1 ? `<span class="ref-count" style="font-size:0.6rem;color:#94a3b8;">+${imgs.length-1} more</span>` : ''}</div>` : '';
    const dateStr = new Date(o.date_created).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    const priceHtml = o.price > 0 ? `\u20b1${parseFloat(o.price).toFixed(2)}` : '<span style="color:#cbd5e1">&mdash;</span>';
    const totalPrice = parseFloat(o.batch_total_price ?? ((parseFloat(o.price) || 0) * (parseInt(o.quantity) || 0))) || 0;
    const paymentAmount = parseFloat(o.batch_payment_amount ?? o.down_payment_amount) || 0;
    const remainingBalance = Math.max(totalPrice - paymentAmount, 0);
    const paymentType = paymentAmount <= 0 ? 'No Payment' : (totalPrice > 0 && paymentAmount >= totalPrice ? 'Full Payment' : 'Down Payment');
    const payHtml = paymentAmount > 0
        ? `<div class="pmt-type">${paymentType}</div><div class="pmt-amount">\u20b1${paymentAmount.toFixed(2)}</div>${remainingBalance > 0 ? `<div class="pmt-ref">Remaining: \u20b1${remainingBalance.toFixed(2)}</div>` : ''}${o.gcash_reference ? `<div class="pmt-ref">Ref: ${escHtml(o.gcash_reference)}</div>` : ''}`
        : `<div class="pmt-type">${paymentType}</div>`;
    const detailData = encodeURIComponent(JSON.stringify({
        orderId: 'ORD-' + orderId,
        orderDbId: Number(o.order_id || o.id || 0),
        batchId: o.batch_id || null,
        status: o.status,
        productName: o.product_name,
        customerName: o.customer_name || 'Unknown',
        customerEmail: o.customer_email || '',
        customerPhone: o.customer_phone || '',
        customerAddress: o.customer_address || '',
        paymentMode: o.payment_mode || 'GCash',
        paymentType: paymentType,
        paymentAmount: paymentAmount,
        totalPrice: totalPrice,
        orderNotes: o.order_notes || '',
        productImage: o.product_image || '',
        designImages: o.image ? o.image.split(',').map(s => s.trim()).filter(Boolean) : [],
        estimatedFinishDate: o.estimated_finish_date || '',
        scheduledRelease: o.scheduled_release || '',
        items: [{ variant_name: o.variant_name || 'Custom', size: fitDetails.size, recommended_size: fitDetails.recommended_size, fit_measurements: fitDetails.fit_measurements, quantity: o.quantity, price: o.price }]
    })).replace(/'/g, '%27');
    const releaseHtml = o.scheduled_release ? new Date(o.scheduled_release + 'T00:00:00').toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) : '<span style="color:#cbd5e1">&mdash;</span>';
    const notesHtml = o.order_notes ? `<span title="${escHtml(o.order_notes)}" class="notes-chip">\ud83d\udcdd ${escHtml(o.order_notes.substring(0,20))}...</span>` : '';
    const pillMap = {'In Cart':'s-incart','Processing':'s-processing','Production':'s-production','Ready for Pickup':'s-ready','Completed':'s-completed','Cancelled':'s-cancelled'};
    const pillCls = pillMap[o.status] || 's-incart';
    const multiHtml = o.items_count > 1 ? `<span class="order-id-chip"><span class="multi-badge">${o.items_count} items</span></span>` : '';
    const statusControl = `<select class="action-select status-select" data-order-id="${o.id}" data-payment-amount="${paymentAmount}" data-total-price="${totalPrice}" data-original="${escHtml(o.status)}" ${['Completed', 'Cancelled'].includes(o.status) ? 'disabled' : ''} onchange="updateStatus(this)">${optionsHtml}</select>`;

    const rowClass = o.status === 'Completed' ? 'completed-row' : '';
    return `<tr class="${rowClass}" data-order-status="${escHtml(o.status)}">
        <td><span class="order-id-chip">ORD-${orderId}</span>${multiHtml}</td>
        <td><div class="customer-info"><div class="name">${escHtml(o.customer_name || 'Unknown')}</div><div class="email">${escHtml(o.customer_email || '')}</div></div></td>
        <td><div class="product-cell"><div class="prod-name">${escHtml(o.product_name)}</div>${variantHtml}${imgHtml}</div></td>
        <td class="num-cell">${o.quantity}</td>
        <td class="price-cell">${priceHtml}</td>
        <td><div class="pmt-cell">${payHtml}</div></td>
        <td class="release-cell">${releaseHtml}</td>
        <td><span class="status-pill ${pillCls}"><span class="status-dot"></span>${o.status}</span></td>
        <td class="date-cell">${dateStr}</td>
        <td><div style="display:flex;flex-direction:column;gap:4px;">
            <button type="button" class="details-button" onclick="openOrderDetails(JSON.parse(decodeURIComponent('${detailData}')))" >View Details</button>
            ${statusControl}
            ${notesHtml}
        </div></td>
    </tr>`;
}

function pollOrders() {
    if (_isUpdating) return;
    fetch('poll_api.php?action=orders')
        .then(r => r.json())
        .then(data => {
            const newOrders = data.orders;
            const newSnapshot = newOrders.map(o => ({ id: o.id, status: o.status }));

            // First poll: just seed the data, no notifications
            if (_lastOrderData === null) {
                _lastOrderData = newSnapshot;
                return;
            }

            const newMap = newOrders.map(o => o.id + ':' + o.status).join(',');
            const oldMap = _lastOrderData.map(o => o.id + ':' + o.status).join(',');

            if (newMap !== oldMap) {
                // Check for new orders
                const oldIds = new Set(_lastOrderData.map(o => o.id));
                const newIds = newOrders.filter(o => !oldIds.has(o.id));
                // Check for status changes
                const oldStatusMap = {};
                _lastOrderData.forEach(o => oldStatusMap[o.id] = o.status);
                const changed = newOrders.filter(o => oldStatusMap[o.id] && oldStatusMap[o.id] !== o.status);

                // Rebuild table
                const tbody = document.querySelector('.admin-table tbody');
                if (newOrders.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="10" class="text-center text-gray-400 py-8">No orders found</td></tr>';
                } else {
                    tbody.innerHTML = newOrders.map(buildOrderRow).join('');
                    filterOrdersByStatus(document.getElementById('orderStatusFilter')?.value || 'all');
                    lockCompletedStatuses();
                }

                // Update count
                const countEl = document.querySelector('.orders-count');
                if (countEl) countEl.textContent = data.count + ' orders';

                // Toast notifications
                if (newIds.length > 0) {
                    playNewOrderSound();
                    showToast('New Order', newIds.length + ' new order' + (newIds.length > 1 ? 's' : '') + ' received!', 'info');
                }
                if (changed.length > 0) {
                    changed.forEach(o => {
                        showToast('Order Updated', 'ORD-' + String(o.id).padStart(3,'0') + ' is now ' + o.status, 'info');
                    });
                }

                _lastOrderData = newSnapshot;
            }
        })
        .catch(() => {});
}

setInterval(pollOrders, 5000);
lockCompletedStatuses();
filterOrdersByStatus(document.getElementById('orderStatusFilter')?.value || 'all');
</script>
</body>
</html>

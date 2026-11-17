<?php

function &pendingOrderHeaders(): array
{
    static $headers = [];
    return $headers;
}

function createOrderHeader(mysqli $conn, array $order): int
{
    $headers =& pendingOrderHeaders();
    $token = -1;
    while (isset($headers[$token])) $token--;
    $headers[$token] = $order + [
        'customer_id' => 0, 'payment_mode' => '', 'gcash_reference' => '',
        'down_payment_proof' => '', 'scheduled_release' => null,
        'estimated_finish_date' => null, 'estimated_working_days' => null,
        'status' => 'In Cart', 'batch_id' => null, 'date_created' => null,
        '_order_id' => null,
    ];
    return $token;
}

function createOrderItem(mysqli $conn, int $headerToken, array $item): int
{
    $headers =& pendingOrderHeaders();
    if (!isset($headers[$headerToken])) {
        throw new RuntimeException('The pending order header is no longer available.');
    }
    $order =& $headers[$headerToken];

    $stmt = $conn->prepare(
        'INSERT INTO orders (order_id, customer_id, product_id, variant_id, product_name, variant_name, size, quantity, price, payment_mode, gcash_reference, down_payment_amount, down_payment_proof, scheduled_release, estimated_finish_date, estimated_working_days, order_notes, image, status, batch_id, date_created, updated_at) VALUES (?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()), NOW())'
    );
    if (!$stmt) throw new RuntimeException('Unable to prepare order: ' . $conn->error);

    $orderId = (int)($order['_order_id'] ?? 0);
    $customerId = (int)$order['customer_id'];
    $productId = (int)($item['product_id'] ?? 0);
    $variantId = isset($item['variant_id']) ? (int)$item['variant_id'] : null;
    $productName = (string)($item['product_name'] ?? 'Custom Order');
    $variantName = (string)($item['variant_name'] ?? '');
    $size = (string)($item['size'] ?? '');
    $quantity = max(1, (int)($item['quantity'] ?? 1));
    $unitPrice = (float)($item['price'] ?? 0);
    $paymentMode = (string)$order['payment_mode'];
    $gcashReference = (string)$order['gcash_reference'];
    $paidAmount = (float)($item['paid_amount'] ?? 0);
    $proof = (string)$order['down_payment_proof'];
    $scheduledRelease = $order['scheduled_release'];
    $estimatedFinishDate = $order['estimated_finish_date'];
    $estimatedWorkingDays = isset($order['estimated_working_days']) ? (int)$order['estimated_working_days'] : null;
    $notes = $item['order_notes'] ?? null;
    $image = $item['image'] ?? null;
    $status = (string)$order['status'];
    $batchId = $order['batch_id'];
    $createdAt = $order['date_created'];

    $stmt->bind_param(
        'iiiisssidsddsssisssss',
        $orderId, $customerId, $productId, $variantId, $productName, $variantName,
        $size, $quantity, $unitPrice, $paymentMode, $gcashReference, $paidAmount,
        $proof, $scheduledRelease, $estimatedFinishDate, $estimatedWorkingDays,
        $notes, $image, $status, $batchId, $createdAt
    );
    if (!$stmt->execute()) throw new RuntimeException('Unable to create order: ' . $stmt->error);

    $itemId = (int)$conn->insert_id;
    if ($orderId === 0) {
        $orderId = $itemId;
        $update = $conn->prepare('UPDATE orders SET order_id = ? WHERE id = ?');
        $update->bind_param('ii', $orderId, $itemId);
        if (!$update->execute()) throw new RuntimeException('Unable to finish order grouping: ' . $update->error);
        $order['_order_id'] = $orderId;
    }
    return $itemId;
}

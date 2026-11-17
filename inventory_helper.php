<?php

function reserveInventory(mysqli $conn, int $productId, ?int $variantId, int $quantity): bool
{
    $quantity = max(1, $quantity);

    if ($variantId !== null && $variantId > 0) {
        $stmt = $conn->prepare("UPDATE products SET quantity = quantity - ? WHERE record_type = 'variant' AND variant_id = ? AND product_id = ? AND is_active = 1 AND quantity >= ?");
        $stmt->bind_param('iiii', $quantity, $variantId, $productId, $quantity);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) return false;

        $stmt = $conn->prepare("UPDATE products SET quantity = quantity - ? WHERE record_type = 'product' AND product_id = ? AND is_active = 1 AND quantity >= ?");
        $stmt->bind_param('iii', $quantity, $productId, $quantity);
    } else {
        $stmt = $conn->prepare("UPDATE products SET quantity = quantity - ? WHERE record_type = 'product' AND product_id = ? AND is_active = 1 AND quantity >= ?");
        $stmt->bind_param('iii', $quantity, $productId, $quantity);
    }

    if (!$stmt->execute()) return false;
    return $stmt->affected_rows === 1;
}

function releaseInventory(mysqli $conn, int $productId, ?int $variantId, int $quantity): bool
{
    if ($productId <= 0) return true;

    $quantity = max(1, $quantity);

    if ($variantId !== null && $variantId > 0) {
        $stmt = $conn->prepare("UPDATE products SET quantity = quantity + ? WHERE record_type = 'variant' AND variant_id = ? AND product_id = ?");
        $stmt->bind_param('iii', $quantity, $variantId, $productId);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) return false;

        $stmt = $conn->prepare("UPDATE products SET quantity = quantity + ? WHERE record_type = 'product' AND product_id = ?");
        $stmt->bind_param('ii', $quantity, $productId);
    } else {
        $stmt = $conn->prepare("UPDATE products SET quantity = quantity + ? WHERE record_type = 'product' AND product_id = ?");
        $stmt->bind_param('ii', $quantity, $productId);
    }

    if (!$stmt->execute()) return false;
    return $stmt->affected_rows === 1;
}

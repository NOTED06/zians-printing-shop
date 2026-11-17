<?php

function notifyAdminsOfNewOrder(mysqli $conn, string $message): void
{
    $result = $conn->query("SELECT phone FROM admins WHERE phone IS NOT NULL AND phone <> ''");
    if (!$result || $result->num_rows === 0) return;

    require_once __DIR__ . '/classes/HttpSmsGateway.php';
    $gateway = new HttpSmsGateway($conn);
    $configuration = $gateway->getConfiguration();
    if (empty($configuration['gateway_type'])) return;

    while ($admin = $result->fetch_assoc()) {
        try {
            $gateway->sendSMS($admin['phone'], $message);
        } catch (Throwable $error) {
            error_log('Admin order SMS failed: ' . $error->getMessage());
        }
    }
}

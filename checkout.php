<?php
require __DIR__ . '/config.php';

function finish(string $title, string $message): never {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><body style="font:16px Arial;max-width:800px;margin:40px auto">';
    echo '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p><p><a href="index.php">Store</a> | <a href="cart.php">Cart</a> | <a href="orders.php">Orders</a></p></body></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit; }

$customer = trim((string)($_POST['customer_name'] ?? ''));
$method = (string)($_POST['payment_method'] ?? 'CARD');
if ($customer === '') finish('Missing information', 'Enter a customer name.');
if (!in_array($method, ['CARD','EWALLET','BANK'], true)) $method = 'CARD';

$cart = $_SESSION['cart'] ?? [];
if (!$cart) finish('Empty cart', 'Add an item before checkout.');

$pdo = cart_db();
$ids = array_map('intval', array_keys($cart));
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id,name,price,stock FROM products WHERE id IN ($marks)");
$stmt->execute($ids);
$products = $stmt->fetchAll();
$total = 0.0; $validItems = [];
foreach ($products as $p) {
    $qty = min((int)$cart[$p['id']], (int)$p['stock']);
    if ($qty > 0) {
        $total += $qty * (float)$p['price'];
        $validItems[] = ['product'=>$p,'qty'=>$qty];
    }
}
if (!$validItems || $total <= 0) finish('Checkout error', 'No valid items were found.');

/* Persist order before calling the independent payment service. */
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO orders (customer_name,total_amount,status) VALUES (?,?,'PENDING_PAYMENT')");
    $stmt->execute([$customer, number_format($total,2,'.','')]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price) VALUES (?,?,?,?,?)');
    foreach ($validItems as $i) {
        $itemStmt->execute([$orderId,$i['product']['id'],$i['product']['name'],$i['qty'],$i['product']['price']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    finish('Order error', 'Could not save the order.');
}



/*
 * STUDENT TASK: Integrate the shopping cart with the
 * independent payment gateway.
 *
 * Requirements:
 * 1. Prepare the payment request payload.
 * 2. Send an HTTP POST request with JSON using PHP cURL.
 * 3. Handle the gateway's HTTP response.
 * 4. Validate the response and match the order ID.
 * 5. Handle SUCCESS, FAILED, and PENDING outcomes.
 * 6. Handle timeouts and invalid responses safely.
 * 7. Save the gateway transaction ID and update the
 *    order status in the shopping cart database.
 * 8. Prevent duplicate payment requests using an
 *    idempotency key.
 *
 * Read payment-gateway/README-API.md for the API contract.
 */

/* ------------------------------------------------------------------
 * PAYMENT GATEWAY INTEGRATION
 * ------------------------------------------------------------------
 * Simulator control: change this to 'FAILED' or 'PENDING' to test
 * those outcomes. In a real system this must NOT be client-chosen.
 * ------------------------------------------------------------------ */
const SIMULATED_OUTCOME = 'SUCCESS';

$apiUrl = 'http://localhost/payment-gateway/api/payments.php';

// Stable idempotency key: same order -> same key -> no duplicate charge.
$idempotencyKey = 'cart-order-' . $orderId;

$payload = [
    'order_id'        => $orderId,
    'amount'          => (float)$total,
    'customer_name'   => $customer,
    'payment_method'  => $method,
    'idempotency_key' => $idempotencyKey,
    'outcome'         => SIMULATED_OUTCOME,
];

// ---------------------------------------------------------------
// Send the request with cURL
// ---------------------------------------------------------------
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 15,
]);

$rawResponse = curl_exec($ch);
$curlErrno   = curl_errno($ch);
$curlError   = curl_error($ch);
$httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ---------------------------------------------------------------
// Handle communication failures (uncertain outcome — do NOT mark failed)
// ---------------------------------------------------------------
if ($curlErrno !== 0 || $rawResponse === false) {
    $stmt = $pdo->prepare(
        "UPDATE orders SET status = 'PAYMENT_PENDING' WHERE id = ?"
    );
    $stmt->execute([$orderId]);
    finish(
        'Payment status unconfirmed',
        'We could not reach the payment gateway (' . $curlError . '). '
        . 'Your order #' . $orderId . ' is saved as pending. '
        . 'Check your order history before trying again.'
    );
}

$response = json_decode($rawResponse, true);

// ---------------------------------------------------------------
// Handle HTTP errors or invalid JSON
// ---------------------------------------------------------------
if ($httpCode < 200 || $httpCode >= 300 || !is_array($response)) {
    $stmt = $pdo->prepare(
        "UPDATE orders SET status = 'PAYMENT_PENDING' WHERE id = ?"
    );
    $stmt->execute([$orderId]);
    finish(
        'Payment status unconfirmed',
        'The payment gateway returned an unexpected response (HTTP '
        . $httpCode . '). Your order #' . $orderId . ' is saved as '
        . 'pending. Please check your order history before retrying.'
    );
}

// ---------------------------------------------------------------
// Validate required fields and matching order ID
// ---------------------------------------------------------------
if (!isset($response['success'], $response['order_id'], $response['status'])
    || (int)$response['order_id'] !== $orderId) {
    $stmt = $pdo->prepare(
        "UPDATE orders SET status = 'INTEGRATION_ERROR' WHERE id = ?"
    );
    $stmt->execute([$orderId]);
    finish(
        'Payment could not be confirmed',
        'The gateway response did not match this order. '
        . 'Order #' . $orderId . ' has been flagged for review.'
    );
}

$gatewayTxnId = isset($response['transaction_id'])
    ? (string)$response['transaction_id']
    : null;
$gatewayStatus = strtoupper((string)$response['status']);

// ---------------------------------------------------------------
// Map gateway status -> shopping cart order status
// ---------------------------------------------------------------
switch ($gatewayStatus) {
    case 'SUCCESS':
        $stmt = $pdo->prepare(
            "UPDATE orders
                SET status = 'PAID', gateway_transaction_id = ?
              WHERE id = ?"
        );
        $stmt->execute([$gatewayTxnId, $orderId]);
        $_SESSION['cart'] = [];
        finish(
            'Payment successful',
            'Thank you, ' . $customer . '! Order #' . $orderId
            . ' has been paid. Transaction: ' . ($gatewayTxnId ?? 'n/a')
        );
        break;

    case 'FAILED':
        $stmt = $pdo->prepare(
            "UPDATE orders
                SET status = 'PAYMENT_FAILED', gateway_transaction_id = ?
              WHERE id = ?"
        );
        $stmt->execute([$gatewayTxnId, $orderId]);
        finish(
            'Payment failed',
            'Your payment for order #' . $orderId . ' was declined. '
            . 'Transaction: ' . ($gatewayTxnId ?? 'n/a')
        );
        break;

    case 'PENDING':
        $stmt = $pdo->prepare(
            "UPDATE orders
                SET status = 'PAYMENT_PENDING', gateway_transaction_id = ?
              WHERE id = ?"
        );
        $stmt->execute([$gatewayTxnId, $orderId]);
        finish(
            'Payment pending',
            'Your payment for order #' . $orderId . ' is awaiting '
            . 'confirmation. Transaction: ' . ($gatewayTxnId ?? 'n/a')
        );
        break;

    default:
        // Unrecognized status — treat as uncertain, not as success or failure.
        $stmt = $pdo->prepare(
            "UPDATE orders
                SET status = 'PAYMENT_PENDING', gateway_transaction_id = ?
              WHERE id = ?"
        );
        $stmt->execute([$gatewayTxnId, $orderId]);
        finish(
            'Payment status unconfirmed',
            'The gateway returned an unrecognized status. '
            . 'Order #' . $orderId . ' is saved as pending.'
        );
}

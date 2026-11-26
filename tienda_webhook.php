<?php
/**
 * tienda_webhook.php — Webhook / IPN oficial de Mercado Pago para confirmar cobros de Tienda.
 *
 * Seguridad:
 *   - Nunca confía ciegamente en el JSON entrante: consulta directamente
 *     GET https://api.mercadopago.com/v1/payments/{id} usando el Access Token privado.
 *   - Actualiza el estado real en `store_orders` ('approved', 'pending', 'rejected', etc.).
 *   - Al aprobarse por primera vez:
 *       a) Descuenta stock opcional (`secondary_price`) en `menu_items` de forma idempotente.
 *       b) Registra la venta confirmada en `order_events` (`source = 'tienda'`) si aún no estaba.
 */

declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
date_default_timezone_set('America/Argentina/Cordoba');

header('Content-Type: application/json; charset=utf-8');

$MP_ACCESS_TOKEN = trim((string)(
    $config['mercadopago']['access_token']
    ?? $config['mp_access_token']
    ?? getenv('MP_ACCESS_TOKEN')
    ?: ''
));

if ($MP_ACCESS_TOKEN === '') {
    http_response_code(200);
    echo '{"ok":true,"note":"no_token"}';
    exit;
}

$rawBody = (string)file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) $payload = [];

$topic = strtolower((string)(
    $payload['type']
    ?? $payload['topic']
    ?? $_GET['type']
    ?? $_GET['topic']
    ?? ''
));

$paymentId = trim((string)(
    $payload['data']['id']
    ?? $_GET['data_id']
    ?? $_GET['data.id']
    ?? (($topic === 'payment' && isset($_GET['id'])) ? $_GET['id'] : '')
));

if ($topic !== 'payment' || $paymentId === '' || !preg_match('/^\d+$/', $paymentId)) {
    // Responder 200 a otros eventos (merchant_order, test ping) para que MP no reintente en bucle
    http_response_code(200);
    echo '{"ok":true,"ignored":true}';
    exit;
}

// ── Consultar el pago real en la API oficial de Mercado Pago ─────────────────
$ch = curl_init('https://api.mercadopago.com/v1/payments/' . rawurlencode($paymentId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $MP_ACCESS_TOKEN,
    ],
    CURLOPT_TIMEOUT        => 15,
]);
$resBody  = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resBody === false || $httpCode < 200 || $httpCode >= 300) {
    error_log("tienda_webhook: fallo al consultar pago MP #{$paymentId} (HTTP {$httpCode})");
    http_response_code(502);
    echo '{"ok":false}';
    exit;
}

$payment = json_decode((string)$resBody, true);
if (!is_array($payment)) {
    http_response_code(200);
    echo '{"ok":false}';
    exit;
}

$externalRef = trim((string)($payment['external_reference'] ?? ''));
$mpStatus    = strtolower(trim((string)($payment['status'] ?? 'pending')));
$paidAmount  = round((float)($payment['transaction_amount'] ?? 0), 2);

if ($externalRef === '') {
    http_response_code(200);
    echo '{"ok":true,"note":"no_external_reference"}';
    exit;
}

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT * FROM store_orders WHERE reference = ? FOR UPDATE");
    $st->execute([$externalRef]);
    $order = $st->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        $nowStr = date('Y-m-d H:i:s');
        $needDeduct = ($mpStatus === 'approved' && empty($order['stock_deducted']));

        $up = $pdo->prepare("
            UPDATE store_orders
            SET status = ?, mp_payment_id = ?, stock_deducted = ?, updated_at = ?
            WHERE id = ?
        ");
        $up->execute([
            $mpStatus,
            $paymentId,
            ($needDeduct || !empty($order['stock_deducted'])) ? 1 : 0,
            $nowStr,
            (int)$order['id'],
        ]);

        // Descontar stock (secondary_price) solo una vez cuando pasa a 'approved'
        if ($needDeduct) {
            $itemsList = json_decode((string)$order['items_json'], true);
            if (is_array($itemsList)) {
                $decSt = $pdo->prepare("
                    UPDATE menu_items
                    SET secondary_price = GREATEST(0, secondary_price - ?)
                    WHERE id = ? AND secondary_price IS NOT NULL
                ");
                foreach ($itemsList as $it) {
                    if (empty($it['variant_id']) && !empty($it['item_id']) && !empty($it['qty'])) {
                        $decSt->execute([(int)$it['qty'], (int)$it['item_id']]);
                    }
                }
            }
        }
    }

    $pdo->commit();

    // Asegurar registro en order_events (estadísticas del panel) si fue aprobado y aún no se registró
    if ($mpStatus === 'approved' && $order) {
        $chk = $pdo->prepare("SELECT id FROM order_events WHERE order_code = ? LIMIT 1");
        $chk->execute([$externalRef]);
        if (!$chk->fetchColumn()) {
            $itemsList = json_decode((string)$order['items_json'], true) ?: [];
            $itemCount = 0;
            $cleanItems = [];
            foreach ($itemsList as $it) {
                $q = max(1, (int)($it['qty'] ?? 1));
                $itemCount += $q;
                $cleanItems[] = [
                    'name'  => (string)($it['name'] ?? ''),
                    'qty'   => $q,
                    'price' => (float)($it['price'] ?? 0),
                ];
            }

            $rawDigits = preg_replace('/\D+/', '', (string)($order['customer_phone'] ?? ''));
            $secretKey = (string)($config['track_salt'] ?? ($config['db']['password'] ?? 'rrbb-track-2026'));
            $phoneHash = (strlen($rawDigits) >= 7) ? hash_hmac('sha256', substr($rawDigits, -10), $secretKey) : null;

            $insEv = $pdo->prepare("
                INSERT INTO order_events
                    (created_at, source, order_code, delivery_type, payment_method, total,
                     pickup_time, item_count, items_json, phone_hash, is_preorder, status)
                VALUES (?, 'tienda', ?, ?, 'Mercado Pago', ?, 'Tienda Online', ?, ?, ?, 0, 'confirmed')
            ");
            $insEv->execute([
                date('Y-m-d H:i:s'),
                substr($externalRef, 0, 24),
                (string)$order['delivery_type'],
                $paidAmount > 0 ? $paidAmount : (float)$order['total'],
                $itemCount,
                json_encode($cleanItems, JSON_UNESCAPED_UNICODE),
                $phoneHash,
            ]);
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('tienda_webhook DB error: ' . $e->getMessage());
}

http_response_code(200);
echo json_encode(['ok' => true, 'status' => $mpStatus]);

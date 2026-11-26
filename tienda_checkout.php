<?php
/**
 * tienda_checkout.php — Crea una preferencia de pago de Mercado Pago (Checkout Pro) para la Tienda.
 *
 * Seguridad y trazabilidad:
 *   1. Re-valida cada producto y cada variante contra la base de datos (ignora precios del navegador).
 *   2. Suma el costo de envío configurado en site_settings ('store_shipping_cost') si eligió envío.
 *   3. Guarda el pedido completo (cliente, email, teléfono, dirección, ítems) en `store_orders`.
 *   4. Lee el Access Token de Mercado Pago desde `/home/c2652217/config.php` (`$config['mp_access_token']`
 *      o `$config['mercadopago']['access_token']`) fuera del repositorio público.
 *   5. Si aún no hay Access Token cargado, opera en MODO DEMO (avisa y redirige a tienda_gracias.php).
 */

declare(strict_types=1);
session_start();
require_once __DIR__ . '/db_connect.php';
date_default_timezone_set('America/Argentina/Cordoba');

header('Content-Type: application/json; charset=utf-8');

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Método no permitido.', 405);
}

// ── 1. Resolver Access Token de Mercado Pago (desde config.php externo o env) ──
$MP_ACCESS_TOKEN = trim((string)(
    $config['mercadopago']['access_token']
    ?? $config['mp_access_token']
    ?? getenv('MP_ACCESS_TOKEN')
    ?: ''
));

// URL base dinámica (soporta tanto arrabbiata.com.ar como pruebas en arrabbiata.com.ar/github)
$rawHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? 'arrabbiata.com.ar')));
if (!preg_match('/^(www\.)?(arrabbiata|lafamosa)\.com\.ar$/i', $rawHost)) {
    $rawHost = 'arrabbiata.com.ar';
}
$dirPath  = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$BASE_URL = 'https://' . $rawHost . ($dirPath !== '' ? $dirPath : '');

// ── 2. Asegurar tabla store_orders ───────────────────────────────────────────
function ensureStoreOrdersSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS store_orders (
            id               BIGINT AUTO_INCREMENT PRIMARY KEY,
            reference        VARCHAR(48) NOT NULL UNIQUE,
            customer_name    VARCHAR(140) NOT NULL,
            customer_email   VARCHAR(160) NOT NULL,
            customer_phone   VARCHAR(48) NOT NULL,
            delivery_type    VARCHAR(16) NOT NULL DEFAULT 'retiro',
            address          VARCHAR(255) NULL,
            shipping_cost    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total            DECIMAL(10,2) NOT NULL,
            items_json       TEXT NOT NULL,
            mp_preference_id VARCHAR(96) NULL,
            mp_payment_id    VARCHAR(64) NULL,
            status           VARCHAR(24) NOT NULL DEFAULT 'pending',
            stock_deducted   TINYINT(1) NOT NULL DEFAULT 0,
            created_at       DATETIME NOT NULL,
            updated_at       DATETIME NOT NULL,
            INDEX idx_created (created_at),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

try {
    ensureStoreOrdersSchema($pdo);
} catch (Throwable $e) {
    error_log('store_orders schema error: ' . $e->getMessage());
}

// ── 3. Leer y validar el payload del carrito ─────────────────────────────────
$raw  = (string)file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) fail('Solicitud inválida.');

$items    = $data['items']    ?? [];
$customer = $data['customer'] ?? [];
$delivery = $data['delivery'] ?? [];

if (empty($items) || !is_array($items)) fail('El carrito está vacío.');

$name  = substr(trim((string)($customer['name']  ?? '')), 0, 140);
$email = substr(trim((string)($customer['email'] ?? '')), 0, 160);
$phone = substr(trim((string)($customer['phone'] ?? '')), 0, 48);
if ($name === '' || $email === '' || $phone === '') fail('Faltan datos del cliente.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Ingresá un email válido.');

$deliveryType = (($delivery['type'] ?? 'retiro') === 'envio') ? 'envio' : 'retiro';
$address      = substr(trim((string)($delivery['address'] ?? '')), 0, 255);
if ($deliveryType === 'envio' && $address === '') fail('Falta la dirección de envío.');

// ── 4. Re-validar productos y variantes contra la BD ─────────────────────────
$validated   = [];
$storedItems = [];
$total       = 0.0;

try {
    $stmt = $pdo->prepare("
        SELECT mi.id, mi.name, mi.price, mi.secondary_price, mi.is_visible, c.name AS cat_name
        FROM menu_items mi
        JOIN categories c ON mi.category_id = c.id
        WHERE mi.id = ?
        LIMIT 1
    ");

    $variant_stmt = $pdo->prepare("
        SELECT id, item_id, name, price
        FROM menu_item_variants
        WHERE id = ? AND item_id = ?
        LIMIT 1
    ");

    foreach ($items as $line) {
        $id  = (int)($line['id'] ?? 0);
        $qty = (int)($line['quantity'] ?? 0);
        if ($id <= 0 || $qty <= 0 || $qty > 99) continue;

        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) fail('Un producto del carrito ya no existe.');
        if ((int)$row['is_visible'] !== 1) fail('Un producto ya no está disponible: ' . $row['name']);
        if (stripos((string)$row['cat_name'], 'Tienda') === false) fail('Producto no válido para la tienda.');

        $rawVariant = trim((string)($line['variantId'] ?? ''));
        $variantDbId = null;

        if ($rawVariant !== '') {
            $variantDbId = (int)ltrim($rawVariant, 'v');
            $variant_stmt->execute([$variantDbId, $id]);
            $vrow = $variant_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$vrow) fail('Una variante seleccionada ya no está disponible.');

            $price = (float)$vrow['price'];
            $title = $row['name'] . ' — ' . $vrow['name'];
        } else {
            if ($row['secondary_price'] !== null) {
                $avail = (int)$row['secondary_price'];
                if ($avail <= 0) {
                    fail('Producto agotado: ' . $row['name']);
                }
                if ($qty > $avail) {
                    fail("Solo quedan {$avail} unidades disponibles de {$row['name']}.");
                }
            }
            $price = (float)$row['price'];
            $title = (string)$row['name'];
        }

        if ($price <= 0) fail('Precio inválido en producto: ' . $title);

        $lineTotal = round($price * $qty, 2);
        $total    += $lineTotal;

        $validated[] = [
            'id'          => $variantDbId ? ($row['id'] . '_v' . $variantDbId) : (string)$row['id'],
            'title'       => $title,
            'quantity'    => $qty,
            'unit_price'  => round($price, 2),
            'currency_id' => 'ARS',
        ];

        $storedItems[] = [
            'item_id'    => (int)$row['id'],
            'variant_id' => $variantDbId,
            'name'       => $title,
            'qty'        => $qty,
            'price'      => round($price, 2),
        ];
    }
} catch (PDOException $e) {
    error_log('Checkout tienda - error BD: ' . $e->getMessage());
    fail('Error interno al validar el pedido.', 500);
}

if (empty($validated) || $total <= 0) {
    fail('No hay productos válidos para pagar.');
}

// ── 5. Costo de envío configurado en site_settings ───────────────────────────
$shippingCost = 0.0;
if ($deliveryType === 'envio') {
    try {
        $shipSt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'store_shipping_cost' LIMIT 1");
        $shipSt->execute();
        $shipVal = $shipSt->fetchColumn();
        if ($shipVal !== false && is_numeric($shipVal) && (float)$shipVal > 0) {
            $shippingCost = round((float)$shipVal, 2);
            $validated[] = [
                'id'          => 'ENVIO',
                'title'       => 'Envío a domicilio',
                'quantity'    => 1,
                'unit_price'  => $shippingCost,
                'currency_id' => 'ARS',
            ];
            $total += $shippingCost;
        }
    } catch (Throwable $e) {
        // Sin costo fijo configurado (se coordina por WhatsApp)
    }
}

$total = round($total, 2);
$external_reference = 'TIENDA-' . date('md-Hi') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
$initialStatus = ($MP_ACCESS_TOKEN === '') ? 'demo' : 'pending';

try {
    $nowStr = date('Y-m-d H:i:s');
    $ins = $pdo->prepare("
        INSERT INTO store_orders
            (reference, customer_name, customer_email, customer_phone, delivery_type, address,
             shipping_cost, total, items_json, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $external_reference,
        $name,
        $email,
        $phone,
        $deliveryType,
        $deliveryType === 'envio' ? $address : '',
        $shippingCost,
        $total,
        json_encode($storedItems, JSON_UNESCAPED_UNICODE),
        $initialStatus,
        $nowStr,
        $nowStr,
    ]);
} catch (Throwable $e) {
    error_log('No se pudo guardar store_orders: ' . $e->getMessage());
}

// ── 6. MODO DEMO (cuando aún no se cargó mp_access_token en config.php) ──────
if ($MP_ACCESS_TOKEN === '') {
    echo json_encode([
        'ok'           => true,
        'demo'         => true,
        'checkout_url' => $BASE_URL . '/tienda_gracias.php?demo=1&ref=' . urlencode($external_reference)
                          . '&total=' . urlencode(number_format($total, 2, '.', '')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 7. Crear Preferencia en Mercado Pago Checkout Pro ────────────────────────
$preference = [
    'items' => $validated,
    'payer' => [
        'name'  => $name,
        'email' => $email,
    ],
    'external_reference'   => $external_reference,
    'statement_descriptor' => 'ARRABBIATA',
    'back_urls' => [
        'success' => $BASE_URL . '/tienda_gracias.php?status=approved&ref=' . urlencode($external_reference) . '&total=' . urlencode(number_format($total, 2, '.', '')),
        'pending' => $BASE_URL . '/tienda_gracias.php?status=pending&ref='  . urlencode($external_reference) . '&total=' . urlencode(number_format($total, 2, '.', '')),
        'failure' => $BASE_URL . '/tienda_gracias.php?status=failure&ref='  . urlencode($external_reference),
    ],
    'auto_return'      => 'approved',
    'notification_url' => $BASE_URL . '/tienda_webhook.php',
    'metadata' => [
        'reference'     => $external_reference,
        'delivery_type' => $deliveryType,
        'address'       => $address,
        'phone'         => $phone,
    ],
];

$ch = curl_init('https://api.mercadopago.com/checkout/preferences');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $MP_ACCESS_TOKEN,
    ],
    CURLOPT_POSTFIELDS     => json_encode($preference, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 20,
]);

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log('MP curl error: ' . $curlErr);
    fail('No se pudo conectar con Mercado Pago. Intentá nuevamente.', 502);
}

$mp = json_decode((string)$response, true);
if ($httpCode >= 200 && $httpCode < 300 && !empty($mp['init_point'])) {
    try {
        $upPref = $pdo->prepare("UPDATE store_orders SET mp_preference_id = ?, updated_at = ? WHERE reference = ?");
        $upPref->execute([(string)($mp['id'] ?? ''), date('Y-m-d H:i:s'), $external_reference]);
    } catch (Throwable $e) {}

    echo json_encode([
        'ok'            => true,
        'checkout_url'  => $mp['init_point'],
        'preference_id' => $mp['id'] ?? null,
        'reference'     => $external_reference,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

error_log('MP error (' . $httpCode . '): ' . $response);
fail('No se pudo generar el pago en Mercado Pago. Revisá el Access Token en config.php.', 502);

<?php
/**
 * tienda_checkout.php — Crea una preferencia de pago para la tienda.
 *
 * Flujo:
 *   1. Recibe el carrito + datos del cliente (JSON) desde tienda.php.
 *   2. Re-valida CADA precio contra la base de datos (nunca se confía en el
 *      precio que manda el navegador).
 *   3. Crea una preferencia de Mercado Pago (Checkout Pro) y devuelve la URL
 *      a la que redirigir al cliente para pagar.
 *
 * CONFIGURACIÓN NECESARIA PARA COBRAR DE VERDAD:
 *   - Definí tu Access Token de producción de Mercado Pago en
 *     $MP_ACCESS_TOKEN (o, mejor, en una variable de entorno / archivo
 *     fuera del repositorio).
 *   - Si el token está vacío, el endpoint funciona en MODO DEMO: devuelve
 *     una URL a tienda_gracias.php para poder probar el flujo completo
 *     sin cobrar.
 */

declare(strict_types=1);
session_start();
require_once 'db_connect.php';

header('Content-Type: application/json; charset=utf-8');

// ====== CONFIG ======
$MP_ACCESS_TOKEN = getenv('MP_ACCESS_TOKEN') ?: ''; // <-- pegá acá tu token, o usá variable de entorno
$BASE_URL = 'https://arrabbiata.com.ar';            // dominio público del sitio
// =====================

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ---- Leer y validar el payload ----
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) fail('Solicitud inválida.');

$items    = $data['items']    ?? [];
$customer = $data['customer'] ?? [];
$delivery = $data['delivery'] ?? [];

if (empty($items) || !is_array($items)) fail('El carrito está vacío.');

$name  = trim((string)($customer['name']  ?? ''));
$email = trim((string)($customer['email'] ?? ''));
$phone = trim((string)($customer['phone'] ?? ''));
if ($name === '' || $email === '' || $phone === '') fail('Faltan datos del cliente.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Email inválido.');

$deliveryType = ($delivery['type'] ?? 'retiro') === 'envio' ? 'envio' : 'retiro';
$address = trim((string)($delivery['address'] ?? ''));
if ($deliveryType === 'envio' && $address === '') fail('Falta la dirección de envío.');

// ---- Re-validar precios contra la BD ----
// Solo se aceptan ítems que pertenezcan a una categoría de tienda,
// estén visibles y no estén agotados (secondary_price > 0 o NULL).
$validated = [];
$total = 0.0;

try {
    $stmt = $pdo->prepare("
        SELECT mi.id, mi.name, mi.price, mi.secondary_price, mi.is_visible, c.name AS cat_name
        FROM menu_items mi
        JOIN categories c ON mi.category_id = c.id
        WHERE mi.id = ?
        LIMIT 1
    ");

    // Para líneas con variante: traemos el precio REAL de la variante.
    $variant_stmt = $pdo->prepare("
        SELECT id, item_id, name, price
        FROM menu_item_variants
        WHERE id = ? AND item_id = ?
        LIMIT 1
    ");

    foreach ($items as $line) {
        $id  = (int)($line['id'] ?? 0);
        $qty = (int)($line['quantity'] ?? 0);
        if ($id <= 0 || $qty <= 0) continue;

        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) fail('Un producto del carrito ya no existe.');

        if ((int)$row['is_visible'] !== 1) fail('Un producto ya no está disponible.');
        if (stripos($row['cat_name'], 'Tienda') === false) fail('Producto no válido para la tienda.');

        // ¿La línea trae una variante? El front la manda como "v<ID>".
        $rawVariant = (string)($line['variantId'] ?? '');
        if ($rawVariant !== '') {
            $variantId = (int)ltrim($rawVariant, 'v');
            $variant_stmt->execute([$variantId, $id]);
            $vrow = $variant_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$vrow) fail('Una variante del carrito ya no existe.');

            $price = (float)$vrow['price'];            // precio REAL de la variante
            $title = $row['name'] . ' — ' . $vrow['name'];
        } else {
            if ($row['secondary_price'] !== null && (float)$row['secondary_price'] <= 0) {
                fail('Un producto está agotado: ' . $row['name']);
            }
            $price = (float)$row['price'];             // precio REAL desde la BD
            $title = $row['name'];
        }

        $lineTotal = $price * $qty;
        $total += $lineTotal;

        $validated[] = [
            'id'          => (string)$row['id'],
            'title'       => $title,
            'quantity'    => $qty,
            'unit_price'  => round($price, 2),
            'currency_id' => 'ARS',
        ];
    }
} catch (PDOException $e) {
    error_log('Checkout tienda - error BD: ' . $e->getMessage());
    fail('Error al validar el pedido.', 500);
}

if (empty($validated) || $total <= 0) fail('No hay productos válidos para pagar.');

// ---- Guardar el pedido como pendiente (opcional pero recomendado) ----
// Si tenés una tabla store_orders, descomentá este bloque y ajustá columnas.
$external_reference = 'TIENDA-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
/*
try {
    $ins = $pdo->prepare("
        INSERT INTO store_orders (reference, customer_name, customer_email, customer_phone,
                                  delivery_type, address, total, items_json, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $ins->execute([
        $external_reference, $name, $email, $phone,
        $deliveryType, $address, round($total, 2), json_encode($validated, JSON_UNESCAPED_UNICODE)
    ]);
} catch (PDOException $e) {
    error_log('No se pudo guardar el pedido: ' . $e->getMessage());
}
*/

// ====== MODO DEMO (sin token de Mercado Pago) ======
if ($MP_ACCESS_TOKEN === '') {
    echo json_encode([
        'ok'           => true,
        'demo'         => true,
        'checkout_url' => $BASE_URL . '/tienda_gracias.php?ref=' . urlencode($external_reference)
                          . '&total=' . urlencode(number_format($total, 2, '.', '')),
    ]);
    exit;
}

// ====== Mercado Pago Checkout Pro ======
$preference = [
    'items' => $validated,
    'payer' => [
        'name'  => $name,
        'email' => $email,
    ],
    'external_reference' => $external_reference,
    'back_urls' => [
        'success' => $BASE_URL . '/tienda_gracias.php?status=success',
        'pending' => $BASE_URL . '/tienda_gracias.php?status=pending',
        'failure' => $BASE_URL . '/tienda_gracias.php?status=failure',
    ],
    'auto_return' => 'approved',
    'metadata' => [
        'delivery_type' => $deliveryType,
        'address'       => $address,
        'phone'         => $phone,
    ],
    // 'notification_url' => $BASE_URL . '/tienda_webhook.php', // para confirmar el pago server-to-server
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
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log('MP curl error: ' . $curlErr);
    fail('No se pudo conectar con la pasarela de pago.', 502);
}

$mp = json_decode($response, true);
if ($httpCode >= 200 && $httpCode < 300 && !empty($mp['init_point'])) {
    echo json_encode([
        'ok'           => true,
        'checkout_url' => $mp['init_point'],   // URL de pago de Mercado Pago
        'preference_id'=> $mp['id'] ?? null,
    ]);
    exit;
}

error_log('MP error (' . $httpCode . '): ' . $response);
fail('No se pudo generar el pago. Revisá las credenciales de Mercado Pago.', 502);

<?php
/**
 * carta/track.php — Endpoint centralizado de métricas, embudo y pedidos.
 *
 * Recibe beacons JSON (navigator.sendBeacon / fetch keepalive) desde:
 *   - Home (arrabbiata.com.ar)
 *   - Carta standalone (carta.arrabbiata.com.ar / arrabbiata.com.ar/carta)
 *   - Tienda online (tienda.php / tienda_gracias.php)
 *
 * Tipos soportados:
 *   - 'view'   : visita con path + query string, referrer, UTMs y session_id
 *   - 'funnel' : micro-eventos del embudo (add_to_cart, open_cart, open_pickup_slot)
 *   - 'order'  : pedido enviado (guarda en order_events con phone_hash, order_code,
 *                is_preorder, UTMs y sincroniza con Pizzalog)
 */

require_once dirname(__DIR__) . '/db_connect.php';
date_default_timezone_set('America/Argentina/Cordoba');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"ok":false}';
    exit;
}

// Validación liviana de Origin / Referer cuando el navegador los envía
$originHeader = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
if ($originHeader !== '') {
    $host = strtolower((string)(parse_url($originHeader, PHP_URL_HOST) ?? ''));
    if ($host !== '' && !preg_match('/(^|\.)((arrabbiata|lafamosa)\.com\.ar|localhost|127\.0\.0\.1)$/i', $host)) {
        http_response_code(403);
        echo '{"ok":false}';
        exit;
    }
}

// Clave derivada de la config privada del servidor (sin exponer secretos en el repo)
const TRACK_IP_SALT = 'rrbb-track-2026-cambia-esto';
$__secretKey = (string)($config['track_salt'] ?? ($config['db']['password'] ?? TRACK_IP_SALT));

function trackEnsureSchema(PDO $pdo, bool $force = false): void {
    $flagFile = rtrim(sys_get_temp_dir(), '/\\') . '/rrbb_track_schema_v2.ok';
    if (!$force && @is_file($flagFile) && (time() - (int)@filemtime($flagFile) < 86400)) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_visits (
            id           BIGINT AUTO_INCREMENT PRIMARY KEY,
            created_at   DATETIME NOT NULL,
            source       VARCHAR(16) NOT NULL DEFAULT 'carta',
            path         VARCHAR(255) NULL,
            referrer     VARCHAR(255) NULL,
            ua           VARCHAR(255) NULL,
            ip_hash      CHAR(64) NULL,
            session_id   VARCHAR(40) NULL,
            utm_source   VARCHAR(64) NULL,
            utm_medium   VARCHAR(64) NULL,
            utm_campaign VARCHAR(96) NULL,
            INDEX idx_created (created_at),
            INDEX idx_iphash (ip_hash),
            INDEX idx_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS funnel_events (
            id         BIGINT AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL,
            source     VARCHAR(16) NOT NULL DEFAULT 'carta',
            step       VARCHAR(32) NOT NULL,
            session_id VARCHAR(40) NULL,
            ip_hash    CHAR(64) NULL,
            item_name  VARCHAR(160) NULL,
            value      DECIMAL(10,2) NULL,
            INDEX idx_created_step (created_at, step),
            INDEX idx_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_events (
            id             BIGINT AUTO_INCREMENT PRIMARY KEY,
            created_at     DATETIME NOT NULL,
            source         VARCHAR(16) NOT NULL DEFAULT 'carta',
            order_code     VARCHAR(24) NULL,
            delivery_type  VARCHAR(16) NULL,
            payment_method VARCHAR(32) NULL,
            total          DECIMAL(10,2) NULL,
            pickup_time    VARCHAR(32) NULL,
            item_count     INT NULL,
            items_json     TEXT NULL,
            ip_hash        CHAR(64) NULL,
            phone_hash     CHAR(64) NULL,
            session_id     VARCHAR(40) NULL,
            is_preorder    TINYINT(1) NOT NULL DEFAULT 0,
            utm_source     VARCHAR(64) NULL,
            utm_campaign   VARCHAR(96) NULL,
            status         VARCHAR(16) NOT NULL DEFAULT 'confirmed',
            INDEX idx_created (created_at),
            INDEX idx_phonehash (phone_hash),
            INDEX idx_ordercode (order_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Migraciones aditivas seguras sobre tablas existentes de versiones previas
    $visitCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM site_visits")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $visitCols[$c['Field']] = true;
    }
    if (!isset($visitCols['session_id']))   $pdo->exec("ALTER TABLE site_visits ADD COLUMN session_id VARCHAR(40) NULL");
    if (!isset($visitCols['utm_source']))   $pdo->exec("ALTER TABLE site_visits ADD COLUMN utm_source VARCHAR(64) NULL");
    if (!isset($visitCols['utm_medium']))   $pdo->exec("ALTER TABLE site_visits ADD COLUMN utm_medium VARCHAR(64) NULL");
    if (!isset($visitCols['utm_campaign'])) $pdo->exec("ALTER TABLE site_visits ADD COLUMN utm_campaign VARCHAR(96) NULL");

    $orderCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM order_events")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $orderCols[$c['Field']] = true;
    }
    if (!isset($orderCols['order_code']))   $pdo->exec("ALTER TABLE order_events ADD COLUMN order_code VARCHAR(24) NULL AFTER source");
    if (!isset($orderCols['phone_hash']))   $pdo->exec("ALTER TABLE order_events ADD COLUMN phone_hash CHAR(64) NULL AFTER ip_hash");
    if (!isset($orderCols['session_id']))   $pdo->exec("ALTER TABLE order_events ADD COLUMN session_id VARCHAR(40) NULL AFTER phone_hash");
    if (!isset($orderCols['is_preorder']))  $pdo->exec("ALTER TABLE order_events ADD COLUMN is_preorder TINYINT(1) NOT NULL DEFAULT 0 AFTER session_id");
    if (!isset($orderCols['utm_source']))   $pdo->exec("ALTER TABLE order_events ADD COLUMN utm_source VARCHAR(64) NULL AFTER is_preorder");
    if (!isset($orderCols['utm_campaign'])) $pdo->exec("ALTER TABLE order_events ADD COLUMN utm_campaign VARCHAR(96) NULL AFTER utm_source");
    if (!isset($orderCols['status']))       $pdo->exec("ALTER TABLE order_events ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'confirmed' AFTER utm_campaign");

    @file_put_contents($flagFile, (string)time());
}

function clientIpHash(): string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';
    if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
    return hash('sha256', TRACK_IP_SALT . '|' . $ip);
}

function normalizePhoneHash(string $rawPhone, string $secretKey): ?string {
    $digits = preg_replace('/\D+/', '', $rawPhone);
    if ($digits === null || strlen($digits) < 7) return null;
    // Normaliza prefijos argentinos (+54 9 351 ... / 0351 ...) quedándose con los últimos 10 dígitos
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }
    return hash_hmac('sha256', $digits, $secretKey);
}

function isAutomatedBotUa(string $ua): bool {
    if ($ua === '') return true;
    return (bool)preg_match('/(bot|crawl|spider|slurp|facebookexternalhit|lighthouse|headless|curl|wget|python-requests|scrapy|go-http-client)/i', $ua);
}

function normPayment(string $p): string {
    $k = strtolower(trim($p));
    $k = strtr($k, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']);
    $map = [
        'transferencia' => 'Transferencia',
        'efectivo'      => 'Efectivo',
        'debito'        => 'Débito',
        'credito'       => 'Crédito',
        'mercadopago'   => 'Mercado Pago',
    ];
    if (isset($map[$k])) return $map[$k];
    return $p !== '' ? $p : '(sin dato)';
}

try {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 25000) { http_response_code(400); echo '{"ok":false}'; exit; }

    $data = json_decode($raw, true);
    if (!is_array($data)) { http_response_code(400); echo '{"ok":false}'; exit; }

    try {
        trackEnsureSchema($pdo, false);
    } catch (Throwable $se) {
        trackEnsureSchema($pdo, true);
    }

    $now         = date('Y-m-d H:i:s');
    $type        = (string)($data['type'] ?? '');
    $source      = substr((string)($data['source'] ?? 'carta'), 0, 16);
    $ua          = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $iph         = clientIpHash();
    $sessionId   = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['session_id'] ?? '')), 0, 40) ?: null;
    $utmSource   = substr(trim((string)($data['utm_source'] ?? '')), 0, 64) ?: null;
    $utmMedium   = substr(trim((string)($data['utm_medium'] ?? '')), 0, 64) ?: null;
    $utmCampaign = substr(trim((string)($data['utm_campaign'] ?? '')), 0, 96) ?: null;

    // Extraer UTMs desde path si aún no vinieron desglosados
    $pathStr = substr((string)($data['path'] ?? ''), 0, 255);
    if ($utmSource === null && strpos($pathStr, '?') !== false) {
        $qs = parse_url($pathStr, PHP_URL_QUERY);
        if ($qs) {
            parse_str($qs, $qp);
            if (!empty($qp['utm_source']))   $utmSource   = substr(trim((string)$qp['utm_source']), 0, 64);
            if (!empty($qp['utm_medium']))   $utmMedium   = substr(trim((string)$qp['utm_medium']), 0, 64);
            if (!empty($qp['utm_campaign'])) $utmCampaign = substr(trim((string)$qp['utm_campaign']), 0, 96);
        }
    }

    if ($type === 'view') {
        $insVis = function() use ($pdo, $now, $source, $pathStr, $data, $ua, $iph, $sessionId, $utmSource, $utmMedium, $utmCampaign) {
            $st = $pdo->prepare("
                INSERT INTO site_visits (created_at, source, path, referrer, ua, ip_hash, session_id, utm_source, utm_medium, utm_campaign)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $st->execute([
                $now,
                $source,
                $pathStr,
                substr((string)($data['ref'] ?? ''), 0, 255),
                $ua,
                $iph,
                $sessionId,
                $utmSource,
                $utmMedium,
                $utmCampaign,
            ]);
        };
        try {
            $insVis();
        } catch (PDOException $e) {
            trackEnsureSchema($pdo, true);
            $insVis();
        }
        echo '{"ok":true}';
        exit;
    }

    if ($type === 'funnel') {
        if (isAutomatedBotUa($ua)) { echo '{"ok":true}'; exit; }
        $step = (string)($data['step'] ?? '');
        if (!in_array($step, ['add_to_cart', 'open_cart', 'open_pickup_slot'], true)) {
            http_response_code(400); echo '{"ok":false}'; exit;
        }
        $insFun = function() use ($pdo, $now, $source, $step, $sessionId, $iph, $data) {
            $st = $pdo->prepare("
                INSERT INTO funnel_events (created_at, source, step, session_id, ip_hash, item_name, value)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $st->execute([
                $now,
                $source,
                $step,
                $sessionId,
                $iph,
                substr(trim((string)($data['item_name'] ?? '')), 0, 160) ?: null,
                is_numeric($data['value'] ?? null) ? round((float)$data['value'], 2) : null,
            ]);
        };
        try {
            $insFun();
        } catch (PDOException $e) {
            trackEnsureSchema($pdo, true);
            $insFun();
        }
        echo '{"ok":true}';
        exit;
    }

    if ($type === 'order') {
        // Anti-abuso: bloquear bots automatizados y ráfagas de pedidos falsos
        if (isAutomatedBotUa($ua)) {
            echo '{"ok":true}';
            exit;
        }
        $rlStmt = $pdo->prepare("SELECT COUNT(*) FROM order_events WHERE ip_hash = ? AND created_at >= DATE_SUB(?, INTERVAL 5 MINUTE)");
        $rlStmt->execute([$iph, $now]);
        if ((int)$rlStmt->fetchColumn() >= 6) {
            http_response_code(429);
            echo '{"ok":false,"error":"rate_limited"}';
            exit;
        }

        $items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : [];
        $total = is_numeric($data['total'] ?? null) ? round((float)$data['total'], 2) : null;
        if (empty($items) || $total === null || $total <= 0 || $total > 5000000) {
            http_response_code(400);
            echo '{"ok":false}';
            exit;
        }

        $orderCode  = substr(preg_replace('/[^a-zA-Z0-9\-]/', '', (string)($data['order_code'] ?? '')), 0, 24) ?: null;
        $rawPhone   = trim((string)($data['customer_phone'] ?? ''));
        $phoneHash  = normalizePhoneHash($rawPhone, $__secretKey);
        $isPreorder = !empty($data['is_preorder']) ? 1 : 0;

        $insOrd = function() use ($pdo, $now, $source, $orderCode, $data, $total, $items, $iph, $phoneHash, $sessionId, $isPreorder, $utmSource, $utmCampaign) {
            $st = $pdo->prepare("
                INSERT INTO order_events
                    (created_at, source, order_code, delivery_type, payment_method, total, pickup_time, item_count, items_json, ip_hash, phone_hash, session_id, is_preorder, utm_source, utm_campaign, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed')
            ");
            $st->execute([
                $now,
                $source,
                $orderCode,
                substr((string)($data['delivery_type'] ?? ''), 0, 16),
                normPayment((string)($data['payment_method'] ?? '')),
                $total,
                substr((string)($data['pickup_time'] ?? ''), 0, 32),
                max(1, (int)($data['item_count'] ?? count($items))),
                substr((string)json_encode($items, JSON_UNESCAPED_UNICODE), 0, 60000),
                $iph,
                $phoneHash,
                $sessionId,
                $isPreorder,
                $utmSource,
                $utmCampaign,
            ]);
        };
        try {
            $insOrd();
        } catch (PDOException $e) {
            trackEnsureSchema($pdo, true);
            $insOrd();
        }

        // Sincronizar pedido de pizzería automáticamente con Pizzalog (no para tienda de merch)
        if ($source !== 'tienda') {
            try {
                $pizzalogUrl = 'https://api.pizzalog.net/public/arrabbiata/external-order';
                $notes = trim((string)($data['notes'] ?? ''));
                if ($orderCode) {
                    $notes = ($notes !== '' ? "[#{$orderCode}] {$notes}" : "[#{$orderCode}]");
                }
                $payload = json_encode([
                    'order_code'     => $orderCode,
                    'customer_name'  => trim((string)($data['customer_name'] ?? 'Cliente Carta Web')),
                    'customer_phone' => $rawPhone,
                    'address'        => trim((string)($data['address'] ?? '')),
                    'notes'          => $notes,
                    'delivery_type'  => (string)($data['delivery_type'] ?? 'retiro'),
                    'payment_method' => (string)($data['payment_method'] ?? 'Efectivo'),
                    'pickup_time'    => (string)($data['pickup_time'] ?? ''),
                    'is_preorder'    => (bool)$isPreorder,
                    'total'          => $total,
                    'items'          => $items,
                ], JSON_UNESCAPED_UNICODE);

                if (function_exists('curl_init')) {
                    $ch = curl_init($pizzalogUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $payload,
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 5,
                        CURLOPT_CONNECTTIMEOUT => 3,
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                } else {
                    $ctx = stream_context_create([
                        'http' => [
                            'method'  => 'POST',
                            'header'  => "Content-Type: application/json\r\n",
                            'content' => $payload,
                            'timeout' => 5,
                        ],
                    ]);
                    @file_get_contents($pizzalogUrl, false, $ctx);
                }
            } catch (Throwable $syncErr) {
                error_log('track.php Pizzalog sync: ' . $syncErr->getMessage());
            }
        }

        echo '{"ok":true}';
        exit;
    }

    http_response_code(400);
    echo '{"ok":false}';
} catch (Throwable $e) {
    error_log('track.php: ' . $e->getMessage());
    http_response_code(500);
    echo '{"ok":false}';
}

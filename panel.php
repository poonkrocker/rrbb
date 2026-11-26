<?php
/**
 * panel.php — Panel principal de inteligencia comercial, embudo y ventas de Arrabbiata.
 */

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/db_connect.php';
date_default_timezone_set('America/Argentina/Cordoba');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Asegurar esquema v2 (tablas + columnas nuevas) ──
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_visits (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'carta', path VARCHAR(255) NULL,
            referrer VARCHAR(255) NULL, ua VARCHAR(255) NULL, ip_hash CHAR(64) NULL,
            session_id VARCHAR(40) NULL, utm_source VARCHAR(64) NULL,
            utm_medium VARCHAR(64) NULL, utm_campaign VARCHAR(96) NULL,
            INDEX idx_created (created_at), INDEX idx_iphash (ip_hash), INDEX idx_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS funnel_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'carta', step VARCHAR(32) NOT NULL,
            session_id VARCHAR(40) NULL, ip_hash CHAR(64) NULL,
            item_name VARCHAR(160) NULL, value DECIMAL(10,2) NULL,
            INDEX idx_created_step (created_at, step), INDEX idx_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'carta', order_code VARCHAR(24) NULL,
            delivery_type VARCHAR(16) NULL, payment_method VARCHAR(32) NULL,
            total DECIMAL(10,2) NULL, pickup_time VARCHAR(32) NULL,
            item_count INT NULL, items_json TEXT NULL, ip_hash CHAR(64) NULL,
            phone_hash CHAR(64) NULL, session_id VARCHAR(40) NULL,
            is_preorder TINYINT(1) NOT NULL DEFAULT 0, utm_source VARCHAR(64) NULL,
            utm_campaign VARCHAR(96) NULL, status VARCHAR(16) NOT NULL DEFAULT 'confirmed',
            INDEX idx_created (created_at), INDEX idx_phonehash (phone_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $vCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM site_visits")->fetchAll(PDO::FETCH_ASSOC) as $c) $vCols[$c['Field']] = true;
    if (!isset($vCols['session_id']))   $pdo->exec("ALTER TABLE site_visits ADD COLUMN session_id VARCHAR(40) NULL");
    if (!isset($vCols['utm_source']))   $pdo->exec("ALTER TABLE site_visits ADD COLUMN utm_source VARCHAR(64) NULL");
    if (!isset($vCols['utm_medium']))   $pdo->exec("ALTER TABLE site_visits ADD COLUMN utm_medium VARCHAR(64) NULL");
    if (!isset($vCols['utm_campaign'])) $pdo->exec("ALTER TABLE site_visits ADD COLUMN utm_campaign VARCHAR(96) NULL");

    $oCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM order_events")->fetchAll(PDO::FETCH_ASSOC) as $c) $oCols[$c['Field']] = true;
    if (!isset($oCols['order_code']))   $pdo->exec("ALTER TABLE order_events ADD COLUMN order_code VARCHAR(24) NULL AFTER source");
    if (!isset($oCols['phone_hash']))   $pdo->exec("ALTER TABLE order_events ADD COLUMN phone_hash CHAR(64) NULL AFTER ip_hash");
    if (!isset($oCols['session_id']))   $pdo->exec("ALTER TABLE order_events ADD COLUMN session_id VARCHAR(40) NULL AFTER phone_hash");
    if (!isset($oCols['is_preorder']))  $pdo->exec("ALTER TABLE order_events ADD COLUMN is_preorder TINYINT(1) NOT NULL DEFAULT 0 AFTER session_id");
    if (!isset($oCols['utm_source']))   $pdo->exec("ALTER TABLE order_events ADD COLUMN utm_source VARCHAR(64) NULL AFTER is_preorder");
    if (!isset($oCols['utm_campaign'])) $pdo->exec("ALTER TABLE order_events ADD COLUMN utm_campaign VARCHAR(96) NULL AFTER utm_source");
    if (!isset($oCols['status']))       $pdo->exec("ALTER TABLE order_events ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'confirmed' AFTER utm_campaign");
} catch (Throwable $e) {
    error_log('panel.php ensure schema: ' . $e->getMessage());
}

// ── Acción AJAX: alternar pedido confirmado <-> anulado/prueba ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_order_status') {
    header('Content-Type: application/json; charset=utf-8');
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF inválido']);
        exit;
    }
    $oid = (int)($_POST['id'] ?? 0);
    if ($oid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'ID inválido']);
        exit;
    }
    $stCur = $pdo->prepare("SELECT status FROM order_events WHERE id = ?");
    $stCur->execute([$oid]);
    $cur = (string)$stCur->fetchColumn();
    $next = ($cur === 'cancelled') ? 'confirmed' : 'cancelled';
    $pdo->prepare("UPDATE order_events SET status = ? WHERE id = ?")->execute([$next, $oid]);
    echo json_encode(['ok' => true, 'status' => $next]);
    exit;
}

// ── Parámetros de filtro ──
$rangePreset = isset($_GET['range']) ? (string)$_GET['range'] : (isset($_GET['days']) ? (string)$_GET['days'] : '30');
$channel     = isset($_GET['channel']) ? (string)$_GET['channel'] : 'pizzeria';
if (!in_array($channel, ['pizzeria', 'carta', 'home', 'tienda', 'all'], true)) {
    $channel = 'pizzeria';
}
$hideBots = !isset($_GET['bots']) || $_GET['bots'] !== '1';

$customFrom = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$customTo   = isset($_GET['to'])   ? trim((string)$_GET['to'])   : '';

if ($customFrom !== '' && $customTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo)) {
    $rangePreset = 'custom';
    $sinceDt = new DateTime($customFrom . ' 00:00:00');
    $untilDt = new DateTime($customTo . ' 23:59:59');
    if ($sinceDt > $untilDt) [$sinceDt, $untilDt] = [$untilDt, $sinceDt];
} else {
    switch ($rangePreset) {
        case 'today':
            $sinceDt = new DateTime('today 00:00:00');
            $untilDt = new DateTime('today 23:59:59');
            break;
        case 'yesterday':
            $sinceDt = new DateTime('yesterday 00:00:00');
            $untilDt = new DateTime('yesterday 23:59:59');
            break;
        case '7':
            $sinceDt = new DateTime('today -6 days 00:00:00');
            $untilDt = new DateTime('today 23:59:59');
            break;
        case '90':
            $sinceDt = new DateTime('today -89 days 00:00:00');
            $untilDt = new DateTime('today 23:59:59');
            break;
        case '365':
            $sinceDt = new DateTime('today -364 days 00:00:00');
            $untilDt = new DateTime('today 23:59:59');
            break;
        case '30':
        default:
            $rangePreset = '30';
            $sinceDt = new DateTime('today -29 days 00:00:00');
            $untilDt = new DateTime('today 23:59:59');
            break;
    }
}

$since = $sinceDt->format('Y-m-d H:i:s');
$until = $untilDt->format('Y-m-d H:i:s');

$periodSeconds = max(86400, $untilDt->getTimestamp() - $sinceDt->getTimestamp() + 1);
$prevUntilDt   = (clone $sinceDt)->modify('-1 second');
$prevSinceDt   = (clone $prevUntilDt)->modify('-' . ($periodSeconds - 1) . ' seconds');
$prevSince     = $prevSinceDt->format('Y-m-d H:i:s');
$prevUntil     = $prevUntilDt->format('Y-m-d H:i:s');

function buildChannelSql(string $channel, array &$params): string {
    if (in_array($channel, ['carta', 'home', 'tienda'], true)) {
        $params[] = $channel;
        return ' AND source = ? ';
    }
    if ($channel === 'pizzeria') {
        return " AND source IN ('carta', 'home', '') ";
    }
    return '';
}

function buildBotSql(bool $hideBots): string {
    if (!$hideBots) return '';
    return " AND (ua IS NULL OR (
        LOWER(ua) NOT LIKE '%bot%' AND
        LOWER(ua) NOT LIKE '%crawl%' AND
        LOWER(ua) NOT LIKE '%spider%' AND
        LOWER(ua) NOT LIKE '%slurp%' AND
        LOWER(ua) NOT LIKE '%facebookexternalhit%' AND
        LOWER(ua) NOT LIKE '%whatsapp%' AND
        LOWER(ua) NOT LIKE '%lighthouse%' AND
        LOWER(ua) NOT LIKE '%headless%'
    )) ";
}

$money = fn($n) => '$' . number_format((float)$n, 0, ',', '.');

// ── Exportación CSV (solo pedidos confirmados/activos) ──
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $p = [$since, $until];
        $chSql = buildChannelSql($channel, $p);
        $st = $pdo->prepare("
            SELECT id, order_code, created_at, source, delivery_type, payment_method, pickup_time, is_preorder, utm_source, utm_campaign, item_count, total, items_json, COALESCE(NULLIF(phone_hash,''), ip_hash) AS cust_id
            FROM order_events
            WHERE created_at BETWEEN ? AND ? AND (status IS NULL OR status != 'cancelled') $chSql
            ORDER BY created_at DESC
        ");
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="pedidos-arrabbiata-' . $sinceDt->format('Ymd') . '-' . $untilDt->format('Ymd') . '.csv"');
        $fp = fopen('php://output', 'w');
        fprintf($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['ID', 'Código', 'Fecha y Hora', 'Día', 'Canal', 'Preventa', 'Entrega', 'Forma de Pago', 'Horario Elegido', 'Origen UTM', 'Campaña UTM', 'Unidades', 'Total ($)', 'Detalle de Productos', 'ID Cliente (Hash)']);
        $diasEs = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        foreach ($rows as $r) {
            $itemsArr = json_decode((string)($r['items_json'] ?? ''), true);
            $itemsTxt = [];
            if (is_array($itemsArr)) {
                foreach ($itemsArr as $it) {
                    $nm = trim((string)($it['name'] ?? ''));
                    $qt = (int)($it['qty'] ?? 1);
                    $pr = isset($it['price']) ? ' ($' . number_format((float)$it['price'], 0, ',', '.') . ')' : '';
                    if ($nm !== '') $itemsTxt[] = "{$nm} x{$qt}{$pr}";
                }
            }
            $ts = strtotime((string)$r['created_at']);
            $diaNombre = $ts ? $diasEs[(int)date('w', $ts)] : '';
            fputcsv($fp, [
                $r['id'],
                $r['order_code'] ?: '-',
                $r['created_at'],
                $diaNombre,
                $r['source'],
                !empty($r['is_preorder']) ? 'Sí (Cerrado)' : 'No (En turno)',
                $r['delivery_type'] === 'envio' ? 'Envío' : ($r['delivery_type'] === 'retiro' ? 'Retiro' : $r['delivery_type']),
                $r['payment_method'],
                $r['pickup_time'] ?: 'Ahora',
                $r['utm_source'] ?: '',
                $r['utm_campaign'] ?: '',
                (int)$r['item_count'],
                number_format((float)$r['total'], 2, '.', ''),
                implode(' | ', $itemsTxt),
                substr((string)$r['cust_id'], 0, 12),
            ]);
        }
        fclose($fp);
        exit;
    } catch (Throwable $e) {
        error_log('panel export csv: ' . $e->getMessage());
    }
}

// ── Cargar catálogo y precios de extras ──
$catalogByName = [];
$extrasPriceMap = [];
$categoriesMap = [];
try {
    foreach ($pdo->query("SELECT id, name FROM categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $categoriesMap[(int)$c['id']] = (string)$c['name'];
    }
    foreach ($pdo->query("SELECT id, name, price, category_id, is_secret_menu, is_weekly_special FROM menu_items")->fetchAll(PDO::FETCH_ASSOC) as $mi) {
        $catName = $categoriesMap[(int)$mi['category_id']] ?? 'Otras';
        $key = mb_strtolower(trim((string)$mi['name']), 'UTF-8');
        $catalogByName[$key] = [
            'id'        => (int)$mi['id'],
            'name'      => (string)$mi['name'],
            'price'     => (float)$mi['price'],
            'category'  => $catName,
        ];
        if (mb_strtolower(trim($catName), 'UTF-8') === 'extras') {
            $extrasPriceMap[$key] = ['name' => (string)$mi['name'], 'price' => (float)$mi['price']];
        }
    }
} catch (Throwable $e) {
    error_log('panel catalog load: ' . $e->getMessage());
}

function classifyCartItemName(string $rawName, array $catalogByName): array {
    $clean   = trim($rawName);
    $isVegan = (stripos($clean, '(Vegana)') !== false);

    // Extraer extras "(+ Stracciatella, + Anchoas)"
    $extrasList = [];
    if (preg_match('/\(\+\s*([^)]+)\)/u', $clean, $em)) {
        $parts = preg_split('/,\s*\+?\s*/u', $em[1]);
        foreach ($parts as $ep) {
            $ep = trim(ltrim(trim($ep), '+'));
            if ($ep !== '') $extrasList[] = $ep;
        }
    }

    $base = preg_replace('/\s*\(\+[^)]+\)/u', '', $clean);
    $base = preg_replace('/\s*\((Vegana|Para tu pizza)\)/iu', '', (string)$base);
    if (strpos($base, ' — ') !== false) $base = trim(explode(' — ', $base)[0]);
    elseif (strpos($base, ' - ') !== false) $base = trim(explode(' - ', $base)[0]);
    if (strpos($base, ' + ') !== false) $base = trim(explode(' + ', $base)[0]);

    $key = mb_strtolower(trim((string)$base), 'UTF-8');
    if (isset($catalogByName[$key])) {
        return [
            'base_name'     => $catalogByName[$key]['name'],
            'category'      => $catalogByName[$key]['category'],
            'catalog_price' => $catalogByName[$key]['price'],
            'is_vegan'      => $isVegan,
            'extras'        => $extrasList,
        ];
    }
    foreach ($catalogByName as $k => $info) {
        if ($k !== '' && (mb_strpos($key, $k, 0, 'UTF-8') === 0 || mb_strpos($k, $key, 0, 'UTF-8') === 0)) {
            return [
                'base_name'     => $info['name'],
                'category'      => $info['category'],
                'catalog_price' => $info['price'],
                'is_vegan'      => $isVegan,
                'extras'        => $extrasList,
            ];
        }
    }
    return [
        'base_name'     => $base ?: $clean,
        'category'      => 'Otros / Especiales',
        'catalog_price' => 0.0,
        'is_vegan'      => $isVegan,
        'extras'        => $extrasList,
    ];
}

function classifyTrafficSource(?string $utmSrc, ?string $ref): string {
    $u = strtolower(trim((string)$utmSrc));
    $r = strtolower(trim((string)$ref));
    if ($u !== '') {
        if (strpos($u, 'link') !== false || strpos($u, 'bio') !== false) return 'Link en Bio (/links)';
        if (strpos($u, 'ig') !== false || strpos($u, 'insta') !== false) return 'Instagram (UTM/Ads)';
        if (strpos($u, 'fb') !== false || strpos($u, 'face') !== false || strpos($u, 'meta') !== false) return 'Facebook / Meta Ads';
        if (strpos($u, 'google') !== false) return 'Google Ads / UTM';
        if (strpos($u, 'qr') !== false) return 'Código QR';
        return 'UTM: ' . $utmSrc;
    }
    if ($r === '' || strpos($r, 'arrabbiata.com.ar') !== false) {
        if (strpos($r, 'links.arrabbiata') !== false || strpos($r, '/links') !== false) return 'Link en Bio (/links)';
        return 'Directo / QR / Bio';
    }
    if (strpos($r, 'instagram') !== false || strpos($r, 'ig.me') !== false) return 'Instagram';
    if (strpos($r, 'google') !== false || strpos($r, 'maps') !== false) return 'Google / Maps';
    if (strpos($r, 'whatsapp') !== false || strpos($r, 'wa.me') !== false) return 'WhatsApp';
    if (strpos($r, 'facebook') !== false || strpos($r, 'fb.') !== false) return 'Facebook';
    return 'Otros sitios';
}

// ── KPIs de un período (identificando clientes recurrentes por phone_hash o ip_hash) ──
function fetchPeriodKpis(PDO $pdo, string $from, string $to, string $channel, bool $hideBots): array {
    $res = [
        'visitas' => 0, 'unicos' => 0, 'sesiones' => 0, 'bots_filtrados' => 0,
        'pedidos' => 0, 'clientes_unicos' => 0, 'clientes_recurrentes' => 0,
        'facturacion' => 0.0, 'items' => 0, 'preorders' => 0, 'preorder_rev' => 0.0,
    ];
    try {
        $pVis  = [$from, $to];
        $chSql = buildChannelSql($channel, $pVis);
        $botSql = buildBotSql($hideBots);
        $st = $pdo->prepare("
            SELECT COUNT(*) c,
                   COUNT(DISTINCT ip_hash) u,
                   COUNT(DISTINCT COALESCE(NULLIF(session_id,''), ip_hash)) s
            FROM site_visits
            WHERE created_at BETWEEN ? AND ? $chSql $botSql
        ");
        $st->execute($pVis);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $res['visitas']  = (int)($row['c'] ?? 0);
        $res['unicos']   = (int)($row['u'] ?? 0);
        $res['sesiones'] = (int)($row['s'] ?? 0);

        if ($hideBots) {
            $pAll  = [$from, $to];
            $chAll = buildChannelSql($channel, $pAll);
            $stB   = $pdo->prepare("SELECT COUNT(*) FROM site_visits WHERE created_at BETWEEN ? AND ? $chAll");
            $stB->execute($pAll);
            $res['bots_filtrados'] = max(0, (int)$stB->fetchColumn() - $res['visitas']);
        }

        $pOrd  = [$from, $to];
        $chOrd = buildChannelSql($channel, $pOrd);
        $st = $pdo->prepare("
            SELECT COUNT(*) c,
                   COUNT(DISTINCT COALESCE(NULLIF(phone_hash,''), ip_hash)) u,
                   COALESCE(SUM(total), 0) s,
                   COALESCE(SUM(item_count), 0) it,
                   COALESCE(SUM(CASE WHEN is_preorder = 1 THEN 1 ELSE 0 END), 0) pre_c,
                   COALESCE(SUM(CASE WHEN is_preorder = 1 THEN total ELSE 0 END), 0) pre_s
            FROM order_events
            WHERE created_at BETWEEN ? AND ? AND (status IS NULL OR status != 'cancelled') $chOrd
        ");
        $st->execute($pOrd);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $res['pedidos']         = (int)($row['c'] ?? 0);
        $res['clientes_unicos'] = (int)($row['u'] ?? 0);
        $res['facturacion']     = (float)($row['s'] ?? 0);
        $res['items']           = (int)($row['it'] ?? 0);
        $res['preorders']       = (int)($row['pre_c'] ?? 0);
        $res['preorder_rev']    = (float)($row['pre_s'] ?? 0);

        $stRec = $pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT COALESCE(NULLIF(phone_hash,''), ip_hash) AS cid
                FROM order_events
                WHERE created_at BETWEEN ? AND ?
                  AND (status IS NULL OR status != 'cancelled')
                  AND COALESCE(NULLIF(phone_hash,''), ip_hash) IS NOT NULL
                  AND COALESCE(NULLIF(phone_hash,''), ip_hash) != ''
                  $chOrd
                GROUP BY cid
                HAVING COUNT(*) >= 2
            ) t
        ");
        $stRec->execute($pOrd);
        $res['clientes_recurrentes'] = (int)$stRec->fetchColumn();
    } catch (Throwable $e) {
        error_log('panel fetchPeriodKpis: ' . $e->getMessage());
    }
    $res['ticket']      = $res['pedidos'] > 0 ? $res['facturacion'] / $res['pedidos'] : 0.0;
    $res['items_x_ped'] = $res['pedidos'] > 0 ? $res['items'] / $res['pedidos'] : 0.0;
    $res['conversion']  = $res['visitas'] > 0 ? ($res['pedidos'] / $res['visitas']) * 100.0 : 0.0;
    $res['conv_unicos'] = $res['unicos']  > 0 ? ($res['pedidos'] / $res['unicos']) * 100.0 : 0.0;
    return $res;
}

$kpi     = fetchPeriodKpis($pdo, $since, $until, $channel, $hideBots);
$prevKpi = fetchPeriodKpis($pdo, $prevSince, $prevUntil, $channel, $hideBots);

function fmtDelta(float $curr, float $prev): string {
    if ($prev <= 0 && $curr <= 0) return '<span class="delta neutral">= s/c</span>';
    if ($prev <= 0) return '<span class="delta up">▲ Nuevo</span>';
    $pct = (($curr - $prev) / $prev) * 100.0;
    if (abs($pct) < 0.5) return '<span class="delta neutral">= 0%</span>';
    $cls  = $pct > 0 ? 'up' : 'down';
    $sign = $pct > 0 ? '▲ +' : '▼ ';
    return '<span class="delta ' . $cls . '" title="Período anterior: ' . number_format($prev, 1, ',', '.') . '">' . $sign . number_format($pct, 1, ',', '.') . '%</span>';
}

// ── Embudo de Conversión por sesiones únicas ──
$funnelSteps = [
    'view'             => $kpi['sesiones'] > 0 ? $kpi['sesiones'] : $kpi['unicos'],
    'add_to_cart'      => 0,
    'open_cart'        => 0,
    'open_pickup_slot' => 0,
    'order'            => 0,
];
try {
    $pFun  = [$since, $until];
    $chFun = buildChannelSql($channel, $pFun);
    $stF = $pdo->prepare("
        SELECT step, COUNT(DISTINCT COALESCE(NULLIF(session_id,''), ip_hash)) AS cnt
        FROM funnel_events
        WHERE created_at BETWEEN ? AND ? $chFun
        GROUP BY step
    ");
    $stF->execute($pFun);
    foreach ($stF->fetchAll(PDO::FETCH_ASSOC) as $fr) {
        $sk = (string)$fr['step'];
        if (isset($funnelSteps[$sk])) $funnelSteps[$sk] = (int)$fr['cnt'];
    }

    $pOrdF  = [$since, $until];
    $chOrdF = buildChannelSql($channel, $pOrdF);
    $stOF = $pdo->prepare("
        SELECT COUNT(DISTINCT COALESCE(NULLIF(session_id,''), NULLIF(phone_hash,''), ip_hash))
        FROM order_events
        WHERE created_at BETWEEN ? AND ? AND (status IS NULL OR status != 'cancelled') $chOrdF
    ");
    $stOF->execute($pOrdF);
    $funnelSteps['order'] = (int)$stOF->fetchColumn();
} catch (Throwable $e) {
    error_log('panel funnel query: ' . $e->getMessage());
}

// ── Series diarias, orígenes, dispositivos y pedidos ──
$labels = []; $visByDay = []; $ordByDay = []; $revByDay = [];
$cursor = new DateTime($sinceDt->format('Y-m-d'));
$endDay = new DateTime($untilDt->format('Y-m-d'));
$idxByDate = [];
$i = 0;
while ($cursor <= $endDay) {
    $d = $cursor->format('Y-m-d');
    $labels[]   = $cursor->format('d/m');
    $visByDay[] = 0;
    $ordByDay[] = 0;
    $revByDay[] = 0.0;
    $idxByDate[$d] = $i++;
    $cursor->modify('+1 day');
}

$dowVisits     = array_fill(0, 7, 0);
$dowOrders     = array_fill(0, 7, 0);
$dowRev        = array_fill(0, 7, 0.0);
$heatmapOrders = array_fill(0, 7, array_fill(0, 24, 0));
$heatmapVisits = array_fill(0, 7, array_fill(0, 24, 0));

$attrSources = []; // origen => ['vis'=>0, 'ord'=>0, 'rev'=>0.0]
$devices     = ['Celular (Android)' => 0, 'Celular (iPhone)' => 0, 'Computadora (PC/Mac)' => 0, 'Otros' => 0];
$channelsVis = []; $channelsOrd = []; $channelsRev = [];

try {
    $pVis  = [$since, $until];
    $chSql = buildChannelSql($channel, $pVis);
    $botSql = buildBotSql($hideBots);
    $st = $pdo->prepare("SELECT created_at, source, referrer, ua, utm_source, utm_campaign FROM site_visits WHERE created_at BETWEEN ? AND ? $chSql $botSql");
    $st->execute($pVis);
    while ($v = $st->fetch(PDO::FETCH_ASSOC)) {
        $ts = strtotime((string)$v['created_at']);
        if ($ts) {
            $d = date('Y-m-d', $ts);
            if (isset($idxByDate[$d])) $visByDay[$idxByDate[$d]]++;
            $w = ((int)date('N', $ts)) - 1;
            $h = (int)date('G', $ts);
            $dowVisits[$w]++;
            $heatmapVisits[$w][$h]++;
        }
        $src = trim((string)($v['source'] ?? '')) ?: 'carta';
        $channelsVis[$src] = ($channelsVis[$src] ?? 0) + 1;

        $originLabel = classifyTrafficSource($v['utm_source'] ?? null, $v['referrer'] ?? null);
        if (!isset($attrSources[$originLabel])) $attrSources[$originLabel] = ['vis' => 0, 'ord' => 0, 'rev' => 0.0];
        $attrSources[$originLabel]['vis']++;

        $ua = strtolower((string)($v['ua'] ?? ''));
        if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) $devices['Celular (iPhone)']++;
        elseif (strpos($ua, 'android') !== false) $devices['Celular (Android)']++;
        elseif (strpos($ua, 'windows') !== false || strpos($ua, 'macintosh') !== false || strpos($ua, 'linux x86') !== false) $devices['Computadora (PC/Mac)']++;
        else $devices['Otros']++;
    }
    $devices = array_filter($devices, fn($v) => $v > 0);
} catch (Throwable $e) {
    error_log('panel visits analysis: ' . $e->getMessage());
}

$pagoCount = []; $pagoRev = [];
$entregaCount = []; $entregaRev = [];
$franjas = [];
$timingModes = [
    '⚡ Ahora (lo antes posible)' => 0,
    '🕒 Horario específico del turno' => 0,
    '📅 Preventa (pedido con local cerrado)' => 0,
];
$prodStats = [];
$catStats  = [];
$pairStats = [];
$extrasStats = []; // extraName => ['qty'=>0, 'rev'=>0.0]
$ordersWithExtras = 0;
$veganUnits = 0;
$recentOrders = [];

try {
    $pOrd  = [$since, $until];
    $chOrd = buildChannelSql($channel, $pOrd);
    $st = $pdo->prepare("
        SELECT id, order_code, created_at, source, delivery_type, payment_method, total, pickup_time, item_count, items_json, ip_hash, phone_hash, is_preorder, utm_source, utm_campaign, status
        FROM order_events
        WHERE created_at BETWEEN ? AND ? $chOrd
        ORDER BY created_at DESC
    ");
    $st->execute($pOrd);
    $allOrders = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allOrders as $o) {
        if (count($recentOrders) < 50) {
            $recentOrders[] = $o;
        }
        // Si el pedido fue marcado como anulado/prueba, mostrarlo en la tabla inferior pero excluirlo de KPIs y gráficos
        if (($o['status'] ?? 'confirmed') === 'cancelled') {
            continue;
        }

        $tot = (float)($o['total'] ?? 0);
        $ts  = strtotime((string)$o['created_at']);
        if ($ts) {
            $d = date('Y-m-d', $ts);
            if (isset($idxByDate[$d])) {
                $ordByDay[$idxByDate[$d]]++;
                $revByDay[$idxByDate[$d]] = round($revByDay[$idxByDate[$d]] + $tot, 2);
            }
            $w = ((int)date('N', $ts)) - 1;
            $h = (int)date('G', $ts);
            $dowOrders[$w]++;
            $dowRev[$w] += $tot;
            $heatmapOrders[$w][$h]++;
        }

        $src = trim((string)($o['source'] ?? '')) ?: 'carta';
        $channelsOrd[$src] = ($channelsOrd[$src] ?? 0) + 1;
        $channelsRev[$src] = ($channelsRev[$src] ?? 0.0) + $tot;

        $originLabel = classifyTrafficSource($o['utm_source'] ?? null, null);
        if (!isset($attrSources[$originLabel])) $attrSources[$originLabel] = ['vis' => 0, 'ord' => 0, 'rev' => 0.0];
        $attrSources[$originLabel]['ord']++;
        $attrSources[$originLabel]['rev'] += $tot;

        $pm = trim((string)($o['payment_method'] ?? '')) ?: '(sin dato)';
        $pagoCount[$pm] = ($pagoCount[$pm] ?? 0) + 1;
        $pagoRev[$pm]   = ($pagoRev[$pm]   ?? 0.0) + $tot;

        $dtRaw = trim((string)($o['delivery_type'] ?? ''));
        $dtLbl = $dtRaw === 'envio' ? '🛵 Envío a domicilio' : ($dtRaw === 'retiro' ? '🏪 Retiro en local' : '(sin dato)');
        $entregaCount[$dtLbl] = ($entregaCount[$dtLbl] ?? 0) + 1;
        $entregaRev[$dtLbl]   = ($entregaRev[$dtLbl]   ?? 0.0) + $tot;

        $pt = trim((string)($o['pickup_time'] ?? ''));
        $isPre = !empty($o['is_preorder']);
        if ($isPre) {
            $timingModes['📅 Preventa (pedido con local cerrado)']++;
        } elseif ($pt === '' || stripos($pt, 'ahora') !== false || stripos($pt, 'antes posible') !== false) {
            $timingModes['⚡ Ahora (lo antes posible)']++;
        } else {
            $timingModes['🕒 Horario específico del turno']++;
        }

        if ($pt === '' || stripos($pt, 'ahora') !== false || stripos($pt, 'antes posible') !== false) {
            $ptKey = '⚡ Ahora';
        } elseif (preg_match('/(\d{2}:\d{2})/', $pt, $m)) {
            $ptKey = $m[1];
        } else {
            $ptKey = $pt;
        }
        $franjas[$ptKey] = ($franjas[$ptKey] ?? 0) + 1;

        $items = json_decode((string)($o['items_json'] ?? ''), true);
        $namesInThisOrder = [];
        $orderHasExtra = false;

        if (is_array($items)) {
            foreach ($items as $it) {
                $name = trim((string)($it['name'] ?? ''));
                if ($name === '' || stripos($name, 'Recargo Tarjeta') === 0) continue;
                $qty   = max(1, (int)($it['qty'] ?? 1));
                $price = isset($it['price']) && is_numeric($it['price']) ? (float)$it['price'] : 0.0;

                $meta = classifyCartItemName($name, $catalogByName);
                if ($price <= 0 && !empty($meta['catalog_price'])) {
                    $price = (float)$meta['catalog_price'];
                }
                $lineRev = $price * $qty;
                $catName = $meta['category'];

                if (!isset($prodStats[$name])) {
                    $prodStats[$name] = ['qty' => 0, 'rev' => 0.0, 'orders' => 0, 'category' => $catName];
                }
                $prodStats[$name]['qty']    += $qty;
                $prodStats[$name]['rev']    += $lineRev;
                $prodStats[$name]['orders'] += 1;

                if (!isset($catStats[$catName])) {
                    $catStats[$catName] = ['qty' => 0, 'rev' => 0.0];
                }
                $catStats[$catName]['qty'] += $qty;
                $catStats[$catName]['rev'] += $lineRev;

                if ($meta['is_vegan']) $veganUnits += $qty;

                if (!empty($meta['extras'])) {
                    $orderHasExtra = true;
                    foreach ($meta['extras'] as $exName) {
                        $exKey = mb_strtolower($exName, 'UTF-8');
                        $exUnitPrice = $extrasPriceMap[$exKey]['price'] ?? 0.0;
                        $exCleanName = $extrasPriceMap[$exKey]['name'] ?? $exName;
                        if (!isset($extrasStats[$exCleanName])) {
                            $extrasStats[$exCleanName] = ['qty' => 0, 'rev' => 0.0];
                        }
                        $extrasStats[$exCleanName]['qty'] += $qty;
                        $extrasStats[$exCleanName]['rev'] += $exUnitPrice * $qty;
                    }
                }

                $namesInThisOrder[$meta['base_name']] = true;
            }
        }
        if ($orderHasExtra) $ordersWithExtras++;

        $uniqNames = array_keys($namesInThisOrder);
        sort($uniqNames);
        $nUniq = count($uniqNames);
        if ($nUniq >= 2 && $nUniq <= 10) {
            for ($a = 0; $a < $nUniq; $a++) {
                for ($b = $a + 1; $b < $nUniq; $b++) {
                    $pairKey = $uniqNames[$a] . '  +  ' . $uniqNames[$b];
                    $pairStats[$pairKey] = ($pairStats[$pairKey] ?? 0) + 1;
                }
            }
        }
    }

    arsort($pagoCount);
    arsort($entregaCount);
    ksort($franjas);
    arsort($pairStats);
    uasort($extrasStats, fn($a, $b) => $b['qty'] <=> $a['qty']);
    uasort($attrSources, fn($a, $b) => ($b['rev'] <=> $a['rev']) ?: ($b['vis'] <=> $a['vis']));
    $topPairs = array_slice($pairStats, 0, 8, true);

    $prodByQty = $prodStats;
    uasort($prodByQty, fn($a, $b) => $b['qty'] <=> $a['qty']);
    $topProdQty = array_slice($prodByQty, 0, 12, true);

    $prodByRev = $prodStats;
    uasort($prodByRev, fn($a, $b) => $b['rev'] <=> $a['rev']);
    $topProdRev = array_slice($prodByRev, 0, 12, true);

    uasort($catStats, fn($a, $b) => $b['qty'] <=> $a['qty']);
} catch (Throwable $e) {
    error_log('panel orders analysis: ' . $e->getMessage());
}

function filterUrl(array $overrides = []): string {
    $q = array_merge($_GET, $overrides);
    if (isset($overrides['range']) && $overrides['range'] !== 'custom') {
        unset($q['from'], $q['to'], $q['days']);
    }
    return '?' . http_build_query($q);
}

$dowLabels = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$extrasAttachPct = $kpi['pedidos'] > 0 ? ($ordersWithExtras / $kpi['pedidos']) * 100.0 : 0.0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Estadísticas — Arrabbiata</title>
<link rel="icon" type="image/png" href="favicon.png"/>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
    :root{
        --rojo:#CC1414; --rojo-d:#A50E0E; --azul:#1A5FBF; --crema:#F0E6D0;
        --blanco:#F8F6F2; --marron:#2b1a0a; --verde:#15803d; --verde-wa:#25D366; --ocre:#D4B483; --ocre-d:#b8955a;
    }
    *{margin:0;padding:0;box-sizing:border-box}
    body{background:var(--crema);color:var(--marron);font-family:'DM Sans',Arial,sans-serif;padding:0 0 60px;line-height:1.4}
    .topbar{background:var(--rojo);color:#fff;padding:14px 24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;border-bottom:3px solid var(--rojo-d);position:sticky;top:0;z-index:100}
    .topbar h1{font-size:1.25rem;letter-spacing:.02em;display:flex;align-items:center;gap:10px}
    .topbar nav{display:flex;align-items:center;flex-wrap:wrap;gap:10px}
    .topbar nav a{color:#fff;text-decoration:none;font-size:.88rem;padding:6px 12px;border-radius:6px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);transition:all .15s;font-weight:600}
    .topbar nav a:hover{background:#fff;color:var(--rojo)}
    .topbar nav a.btn-csv{background:var(--verde-wa);color:#062e15;border-color:#128c50}
    .topbar nav a.btn-csv:hover{background:#1ebe5d;color:#fff}

    .wrap{max-width:1240px;margin:0 auto;padding:20px 16px 0}
    .disclaimer{background:#fff;border-left:4px solid var(--azul);padding:10px 14px;font-size:.84rem;margin-bottom:16px;border-radius:4px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}

    .filter-bar{background:#fff;border:2px solid var(--ocre);border-radius:8px;padding:12px 14px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between}
    .filter-group{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
    .filter-group .lbl{font-size:.76rem;text-transform:uppercase;font-weight:700;letter-spacing:.04em;color:#7a6a54;margin-right:4px}
    .pill-btn{padding:6px 12px;border:2px solid var(--azul);border-radius:5px;text-decoration:none;color:var(--azul);font-size:.84rem;font-weight:600;background:#fff;cursor:pointer;transition:all .12s}
    .pill-btn:hover{background:#eef4ff}
    .pill-btn.active{background:var(--azul);color:#fff}
    .pill-btn.ch{border-color:var(--marron);color:var(--marron)}
    .pill-btn.ch.active{background:var(--marron);color:#fff}
    .custom-dates{display:inline-flex;align-items:center;gap:5px;flex-wrap:wrap}
    .custom-dates input[type="date"]{padding:5px 8px;border:1.5px solid var(--ocre-d);border-radius:5px;font-family:inherit;font-size:.82rem;color:var(--marron)}
    .custom-dates button{padding:6px 10px;background:var(--azul);color:#fff;border:none;border-radius:5px;font-weight:600;font-size:.82rem;cursor:pointer}

    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:22px}
    .kpi{background:#fff;border:2.5px solid var(--rojo);border-radius:8px;padding:13px 15px;box-shadow:4px 4px 0 var(--rojo-d);display:flex;flex-direction:column;justify-content:space-between}
    .kpi-top{display:flex;justify-content:space-between;align-items:flex-start;gap:6px}
    .kpi .num{font-size:1.58rem;font-weight:700;color:var(--rojo);line-height:1.1}
    .kpi .lbl{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;opacity:.82;margin-top:4px;font-weight:700}
    .kpi .sub{font-size:.75rem;color:#6b5b47;margin-top:4px}
    .delta{font-size:.72rem;font-weight:700;padding:2px 6px;border-radius:99px;white-space:nowrap}
    .delta.up{background:#dcfce7;color:#15803d}
    .delta.down{background:#fee2e2;color:#b91c1c}
    .delta.neutral{background:#f3f4f6;color:#6b7280}

    .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:18px;margin-bottom:18px}
    .card{background:#fff;border:2px solid var(--ocre);border-radius:8px;padding:16px;grid-column:span 6;display:flex;flex-direction:column}
    .card.full{grid-column:1 / -1}
    .card.third{grid-column:span 4}
    @media (max-width:960px){ .card,.card.third{grid-column:span 6} }
    @media (max-width:680px){ .card,.card.third{grid-column:1 / -1} }
    .card-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap}
    .card h2{font-size:1rem;color:var(--marron);font-weight:700}
    .card .hint{font-size:.78rem;color:#7a6a54}
    .empty{color:#999;font-size:.9rem;padding:24px 0;text-align:center}
    canvas{max-height:310px}

    /* Embudo visual */
    .funnel-list{display:flex;flex-direction:column;gap:8px}
    .funnel-row{display:grid;grid-template-columns:210px 1fr 90px;gap:10px;align-items:center;font-size:.86rem}
    @media(max-width:640px){ .funnel-row{grid-template-columns:1fr;gap:4px} }
    .funnel-bar-bg{background:#f5efe3;border-radius:6px;height:24px;overflow:hidden;position:relative}
    .funnel-bar-fill{height:100%;background:linear-gradient(90deg,var(--rojo),#e84a3a);border-radius:6px;transition:width .3s}
    .funnel-pct{text-align:right;font-weight:700;font-variant-numeric:tabular-nums;color:var(--marron)}

    .doughnut-split{display:grid;grid-template-columns:140px minmax(0,1fr);gap:16px;align-items:center}
    .doughnut-box{position:relative;width:140px;height:140px;flex-shrink:0;margin:0 auto}
    @media(max-width:560px){.doughnut-split{grid-template-columns:1fr}.doughnut-box{width:150px;height:150px}}

    .data-table{width:100%;border-collapse:collapse;font-size:.85rem}
    .data-table th,.data-table td{padding:8px 10px;text-align:left;border-bottom:1px solid #eee0c8;vertical-align:middle}
    .data-table th{color:var(--rojo);text-transform:uppercase;font-size:.72rem;letter-spacing:.04em;background:#fcf9f2;font-weight:700}
    .data-table tr:hover td{background:#fdfaf4}
    .data-table tr.is-cancelled td{opacity:.48;text-decoration:line-through}
    .data-table tr.is-cancelled td:last-child{opacity:1;text-decoration:none}
    .data-table td.num,.data-table th.num{text-align:right;font-variant-numeric:tabular-nums}
    .badge-cat{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.72rem;font-weight:600;background:#f7efdf;color:var(--marron);border:1px solid var(--ocre)}
    .badge-src{display:inline-block;padding:2px 7px;border-radius:4px;font-size:.72rem;font-weight:700;text-transform:uppercase}
    .badge-src.carta{background:#fee2e2;color:var(--rojo)}
    .badge-src.home{background:#dbeafe;color:var(--azul)}
    .badge-src.tienda{background:#dcfce7;color:#15803d}

    .btn-status{border:1px solid #ccc;background:#fff;border-radius:5px;padding:3px 8px;font-size:.75rem;font-weight:700;cursor:pointer}
    .btn-status.ok{color:#15803d;border-color:#86efac;background:#f0fdf4}
    .btn-status.cancel{color:#b91c1c;border-color:#fca5a5;background:#fef2f2}

    .heatmap-wrap{overflow-x:auto}
    .heatmap{width:100%;border-collapse:separate;border-spacing:3px;font-size:.75rem;min-width:640px}
    .heatmap th{padding:4px;color:#7a6a54;font-weight:600;text-align:center}
    .heatmap td.day-lbl{font-weight:700;padding:4px 8px;text-align:right;white-space:nowrap;color:var(--marron)}
    .heatmap td.cell{text-align:center;padding:6px 2px;border-radius:4px;font-weight:600;font-variant-numeric:tabular-nums;transition:transform .1s}
    .heatmap td.cell:hover{transform:scale(1.12);outline:1.5px solid var(--marron)}

    .search-input{padding:6px 12px;border:1.5px solid var(--ocre-d);border-radius:6px;font-size:.85rem;font-family:inherit;min-width:220px}
</style>
</head>
<body>
    <div class="topbar">
        <h1>📊 Estadísticas Comerciales — Arrabbiata</h1>
        <nav>
            <a href="<?php echo htmlspecialchars(filterUrl(['export' => 'csv']), ENT_QUOTES, 'UTF-8'); ?>" class="btn-csv">⬇ Exportar Pedidos CSV</a>
            <a href="panel_concurso.php">🍕 Archivo «Armá tu pizza»</a>
            <a href="editor.php">← Editor de Carta</a>
            <a href="logout.php">Salir</a>
        </nav>
    </div>

    <div class="wrap">
        <div class="disclaimer">
            <div>
                Comparativa automática contra los <b><?php echo (int)round($periodSeconds / 86400); ?> días previos</b> (<?php echo $prevSinceDt->format('d/m'); ?> al <?php echo $prevUntilDt->format('d/m'); ?>).
                Podés anular pedidos de prueba en la tabla inferior para que no afecten la facturación ni el ticket.
            </div>
            <div>
                <?php if ($hideBots): ?>
                    🤖 Bots ocultos (<?php echo number_format($kpi['bots_filtrados'], 0, ',', '.'); ?> filtrados) · <a href="<?php echo htmlspecialchars(filterUrl(['bots' => '1']), ENT_QUOTES, 'UTF-8'); ?>" style="color:var(--azul)">Incluir bots</a>
                <?php else: ?>
                    🤖 Bots incluidos · <a href="<?php echo htmlspecialchars(filterUrl(['bots' => '0']), ENT_QUOTES, 'UTF-8'); ?>" style="color:var(--azul)">Ocultar bots</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filter-bar">
            <div class="filter-group">
                <span class="lbl">Período:</span>
                <?php
                $presets = ['today'=>'Hoy','yesterday'=>'Ayer','7'=>'7 días','30'=>'30 días','90'=>'90 días','365'=>'1 año'];
                foreach ($presets as $k => $lbl):
                ?>
                    <a href="<?php echo htmlspecialchars(filterUrl(['range' => $k]), ENT_QUOTES, 'UTF-8'); ?>"
                       class="pill-btn <?php echo $rangePreset === (string)$k ? 'active' : ''; ?>"><?php echo $lbl; ?></a>
                <?php endforeach; ?>

                <form method="GET" class="custom-dates">
                    <input type="hidden" name="channel" value="<?php echo htmlspecialchars($channel, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="bots" value="<?php echo $hideBots ? '0' : '1'; ?>">
                    <input type="date" name="from" value="<?php echo htmlspecialchars($sinceDt->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                    <span>a</span>
                    <input type="date" name="to" value="<?php echo htmlspecialchars($untilDt->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                    <button type="submit">Filtrar</button>
                </form>
            </div>

            <div class="filter-group">
                <span class="lbl">Canal:</span>
                <?php
                $chOptions = [
                    'pizzeria' => '🍕 Pizzería (Carta + Home)',
                    'carta'    => '📱 Solo /carta',
                    'home'     => '🏠 Solo Home',
                    'tienda'   => '🛍️ Tienda',
                ];
                foreach ($chOptions as $ck => $clbl):
                ?>
                    <a href="<?php echo htmlspecialchars(filterUrl(['channel' => $ck]), ENT_QUOTES, 'UTF-8'); ?>"
                       class="pill-btn ch <?php echo $channel === $ck ? 'active' : ''; ?>"><?php echo $clbl; ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- KPIs -->
        <div class="kpis">
            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo number_format($kpi['visitas'], 0, ',', '.'); ?></div>
                    <?php echo fmtDelta($kpi['visitas'], $prevKpi['visitas']); ?>
                </div>
                <div class="lbl">Visitas</div>
                <div class="sub">👤 <?php echo number_format($kpi['unicos'], 0, ',', '.'); ?> únicos</div>
            </div>

            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo number_format($kpi['pedidos'], 0, ',', '.'); ?></div>
                    <?php echo fmtDelta($kpi['pedidos'], $prevKpi['pedidos']); ?>
                </div>
                <div class="lbl">Pedidos Activos</div>
                <div class="sub">🍕 <?php echo number_format($kpi['items'], 0, ',', '.'); ?> unid. (<?php echo number_format($kpi['items_x_ped'], 1, ',', '.'); ?>/ped)</div>
            </div>

            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo number_format($kpi['conversion'], 1, ',', '.'); ?>%</div>
                    <?php echo fmtDelta($kpi['conversion'], $prevKpi['conversion']); ?>
                </div>
                <div class="lbl">Conversión</div>
                <div class="sub">🎯 <?php echo number_format($kpi['conv_unicos'], 1, ',', '.'); ?>% s/ visitantes únicos</div>
            </div>

            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo $money($kpi['facturacion']); ?></div>
                    <?php echo fmtDelta($kpi['facturacion'], $prevKpi['facturacion']); ?>
                </div>
                <div class="lbl">Facturación</div>
                <div class="sub">Ant.: <?php echo $money($prevKpi['facturacion']); ?></div>
            </div>

            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo $money($kpi['ticket']); ?></div>
                    <?php echo fmtDelta($kpi['ticket'], $prevKpi['ticket']); ?>
                </div>
                <div class="lbl">Ticket Promedio</div>
                <div class="sub">➕ <?php echo number_format($extrasAttachPct, 1, ',', '.'); ?>% llevan extras</div>
            </div>

            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo number_format($kpi['clientes_recurrentes'], 0, ',', '.'); ?></div>
                    <?php echo fmtDelta($kpi['clientes_recurrentes'], $prevKpi['clientes_recurrentes']); ?>
                </div>
                <div class="lbl">Clientes Recurrentes</div>
                <div class="sub">🔁 De <?php echo number_format($kpi['clientes_unicos'], 0, ',', '.'); ?> clientes (por tel.)</div>
            </div>

            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo number_format($kpi['preorders'], 0, ',', '.'); ?></div>
                    <?php echo fmtDelta($kpi['preorders'], $prevKpi['preorders']); ?>
                </div>
                <div class="lbl">Pedidos en Preventa</div>
                <div class="sub">🌙 <?php echo $money($kpi['preorder_rev']); ?> fuera de horario</div>
            </div>
        </div>

        <div class="grid">
            <!-- Embudo de Conversión -->
            <div class="card">
                <div class="card-head">
                    <h2>🎯 Embudo de Conversión de Compra</h2>
                    <span class="hint">Sesiones que avanzan en cada paso</span>
                </div>
                <?php
                $baseFunnel = max(1, $funnelSteps['view']);
                $fRows = [
                    ['lbl' => '1. Vieron la carta',         'val' => $funnelSteps['view']],
                    ['lbl' => '2. Agregaron un producto',   'val' => $funnelSteps['add_to_cart']],
                    ['lbl' => '3. Abrieron «Tu Pedido»',    'val' => $funnelSteps['open_cart']],
                    ['lbl' => '4. Pasaron a elegir horario','val' => $funnelSteps['open_pickup_slot']],
                    ['lbl' => '5. Enviaron pedido',         'val' => $funnelSteps['order']],
                ];
                ?>
                <div class="funnel-list">
                    <?php foreach ($fRows as $fr):
                        $pct = min(100, ($fr['val'] / $baseFunnel) * 100);
                    ?>
                    <div class="funnel-row">
                        <div><b><?php echo htmlspecialchars($fr['lbl'], ENT_QUOTES, 'UTF-8'); ?></b></div>
                        <div class="funnel-bar-bg"><div class="funnel-bar-fill" style="width:<?php echo round($pct, 1); ?>%"></div></div>
                        <div class="funnel-pct"><?php echo number_format($fr['val'], 0, ',', '.'); ?> (<?php echo number_format($pct, 1, ',', '.'); ?>%)</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Atribución por Origen / Campaña UTM -->
            <div class="card">
                <div class="card-head">
                    <h2>📣 Ventas y Visitas por Origen / UTM</h2>
                    <span class="hint">Cruza UTMs de Instagram/Bio/Ads + Referrer</span>
                </div>
                <?php if ($attrSources): ?>
                <div style="overflow-x:auto;max-height:240px;overflow-y:auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Origen / Campaña</th>
                                <th class="num">Visitas</th>
                                <th class="num">Pedidos</th>
                                <th class="num">Facturación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attrSources as $srcLbl => $st): ?>
                            <tr>
                                <td><b><?php echo htmlspecialchars($srcLbl, ENT_QUOTES, 'UTF-8'); ?></b></td>
                                <td class="num"><?php echo number_format($st['vis'], 0, ',', '.'); ?></td>
                                <td class="num"><b><?php echo number_format($st['ord'], 0, ',', '.'); ?></b></td>
                                <td class="num"><?php echo $money($st['rev']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="empty">Sin datos en este período</div>
                <?php endif; ?>
            </div>

            <!-- Evolución diaria -->
            <div class="card full">
                <div class="card-head">
                    <h2>📈 Evolución diaria de Visitas y Pedidos</h2>
                    <span class="hint">Del <?php echo $sinceDt->format('d/m/Y'); ?> al <?php echo $untilDt->format('d/m/Y'); ?></span>
                </div>
                <canvas id="chartDaily"></canvas>
            </div>

            <!-- Día de la semana -->
            <div class="card">
                <div class="card-head">
                    <h2>📅 Pedidos y Visitas por Día de la Semana</h2>
                    <span class="hint">Detectá tus días más fuertes</span>
                </div>
                <canvas id="chartDow"></canvas>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>💰 Facturación y Ticket por Día de la Semana</h2>
                    <span class="hint">Total acumulado en el período</span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Día</th>
                            <th class="num">Visitas</th>
                            <th class="num">Pedidos</th>
                            <th class="num">Conv.</th>
                            <th class="num">Facturación</th>
                            <th class="num">Ticket Prom.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dowLabels as $wi => $dName):
                            $v = $dowVisits[$wi]; $o = $dowOrders[$wi]; $r = $dowRev[$wi];
                            $cv = $v > 0 ? ($o / $v) * 100 : 0;
                            $tk = $o > 0 ? $r / $o : 0;
                        ?>
                        <tr>
                            <td><b><?php echo $dName; ?></b></td>
                            <td class="num"><?php echo number_format($v, 0, ',', '.'); ?></td>
                            <td class="num"><b><?php echo number_format($o, 0, ',', '.'); ?></b></td>
                            <td class="num"><?php echo number_format($cv, 1, ',', '.'); ?>%</td>
                            <td class="num"><?php echo $money($r); ?></td>
                            <td class="num"><?php echo $o > 0 ? $money($tk) : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mapa de calor -->
            <div class="card full">
                <div class="card-head">
                    <h2>🔥 Mapa de Calor de Pedidos (Día × Hora de envío)</h2>
                    <span class="hint">Horas pico reales (11h a 01h)</span>
                </div>
                <div class="heatmap-wrap">
                    <?php
                    $hoursShow = [11,12,13,14,15,16,17,18,19,20,21,22,23,0,1];
                    $maxCell = 1;
                    foreach ($heatmapOrders as $wRow) {
                        foreach ($hoursShow as $hh) {
                            if ($wRow[$hh] > $maxCell) $maxCell = $wRow[$hh];
                        }
                    }
                    ?>
                    <table class="heatmap">
                        <thead>
                            <tr>
                                <th>Día \ Hora</th>
                                <?php foreach ($hoursShow as $hh): ?>
                                    <th><?php echo str_pad((string)$hh, 2, '0', STR_PAD_LEFT); ?>h</th>
                                <?php endforeach; ?>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dowLabels as $wi => $dName): ?>
                            <tr>
                                <td class="day-lbl"><?php echo $dName; ?></td>
                                <?php foreach ($hoursShow as $hh):
                                    $val = $heatmapOrders[$wi][$hh];
                                    $vVis = $heatmapVisits[$wi][$hh];
                                    $alpha = $val > 0 ? round(0.15 + 0.85 * ($val / $maxCell), 2) : 0;
                                    $bg = $val > 0 ? "rgba(204, 20, 20, {$alpha})" : '#f8f5ee';
                                    $fg = ($alpha > 0.55) ? '#fff' : ($val > 0 ? '#2b1a0a' : '#bbb');
                                ?>
                                    <td class="cell" style="background:<?php echo $bg; ?>;color:<?php echo $fg; ?>"
                                        title="<?php echo $dName . ' ' . str_pad((string)$hh, 2, '0', STR_PAD_LEFT) . ':00 — ' . $val . ' pedidos / ' . $vVis . ' visitas'; ?>">
                                        <?php echo $val > 0 ? $val : '·'; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="cell" style="background:#f7efdf;font-weight:700"><?php echo $dowOrders[$wi]; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top productos -->
            <div class="card">
                <div class="card-head">
                    <h2>🍕 Top Productos (Unidades)</h2>
                    <span class="hint"><?php if ($veganUnits > 0) echo "🌿 {$veganUnits} unid. veganas"; ?></span>
                </div>
                <?php if (!empty($topProdQty)): ?>
                    <canvas id="chartProd"></canvas>
                <?php else: ?>
                    <div class="empty">Sin datos de productos en este rango</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>💵 Top Productos por Facturación ($)</h2>
                    <span class="hint">Cruzado con el catálogo actual</span>
                </div>
                <?php if (!empty($topProdRev)): ?>
                <div style="overflow-x:auto;max-height:310px;overflow-y:auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Categoría</th>
                                <th class="num">Unid.</th>
                                <th class="num">Pedidos</th>
                                <th class="num">Facturación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProdRev as $pName => $st): ?>
                            <tr>
                                <td><b><?php echo htmlspecialchars($pName, ENT_QUOTES, 'UTF-8'); ?></b></td>
                                <td><span class="badge-cat"><?php echo htmlspecialchars($st['category'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td class="num"><?php echo number_format($st['qty'], 0, ',', '.'); ?></td>
                                <td class="num"><?php echo number_format($st['orders'], 0, ',', '.'); ?></td>
                                <td class="num"><b><?php echo $money($st['rev']); ?></b></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="empty">Sin datos de productos en este rango</div>
                <?php endif; ?>
            </div>

            <!-- Extras + Modalidad de Horario ("Ahora" vs Turno vs Preventa) -->
            <div class="card">
                <div class="card-head">
                    <h2>➕ Rendimiento de Extras («Sumale a tu pizza»)</h2>
                    <span class="hint"><?php echo number_format($extrasAttachPct, 1, ',', '.'); ?>% de los pedidos suman extras</span>
                </div>
                <?php if (!empty($extrasStats)): ?>
                <table class="data-table">
                    <thead>
                        <tr><th>Extra</th><th class="num">Unidades</th><th class="num">Ingreso Incremental</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($extrasStats as $exName => $exSt): ?>
                        <tr>
                            <td><b><?php echo htmlspecialchars($exName, ENT_QUOTES, 'UTF-8'); ?></b></td>
                            <td class="num"><?php echo number_format($exSt['qty'], 0, ',', '.'); ?></td>
                            <td class="num"><?php echo $money($exSt['rev']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="empty">Todavía no se registraron extras en este rango</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>⏱️ Preferencia de Momento («Ahora» vs. Programado)</h2>
                    <span class="hint">Cómo eligen recibir su pedido</span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr><th>Tipo de Horario</th><th class="num">Pedidos</th><th class="num">%</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($timingModes as $tmLbl => $tmCnt):
                            $pctTm = $kpi['pedidos'] > 0 ? ($tmCnt / $kpi['pedidos']) * 100 : 0;
                        ?>
                        <tr>
                            <td><b><?php echo htmlspecialchars($tmLbl, ENT_QUOTES, 'UTF-8'); ?></b></td>
                            <td class="num"><?php echo number_format($tmCnt, 0, ',', '.'); ?></td>
                            <td class="num"><?php echo number_format($pctTm, 1, ',', '.'); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Categorías y Pares -->
            <div class="card">
                <div class="card-head">
                    <h2>🗂️ Ventas por Categoría de Carta</h2>
                    <span class="hint">Participación en unidades y facturación</span>
                </div>
                <?php if (!empty($catStats)): ?>
                <table class="data-table">
                    <thead>
                        <tr><th>Categoría</th><th class="num">Unidades</th><th class="num">Facturación</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($catStats as $cName => $cst): ?>
                        <tr>
                            <td><b><?php echo htmlspecialchars($cName, ENT_QUOTES, 'UTF-8'); ?></b></td>
                            <td class="num"><?php echo number_format($cst['qty'], 0, ',', '.'); ?></td>
                            <td class="num"><?php echo $money($cst['rev']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="empty">Sin datos todavía</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>🤝 Pizzas que más se piden juntas</h2>
                    <span class="hint">Pares frecuentes dentro del mismo carrito</span>
                </div>
                <?php if (!empty($topPairs)): ?>
                <table class="data-table">
                    <thead>
                        <tr><th>Combinación en el mismo pedido</th><th class="num">Veces juntos</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topPairs as $pairName => $cnt): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($pairName, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><b><?php echo number_format($cnt, 0, ',', '.'); ?></b></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="empty">Todavía no hay suficientes pedidos con 2+ productos distintos</div>
                <?php endif; ?>
            </div>

            <!-- Pago y Entrega -->
            <div class="card">
                <div class="card-head">
                    <h2>💳 Forma de Pago</h2>
                    <span class="hint">Desglose financiero</span>
                </div>
                <?php if ($pagoCount): ?>
                    <div class="doughnut-split">
                        <div class="doughnut-box"><canvas id="chartPago"></canvas></div>
                        <div style="overflow-x:auto">
                        <table class="data-table">
                            <thead>
                                <tr><th>Medio</th><th class="num">Ped.</th><th class="num">Total ($)</th><th class="num">% $</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pagoCount as $pm => $c):
                                    $rv = $pagoRev[$pm] ?? 0;
                                    $pctRev = $kpi['facturacion'] > 0 ? ($rv / $kpi['facturacion']) * 100 : 0;
                                ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($pm, ENT_QUOTES, 'UTF-8'); ?></b></td>
                                    <td class="num"><?php echo $c; ?></td>
                                    <td class="num"><?php echo $money($rv); ?></td>
                                    <td class="num"><?php echo number_format($pctRev, 1, ',', '.'); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty">Sin datos todavía</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>🛵 Retiro en Local vs. Envío a Domicilio</h2>
                    <span class="hint">Cantidad y ticket promedio por modalidad</span>
                </div>
                <?php if ($entregaCount): ?>
                    <div class="doughnut-split">
                        <div class="doughnut-box"><canvas id="chartEntrega"></canvas></div>
                        <div style="overflow-x:auto">
                        <table class="data-table">
                            <thead>
                                <tr><th>Modalidad</th><th class="num">Ped.</th><th class="num">Total ($)</th><th class="num">Ticket Prom.</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entregaCount as $dtLbl => $c):
                                    $rv = $entregaRev[$dtLbl] ?? 0;
                                    $tk = $c > 0 ? $rv / $c : 0;
                                ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($dtLbl, ENT_QUOTES, 'UTF-8'); ?></b></td>
                                    <td class="num"><?php echo $c; ?></td>
                                    <td class="num"><?php echo $money($rv); ?></td>
                                    <td class="num"><?php echo $money($tk); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty">Sin datos todavía</div>
                <?php endif; ?>
            </div>

            <div class="card full">
                <div class="card-head">
                    <h2>🕒 Franjas Horarias Elegidas («⚡ Ahora» vs. Turnos de 15')</h2>
                    <span class="hint">Horarios solicitados en el selector de la carta</span>
                </div>
                <?php if ($franjas): ?>
                    <canvas id="chartFranjas"></canvas>
                <?php else: ?>
                    <div class="empty">Sin datos todavía</div>
                <?php endif; ?>
            </div>

            <!-- Canales y Dispositivos -->
            <div class="card">
                <div class="card-head">
                    <h2>🚪 Rendimiento por Canal</h2>
                    <span class="hint">Carta vs. Home vs. Tienda</span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr><th>Canal</th><th class="num">Visitas</th><th class="num">Ped.</th><th class="num">Conv.</th><th class="num">Facturación</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $allSrcs = array_unique(array_merge(array_keys($channelsVis), array_keys($channelsOrd)));
                        foreach ($allSrcs as $sKey):
                            $sv = $channelsVis[$sKey] ?? 0;
                            $so = $channelsOrd[$sKey] ?? 0;
                            $sr = $channelsRev[$sKey] ?? 0.0;
                            $sc = $sv > 0 ? ($so / $sv) * 100 : 0;
                        ?>
                        <tr>
                            <td><span class="badge-src <?php echo htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td class="num"><?php echo number_format($sv, 0, ',', '.'); ?></td>
                            <td class="num"><b><?php echo number_format($so, 0, ',', '.'); ?></b></td>
                            <td class="num"><?php echo number_format($sc, 1, ',', '.'); ?>%</td>
                            <td class="num"><?php echo $money($sr); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>📱 Dispositivos</h2>
                    <span class="hint">Según User-Agent</span>
                </div>
                <?php if ($devices): ?>
                <table class="data-table">
                    <thead>
                        <tr><th>Dispositivo</th><th class="num">Visitas</th><th class="num">%</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($devices as $dName => $dCnt):
                            $pct = $kpi['visitas'] > 0 ? ($dCnt / $kpi['visitas']) * 100 : 0;
                        ?>
                        <tr>
                            <td><b><?php echo htmlspecialchars($dName, ENT_QUOTES, 'UTF-8'); ?></b></td>
                            <td class="num"><?php echo number_format($dCnt, 0, ',', '.'); ?></td>
                            <td class="num"><?php echo number_format($pct, 1, ',', '.'); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="empty">Sin datos</div>
                <?php endif; ?>
            </div>

            <!-- Tabla en vivo de últimos pedidos con conciliación Activo / Anulado -->
            <div class="card full">
                <div class="card-head">
                    <h2>🧾 Últimos Pedidos (<?php echo count($recentOrders); ?> mostrados)</h2>
                    <input type="search" id="orderSearch" class="search-input" placeholder="🔍 Buscar por código, pizza, pago, horario...">
                </div>
                <?php if ($recentOrders): ?>
                <div style="overflow-x:auto">
                    <table class="data-table" id="ordersTable">
                        <thead>
                            <tr>
                                <th>Fecha / Código</th>
                                <th>Canal</th>
                                <th>Modalidad</th>
                                <th>Horario</th>
                                <th>Pago</th>
                                <th>Detalle del Pedido</th>
                                <th class="num">Total</th>
                                <th class="num">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $ro):
                                $ts = strtotime((string)$ro['created_at']);
                                $fechaFmt = $ts ? date('d/m H:i', $ts) : $ro['created_at'];
                                $itArr = json_decode((string)($ro['items_json'] ?? ''), true);
                                $pills = [];
                                if (is_array($itArr)) {
                                    foreach ($itArr as $it) {
                                        $nm = trim((string)($it['name'] ?? ''));
                                        $qt = (int)($it['qty'] ?? 1);
                                        if ($nm !== '') $pills[] = htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') . ' <b>×' . $qt . '</b>';
                                    }
                                }
                                $dt = $ro['delivery_type'] === 'envio' ? '🛵 Envío' : ($ro['delivery_type'] === 'retiro' ? '🏪 Retiro' : '—');
                                $isCanc = (($ro['status'] ?? 'confirmed') === 'cancelled');
                            ?>
                            <tr class="<?php echo $isCanc ? 'is-cancelled' : ''; ?>" data-order-id="<?php echo (int)$ro['id']; ?>">
                                <td style="white-space:nowrap">
                                    <div><?php echo htmlspecialchars($fechaFmt, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if (!empty($ro['order_code'])): ?>
                                        <small style="color:var(--azul);font-weight:700;">#<?php echo htmlspecialchars((string)$ro['order_code'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge-src <?php echo htmlspecialchars((string)$ro['source'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$ro['source'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td style="white-space:nowrap"><?php echo $dt; ?></td>
                                <td style="white-space:nowrap">
                                    <?php echo htmlspecialchars($ro['pickup_time'] ?: 'Ahora', ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($ro['is_preorder'])): ?><span title="Pedido programado con local cerrado">🌙</span><?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($ro['payment_method'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo $pills ? implode(' · ', $pills) : '<span style="color:#999">(sin detalle)</span>'; ?></td>
                                <td class="num"><b><?php echo $money($ro['total']); ?></b></td>
                                <td class="num">
                                    <button type="button"
                                            class="btn-status <?php echo $isCanc ? 'cancel' : 'ok'; ?>"
                                            onclick="toggleOrderStatus(<?php echo (int)$ro['id']; ?>, this)"
                                            title="Clic para alternar entre Confirmado y Anulado/Prueba">
                                        <?php echo $isCanc ? '✕ Anulado' : '✔ Activo'; ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="empty">No hay pedidos registrados en este rango de fechas</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<script>
    const PANEL_CSRF = <?php echo json_encode($_SESSION['csrf_token']); ?>;
    const RED='#CC1414', BLUE='#1A5FBF', GREEN='#25D366', OCRE='#D4B483', GOLD='#FDB740', REDF='#D73828';
    const PALETTE=[RED, BLUE, GREEN, GOLD, OCRE, REDF, '#7B5EA7', '#2F9E8F', '#E07A3F', '#5C7AEA'];

    const dailyData = {
        labels: <?php echo json_encode($labels); ?>,
        vis: <?php echo json_encode($visByDay); ?>,
        ord: <?php echo json_encode($ordByDay); ?>,
        rev: <?php echo json_encode($revByDay); ?>
    };
    const dowLabels = <?php echo json_encode($dowLabels); ?>;
    const dowVisits = <?php echo json_encode($dowVisits); ?>;
    const dowOrders = <?php echo json_encode($dowOrders); ?>;
    const pago      = <?php echo json_encode($pagoCount); ?>;
    const entrega   = <?php echo json_encode($entregaCount); ?>;
    const prodQty   = <?php echo json_encode(array_map(fn($x) => $x['qty'], $topProdQty ?? [])); ?>;
    const franjas   = <?php echo json_encode($franjas); ?>;

    new Chart(document.getElementById('chartDaily'), {
        type: 'line',
        data: {
            labels: dailyData.labels,
            datasets: [
                { label: 'Visitas', data: dailyData.vis, borderColor: BLUE, backgroundColor: 'rgba(26,95,191,.10)', fill: true, tension: .25, yAxisID: 'y' },
                { label: 'Pedidos', data: dailyData.ord, borderColor: RED, backgroundColor: 'rgba(204,20,20,.15)', fill: true, tension: .25, yAxisID: 'y1' }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y:  { position: 'left',  beginAtZero: true, title: { display: true, text: 'Visitas' }, ticks: { precision: 0 } },
                y1: { position: 'right', beginAtZero: true, title: { display: true, text: 'Pedidos' }, grid: { drawOnChartArea: false }, ticks: { precision: 0 } }
            }
        }
    });

    if (document.getElementById('chartDow')) {
        new Chart(document.getElementById('chartDow'), {
            type: 'bar',
            data: {
                labels: dowLabels,
                datasets: [
                    { label: 'Pedidos', data: dowOrders, backgroundColor: RED, yAxisID: 'y1' },
                    { label: 'Visitas', data: dowVisits, backgroundColor: 'rgba(26,95,191,.35)', yAxisID: 'y' }
                ]
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y:  { position: 'left', beginAtZero: true, title: { display: true, text: 'Visitas' }, ticks: { precision: 0 } },
                    y1: { position: 'right', beginAtZero: true, title: { display: true, text: 'Pedidos' }, grid: { drawOnChartArea: false }, ticks: { precision: 0 } }
                }
            }
        });
    }

    function doughnut(id, obj) {
        const el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type: 'doughnut',
            data: { labels: Object.keys(obj), datasets: [{ data: Object.values(obj), backgroundColor: PALETTE }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }
    doughnut('chartPago', pago);
    doughnut('chartEntrega', entrega);

    if (document.getElementById('chartProd')) {
        new Chart(document.getElementById('chartProd'), {
            type: 'bar',
            data: { labels: Object.keys(prodQty), datasets: [{ label: 'Unidades', data: Object.values(prodQty), backgroundColor: RED }] },
            options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }

    if (document.getElementById('chartFranjas')) {
        new Chart(document.getElementById('chartFranjas'), {
            type: 'bar',
            data: { labels: Object.keys(franjas), datasets: [{ label: 'Pedidos', data: Object.values(franjas), backgroundColor: BLUE }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }

    const searchInput = document.getElementById('orderSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('#ordersTable tbody tr').forEach(tr => {
                tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }

    async function toggleOrderStatus(orderId, btn) {
        btn.disabled = true;
        const fd = new FormData();
        fd.append('action', 'toggle_order_status');
        fd.append('id', String(orderId));
        fd.append('csrf_token', PANEL_CSRF);
        try {
            const res  = await fetch('panel.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                location.reload();
            } else {
                alert(data.error || 'No se pudo cambiar el estado');
                btn.disabled = false;
            }
        } catch (e) {
            alert('Error de conexión');
            btn.disabled = false;
        }
    }
</script>
</body>
</html>

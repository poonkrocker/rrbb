<?php
/**
 * panel.php — Panel principal de estadísticas comerciales y de uso de Arrabbiata.
 *
 * Lee las tablas que llena track.php (site_visits, order_events) y las cruza
 * con menu_items / categories / business_hours para inteligencia de negocio.
 *
 * NOTA: Las estadísticas del concurso «Armá tu pizza» viven ahora en su propia
 * página separada: panel_concurso.php
 */

session_start();
require_once 'db_connect.php';
date_default_timezone_set('America/Argentina/Cordoba');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// Aseguramos que las tablas existan
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_visits (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'carta', path VARCHAR(255) NULL,
            referrer VARCHAR(255) NULL, ua VARCHAR(255) NULL, ip_hash CHAR(64) NULL,
            INDEX idx_created (created_at), INDEX idx_iphash (ip_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'carta', delivery_type VARCHAR(16) NULL,
            payment_method VARCHAR(32) NULL, total DECIMAL(10,2) NULL, pickup_time VARCHAR(32) NULL,
            item_count INT NULL, items_json TEXT NULL, ip_hash CHAR(64) NULL,
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    error_log('panel.php ensure tables: ' . $e->getMessage());
}

// ── Parámetros de filtro (Rango de fechas, Canal, Exclusión de bots) ──
$rangePreset = isset($_GET['range']) ? (string)$_GET['range'] : (isset($_GET['days']) ? (string)$_GET['days'] : '30');
$channel     = isset($_GET['channel']) ? (string)$_GET['channel'] : 'pizzeria'; // 'pizzeria' (carta+home), 'carta', 'home', 'all'
if (!in_array($channel, ['pizzeria', 'carta', 'home', 'all'], true)) {
    $channel = 'pizzeria';
}
$hideBots    = !isset($_GET['bots']) || $_GET['bots'] !== '1'; // por defecto ocultamos bots para no distorsionar conversión

$nowDt   = new DateTime('now');
$todayDt = new DateTime('today');

$customFrom = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$customTo   = isset($_GET['to'])   ? trim((string)$_GET['to'])   : '';

if ($customFrom !== '' && $customTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo)) {
    $rangePreset = 'custom';
    $sinceDt = new DateTime($customFrom . ' 00:00:00');
    $untilDt = new DateTime($customTo . ' 23:59:59');
    if ($sinceDt > $untilDt) {
        [$sinceDt, $untilDt] = [$untilDt, $sinceDt];
    }
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

// Período anterior equivalente (para calcular variación %)
$periodSeconds = max(86400, $untilDt->getTimestamp() - $sinceDt->getTimestamp() + 1);
$prevUntilDt   = (clone $sinceDt)->modify('-1 second');
$prevSinceDt   = (clone $prevUntilDt)->modify('-' . ($periodSeconds - 1) . ' seconds');
$prevSince     = $prevSinceDt->format('Y-m-d H:i:s');
$prevUntil     = $prevUntilDt->format('Y-m-d H:i:s');

// Condición SQL de canal
function buildChannelSql(string $channel, array &$params): string {
    if ($channel === 'carta') {
        $params[] = 'carta';
        return ' AND source = ? ';
    }
    if ($channel === 'home') {
        $params[] = 'home';
        return ' AND source = ? ';
    }
    if ($channel === 'pizzeria') {
        // Excluye visitas de 'cumple' para no distorsionar la conversión de venta de pizzas
        return " AND source IN ('carta', 'home', '') ";
    }
    return '';
}

// Condición SQL anti-bots sobre site_visits.ua
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

// ── Exportación directa de pedidos a CSV ──
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $p = [$since, $until];
        $chSql = buildChannelSql($channel, $p);
        $st = $pdo->prepare("
            SELECT id, created_at, source, delivery_type, payment_method, pickup_time, item_count, total, items_json, ip_hash
            FROM order_events
            WHERE created_at BETWEEN ? AND ? $chSql
            ORDER BY created_at DESC
        ");
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="pedidos-arrabbiata-' . $sinceDt->format('Ymd') . '-' . $untilDt->format('Ymd') . '.csv"');
        $fp = fopen('php://output', 'w');
        fprintf($fp, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
        fputcsv($fp, ['ID', 'Fecha y Hora', 'Día', 'Canal', 'Entrega', 'Forma de Pago', 'Horario Elegido', 'Cant. Items', 'Total ($)', 'Detalle de Productos', 'Cliente Hash']);
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
                $r['created_at'],
                $diaNombre,
                $r['source'],
                $r['delivery_type'] === 'envio' ? 'Envío' : ($r['delivery_type'] === 'retiro' ? 'Retiro' : $r['delivery_type']),
                $r['payment_method'],
                $r['pickup_time'] ?: '(inmediato / sin horario)',
                (int)$r['item_count'],
                number_format((float)$r['total'], 2, '.', ''),
                implode(' | ', $itemsTxt),
                substr((string)$r['ip_hash'], 0, 12),
            ]);
        }
        fclose($fp);
        exit;
    } catch (Throwable $e) {
        error_log('panel export csv: ' . $e->getMessage());
    }
}

// ── Cargar catálogo de productos y categorías para cruzar datos ──
$catalogByName = []; // strtolower(name) => ['category' => ..., 'price' => ..., 'is_vegan' => ..., 'is_secret' => ...]
$categoriesMap = [];
try {
    $st = $pdo->query("SELECT id, name FROM categories");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $categoriesMap[(int)$c['id']] = (string)$c['name'];
    }
    $st = $pdo->query("SELECT id, name, price, category_id, has_vegan_option, is_secret_menu, is_weekly_special FROM menu_items");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $mi) {
        $catName = $categoriesMap[(int)$mi['category_id']] ?? 'Otras';
        $key = mb_strtolower(trim((string)$mi['name']), 'UTF-8');
        $catalogByName[$key] = [
            'id'        => (int)$mi['id'],
            'name'      => (string)$mi['name'],
            'price'     => (float)$mi['price'],
            'category'  => $catName,
            'is_secret' => (bool)$mi['is_secret_menu'],
            'is_weekly' => (bool)$mi['is_weekly_special'],
        ];
    }
} catch (Throwable $e) {
    error_log('panel catalog load: ' . $e->getMessage());
}

/**
 * Clasifica el nombre de un ítem del carrito devolviendo su categoría y nombre limpio.
 */
function classifyCartItemName(string $rawName, array $catalogByName): array {
    $clean = trim($rawName);
    $isVegan = false;
    if (stripos($clean, '(Vegana)') !== false) {
        $isVegan = true;
    }
    // Base name antes de " (Vegana)", " — Variante", " + Pizza", " (Para tu pizza)"
    $base = preg_replace('/\s*\((Vegana|Para tu pizza)\)/i', '', $clean);
    if (strpos($base, ' — ') !== false) {
        $base = trim(explode(' — ', $base)[0]);
    }
    if (strpos($base, ' + ') !== false) {
        $base = trim(explode(' + ', $base)[0]);
    }
    $key = mb_strtolower(trim((string)$base), 'UTF-8');
    if (isset($catalogByName[$key])) {
        return [
            'category' => $catalogByName[$key]['category'],
            'catalog_price' => $catalogByName[$key]['price'],
            'is_vegan' => $isVegan,
        ];
    }
    // Búsqueda parcial por prefijo
    foreach ($catalogByName as $k => $info) {
        if ($k !== '' && (mb_strpos($key, $k, 0, 'UTF-8') === 0 || mb_strpos($k, $key, 0, 'UTF-8') === 0)) {
            return [
                'category' => $info['category'],
                'catalog_price' => $info['price'],
                'is_vegan' => $isVegan,
            ];
        }
    }
    return [
        'category' => 'Otros / Especiales',
        'catalog_price' => 0.0,
        'is_vegan' => $isVegan,
    ];
}

// ── Función para calcular resumen KPI de un intervalo ──
function fetchPeriodKpis(PDO $pdo, string $from, string $to, string $channel, bool $hideBots): array {
    $res = [
        'visitas' => 0, 'unicos' => 0, 'bots_filtrados' => 0,
        'pedidos' => 0, 'clientes_unicos' => 0, 'clientes_recurrentes' => 0,
        'facturacion' => 0.0, 'items' => 0,
    ];
    try {
        $pVis = [$from, $to];
        $chSql = buildChannelSql($channel, $pVis);
        $botSql = buildBotSql($hideBots);
        $st = $pdo->prepare("SELECT COUNT(*) c, COUNT(DISTINCT ip_hash) u FROM site_visits WHERE created_at BETWEEN ? AND ? $chSql $botSql");
        $st->execute($pVis);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $res['visitas'] = (int)($row['c'] ?? 0);
        $res['unicos']  = (int)($row['u'] ?? 0);

        if ($hideBots) {
            $pAll = [$from, $to];
            $chAll = buildChannelSql($channel, $pAll);
            $stB = $pdo->prepare("SELECT COUNT(*) FROM site_visits WHERE created_at BETWEEN ? AND ? $chAll");
            $stB->execute($pAll);
            $totalRaw = (int)$stB->fetchColumn();
            $res['bots_filtrados'] = max(0, $totalRaw - $res['visitas']);
        }

        $pOrd = [$from, $to];
        $chOrd = buildChannelSql($channel, $pOrd);
        $st = $pdo->prepare("
            SELECT COUNT(*) c, COUNT(DISTINCT ip_hash) u, COALESCE(SUM(total),0) s, COALESCE(SUM(item_count),0) it
            FROM order_events
            WHERE created_at BETWEEN ? AND ? $chOrd
        ");
        $st->execute($pOrd);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $res['pedidos']         = (int)($row['c'] ?? 0);
        $res['clientes_unicos'] = (int)($row['u'] ?? 0);
        $res['facturacion']     = (float)($row['s'] ?? 0);
        $res['items']           = (int)($row['it'] ?? 0);

        // Clientes con 2+ pedidos en el período
        $stRec = $pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT ip_hash FROM order_events
                WHERE created_at BETWEEN ? AND ? AND ip_hash IS NOT NULL AND ip_hash != '' $chOrd
                GROUP BY ip_hash HAVING COUNT(*) >= 2
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

// ── Series diarias (Visitas, Pedidos, Facturación) ──
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

// Estructuras para análisis profundo
$dowVisits = array_fill(0, 7, 0); // 0=Lunes ... 6=Domingo
$dowOrders = array_fill(0, 7, 0);
$dowRev    = array_fill(0, 7, 0.0);
$heatmapOrders = array_fill(0, 7, array_fill(0, 24, 0));
$heatmapVisits = array_fill(0, 7, array_fill(0, 24, 0));

$referrers = ['Directo / QR' => 0, 'Instagram' => 0, 'Google / Maps' => 0, 'WhatsApp' => 0, 'Facebook' => 0, 'Otros' => 0];
$devices   = ['Celular (Android)' => 0, 'Celular (iPhone)' => 0, 'Computadora (PC/Mac)' => 0, 'Otros' => 0];
$channelsVis = [];
$channelsOrd = [];
$channelsRev = [];

try {
    // 1. Recorremos site_visits del período
    $pVis = [$since, $until];
    $chSql = buildChannelSql($channel, $pVis);
    $botSql = buildBotSql($hideBots);
    $st = $pdo->prepare("SELECT created_at, source, referrer, ua FROM site_visits WHERE created_at BETWEEN ? AND ? $chSql $botSql");
    $st->execute($pVis);
    while ($v = $st->fetch(PDO::FETCH_ASSOC)) {
        $ts = strtotime((string)$v['created_at']);
        if ($ts) {
            $d = date('Y-m-d', $ts);
            if (isset($idxByDate[$d])) $visByDay[$idxByDate[$d]]++;
            $w = ((int)date('N', $ts)) - 1; // 0=Lunes..6=Domingo
            $h = (int)date('G', $ts);
            $dowVisits[$w]++;
            $heatmapVisits[$w][$h]++;
        }
        $src = trim((string)($v['source'] ?? '')) ?: 'carta';
        $channelsVis[$src] = ($channelsVis[$src] ?? 0) + 1;

        // Clasificar referrer
        $ref = strtolower(trim((string)($v['referrer'] ?? '')));
        if ($ref === '' || strpos($ref, 'arrabbiata.com.ar') !== false) {
            $referrers['Directo / QR']++;
        } elseif (strpos($ref, 'instagram') !== false || strpos($ref, 'ig.me') !== false) {
            $referrers['Instagram']++;
        } elseif (strpos($ref, 'google') !== false || strpos($ref, 'maps') !== false) {
            $referrers['Google / Maps']++;
        } elseif (strpos($ref, 'whatsapp') !== false || strpos($ref, 'wa.me') !== false) {
            $referrers['WhatsApp']++;
        } elseif (strpos($ref, 'facebook') !== false || strpos($ref, 'fb.') !== false) {
            $referrers['Facebook']++;
        } else {
            $referrers['Otros']++;
        }

        // Clasificar dispositivo
        $ua = strtolower((string)($v['ua'] ?? ''));
        if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) {
            $devices['Celular (iPhone)']++;
        } elseif (strpos($ua, 'android') !== false) {
            $devices['Celular (Android)']++;
        } elseif (strpos($ua, 'windows') !== false || strpos($ua, 'macintosh') !== false || strpos($ua, 'linux x86') !== false) {
            $devices['Computadora (PC/Mac)']++;
        } else {
            $devices['Otros']++;
        }
    }
    arsort($referrers);
    $referrers = array_filter($referrers, fn($v) => $v > 0);
    $devices   = array_filter($devices, fn($v) => $v > 0);
} catch (Throwable $e) {
    error_log('panel visits analysis: ' . $e->getMessage());
}

// 2. Recorremos order_events del período (una sola pasada en memoria para todas las métricas de pedidos)
$pagoCount = []; $pagoRev = [];
$entregaCount = []; $entregaRev = [];
$franjas = [];
$scheduledVsAsap = ['Horario específico (ej. 21:15)' => 0, 'Lo antes posible / Inmediato' => 0, 'Programado (día siguiente)' => 0];
$prodStats = []; // name => ['qty' => 0, 'rev' => 0.0, 'orders' => 0, 'category' => '']
$catStats  = []; // cat  => ['qty' => 0, 'rev' => 0.0]
$pairStats = []; // "A + B" => count
$veganUnits = 0; $totalPizzaUnits = 0;
$recentOrders = [];

try {
    $pOrd = [$since, $until];
    $chOrd = buildChannelSql($channel, $pOrd);
    $st = $pdo->prepare("
        SELECT id, created_at, source, delivery_type, payment_method, total, pickup_time, item_count, items_json, ip_hash
        FROM order_events
        WHERE created_at BETWEEN ? AND ? $chOrd
        ORDER BY created_at DESC
    ");
    $st->execute($pOrd);
    $allOrders = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allOrders as $idx => $o) {
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

        // Pago
        $pm = trim((string)($o['payment_method'] ?? '')) ?: '(sin dato)';
        $pagoCount[$pm] = ($pagoCount[$pm] ?? 0) + 1;
        $pagoRev[$pm]   = ($pagoRev[$pm]   ?? 0.0) + $tot;

        // Entrega
        $dtRaw = trim((string)($o['delivery_type'] ?? ''));
        $dtLbl = $dtRaw === 'envio' ? '🛵 Envío a domicilio' : ($dtRaw === 'retiro' ? '🏪 Retiro en local' : '(sin dato)');
        $entregaCount[$dtLbl] = ($entregaCount[$dtLbl] ?? 0) + 1;
        $entregaRev[$dtLbl]   = ($entregaRev[$dtLbl]   ?? 0.0) + $tot;

        // Franja horaria
        $pt = trim((string)($o['pickup_time'] ?? ''));
        if ($pt === '' || stripos($pt, 'antes posible') !== false) {
            $scheduledVsAsap['Lo antes posible / Inmediato']++;
            $ptKey = $pt !== '' ? $pt : 'Inmediato (Home)';
        } elseif (preg_match('/(lunes|martes|miércoles|miercoles|jueves|viernes|sábado|sabado|domingo)/i', $pt)) {
            $scheduledVsAsap['Programado (día siguiente)']++;
            $ptKey = $pt;
        } else {
            $scheduledVsAsap['Horario específico (ej. 21:15)']++;
            // Extraer HH:MM si viene con prefijo "hoy 21:00"
            if (preg_match('/(\d{2}:\d{2})/', $pt, $m)) {
                $ptKey = $m[1];
            } else {
                $ptKey = $pt;
            }
        }
        $franjas[$ptKey] = ($franjas[$ptKey] ?? 0) + 1;

        // Productos e ítems
        $items = json_decode((string)($o['items_json'] ?? ''), true);
        $namesInThisOrder = [];
        if (is_array($items)) {
            foreach ($items as $it) {
                $name = trim((string)($it['name'] ?? ''));
                if ($name === '') continue;
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
                $prodStats[$name]['qty'] += $qty;
                $prodStats[$name]['rev'] += $lineRev;
                $prodStats[$name]['orders']++;

                if (!isset($catStats[$catName])) {
                    $catStats[$catName] = ['qty' => 0, 'rev' => 0.0];
                }
                $catStats[$catName]['qty'] += $qty;
                $catStats[$catName]['rev'] += $lineRev;

                if ($meta['is_vegan']) $veganUnits += $qty;
                $totalPizzaUnits += $qty;

                $namesInThisOrder[$name] = true;
            }
        }

        // Pares de productos (Market Basket)
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

        if (count($recentOrders) < 40) {
            $recentOrders[] = $o;
        }
    }

    arsort($pagoCount);
    arsort($entregaCount);
    ksort($franjas);
    arsort($pairStats);
    $topPairs = array_slice($pairStats, 0, 8, true);

    // Ordenar productos por unidades y por facturación
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

// Helper para mantener filtros en links
function filterUrl(array $overrides = []): string {
    $q = array_merge($_GET, $overrides);
    if (isset($overrides['range']) && $overrides['range'] !== 'custom') {
        unset($q['from'], $q['to'], $q['days']);
    }
    return '?' . http_build_query($q);
}

$dowLabels = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
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

    .wrap{max-width:1220px;margin:0 auto;padding:20px 16px 0}
    .disclaimer{background:#fff;border-left:4px solid var(--azul);padding:10px 14px;font-size:.84rem;margin-bottom:16px;border-radius:4px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}

    /* Barra de filtros */
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

    /* KPIs */
    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px;margin-bottom:22px}
    .kpi{background:#fff;border:2.5px solid var(--rojo);border-radius:8px;padding:13px 15px;box-shadow:4px 4px 0 var(--rojo-d);display:flex;flex-direction:column;justify-content:space-between}
    .kpi-top{display:flex;justify-content:space-between;align-items:flex-start;gap:6px}
    .kpi .num{font-size:1.65rem;font-weight:700;color:var(--rojo);line-height:1.1}
    .kpi .lbl{font-size:.76rem;text-transform:uppercase;letter-spacing:.05em;opacity:.8;margin-top:4px;font-weight:700}
    .kpi .sub{font-size:.76rem;color:#6b5b47;margin-top:4px}
    .delta{font-size:.72rem;font-weight:700;padding:2px 6px;border-radius:99px;white-space:nowrap}
    .delta.up{background:#dcfce7;color:#15803d}
    .delta.down{background:#fee2e2;color:#b91c1c}
    .delta.neutral{background:#f3f4f6;color:#6b7280}

    /* Grilla de tarjetas */
    .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:18px;margin-bottom:18px}
    .card{background:#fff;border:2px solid var(--ocre);border-radius:8px;padding:16px;grid-column:span 6;display:flex;flex-direction:column}
    .card.full{grid-column:1 / -1}
    .card.third{grid-column:span 4}
    @media (max-width:960px){ .card,.card.third{grid-column:span 6} }
    @media (max-width:680px){ .card,.card.third{grid-column:1 / -1} }
    .card-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap}
    .card h2{font-size:1rem;color:var(--marron);font-weight:700}
    .card .hint{font-size:.78rem;color:#7a6a54}
    .empty{color:#999;font-size:.9rem;padding:28px 0;text-align:center}
    canvas{max-height:310px}

    /* Tablas internas */
    .data-table{width:100%;border-collapse:collapse;font-size:.86rem}
    .data-table th,.data-table td{padding:8px 10px;text-align:left;border-bottom:1px solid #eee0c8;vertical-align:middle}
    .data-table th{color:var(--rojo);text-transform:uppercase;font-size:.73rem;letter-spacing:.04em;background:#fcf9f2;font-weight:700}
    .data-table tr:hover td{background:#fdfaf4}
    .data-table td.num,.data-table th.num{text-align:right;font-variant-numeric:tabular-nums}
    .badge-cat{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.72rem;font-weight:600;background:#f7efdf;color:var(--marron);border:1px solid var(--ocre)}
    .badge-src{display:inline-block;padding:2px 7px;border-radius:4px;font-size:.72rem;font-weight:700;text-transform:uppercase}
    .badge-src.carta{background:#fee2e2;color:var(--rojo)}
    .badge-src.home{background:#dbeafe;color:var(--azul)}

    /* Mapa de calor */
    .heatmap-wrap{overflow-x:auto}
    .heatmap{width:100%;border-collapse:separate;border-spacing:3px;font-size:.75rem;min-width:640px}
    .heatmap th{padding:4px;color:#7a6a54;font-weight:600;text-align:center}
    .heatmap td.day-lbl{font-weight:700;padding:4px 8px;text-align:right;white-space:nowrap;color:var(--marron)}
    .heatmap td.cell{text-align:center;padding:6px 2px;border-radius:4px;font-weight:600;font-variant-numeric:tabular-nums;transition:transform .1s}
    .heatmap td.cell:hover{transform:scale(1.12);outline:1.5px solid var(--marron)}

    /* Buscador tabla de pedidos */
    .search-input{padding:6px 12px;border:1.5px solid var(--ocre-d);border-radius:6px;font-size:.85rem;font-family:inherit;min-width:220px}
</style>
</head>
<body>
    <div class="topbar">
        <h1>📊 Estadísticas — Arrabbiata</h1>
        <nav>
            <a href="<?php echo htmlspecialchars(filterUrl(['export' => 'csv'])); ?>" class="btn-csv">⬇ Exportar Pedidos CSV</a>
            <a href="panel_concurso.php">🍕 Archivo «Armá tu pizza»</a>
            <a href="editor.php">← Editor de Carta</a>
            <a href="logout.php">Salir</a>
        </nav>
    </div>

    <div class="wrap">
        <div class="disclaimer">
            <div>
                Los <b>pedidos</b> corresponden a la intención enviada a WhatsApp desde la carta o la web.
                Las variaciones (<b>▲/▼</b>) comparan contra los <b><?php echo round($periodSeconds / 86400); ?> días previos</b> (<?php echo $prevSinceDt->format('d/m'); ?> al <?php echo $prevUntilDt->format('d/m'); ?>).
            </div>
            <div>
                <?php if ($hideBots): ?>
                    🤖 Bots ocultos (<?php echo number_format($kpi['bots_filtrados'], 0, ',', '.'); ?> filtrados) · <a href="<?php echo htmlspecialchars(filterUrl(['bots' => '1'])); ?>" style="color:var(--azul)">Incluir bots</a>
                <?php else: ?>
                    🤖 Bots incluidos · <a href="<?php echo htmlspecialchars(filterUrl(['bots' => '0'])); ?>" style="color:var(--azul)">Ocultar bots</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Barra de filtros -->
        <div class="filter-bar">
            <div class="filter-group">
                <span class="lbl">Período:</span>
                <?php
                $presets = [
                    'today'     => 'Hoy',
                    'yesterday' => 'Ayer',
                    '7'         => '7 días',
                    '30'        => '30 días',
                    '90'        => '90 días',
                    '365'       => '1 año',
                ];
                foreach ($presets as $k => $lbl):
                ?>
                    <a href="<?php echo htmlspecialchars(filterUrl(['range' => $k])); ?>"
                       class="pill-btn <?php echo $rangePreset === (string)$k ? 'active' : ''; ?>"><?php echo $lbl; ?></a>
                <?php endforeach; ?>

                <form method="GET" class="custom-dates">
                    <input type="hidden" name="channel" value="<?php echo htmlspecialchars($channel); ?>">
                    <input type="hidden" name="bots" value="<?php echo $hideBots ? '0' : '1'; ?>">
                    <input type="date" name="from" value="<?php echo htmlspecialchars($sinceDt->format('Y-m-d')); ?>" required>
                    <span>a</span>
                    <input type="date" name="to" value="<?php echo htmlspecialchars($untilDt->format('Y-m-d')); ?>" required>
                    <button type="submit">Filtrar</button>
                </form>
            </div>

            <div class="filter-group">
                <span class="lbl">Canal:</span>
                <?php
                $chOptions = [
                    'pizzeria' => '🍕 Pizzería (Carta + Web)',
                    'carta'    => '📱 Solo /carta',
                    'home'     => '🏠 Solo Home',
                ];
                foreach ($chOptions as $ck => $clbl):
                ?>
                    <a href="<?php echo htmlspecialchars(filterUrl(['channel' => $ck])); ?>"
                       class="pill-btn ch <?php echo $channel === $ck ? 'active' : ''; ?>"><?php echo $clbl; ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- KPIs con comparativa contra período anterior -->
        <div class="kpis">
            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo number_format($kpi['visitas'], 0, ',', '.'); ?></div>
                    <?php echo fmtDelta($kpi['visitas'], $prevKpi['visitas']); ?>
                </div>
                <div class="lbl">Visitas a la carta</div>
                <div class="sub">👤 <?php echo number_format($kpi['unicos'], 0, ',', '.'); ?> visitantes únicos</div>
            </div>

            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo number_format($kpi['pedidos'], 0, ',', '.'); ?></div>
                    <?php echo fmtDelta($kpi['pedidos'], $prevKpi['pedidos']); ?>
                </div>
                <div class="lbl">Pedidos (WhatsApp)</div>
                <div class="sub">🍕 <?php echo number_format($kpi['items'], 0, ',', '.'); ?> unidades (<?php echo number_format($kpi['items_x_ped'], 1, ',', '.'); ?>/ped)</div>
            </div>

            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo number_format($kpi['conversion'], 1, ',', '.'); ?>%</div>
                    <?php echo fmtDelta($kpi['conversion'], $prevKpi['conversion']); ?>
                </div>
                <div class="lbl">Conversión por visita</div>
                <div class="sub">🎯 <?php echo number_format($kpi['conv_unicos'], 1, ',', '.'); ?>% s/ visitantes únicos</div>
            </div>

            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo $money($kpi['facturacion']); ?></div>
                    <?php echo fmtDelta($kpi['facturacion'], $prevKpi['facturacion']); ?>
                </div>
                <div class="lbl">Total (Intención)</div>
                <div class="sub">Ant.: <?php echo $money($prevKpi['facturacion']); ?></div>
            </div>

            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo $money($kpi['ticket']); ?></div>
                    <?php echo fmtDelta($kpi['ticket'], $prevKpi['ticket']); ?>
                </div>
                <div class="lbl">Ticket Promedio</div>
                <div class="sub">Ant.: <?php echo $money($prevKpi['ticket']); ?></div>
            </div>

            <div class="kpi">
                <div class="kpi-top">
                    <div class="num"><?php echo number_format($kpi['clientes_recurrentes'], 0, ',', '.'); ?></div>
                    <?php echo fmtDelta($kpi['clientes_recurrentes'], $prevKpi['clientes_recurrentes']); ?>
                </div>
                <div class="lbl">Clientes Recurrentes</div>
                <div class="sub">🔁 De <?php echo number_format($kpi['clientes_unicos'], 0, ',', '.'); ?> clientes que pidieron</div>
            </div>
        </div>

        <!-- Evolución diaria -->
        <div class="grid">
            <div class="card full">
                <div class="card-head">
                    <h2>📈 Evolución diaria de Visitas, Pedidos y Facturación</h2>
                    <span class="hint">Del <?php echo $sinceDt->format('d/m/Y'); ?> al <?php echo $untilDt->format('d/m/Y'); ?></span>
                </div>
                <canvas id="chartDaily"></canvas>
            </div>

            <!-- Rendimiento por día de la semana -->
            <div class="card">
                <div class="card-head">
                    <h2>📅 Pedidos y Visitas por Día de la Semana</h2>
                    <span class="hint">Detectá tus días más fuertes</span>
                </div>
                <canvas id="chartDow"></canvas>
            </div>

            <!-- Facturación por día de la semana -->
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
                            $v = $dowVisits[$wi];
                            $o = $dowOrders[$wi];
                            $r = $dowRev[$wi];
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

            <!-- Mapa de calor Día x Hora -->
            <div class="card full">
                <div class="card-head">
                    <h2>🔥 Mapa de Calor de Pedidos (Día × Hora en que se envía el pedido)</h2>
                    <span class="hint">Muestra las horas pico reales en las que los clientes arman y mandan el carrito (11h a 01h)</span>
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

            <!-- Ranking de Productos: Unidades y Facturación -->
            <div class="card">
                <div class="card-head">
                    <h2>🍕 Top Productos (por Unidades vendidas)</h2>
                    <span class="hint"><?php if ($veganUnits > 0) echo "🌿 {$veganUnits} unid. veganas en el período"; ?></span>
                </div>
                <?php if ($topProdQty): ?>
                    <canvas id="chartProd"></canvas>
                <?php else: ?>
                    <div class="empty">Sin datos de productos en este rango</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>💵 Top Productos por Facturación ($) y Categoría</h2>
                    <span class="hint">Cruzado con el catálogo actual</span>
                </div>
                <?php if ($topProdRev): ?>
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
                                <td><b><?php echo htmlspecialchars($pName); ?></b></td>
                                <td><span class="badge-cat"><?php echo htmlspecialchars($st['category']); ?></span></td>
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

            <!-- Mix por Categoría y Combinaciones Frecuentes -->
            <div class="card">
                <div class="card-head">
                    <h2>🗂️ Ventas por Categoría de Carta</h2>
                    <span class="hint">Participación en unidades y facturación</span>
                </div>
                <?php if ($catStats): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Categoría</th>
                            <th class="num">Unidades</th>
                            <th class="num">Facturación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($catStats as $cName => $cst): ?>
                        <tr>
                            <td><b><?php echo htmlspecialchars($cName); ?></b></td>
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
                    <h2>🤝 Productos que más se piden juntos (Combos reales)</h2>
                    <span class="hint">Pares frecuentes dentro del mismo carrito</span>
                </div>
                <?php if (!empty($topPairs)): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Combinación en el mismo pedido</th>
                            <th class="num">Veces juntos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topPairs as $pairName => $cnt): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($pairName); ?></td>
                            <td class="num"><b><?php echo number_format($cnt, 0, ',', '.'); ?></b></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="empty">Todavía no hay suficientes pedidos con 2+ productos distintos</div>
                <?php endif; ?>
            </div>

            <!-- Operativa: Forma de pago, Retiro vs Envío, Franjas Horarias -->
            <div class="card">
                <div class="card-head">
                    <h2>💳 Forma de Pago (Pedidos y Monto $)</h2>
                    <span class="hint">Desglose financiero</span>
                </div>
                <?php if ($pagoCount): ?>
                    <div style="display:grid;grid-template-columns:140px 1fr;gap:12px;align-items:center">
                        <canvas id="chartPago" style="max-height:140px"></canvas>
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
                                    <td><b><?php echo htmlspecialchars($pm); ?></b></td>
                                    <td class="num"><?php echo $c; ?></td>
                                    <td class="num"><?php echo $money($rv); ?></td>
                                    <td class="num"><?php echo number_format($pctRev, 1, ',', '.'); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
                    <div style="display:grid;grid-template-columns:140px 1fr;gap:12px;align-items:center">
                        <canvas id="chartEntrega" style="max-height:140px"></canvas>
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
                                    <td><b><?php echo htmlspecialchars($dtLbl); ?></b></td>
                                    <td class="num"><?php echo $c; ?></td>
                                    <td class="num"><?php echo $money($rv); ?></td>
                                    <td class="num"><?php echo $money($tk); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty">Sin datos todavía</div>
                <?php endif; ?>
            </div>

            <div class="card full">
                <div class="card-head">
                    <h2>🕒 Franjas Horarias de Retiro / Entrega Elegidas</h2>
                    <span class="hint">Horarios solicitados en el selector de turnos de la carta</span>
                </div>
                <?php if ($franjas): ?>
                    <canvas id="chartFranjas"></canvas>
                <?php else: ?>
                    <div class="empty">Sin datos todavía</div>
                <?php endif; ?>
            </div>

            <!-- Canales, Orígenes de Tráfico y Dispositivos -->
            <div class="card third">
                <div class="card-head">
                    <h2>🚪 Rendimiento por Canal</h2>
                    <span class="hint">Carta vs. Home</span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr><th>Canal</th><th class="num">Visitas</th><th class="num">Ped.</th><th class="num">Conv.</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $allSrcs = array_unique(array_merge(array_keys($channelsVis), array_keys($channelsOrd)));
                        foreach ($allSrcs as $sKey):
                            $sv = $channelsVis[$sKey] ?? 0;
                            $so = $channelsOrd[$sKey] ?? 0;
                            $sc = $sv > 0 ? ($so / $sv) * 100 : 0;
                        ?>
                        <tr>
                            <td><span class="badge-src <?php echo htmlspecialchars($sKey); ?>"><?php echo htmlspecialchars($sKey); ?></span></td>
                            <td class="num"><?php echo number_format($sv, 0, ',', '.'); ?></td>
                            <td class="num"><b><?php echo number_format($so, 0, ',', '.'); ?></b></td>
                            <td class="num"><?php echo number_format($sc, 1, ',', '.'); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card third">
                <div class="card-head">
                    <h2>📲 Origen de las Visitas</h2>
                    <span class="hint">Según Referrer HTTP</span>
                </div>
                <?php if ($referrers): ?>
                <table class="data-table">
                    <thead>
                        <tr><th>Origen</th><th class="num">Visitas</th><th class="num">%</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($referrers as $rName => $rCnt):
                            $pct = $kpi['visitas'] > 0 ? ($rCnt / $kpi['visitas']) * 100 : 0;
                        ?>
                        <tr>
                            <td><b><?php echo htmlspecialchars($rName); ?></b></td>
                            <td class="num"><?php echo number_format($rCnt, 0, ',', '.'); ?></td>
                            <td class="num"><?php echo number_format($pct, 1, ',', '.'); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="empty">Sin datos</div>
                <?php endif; ?>
            </div>

            <div class="card third">
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
                            <td><b><?php echo htmlspecialchars($dName); ?></b></td>
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

            <!-- Tabla en vivo de últimos pedidos -->
            <div class="card full">
                <div class="card-head">
                    <h2>🧾 Últimos Pedidos Enviados a WhatsApp (<?php echo count($recentOrders); ?> mostrados)</h2>
                    <input type="search" id="orderSearch" class="search-input" placeholder="🔍 Buscar por pizza, pago, horario...">
                </div>
                <?php if ($recentOrders): ?>
                <div style="overflow-x:auto">
                    <table class="data-table" id="ordersTable">
                        <thead>
                            <tr>
                                <th>Fecha / Hora</th>
                                <th>Canal</th>
                                <th>Modalidad</th>
                                <th>Horario</th>
                                <th>Pago</th>
                                <th>Detalle del Pedido</th>
                                <th class="num">Total</th>
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
                                        if ($nm !== '') $pills[] = htmlspecialchars($nm) . ' <b>×' . $qt . '</b>';
                                    }
                                }
                                $dt = $ro['delivery_type'] === 'envio' ? '🛵 Envío' : ($ro['delivery_type'] === 'retiro' ? '🏪 Retiro' : '—');
                            ?>
                            <tr>
                                <td style="white-space:nowrap"><?php echo htmlspecialchars($fechaFmt); ?></td>
                                <td><span class="badge-src <?php echo htmlspecialchars((string)$ro['source']); ?>"><?php echo htmlspecialchars((string)$ro['source']); ?></span></td>
                                <td style="white-space:nowrap"><?php echo $dt; ?></td>
                                <td style="white-space:nowrap"><?php echo htmlspecialchars($ro['pickup_time'] ?: 'Inmediato'); ?></td>
                                <td><?php echo htmlspecialchars($ro['payment_method'] ?: '—'); ?></td>
                                <td><?php echo $pills ? implode(' · ', $pills) : '<span style="color:#999">(sin detalle)</span>'; ?></td>
                                <td class="num"><b><?php echo $money($ro['total']); ?></b></td>
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
    const prodQty   = <?php echo json_encode(array_map(fn($x) => $x['qty'], $topProdQty)); ?>;
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
            options: { responsive: true, plugins: { legend: { display: false } } }
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

    // Filtro en vivo para la tabla de últimos pedidos
    const searchInput = document.getElementById('orderSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('#ordersTable tbody tr').forEach(tr => {
                tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }
</script>
</body>
</html>

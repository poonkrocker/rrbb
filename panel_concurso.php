<?php
/**
 * panel_concurso.php — Estadísticas históricas y archivo de «Armá tu pizza».
 *
 * Página separada de panel.php para consultar los resultados del concurso
 * (/cumple) o de los editores (/semana, /pizza) sin sobrecargar el panel diario.
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

// ── Selección de carpeta de pizzas (cumple | semana | pizza) ──
$folderKey = isset($_GET['src']) ? (string)$_GET['src'] : 'cumple';
if (!in_array($folderKey, ['cumple', 'semana', 'pizza'], true)) {
    $folderKey = 'cumple';
}

$candidates = [
    __DIR__ . '/' . $folderKey . '/data/pizzas',
    dirname(__DIR__) . '/' . $folderKey . '/data/pizzas',
    __DIR__ . '/data/pizzas',
];
$pizzaDir = '';
foreach ($candidates as $cand) {
    if (is_dir($cand)) { $pizzaDir = $cand; break; }
}

$pizzaLib = '';
if ($pizzaDir !== '') {
    foreach ([dirname($pizzaDir, 2) . '/ingredients.json', dirname($pizzaDir) . '/ingredients.json'] as $cand) {
        if (is_file($cand)) { $pizzaLib = $cand; break; }
    }
}

$staffPickId = '';
if ($pizzaDir !== '') {
    $staffFile = dirname($pizzaDir) . '/staff-pick.json';
    if (is_file($staffFile)) {
        $sj = json_decode((string)@file_get_contents($staffFile), true);
        if (is_array($sj)) $staffPickId = (string)($sj['id'] ?? '');
    }
}

// ── Rango de fechas (Por defecto: 'all' porque el concurso ya pasó) ──
$days = isset($_GET['days']) ? (string)$_GET['days'] : 'all';
if (!in_array($days, ['all', '7', '30', '90'], true)) {
    $days = 'all';
}
$sinceTs = 0;
if ($days !== 'all') {
    $sinceDt = new DateTime("today -" . ((int)$days - 1) . " days");
    $sinceTs = $sinceDt->getTimestamp();
}

// ── Visitas registradas en MySQL para source='cumple' ──
$visitasConcurso = 0;
$unicosConcurso  = 0;
try {
    if ($sinceTs > 0) {
        $st = $pdo->prepare("SELECT COUNT(*) c, COUNT(DISTINCT ip_hash) u FROM site_visits WHERE source = 'cumple' AND created_at >= ?");
        $st->execute([date('Y-m-d 00:00:00', $sinceTs)]);
    } else {
        $st = $pdo->query("SELECT COUNT(*) c, COUNT(DISTINCT ip_hash) u FROM site_visits WHERE source = 'cumple'");
    }
    $row = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    $visitasConcurso = (int)($row['c'] ?? 0);
    $unicosConcurso  = (int)($row['u'] ?? 0);
} catch (Throwable $e) {
    error_log('panel_concurso visits: ' . $e->getMessage());
}

// ── Mapas sid/base/drizzle -> nombre legible ──
$ingName = []; $baseName = []; $drizName = [];
if ($pizzaLib !== '') {
    $lib = json_decode((string)@file_get_contents($pizzaLib), true);
    if (is_array($lib)) {
        foreach (($lib['ingredients'] ?? []) as $x) if (isset($x['id'])) $ingName[(string)$x['id']]  = (string)($x['name'] ?? $x['id']);
        foreach (($lib['bases']       ?? []) as $x) if (isset($x['id'])) $baseName[(string)$x['id']] = (string)($x['name'] ?? $x['id']);
        foreach (($lib['drizzles']    ?? []) as $x) if (isset($x['id'])) $drizName[(string)$x['id']] = (string)($x['name'] ?? $x['id']);
    }
}

// ── Recorre las pizzas guardadas y agrega métricas ──
$pz = [
    'creadas'      => 0,
    'ocultas'      => 0,
    'participan'   => 0,
    'split'        => 0,
    'conDrizzle'   => 0,
    'totalItems'   => 0,
    'totalVotes'   => 0,
    'uniqueVoters' => 0,
];
$allVotersSet   = [];
$allDevicesSet  = [];
$ingCountUnique = [];
$ingCountAbs    = [];
$ingStateCount  = []; // name => ['entero'=>0, 'picado'=>0, 'mitad'=>0]
$baseCount      = [];
$drizCount      = [];
$pairIngCount   = [];
$byDateMap      = []; // 'Y-m-d' => count
$pizzasList     = [];

if ($pizzaDir !== '') {
    try {
        foreach ((array)glob($pizzaDir . '/*.json') as $f) {
            if (basename($f) === 'index.json') continue;
            $j = json_decode((string)@file_get_contents($f), true);
            if (!is_array($j)) continue;

            $ts = (int)($j['createdAt'] ?? @filemtime($f));
            if ($sinceTs > 0 && $ts && $ts < $sinceTs) continue;

            if (!empty($j['hidden'])) {
                $pz['ocultas']++;
                continue;
            }

            $pz['creadas']++;
            if (!empty($j['entered']) || !empty($j['author'])) $pz['participan']++;
            if (!empty($j['split'])) $pz['split']++;

            $votes = (int)($j['votes'] ?? 0);
            $pz['totalVotes'] += $votes;
            if (!empty($j['voters']) && is_array($j['voters'])) {
                foreach ($j['voters'] as $vh) $allVotersSet[(string)$vh] = true;
            }
            if (!empty($j['device'])) {
                $allDevicesSet[(string)$j['device']] = true;
            }

            if ($ts) {
                $d = date('Y-m-d', $ts);
                $byDateMap[$d] = ($byDateMap[$d] ?? 0) + 1;
            }

            // Bases
            foreach ([(string)($j['base'] ?? ''), !empty($j['split']) ? (string)($j['base2'] ?? '') : ''] as $b) {
                if ($b === '' || $b === 'none') continue;
                $lbl = $baseName[$b] ?? $b;
                $baseCount[$lbl] = ($baseCount[$lbl] ?? 0) + 1;
            }

            // Salsas / Drizzles
            $drizArr = is_array($j['drizzles'] ?? null) ? $j['drizzles'] : [];
            if (count($drizArr) > 0) $pz['conDrizzle']++;
            foreach ($drizArr as $dz) {
                $dz = (string)$dz;
                if ($dz === '') continue;
                $lbl = $drizName[$dz] ?? $dz;
                $drizCount[$lbl] = ($drizCount[$lbl] ?? 0) + 1;
            }

            // Ingredientes
            $seenInPizza = [];
            foreach ((is_array($j['items'] ?? null) ? $j['items'] : []) as $it) {
                $sid = (string)($it['sid'] ?? '');
                if ($sid === '') continue;
                $st  = (string)($it['st'] ?? 'entero');
                $lbl = $ingName[$sid] ?? $sid;

                $ingCountAbs[$lbl] = ($ingCountAbs[$lbl] ?? 0) + 1;
                if (!isset($ingStateCount[$lbl])) {
                    $ingStateCount[$lbl] = ['entero' => 0, 'picado' => 0, 'mitad' => 0];
                }
                if (isset($ingStateCount[$lbl][$st])) {
                    $ingStateCount[$lbl][$st]++;
                } else {
                    $ingStateCount[$lbl]['entero']++;
                }

                if (!isset($seenInPizza[$lbl])) {
                    $seenInPizza[$lbl] = true;
                    $ingCountUnique[$lbl] = ($ingCountUnique[$lbl] ?? 0) + 1;
                }
                $pz['totalItems']++;
            }

            // Parejas de ingredientes (excluyendo muzzarella para descubrir combinaciones creativas)
            $creativeIngs = array_values(array_filter(array_keys($seenInPizza), fn($nm) => mb_strtolower($nm, 'UTF-8') !== 'muzzarella'));
            sort($creativeIngs);
            $nC = count($creativeIngs);
            if ($nC >= 2 && $nC <= 12) {
                for ($a = 0; $a < $nC; $a++) {
                    for ($b = $a + 1; $b < $nC; $b++) {
                        $pk = $creativeIngs[$a] . ' + ' . $creativeIngs[$b];
                        $pairIngCount[$pk] = ($pairIngCount[$pk] ?? 0) + 1;
                    }
                }
            }

            $id = (string)($j['id'] ?? basename($f, '.json'));
            $pizzasList[] = [
                'id'          => $id,
                'name'        => (string)($j['name'] ?? '(sin nombre)'),
                'author'      => (string)($j['author'] ?? 'Anónimo'),
                'phone'       => (string)($j['phone'] ?? ''),
                'instagram'   => (string)($j['instagram'] ?? ''),
                'votes'       => $votes,
                'split'       => !empty($j['split']),
                'base'        => $baseName[(string)($j['base'] ?? '')] ?? (string)($j['base'] ?? ''),
                'ingredients' => array_keys($seenInPizza),
                'itemCount'   => is_array($j['items'] ?? null) ? count($j['items']) : 0,
                'staffPick'   => ($staffPickId !== '' && $staffPickId === $id),
                'createdAt'   => $ts,
            ];
        }
    } catch (Throwable $e) {
        error_log('panel_concurso scan: ' . $e->getMessage());
    }
}

$pz['uniqueVoters'] = count($allVotersSet);
$pzAvgItems = $pz['creadas'] > 0 ? $pz['totalItems'] / $pz['creadas'] : 0;
$pzSplitPct = $pz['creadas'] > 0 ? ($pz['split'] / $pz['creadas']) * 100 : 0;

arsort($ingCountUnique);
arsort($ingCountAbs);
arsort($baseCount);
arsort($drizCount);
arsort($pairIngCount);

$ingTop    = array_slice($ingCountUnique, 0, 15, true);
$ingTopAbs = array_slice($ingCountAbs, 0, 20, true);
$topPairs  = array_slice($pairIngCount, 0, 12, true);

// Ordenar pizzas por votos DESC, luego fecha DESC
usort($pizzasList, function ($a, $b) {
    if ($b['votes'] !== $a['votes']) return $b['votes'] <=> $a['votes'];
    return $b['createdAt'] <=> $a['createdAt'];
});
$topPizzas = array_slice($pizzasList, 0, 15);

// Construir serie de días continua entre primera y última pizza
ksort($byDateMap);
$labels = []; $pzByDay = [];
if (!empty($byDateMap)) {
    $firstDate = array_key_first($byDateMap);
    $lastDate  = array_key_last($byDateMap);
    $cur = new DateTime($firstDate);
    $end = new DateTime($lastDate);
    while ($cur <= $end) {
        $k = $cur->format('Y-m-d');
        $labels[]  = $cur->format('d/m');
        $pzByDay[] = $byDateMap[$k] ?? 0;
        $cur->modify('+1 day');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Archivo «Armá tu pizza» — Arrabbiata</title>
<link rel="icon" type="image/png" href="favicon.png"/>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
    :root{
        --rojo:#CC1414; --rojo-d:#A50E0E; --azul:#1A5FBF; --crema:#F0E6D0;
        --blanco:#F8F6F2; --marron:#2b1a0a; --verde:#25D366; --ocre:#D4B483; --ocre-d:#b8955a; --oro:#FDB740;
    }
    *{margin:0;padding:0;box-sizing:border-box}
    body{background:var(--crema);color:var(--marron);font-family:'DM Sans',Arial,sans-serif;padding:0 0 60px;line-height:1.4}
    .topbar{background:var(--marron);color:#fff;padding:14px 24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;border-bottom:3px solid var(--rojo)}
    .topbar h1{font-size:1.25rem;letter-spacing:.02em}
    .topbar nav{display:flex;align-items:center;flex-wrap:wrap;gap:10px}
    .topbar nav a{color:#fff;text-decoration:none;font-size:.88rem;padding:6px 12px;border-radius:6px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);font-weight:600}
    .topbar nav a:hover{background:var(--rojo);border-color:var(--rojo)}

    .wrap{max-width:1200px;margin:0 auto;padding:20px 16px 0}
    .disclaimer{background:#fff;border-left:4px solid var(--rojo);padding:10px 14px;font-size:.85rem;margin-bottom:16px;border-radius:4px}
    .filter-bar{background:#fff;border:2px solid var(--ocre);border-radius:8px;padding:12px 14px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between}
    .filter-group{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
    .filter-group .lbl{font-size:.76rem;text-transform:uppercase;font-weight:700;letter-spacing:.04em;color:#7a6a54;margin-right:4px}
    .pill-btn{padding:6px 12px;border:2px solid var(--azul);border-radius:5px;text-decoration:none;color:var(--azul);font-size:.84rem;font-weight:600;background:#fff}
    .pill-btn.active{background:var(--azul);color:#fff}
    .pill-btn.src{border-color:var(--rojo);color:var(--rojo)}
    .pill-btn.src.active{background:var(--rojo);color:#fff}

    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:22px}
    .kpi{background:#fff;border:2.5px solid var(--rojo);border-radius:8px;padding:13px 15px;box-shadow:4px 4px 0 var(--rojo-d)}
    .kpi .num{font-size:1.65rem;font-weight:700;color:var(--rojo)}
    .kpi .lbl{font-size:.76rem;text-transform:uppercase;letter-spacing:.05em;opacity:.8;margin-top:3px;font-weight:700}
    .kpi .sub{font-size:.76rem;color:#6b5b47;margin-top:4px}

    .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:18px;margin-bottom:18px}
    .card{background:#fff;border:2px solid var(--ocre);border-radius:8px;padding:16px;grid-column:span 6}
    .card.full{grid-column:1 / -1}
    @media (max-width:768px){ .card{grid-column:1 / -1} }
    .card h2{font-size:1rem;margin-bottom:12px;color:var(--marron);font-weight:700}
    .empty{color:#999;font-size:.9rem;padding:28px 0;text-align:center}
    canvas{max-height:320px}

    .ing-table{width:100%;border-collapse:collapse;font-size:.86rem}
    .ing-table th,.ing-table td{padding:8px 10px;text-align:left;border-bottom:1px solid #eee0c8;vertical-align:middle}
    .ing-table th{color:var(--rojo);text-transform:uppercase;font-size:.73rem;letter-spacing:.04em;background:#fcf9f2}
    .ing-table td.num,.ing-table th.num{text-align:right;font-variant-numeric:tabular-nums}
    .pill-ing{display:inline-block;padding:2px 7px;margin:1px;border-radius:99px;background:#f7efdf;border:1px solid var(--ocre);font-size:.73rem}
    .thumb-sm{width:44px;height:44px;border-radius:6px;object-fit:contain;background:#f7efdf;vertical-align:middle}
    .badge-win{display:inline-block;padding:2px 7px;border-radius:99px;font-size:.7rem;font-weight:700;background:var(--oro);color:var(--marron)}
    .badge-staff{display:inline-block;padding:2px 7px;border-radius:99px;font-size:.7rem;font-weight:700;background:var(--rojo);color:#fff}
</style>
</head>
<body>
    <div class="topbar">
        <h1>🍕 Estadísticas «Armá tu pizza» (Archivo)</h1>
        <nav>
            <a href="cumple/admin/panel.php">⚙️ Moderación y Exportador de Pizzas</a>
            <a href="cumple/api.php?action=admin-csv">⬇ Exportar Participantes CSV</a>
            <a href="panel.php">← Volver al Panel Principal</a>
        </nav>
    </div>

    <div class="wrap">
        <div class="disclaimer">
            Archivo de análisis del editor interactivo <b>«Armá tu pizza»</b>. Lee las pizzas guardadas en <code><?php echo htmlspecialchars($folderKey); ?>/data/pizzas</code> y traduce cada ingrediente desde <code>ingredients.json</code>. Por defecto muestra <b>todo el historial</b> del concurso.
        </div>

        <div class="filter-bar">
            <div class="filter-group">
                <span class="lbl">Galería / Carpeta:</span>
                <?php foreach (['cumple' => '🎉 Concurso Cumple (/cumple)', 'semana' => '📅 Pizza de la Semana (/semana)', 'pizza' => '🍕 Editor (/pizza)'] as $sk => $slbl): ?>
                    <a href="?src=<?php echo $sk; ?>&days=<?php echo htmlspecialchars($days); ?>"
                       class="pill-btn src <?php echo $folderKey === $sk ? 'active' : ''; ?>"><?php echo $slbl; ?></a>
                <?php endforeach; ?>
            </div>
            <div class="filter-group">
                <span class="lbl">Rango:</span>
                <?php foreach (['all' => 'Todo el historial', '90' => 'Últimos 90d', '30' => 'Últimos 30d', '7' => 'Últimos 7d'] as $dk => $dlbl): ?>
                    <a href="?src=<?php echo htmlspecialchars($folderKey); ?>&days=<?php echo $dk; ?>"
                       class="pill-btn <?php echo $days === (string)$dk ? 'active' : ''; ?>"><?php echo $dlbl; ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($pizzaDir === ''): ?>
            <div class="card full"><div class="empty">No se encontró la carpeta de pizzas para <code><?php echo htmlspecialchars($folderKey); ?></code>.</div></div>
        <?php else: ?>
            <div class="kpis">
                <div class="kpi">
                    <div class="num"><?php echo number_format($pz['creadas'], 0, ',', '.'); ?></div>
                    <div class="lbl">Pizzas Creadas</div>
                    <div class="sub"><?php echo count($allDevicesSet); ?> dispositivos · <?php echo $pz['ocultas']; ?> ocultas</div>
                </div>
                <div class="kpi">
                    <div class="num"><?php echo number_format($pz['totalVotes'], 0, ',', '.'); ?></div>
                    <div class="lbl">Votos Totales</div>
                    <div class="sub">🗳️ <?php echo number_format($pz['uniqueVoters'], 0, ',', '.'); ?> votantes únicos</div>
                </div>
                <div class="kpi">
                    <div class="num"><?php echo number_format($pzAvgItems, 1, ',', '.'); ?></div>
                    <div class="lbl">Ingredientes Promedio</div>
                    <div class="sub">🧀 <?php echo number_format($pz['totalItems'], 0, ',', '.'); ?> piezas colocadas</div>
                </div>
                <div class="kpi">
                    <div class="num"><?php echo number_format($pzSplitPct, 1, ',', '.'); ?>%</div>
                    <div class="lbl">Pizzas Mitad y Mitad</div>
                    <div class="sub">🌗 <?php echo $pz['split']; ?> partidas · 🍯 <?php echo $pz['conDrizzle']; ?> con salsa final</div>
                </div>
                <?php if ($folderKey === 'cumple'): ?>
                <div class="kpi">
                    <div class="num"><?php echo number_format($visitasConcurso, 0, ',', '.'); ?></div>
                    <div class="lbl">Visitas al Editor</div>
                    <div class="sub">👤 <?php echo number_format($unicosConcurso, 0, ',', '.'); ?> únicos (<?php echo $visitasConcurso > 0 ? number_format(($pz['creadas'] / $visitasConcurso) * 100, 1, ',', '.') : '0'; ?>% armó pizza)</div>
                </div>
                <?php endif; ?>
            </div>

            <div class="grid">
                <div class="card full">
                    <h2>📈 Pizzas creadas por día</h2>
                    <?php if ($pzByDay): ?><canvas id="chartPzDay"></canvas><?php else: ?><div class="empty">Sin pizzas en este rango</div><?php endif; ?>
                </div>

                <div class="card">
                    <h2>🧀 Ingredientes más elegidos (por Pizzas Únicas)</h2>
                    <?php if ($ingTop): ?><canvas id="chartIng"></canvas><?php else: ?><div class="empty">Sin datos</div><?php endif; ?>
                </div>

                <div class="card">
                    <h2>🤝 Combinaciones de Ingredientes más frecuentes (sin contar Muzza)</h2>
                    <?php if ($topPairs): ?>
                    <div style="max-height:310px;overflow-y:auto">
                        <table class="ing-table">
                            <thead><tr><th>Dupla creativa</th><th class="num">Pizzas que la combinan</th></tr></thead>
                            <tbody>
                                <?php foreach ($topPairs as $pair => $cnt): ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($pair); ?></b></td>
                                    <td class="num"><b><?php echo $cnt; ?></b> (<?php echo $pz['creadas'] > 0 ? number_format(($cnt / $pz['creadas']) * 100, 1, ',', '.') : 0; ?>%)</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?><div class="empty">Sin datos suficientes</div><?php endif; ?>
                </div>

                <div class="card">
                    <h2>🍅 Bases más elegidas</h2>
                    <?php if ($baseCount): ?><canvas id="chartBase"></canvas><?php else: ?><div class="empty">Sin datos</div><?php endif; ?>
                </div>

                <div class="card">
                    <h2>🍯 Salsas / Terminaciones (Drizzles) más usadas</h2>
                    <?php if ($drizCount): ?><canvas id="chartDriz"></canvas><?php else: ?><div class="empty">Sin datos</div><?php endif; ?>
                </div>

                <div class="card full">
                    <h2>📊 Detalle de Ingredientes: Pizzas Únicas, Piezas Absolutas y Formato (Entero / Picado / Mitad)</h2>
                    <?php if ($ingTopAbs): ?>
                    <div style="overflow-x:auto">
                        <table class="ing-table">
                            <thead>
                                <tr>
                                    <th>Ingrediente</th>
                                    <th class="num">En Pizzas Únicas</th>
                                    <th class="num">% de Pizzas</th>
                                    <th class="num">Veces Colocado (Total)</th>
                                    <th class="num">Prom. por Pizza que lo usa</th>
                                    <th class="num">Entero</th>
                                    <th class="num">Picado</th>
                                    <th class="num">Mitad</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ingTopAbs as $lbl => $absQty):
                                    $uQty = $ingCountUnique[$lbl] ?? 0;
                                    $pctPz = $pz['creadas'] > 0 ? ($uQty / $pz['creadas']) * 100 : 0;
                                    $avgPerUse = $uQty > 0 ? $absQty / $uQty : 0;
                                    $stRow = $ingStateCount[$lbl] ?? ['entero' => 0, 'picado' => 0, 'mitad' => 0];
                                ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($lbl); ?></b></td>
                                    <td class="num"><b><?php echo number_format($uQty, 0, ',', '.'); ?></b></td>
                                    <td class="num"><?php echo number_format($pctPz, 1, ',', '.'); ?>%</td>
                                    <td class="num"><?php echo number_format($absQty, 0, ',', '.'); ?></td>
                                    <td class="num"><?php echo number_format($avgPerUse, 1, ',', '.'); ?> pzs</td>
                                    <td class="num"><?php echo $stRow['entero']; ?></td>
                                    <td class="num"><?php echo $stRow['picado']; ?></td>
                                    <td class="num"><?php echo $stRow['mitad']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?><div class="empty">Sin datos</div><?php endif; ?>
                </div>

                <div class="card full">
                    <h2>🏆 Ranking de Pizzas Creadas (Top 15 por Votos)</h2>
                    <?php if ($topPizzas): ?>
                    <div style="overflow-x:auto">
                        <table class="ing-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Miniatura</th>
                                    <th>Pizza</th>
                                    <th>Autor / Contacto</th>
                                    <th>Base + Ingredientes</th>
                                    <th class="num">Votos</th>
                                    <th class="num">Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topPizzas as $idx => $p):
                                    $thumbSrc = htmlspecialchars($folderKey . '/api.php?action=thumb&id=' . rawurlencode($p['id']));
                                ?>
                                <tr>
                                    <td><b><?php echo $idx + 1; ?></b></td>
                                    <td><img class="thumb-sm" loading="lazy" src="<?php echo $thumbSrc; ?>" alt=""></td>
                                    <td>
                                        <b><?php echo htmlspecialchars($p['name']); ?></b>
                                        <?php if ($idx === 0 && $p['votes'] > 0): ?><span class="badge-win">👑 1°</span><?php endif; ?>
                                        <?php if ($p['staffPick']): ?><span class="badge-staff">⭐ Staff</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($p['author']); ?>
                                        <?php if ($p['instagram']): ?><br><small>@<?php echo htmlspecialchars($p['instagram']); ?></small><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($p['base']): ?><span class="pill-ing" style="border-color:var(--rojo);color:var(--rojo)">Base: <?php echo htmlspecialchars($p['base']); ?></span><?php endif; ?>
                                        <?php foreach ($p['ingredients'] as $inm): ?>
                                            <span class="pill-ing"><?php echo htmlspecialchars($inm); ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="num"><b style="color:var(--rojo);font-size:1.05rem"><?php echo $p['votes']; ?></b></td>
                                    <td class="num" style="white-space:nowrap"><?php echo $p['createdAt'] ? date('d/m H:i', $p['createdAt']) : '—'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?><div class="empty">Sin pizzas guardadas</div><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

<script>
    const RED='#CC1414', BLUE='#1A5FBF', GREEN='#25D366', OCRE='#D4B483', GOLD='#FDB740', REDF='#D73828';
    const PALETTE=[RED, BLUE, GREEN, GOLD, OCRE, REDF, '#7B5EA7', '#2F9E8F', '#E07A3F', '#5C7AEA'];

    const pzLabels = <?php echo json_encode($labels); ?>;
    const pzByDay  = <?php echo json_encode($pzByDay); ?>;
    const ingTop   = <?php echo json_encode($ingTop); ?>;
    const baseTop  = <?php echo json_encode($baseCount); ?>;
    const drizTop  = <?php echo json_encode($drizCount); ?>;

    if (document.getElementById('chartPzDay')) {
        new Chart(document.getElementById('chartPzDay'), {
            type: 'bar',
            data: { labels: pzLabels, datasets: [{ label: 'Pizzas creadas', data: pzByDay, backgroundColor: GOLD, borderColor: RED, borderWidth: 1 }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }
    if (document.getElementById('chartIng')) {
        new Chart(document.getElementById('chartIng'), {
            type: 'bar',
            data: { labels: Object.keys(ingTop), datasets: [{ label: 'Pizzas que lo incluyen', data: Object.values(ingTop), backgroundColor: RED }] },
            options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }
    if (document.getElementById('chartBase')) {
        new Chart(document.getElementById('chartBase'), {
            type: 'doughnut',
            data: { labels: Object.keys(baseTop), datasets: [{ data: Object.values(baseTop), backgroundColor: PALETTE }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
    }
    if (document.getElementById('chartDriz')) {
        new Chart(document.getElementById('chartDriz'), {
            type: 'doughnut',
            data: { labels: Object.keys(drizTop), datasets: [{ data: Object.values(drizTop), backgroundColor: PALETTE }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
    }
</script>
</body>
</html>

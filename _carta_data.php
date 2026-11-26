<?php
/**
 * _carta_data.php — Motor compartido de datos, turnos, preventa, categorías,
 *                   variantes y extras para la Carta (usado tanto en index.php
 *                   como en carta/index.php).
 *
 * Requiere: $pdo (desde db_connect.php).
 */

require_once __DIR__ . '/_visibility.php';
require_once __DIR__ . '/_variants_data.php';

if (!function_exists('fmtArs')) {
function fmtArs($amount): string {
    $n = round((float)$amount, 2);
    return (floor($n) == $n)
        ? number_format($n, 0, ',', '.')
        : number_format($n, 2, ',', '.');
}}

date_default_timezone_set('America/Argentina/Cordoba');
$current_time = date('H:i:s');
$current_day  = date('l');
$day_map = [
    'Monday'    => 'Lunes',
    'Tuesday'   => 'Martes',
    'Wednesday' => 'Miércoles',
    'Thursday'  => 'Jueves',
    'Friday'    => 'Viernes',
    'Saturday'  => 'Sábado',
    'Sunday'    => 'Domingo',
];
$current_day_es = $day_map[$current_day] ?? 'Lunes';

/**
 * Unifica los turnos de business_hours soportando tanto:
 *   1) Filas directas que cruzan medianoche (ej. Viernes 20:30:00 -> 00:30:00, o 20:00:00 -> 00:00:00)
 *   2) Pares legados partidos en dos filas (20:30 -> 23:59:59 + día siguiente 00:00:00 -> 00:30:00)
 *   3) Turnos diurnos normales (12:00:00 -> 15:00:00)
 */
if (!function_exists('getUnifiedBusinessShifts')) {
function getUnifiedBusinessShifts(array $all_hours): array {
    $daysOrder = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    $byDay = [];
    foreach ($daysOrder as $d) $byDay[$d] = [];
    foreach ($all_hours as $r) {
        $d = $r['day_of_week'] ?? '';
        if (isset($byDay[$d])) {
            $byDay[$d][] = $r;
        }
    }

    // Pre-marcar residuos 00:00 -> 00:xx..05:xx que sean continuación de un 23:59 del día previo
    for ($i = 0; $i < 7; $i++) {
        $d = $daysOrder[$i];
        $nextD = $daysOrder[($i + 1) % 7];
        foreach ($byDay[$d] as $idx => $row) {
            $e = trim((string)($row['end_time'] ?? ''));
            if (preg_match('/^23:59(?::\d{2})?$/', $e) && !empty($byDay[$nextD])) {
                foreach ($byDay[$nextD] as $nIdx => $nextRow) {
                    if (!empty($nextRow['_used'])) continue;
                    $ns = trim((string)($nextRow['start_time'] ?? ''));
                    $ne = trim((string)($nextRow['end_time'] ?? ''));
                    if (preg_match('/^00:00(?::\d{2})?$/', $ns) && $ne <= '06:00:00') {
                        $byDay[$d][$idx]['_merged_end'] = $ne;
                        $byDay[$nextD][$nIdx]['_used']  = true;
                        break;
                    }
                }
            }
        }
    }

    $unified = [];
    for ($i = 0; $i < 7; $i++) {
        $d = $daysOrder[$i];
        $nextD = $daysOrder[($i + 1) % 7];

        foreach ($byDay[$d] as $row) {
            if (!empty($row['_used'])) continue;

            $s = trim((string)($row['start_time'] ?? ''));
            $e = trim((string)($row['end_time'] ?? ''));
            if ($s === '' || $e === '') continue;

            if (isset($row['_merged_end'])) {
                $unified[] = [
                    'start_day'  => $d,
                    'start_time' => $s,
                    'end_day'    => $nextD,
                    'end_time'   => $row['_merged_end'],
                ];
                continue;
            }

            // Fila única que cruza medianoche (ej. 20:30 -> 00:30 o 20:00 -> 00:00)
            if ($s >= $e) {
                if ($s === $e) continue;
                $unified[] = [
                    'start_day'  => $d,
                    'start_time' => $s,
                    'end_day'    => $nextD,
                    'end_time'   => $e,
                ];
                continue;
            }

            // Fila que termina en 23:59 / 23:59:59 sin continuación explícita -> cierra 00:00
            if (preg_match('/^23:59(?::\d{2})?$/', $e)) {
                $unified[] = [
                    'start_day'  => $d,
                    'start_time' => $s,
                    'end_day'    => $nextD,
                    'end_time'   => '00:00:00',
                ];
                continue;
            }

            // Turno normal dentro del mismo día
            $unified[] = [
                'start_day'  => $d,
                'start_time' => $s,
                'end_day'    => $d,
                'end_time'   => $e,
            ];
        }
    }

    return $unified;
}}

if (!function_exists('formatGroupedHours')) {
function formatGroupedHours(array $rows): string {
    $order = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    $dayIndex = array_flip($order);
    $unified = getUnifiedBusinessShifts($rows);

    $segmentsByDay = [];
    foreach ($order as $d) $segmentsByDay[$d] = [];

    foreach ($unified as $u) {
        $d = $u['start_day'];
        $start = substr($u['start_time'], 0, 5);
        $end   = substr($u['end_time'], 0, 5);
        if ($end === '23:59') $end = '00:00';
        if ($start === $end) continue;
        $seg = $start . ' - ' . $end;
        if (!in_array($seg, $segmentsByDay[$d], true)) {
            $segmentsByDay[$d][] = $seg;
        }
    }

    $unique = [];
    foreach ($order as $d) {
        foreach ($segmentsByDay[$d] as $seg) $unique[$seg] = true;
    }
    $uniqueSegments = array_keys($unique);
    if (empty($uniqueSegments)) {
        return 'Horarios no configurados, contacta al administrador.';
    }

    $lines = [];
    foreach ($uniqueSegments as $seg) {
        $daysWithSeg = [];
        foreach ($order as $d) {
            if (in_array($seg, $segmentsByDay[$d], true)) {
                $daysWithSeg[] = $dayIndex[$d];
            }
        }
        sort($daysWithSeg);
        $k = 0; $m = count($daysWithSeg);
        while ($k < $m) {
            $startIdx = $daysWithSeg[$k];
            $endIdx   = $startIdx;
            while ($k + 1 < $m && $daysWithSeg[$k + 1] === $endIdx + 1) {
                $k++;
                $endIdx = $daysWithSeg[$k];
            }
            $rango = ($startIdx === $endIdx)
                ? $order[$startIdx]
                : "De {$order[$startIdx]} a {$order[$endIdx]}";
            $lines[] = [
                'key'      => $seg,
                'startIdx' => $startIdx,
                'label'    => $rango . ': ' . $seg,
            ];
            $k++;
        }
    }

    usort($lines, function ($a, $b) {
        [$sa] = array_map('trim', explode('-', $a['key'], 2));
        [$sb] = array_map('trim', explode('-', $b['key'], 2));
        [$ah, $am] = array_map('intval', explode(':', $sa));
        [$bh, $bm] = array_map('intval', explode(':', $sb));
        $ma = $ah * 60 + $am;
        $mb = $bh * 60 + $bm;
        return ($ma === $mb) ? ($a['startIdx'] <=> $b['startIdx']) : ($ma <=> $mb);
    });

    return implode('<br>', array_column($lines, 'label'));
}}

if (!function_exists('buildOpeningHoursSchema')) {
function buildOpeningHoursSchema(array $rows): array {
    $dayToEn = [
        'Lunes'     => 'Monday',
        'Martes'    => 'Tuesday',
        'Miércoles' => 'Wednesday',
        'Jueves'    => 'Thursday',
        'Viernes'   => 'Friday',
        'Sábado'    => 'Saturday',
        'Domingo'   => 'Sunday',
    ];
    $unified = getUnifiedBusinessShifts($rows);
    $segmentDays = [];
    foreach ($unified as $u) {
        $opens  = substr($u['start_time'], 0, 5);
        $closes = substr($u['end_time'], 0, 5);
        if ($closes === '23:59') $closes = '00:00';
        if ($opens === $closes) continue;
        $key = $opens . '|' . $closes;
        $en  = $dayToEn[$u['start_day']] ?? null;
        if ($en) $segmentDays[$key][] = $en;
    }

    $result = [];
    foreach ($segmentDays as $seg => $days) {
        [$opens, $closes] = explode('|', $seg);
        $result[] = [
            '@type'     => 'OpeningHoursSpecification',
            'dayOfWeek' => array_values(array_unique($days)),
            'opens'     => $opens,
            'closes'    => $closes,
        ];
    }
    return $result;
}}

// ── 1. Estado abierto/cerrado y ventana del turno actual o próximo ──
$is_open         = false;
$closed_message  = "Estamos cerrados ahora. Horarios de atención:<br>";
$all_hours       = [];
$shift_start_iso = null;
$shift_end_iso   = null;

try {
    $all_hours_stmt = $pdo->query("
        SELECT day_of_week, start_time, end_time
        FROM business_hours
        ORDER BY FIELD(day_of_week,'Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'),
                 start_time, end_time
    ");
    $all_hours = $all_hours_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($all_hours) === 0) {
        $closed_message = "Estamos cerrados ahora. Horarios no configurados, contacta al administrador.";
    } else {
        $closed_message = "Estamos cerrados ahora, pero podés dejar tu pedido programado para cuando abramos. Horarios de atención:<br>" . formatGroupedHours($all_hours);
    }

    $tz        = new DateTimeZone('America/Argentina/Cordoba');
    $nowDt     = new DateTime('now', $tz);
    $daysOrder = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    $idxToday  = (int)array_search($current_day_es, $daysOrder, true);

    $unifiedShifts   = getUnifiedBusinessShifts($all_hours);
    $shiftsInstances = [];

    for ($offset = -1; $offset <= 7; $offset++) {
        $refDt      = (clone $nowDt)->modify("{$offset} day");
        $refDateStr = $refDt->format('Y-m-d');
        $dayName    = $daysOrder[($idxToday + $offset + 70) % 7];

        foreach ($unifiedShifts as $u) {
            if ($u['start_day'] !== $dayName) continue;

            $sDt = new DateTime("{$refDateStr} {$u['start_time']}", $tz);
            if ($u['start_day'] === $u['end_day']) {
                $eDt = new DateTime("{$refDateStr} {$u['end_time']}", $tz);
            } else {
                $eDt   = (clone $sDt)->modify('+1 day');
                $parts = explode(':', $u['end_time']);
                $eDt->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0), (int)($parts[2] ?? 0));
            }
            $shiftsInstances[] = ['s' => $sDt, 'e' => $eDt, 'day' => $dayName];
        }
    }

    if (defined('FORCE_OPEN') && FORCE_OPEN) {
        $is_open = true;
    }

    $activeShift = null;
    foreach ($shiftsInstances as $inst) {
        if ($nowDt >= $inst['s'] && $nowDt <= $inst['e']) {
            $activeShift = $inst;
            break;
        }
    }

    if ($activeShift !== null) {
        $is_open         = true;
        $shift_start_iso = $activeShift['s']->format('c');
        $shift_end_iso   = $activeShift['e']->format('c');
    } else {
        $nextShift = null;
        foreach ($shiftsInstances as $inst) {
            if ($inst['s'] > $nowDt && ($nextShift === null || $inst['s'] < $nextShift['s'])) {
                $nextShift = $inst;
            }
        }
        if ($nextShift !== null) {
            $shift_start_iso = $nextShift['s']->format('c');
            $shift_end_iso   = $nextShift['e']->format('c');
        }
    }
} catch (PDOException $e) {
    error_log("Error en consulta de horarios: " . $e->getMessage());
    $closed_message = "Estamos cerrados ahora. Error al verificar horarios, contacta al administrador.";
}

// ── 2. Categorías (excluyendo 'Extras' y 'Tienda', que tienen su propia sección) ──
$categories = [];
try {
    $categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY display_order");
    foreach ($categories_stmt->fetchAll(PDO::FETCH_ASSOC) as $cat) {
        $cLower = mb_strtolower(trim((string)$cat['name']), 'UTF-8');
        if ($cLower === 'extras') continue;
        if (strpos($cLower, 'tienda') !== false) continue;
        $categories[] = $cat;
    }
} catch (PDOException $e) {
    $categories = [];
}

// ── 3. Ítems visibles por categoría y detección de Carta Secreta ──
$items_by_category    = [];
$secret_category_ids  = [];
$has_any_secret       = false;

try {
    $items_stmt = $pdo->prepare("
        SELECT mi.*
        FROM menu_items mi
        WHERE mi.category_id = ?
          AND mi.is_visible = 1
        ORDER BY mi.display_order
    ");
    foreach ($categories as $category) {
        $cid = (int)$category['id'];
        $items_stmt->execute([$cid]);
        $all_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        $visible_items = array_values(array_filter(
            $all_items,
            fn($it) => isItemVisibleNow($it, $current_day_es, $current_time)
        ));
        $items_by_category[$cid] = $visible_items;

        if (!empty($visible_items)) {
            $isCatNamedSecret = (mb_strtolower(trim((string)$category['name']), 'UTF-8') === 'carta secreta');
            $allItemsSecret   = true;
            foreach ($visible_items as $vi) {
                if (!empty($vi['is_secret_menu'])) {
                    $has_any_secret = true;
                } else {
                    $allItemsSecret = false;
                }
            }
            if ($isCatNamedSecret || $allItemsSecret) {
                $secret_category_ids[$cid] = true;
                $has_any_secret = true;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Error pre-fetching items por categoría: " . $e->getMessage());
    $items_by_category = [];
}

// ── 4. Extras dinámicos para pizzas (solo lectura rápida desde BD) ──
$available_extras = [];
$enable_extras    = true;
try {
    $extStmt = $pdo->query("
        SELECT mi.id, mi.name, mi.price, mi.description
        FROM menu_items mi
        JOIN categories c ON mi.category_id = c.id
        WHERE LOWER(TRIM(c.name)) = 'extras'
          AND mi.is_visible = 1
        ORDER BY mi.display_order ASC, mi.price ASC
    ");
    $available_extras = $extStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $available_extras = [];
}

// ── 5. Datos de transferencia editables (site_settings) ──
$transfer_alias  = 'RRBBPIZZA';
$transfer_holder = 'Ezequiel Urquidi';
try {
    $ts = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('transfer_alias','transfer_holder')");
    foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['setting_key'] === 'transfer_alias'  && trim((string)$row['setting_value']) !== '') $transfer_alias  = trim((string)$row['setting_value']);
        if ($row['setting_key'] === 'transfer_holder' && trim((string)$row['setting_value']) !== '') $transfer_holder = trim((string)$row['setting_value']);
    }
} catch (Throwable $e) {
    // Valores por defecto
}

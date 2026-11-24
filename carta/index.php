<?php
session_start();
require_once dirname(__DIR__) . '/db_connect.php';
require_once dirname(__DIR__) . '/_visibility.php';   // helper compartido (soporta franjas nuevas y legado)

// Configurar zona horaria y obtener día/hora actual
date_default_timezone_set('America/Argentina/Cordoba');
$current_time = date('H:i:s');
$current_day  = date('l');
$day_map = array(
    'Monday'    => 'Lunes',
    'Tuesday'   => 'Martes',
    'Wednesday' => 'Miércoles',
    'Thursday'  => 'Jueves',
    'Friday'    => 'Viernes',
    'Saturday'  => 'Sábado',
    'Sunday'    => 'Domingo'
);
$current_day_es = isset($day_map[$current_day]) ? $day_map[$current_day] : 'Lunes';


/**
 * La visibilidad ya NO se filtra en SQL.
 * El filtro viejo usaba JSON_CONTAINS(visible_days, '"Viernes"'), que solo
 * funciona con el formato legado ["Viernes",...]. Con el formato nuevo
 * [{"days":["Viernes"],"start":"20:00","end":"23:59"}] devolvía siempre 0 y
 * escondía los ítems (ej.: la pizza de la semana). Ahora se filtra en PHP con
 * isItemVisibleNow(), igual que en el home.
 */
$visibilityWhere = "";
$visParams = [];

function filtrarVisibles(array $rows, string $dia, string $hora): array {
    return array_values(array_filter($rows, function ($it) use ($dia, $hora) {
        return isItemVisibleNow($it, $dia, $hora);
    }));
}

/**
 * Agrupa por **segmento** (cada "HH:MM - HH:MM") y además
 * **fusiona los cruces de medianoche**:
 *   ... 20:30 - 23:59:59 (Viernes) + 00:00:00 - 00:30:00 (Sábado)
 *   => muestra solo: "Viernes: 20:30 - 00:30" y oculta el residual del Sábado.
 *
 * - Convierte 23:59 / 23:59:59 -> 00:00 (cierre).
 * - Quita residuales 00:00 - 00:xx.
 * - Omite tramos vacíos (start == end).
 */
function formatGroupedHours($rows) {
    $order = array('Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo');
    $dayIndex = array(
        'Lunes'=>0,'Martes'=>1,'Miércoles'=>2,'Jueves'=>3,'Viernes'=>4,'Sábado'=>5,'Domingo'=>6
    );

    // --- 1) Cargar crudo por día (con segundos) ---
    $rawByDay = array();
    foreach ($order as $d) $rawByDay[$d] = array();

    foreach ($rows as $r) {
        $day = isset($r['day_of_week']) ? $r['day_of_week'] : '';
        if (!isset($rawByDay[$day])) continue;

        $s = isset($r['start_time']) ? $r['start_time'] : '';
        $e = isset($r['end_time'])   ? $r['end_time']   : '';

        $rawByDay[$day][] = array('s'=>$s, 'e'=>$e);
    }

    // --- 2) Fusionar cruce de medianoche ---
    for ($i = 0; $i < 7; $i++) {
        $d = $order[$i];
        $next = $order[($i + 1) % 7];

        if (!empty($rawByDay[$d]) && !empty($rawByDay[$next])) {
            // último tramo del día d
            $lastIdx = count($rawByDay[$d]) - 1;
            $last = $rawByDay[$d][$lastIdx];

            // primer tramo del día siguiente
            $firstNext = $rawByDay[$next][0];

            $endsAt2359 = preg_match('/^23:59(?::\d{2})?$/', $last['e']);
            $startsAt0000 = preg_match('/^00:00(?::\d{2})?$/', $firstNext['s']);

            if ($endsAt2359 && $startsAt0000) {
                // Extiende el final del día actual al final del primer tramo del siguiente
                $rawByDay[$d][$lastIdx]['e'] = $firstNext['e'];
                // Marca el primer tramo del siguiente día para omitirlo (residual)
                $rawByDay[$next][0]['_skip'] = true;
            }
        }
    }

    // --- 3) Convertir a segmentos por día (HH:MM), omitiendo residuales/skip ---
    $segmentsByDay = array();
    foreach ($order as $d) $segmentsByDay[$d] = array();

    foreach ($order as $d) {
        foreach ($rawByDay[$d] as $seg) {
            if (!empty($seg['_skip'])) continue;

            $start = date('H:i', strtotime($seg['s']));
            $endRaw = $seg['e'];
            $end = date('H:i', strtotime($endRaw));

            // 23:59 / 23:59:59 -> 00:00 (cierre)
            if ($end === '23:59' || preg_match('/23:59:59$/', (string)$endRaw)) {
                $end = '00:00';
            }

            // residuales 00:00 - 00:xx (resto de la noche anterior) => omitir
            if ($start === '00:00' && preg_match('/^00:\d{2}$/', $end)) continue;

            // tramos vacíos
            if ($start === $end) continue;

            $segmentsByDay[$d][] = $start . ' - ' . $end;
        }
    }

    // --- 4) Conjunto de segmentos únicos ---
    $unique = array();
    foreach ($order as $d) {
        foreach ($segmentsByDay[$d] as $seg) {
            $unique[$seg] = true;
        }
    }
    $uniqueSegments = array_keys($unique);
    if (empty($uniqueSegments)) {
        return 'Horarios no configurados, contacta al administrador.';
    }

    // --- 5) Para cada segmento, agrupar días consecutivos donde aparece ---
    $lines = array();
    foreach ($uniqueSegments as $seg) {
        $daysWithSeg = array();
        foreach ($order as $d) {
            if (in_array($seg, $segmentsByDay[$d], true)) {
                $daysWithSeg[] = $dayIndex[$d];
            }
        }
        if (empty($daysWithSeg)) continue;
        sort($daysWithSeg);

        $k = 0; $m = count($daysWithSeg);
        while ($k < $m) {
            $startIdx = $daysWithSeg[$k];
            $endIdx = $startIdx;
            while ($k+1 < $m && $daysWithSeg[$k+1] === $endIdx + 1) {
                $k++;
                $endIdx = $daysWithSeg[$k];
            }

            $startDay = $order[$startIdx];
            $endDay   = $order[$endIdx];
            $rango = ($startIdx === $endIdx) ? $startDay : "De $startDay a $endDay";

            $lines[] = array(
                'key' => $seg,
                'startIdx' => $startIdx,
                'label' => $rango . ': ' . $seg
            );

            $k++;
        }
    }

    // --- 6) Ordenar líneas por hora de inicio (almuerzo antes que noche) ---
    usort($lines, function($a, $b){
        list($sa,) = array_map('trim', explode('-', $a['key'], 2));
        list($sb,) = array_map('trim', explode('-', $b['key'], 2));
        list($ah, $am) = array_map('intval', explode(':', $sa));
        list($bh, $bm) = array_map('intval', explode(':', $sb));
        $ma = $ah*60 + $am;
        $mb = $bh*60 + $bm;
        if ($ma === $mb) {
            return $a['startIdx'] <=> $b['startIdx'];
        }
        return $ma <=> $mb;
    });

    // --- 7) Salida ---
    $out = array();
    foreach ($lines as $ln) $out[] = $ln['label'];
    return implode('<br>', $out);
}

/**
 * Fusiona los turnos de business_hours que cruzan la medianoche
 * (ej. 20:30 a 23:59:59 + 00:00:00 a 00:30:00 del día siguiente,
 *  o turnos con start_time > end_time como 20:30 a 00:30)
 * para calcular correctamente las franjas horarias y la apertura.
 */
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

    $unified = [];

    for ($i = 0; $i < 7; $i++) {
        $d = $daysOrder[$i];
        $nextD = $daysOrder[($i + 1) % 7];
        $count = count($byDay[$d]);

        for ($j = 0; $j < $count; $j++) {
            $row = $byDay[$d][$j];
            if (!empty($row['_used'])) continue;

            $s = trim((string)$row['start_time']);
            $e = trim((string)$row['end_time']);

            // 1. Cruce directo en una sola fila (start > end, ej 20:30 - 00:30 o 20:00 - 00:00)
            if ($s > $e) {
                $unified[] = [
                    'start_day'  => $d,
                    'start_time' => $s,
                    'end_day'    => $nextD,
                    'end_time'   => $e,
                ];
                continue;
            }

            // 2. Fila que termina en 23:59 / 23:59:59 -> buscar si el día siguiente arranca en 00:00
            $endsAtMidnight = preg_match('/^23:59(?::\d{2})?$/', $e);
            if ($endsAtMidnight) {
                $foundContinuation = false;
                if (!empty($byDay[$nextD])) {
                    foreach ($byDay[$nextD] as &$nextRow) {
                        if (!empty($nextRow['_used'])) continue;
                        if (preg_match('/^00:00(?::\d{2})?$/', trim((string)$nextRow['start_time']))) {
                            $nextRow['_used'] = true;
                            $unified[] = [
                                'start_day'  => $d,
                                'start_time' => $s,
                                'end_day'    => $nextD,
                                'end_time'   => trim((string)$nextRow['end_time']),
                            ];
                            $foundContinuation = true;
                            break;
                        }
                    }
                    unset($nextRow);
                }
                if (!$foundContinuation) {
                    $unified[] = [
                        'start_day'  => $d,
                        'start_time' => $s,
                        'end_day'    => $nextD,
                        'end_time'   => '00:00:00',
                    ];
                }
                continue;
            }

            // 3. Residuo 00:00 a 00:xx que no fue fusionado
            if (preg_match('/^00:00(?::\d{2})?$/', $s) && preg_match('/^00:\d{2}(?::\d{2})?$/', $e)) {
                continue;
            }

            // 4. Turno normal diurno
            $unified[] = [
                'start_day'  => $d,
                'start_time' => $s,
                'end_day'    => $d,
                'end_time'   => $e,
            ];
        }
    }

    return $unified;
}

// Verificar si el negocio está abierto y obtener horarios y ventanas de turnos
$is_open = false;
$closed_message = "Estamos cerrados ahora. Horarios de atención:<br>";
$shift_start_iso = null;
$shift_end_iso   = null;
$next_shift_is_today = false;

try {
    // Todos los horarios (para mostrar en el aviso y calcular turnos unificados)
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
        // mensaje agrupado por segmento + fusión de medianoche
        $closed_message = "Estamos cerrados ahora, pero podés dejar tu pedido programado para cuando abramos. Horarios de atención:<br>" . formatGroupedHours($all_hours);
    }

    $tz = new DateTimeZone('America/Argentina/Cordoba');
    $nowDt = new DateTime('now', $tz);
    $daysOrder = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    $idxToday = array_search($current_day_es, $daysOrder, true);

    $unifiedShifts = getUnifiedBusinessShifts($all_hours);

    // Creamos las instancias de turnos desde ayer (-1) hasta 7 días hacia adelante
    $shiftsInstances = [];
    for ($offset = -1; $offset <= 7; $offset++) {
        $refDt = (clone $nowDt)->modify("{$offset} day");
        $refDateStr = $refDt->format('Y-m-d');
        $dayName = $daysOrder[($idxToday + $offset + 70) % 7];

        foreach ($unifiedShifts as $u) {
            if ($u['start_day'] !== $dayName) continue;

            $sDt = new DateTime("{$refDateStr} {$u['start_time']}", $tz);
            if ($u['start_day'] === $u['end_day']) {
                $eDt = new DateTime("{$refDateStr} {$u['end_time']}", $tz);
            } else {
                $eDt = (clone $sDt)->modify('+1 day');
                $parts = explode(':', $u['end_time']);
                $eh = (int)($parts[0] ?? 0);
                $em = (int)($parts[1] ?? 0);
                $es = (int)($parts[2] ?? 0);
                $eDt->setTime($eh, $em, $es);
            }
            $shiftsInstances[] = [
                's' => $sDt,
                'e' => $eDt,
                'day' => $dayName
            ];
        }
    }

    // 1. ¿Hay algún turno activo AHORA?
    $activeShift = null;
    foreach ($shiftsInstances as $inst) {
        if ($nowDt >= $inst['s'] && $nowDt <= $inst['e']) {
            $activeShift = $inst;
            break;
        }
    }

    if ($activeShift !== null) {
        $is_open = true;
        $shift_start_iso = $activeShift['s']->format('c');
        $shift_end_iso   = $activeShift['e']->format('c');
    } else {
        $is_open = false;
        // Buscamos el próximo turno que empiece después de ahora
        $nextShift = null;
        foreach ($shiftsInstances as $inst) {
            if ($inst['s'] > $nowDt) {
                if ($nextShift === null || $inst['s'] < $nextShift['s']) {
                    $nextShift = $inst;
                }
            }
        }
        if ($nextShift !== null) {
            $shift_start_iso = $nextShift['s']->format('c');
            $shift_end_iso   = $nextShift['e']->format('c');
            $next_shift_is_today = ($nextShift['s']->format('Y-m-d') === $nowDt->format('Y-m-d'));
        }
    }
} catch (PDOException $e) {
    error_log("Error en consulta de horarios: " . $e->getMessage());
    $closed_message = "Estamos cerrados ahora. Error al verificar horarios, contacta al administrador.";
}


// Fetch categories
try {
    $categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY display_order");
    $categories = $categories_stmt->fetchAll();
    // Excluir la categoría 'Extras' de la navegación y de las secciones de la carta
    // (sus productos se usan exclusivamente como opciones / botones en las pizzas)
    $categories = array_values(array_filter($categories, function($cat) {
        return strtolower(trim($cat['name'])) !== 'extras';
    }));
} catch (PDOException $e) {
    $categories = [];
}

// Pre-fetch de ítems por categoría aplicando filtros de día/hora en PHP.
// Misma fuente para el submenú y para la sección, así una categoría sin
// ítems disponibles ahora se oculta consistentemente en ambos lugares.
$items_by_category = [];
try {
    $items_stmt = $pdo->prepare("
        SELECT mi.*
        FROM menu_items mi
        WHERE mi.category_id = ?
          AND mi.is_visible = 1
        ORDER BY mi.display_order
    ");
    foreach ($categories as $category) {
        $items_stmt->execute([$category['id']]);
        $all_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        $items_by_category[$category['id']] = array_values(array_filter(
            $all_items,
            fn($it) => isItemVisibleNow($it, $current_day_es, $current_time)
        ));
    }
} catch (PDOException $e) {
    error_log("Error pre-fetching items por categoría: " . $e->getMessage());
    $items_by_category = [];
}



// Fetch sub-products
$sub_products = [];
try {
    $stmt = $pdo->query("
        SELECT ms.parent_item_id, mi.id, mi.name, ms.quantity, ms.is_required 
        FROM menu_item_subproducts ms 
        JOIN menu_items mi ON ms.sub_item_id = mi.id
    ");
    $sub_results = $stmt->fetchAll();
    foreach ($sub_results as $sub) {
        $sub_products[$sub['parent_item_id']][] = [
            'id' => $sub['id'], 
            'name' => $sub['name'], 
            'quantity' => $sub['quantity'],
            'is_required' => $sub['is_required']
        ];
    }
} catch (PDOException $e) {
    $sub_products = [];
}

// Variantes con precio propio (Muzza / Fugazzeta / Arrabbiata, etc.).
// Vive en la carpeta padre (raíz del home), igual que db_connect/_visibility.
require_once dirname(__DIR__) . '/_variants_data.php';

// Fetch eligible items (visible pizzas)
$eligible_items = [];
try {
    $stmt = $pdo->query("
        SELECT mi.*
        FROM menu_items mi
        WHERE mi.is_visible = 1
        AND mi.category_id = (SELECT id FROM categories WHERE name = 'Pizzas' LIMIT 1)
        ORDER BY mi.name
    ");
    $eligible_items = filtrarVisibles($stmt->fetchAll(), $current_day_es, $current_time);
} catch (PDOException $e) {
    $eligible_items = [];
}

// Datos de transferencia (editables desde el editor → tabla site_settings).
// Si la tabla no existe todavía, quedan los valores por defecto de siempre.
$transfer_alias  = 'RRBBPIZZA';
$transfer_holder = 'Ezequiel Urquidi';
try {
    $ts = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('transfer_alias','transfer_holder')");
    foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['setting_key'] === 'transfer_alias'  && $row['setting_value'] !== '') $transfer_alias  = $row['setting_value'];
        if ($row['setting_key'] === 'transfer_holder' && $row['setting_value'] !== '') $transfer_holder = $row['setting_value'];
    }
} catch (Throwable $e) {
    // tabla inexistente o error → valores por defecto
}

// Carga dinámica de productos de la categoría 'Extras' desde la base de datos
// Si no existen aún en la BD, se crean automáticamente para que queden disponibles y editables en editor.php.
function getOrCreateExtrasFromDB(PDO $pdo): array {
    try {
        // 1. Buscar si existe la categoría 'Extras'
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE LOWER(TRIM(name)) = 'extras' LIMIT 1");
        $stmt->execute();
        $catId = $stmt->fetchColumn();

        if (!$catId) {
            $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(display_order), 0) FROM categories")->fetchColumn();
            $insCat = $pdo->prepare("INSERT INTO categories (name, display_order) VALUES ('Extras', ?)");
            $insCat->execute([$maxOrder + 1]);
            $catId = (int)$pdo->lastInsertId();
        }

        // 2. Buscar si existen productos en la categoría Extras
        $itemsStmt = $pdo->prepare("
            SELECT id, name, price, description, is_visible, display_order 
            FROM menu_items 
            WHERE category_id = ? 
            ORDER BY display_order ASC, price ASC
        ");
        $itemsStmt->execute([$catId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            // Creamos los 3 extras estándar en menu_items para que ya sean editables desde editor.php
            $initialExtras = [
                ['name' => 'Stracciatella',   'price' => 5000.00, 'order' => 1],
                ['name' => 'Anchoas',         'price' => 3000.00, 'order' => 2],
                ['name' => 'Dip aceite oliva', 'price' => 1500.00, 'order' => 3],
            ];
            $insItem = $pdo->prepare("
                INSERT INTO menu_items (name, price, category_id, is_visible, display_order, description)
                VALUES (?, ?, ?, 1, ?, 'Extra para sumar a tu pizza')
            ");
            foreach ($initialExtras as $ie) {
                $insItem->execute([$ie['name'], $ie['price'], $catId, $ie['order']]);
            }

            $itemsStmt->execute([$catId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Si existía 'Dip de aceite de oliva' con el nombre previo, actualizarlo a 'Dip aceite oliva'
            foreach ($items as &$it) {
                if (trim($it['name']) === 'Dip de aceite de oliva') {
                    $pdo->prepare("UPDATE menu_items SET name = 'Dip aceite oliva' WHERE id = ?")->execute([$it['id']]);
                    $it['name'] = 'Dip aceite oliva';
                }
            }
            unset($it);
        }

        // Devolvemos los extras visibles
        $visibleExtras = array_values(array_filter($items, fn($it) => (int)($it['is_visible'] ?? 1) === 1));
        return !empty($visibleExtras) ? $visibleExtras : $items;
    } catch (Throwable $e) {
        error_log("Error in getOrCreateExtrasFromDB: " . $e->getMessage());
        return [
            ['id' => 'extra_stracciatella', 'name' => 'Stracciatella',   'price' => 5000.00],
            ['id' => 'extra_anchoas',       'name' => 'Anchoas',         'price' => 3000.00],
            ['id' => 'extra_dip_oliva',     'name' => 'Dip aceite oliva', 'price' => 1500.00]
        ];
    }
}

$available_extras = getOrCreateExtrasFromDB($pdo);

// Control de versión:
// index.php es ahora la versión oficial con extras activados por defecto.
// index_old.php pasa $force_disable_extras = true para mostrar la versión clásica sin extras.
if (!empty($force_disable_extras) || (isset($_GET['extras']) && $_GET['extras'] === '0')) {
    $enable_extras = false;
} else {
    $enable_extras = true;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuestra Carta - Pizzería Arrabbiata</title>
    <link rel="icon" type="image/png" href="favicon.png"/>
    <link rel="stylesheet" href="https://arrabbiata.com.ar/arrabbiata.css">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "LocalBusiness",
        "name": "Arrabbiata",
        "image": "https://arrabbiata.com.ar/wp-content/uploads/2025/01/Identidad-Arrabbiata-19_page-0001-1-scaled-e1746087327655.png",
        "address": {
            "@type": "PostalAddress",
            "streetAddress": "27 de abril 798",
            "addressLocality": "Córdoba",
            "addressRegion": "Córdoba",
            "postalCode": "X5000",
            "addressCountry": "AR"
        },
        "geo": {
            "@type": "GeoCoordinates",
            "latitude": -31.415915,
            "longitude": -64.188624
        },
        "url": "https://arrabbiata.com.ar",
        "telephone": "+5493517548030",
        "openingHoursSpecification": [
            {
                "@type": "OpeningHoursSpecification",
                "dayOfWeek": [
                    "Monday",
                    "Tuesday",
                    "Wednesday",
                    "Thursday",
                    "Friday"
                ],
                "opens": "12:00",
                "closes": "15:00"
            },
            {
                "@type": "OpeningHoursSpecification",
                "dayOfWeek": [
                    "Thursday",
                    "Friday",
                    "Saturday"
                ],
                "opens": "20:00",
                "closes": "00:00"
            }
        ],
        "sameAs": [
            "https://www.instagram.com/arrabbiata.pizza"
        ],
        "description": "Pizzería Arrabbiata, la mejor pizza de Córdoba, ofreciendo auténticas pizzas italianas..."
    }
    </script>
   
<style>

/* Fuentes locales */
@font-face {
  font-family: 'Alberdini';
  src: url('rrbb.woff2') format('woff2'),
       url('rrbb.woff') format('woff');
  font-weight: normal;
  font-style: normal;
}

/* Sin nav fijo encima: reducir padding-top de .section */
.section {
  padding-top: 30px !important;
}

/* Secret image visibility control */
.secret-image {
  position: fixed;
  right: 20px;
  bottom: 105px;
  width: 72px;
  height: auto;
  opacity: 0;
  pointer-events: none;
  transition: opacity .35s ease;
  z-index: 1000;
}
.secret-image.visible {
  opacity: 1;
  pointer-events: auto;
}

/* Encabezado con logo y datos de contacto */
.menu-header {
  text-align: center;
  padding: 12px 16px 14px;
  border-bottom: 2px solid var(--color-rojo, #c0392b);
  margin-bottom: 5px;
}

.menu-header img.menu-logo {
  width: clamp(200px, 75vw, 340px);
  height: auto;
  display: block;
  margin: 0 auto 1%;
  /* Compensa el espacio transparente interno del PNG */
}

.menu-header-info {
  display: flex;
  justify-content: center;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px 16px;
  font-family: 'DM Sans', sans-serif;
  font-size: 0.75rem;
  color: var(--color-texto, #222);
}

.menu-header-info a {
  color: inherit;
  text-decoration: none;
  white-space: nowrap;
}

.menu-header-info a:hover {
  text-decoration: underline;
}

.menu-header-info .sep {
  color: var(--color-rojo, #c0392b);
  font-weight: bold;
  user-select: none;
}

/* ===== MODAL HORARIO DEL PEDIDO ===== */
.pickup-time-modal {
  position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
  background: var(--blanco, #fff);
  border: 4px solid var(--rojo, #c0392b); border-radius: 0;
  padding: 22px;
  box-shadow: 8px 8px 0 var(--rojo-d, #7d1c14);
  max-width: 440px; width: 90%; display: none; z-index: 10002;
}
.pickup-time-modal.active { display: block; }
.pickup-time-modal h3 {
  font-family: var(--font-display);
  margin: 0 0 8px; font-size: 1.4rem; color: var(--rojo, #c0392b);
}
.pickup-time-sub {
  font-family: var(--font-body);
  margin: 0 0 14px; font-size: .9rem; color: var(--marron, #4a2f28);
}
.pickup-time-slots {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;
  max-height: 300px; overflow-y: auto; margin-bottom: 16px;
}
.pickup-slot {
  border: 2px solid var(--azul-elec, #2b50c8); background: #fff;
  color: var(--marron, #4a2f28);
  padding: 10px 0; border-radius: 2px; cursor: pointer;
  font-family: var(--font-display); font-size: .95rem;
  transition: all .12s ease;
}
.pickup-slot:hover { background: #eef1ff; }
.pickup-slot.selected {
  background: var(--rojo, #c0392b); color: #fff;
  border-color: var(--rojo, #c0392b);
  box-shadow: 2px 2px 0 var(--rojo-d, #7d1c14);
}
.pickup-slot-asap { grid-column: 1 / -1; }
.pickup-time-actions { display: flex; gap: 8px; }
.pickup-time-cancel, .pickup-time-confirm {
  border: none; padding: 11px 18px; border-radius: 2px;
  cursor: pointer; flex: 1; font-size: 15px;
  font-family: var(--font-display); letter-spacing: .05em;
  transition: all .15s ease;
}
.pickup-time-cancel { background: #eee; color: var(--marron, #4a2f28); border: 2px solid #ccc; }
.pickup-time-cancel:hover { background: #ddd; }
.pickup-time-confirm {
  background: var(--verde-wa, #25d366); color: #fff;
  box-shadow: 3px 3px 0 var(--verde-wa-d, #128c50);
}
.pickup-time-confirm:hover:not(:disabled) {
  background: var(--verde-wa-d, #128c50);
  box-shadow: 1px 1px 0 #0a6b50; transform: translate(1px,1px);
}
.pickup-time-confirm:disabled { opacity: .5; cursor: not-allowed; }

/* ===== BOTONES / PILLS DE EXTRAS EN TARJETA DE PIZZA ===== */
.pizza-extras-container {
  margin: 10px 0 8px 0;
  display: flex;
  flex-direction: column;
  gap: 5px;
  text-align: left;
}
.extras-label {
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--marron, #4a2f28);
  opacity: 0.85;
}
.extras-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.extra-pill {
  background: #f8f9fa;
  border: 1.5px solid #cbd5e1;
  border-radius: 20px;
  padding: 5px 10px;
  font-size: 0.82rem;
  font-weight: 600;
  font-family: inherit;
  color: #334155;
  cursor: pointer;
  transition: all 0.15s ease;
  user-select: none;
  -webkit-tap-highlight-color: transparent;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.extra-pill:hover {
  border-color: var(--rojo, #c0392b);
  background: #fff5f5;
  color: var(--rojo, #c0392b);
}
.extra-pill.active {
  background: var(--rojo, #c0392b) !important;
  color: #ffffff !important;
  border-color: var(--rojo, #c0392b) !important;
  box-shadow: 0 2px 5px rgba(192, 57, 43, 0.3);
}
.extra-pill.active .extra-price-tag {
  color: #ffffff !important;
  opacity: 0.95;
}
.extra-pill .extra-plus {
  font-weight: bold;
}
.extra-pill .extra-price-tag {
  font-size: 0.76rem;
  color: #64748b;
  font-weight: normal;
}
.added-toast {
  position: absolute;
  top: 12px;
  right: 12px;
  background: #16a34a;
  color: #fff;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 0.8rem;
  font-weight: bold;
  opacity: 0;
  transform: translateY(-5px);
  transition: all 0.25s ease;
  pointer-events: none;
  z-index: 10;
}
.added-toast.show {
  opacity: 1;
  transform: translateY(0);
}
.item-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 4px;
}
.item-title-row h3 {
  margin: 0;
  flex: 1;
}
.vegan-badge-btn {
  background: #f0fdf4;
  border: 1.5px solid #16a34a;
  color: #15803d;
  font-size: 0.74rem;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 16px;
  cursor: pointer;
  transition: all 0.15s ease;
  white-space: nowrap;
  font-family: inherit;
  user-select: none;
  -webkit-tap-highlight-color: transparent;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.vegan-badge-btn:hover {
  background: #16a34a;
  color: #ffffff;
  box-shadow: 0 2px 4px rgba(22, 163, 74, 0.25);
  transform: translateY(-1px);
}
.vegan-badge-btn:active {
  transform: translateY(0);
}
</style>
</head>
<body>
    <?php if (!empty($show_classic_version_notice)): ?>
        <div style="background: #e0f2fe; color: #0369a1; padding: 7px 12px; font-size: 0.85rem; text-align: center; font-weight: 600; border-bottom: 1px solid #bae6fd;">
            ℹ️ Estás viendo la versión clásica de la carta (sin extras) &nbsp;·&nbsp; <a href="index.php" style="color: #0284c7; text-decoration: underline;">Ir a la versión con extras</a>
        </div>
    <?php endif; ?>
    <section class="section">
        <div class="menu-container">

            <!-- Encabezado: logo + datos de contacto -->
            <header class="menu-header">
                <a href="https://www.arrabbiata.com.ar" ><img class="menu-logo" src="https://arrabbiata.com.ar/Uploads/logoborde.png" alt="Pizzería Arrabbiata"></a>
                <div class="menu-header-info">
                    <a href="https://maps.app.goo.gl/weS5JgVfJapUXhRj9" target="_blank" rel="noopener">27 de Abril 798</a>
                    <span class="sep">·</span>
                    <a href="https://wa.me/5493517548030" target="_blank" rel="noopener">3517548030</a>
                    <span class="sep">·</span>
                    <a href="https://instagram.com/arrabbiata.pizza" target="_blank" rel="noopener">@arrabbiata.pizza</a>
                </div>
            </header>

            <?php if (!$is_open): ?>
                <div class="closed-notice active"><?php echo $closed_message; ?></div>
            <?php endif; ?>
            <h2>Nuestra Carta</h2>

            <nav class="category-submenu">
                <ul>
                    <?php foreach ($categories as $category): ?>
                        <?php
                        $item_count = count($items_by_category[$category['id']] ?? []);
                        if ($item_count > 0):
                        ?>
                            <li class="<?php if ($category['name'] == 'Carta Secreta') echo 'secret-link'; ?>">
                                <a href="#category-<?php echo $category['id']; ?>"
                                   onclick="scrollToCategory('category-<?php echo $category['id']; ?>', event)"
                                   aria-label="Navegar a la categoría <?php echo htmlspecialchars($category['name']); ?>">
                                   <?php echo htmlspecialchars($category['name']); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <?php foreach ($categories as $category): ?>
                <?php
                // Reutilizamos el array pre-calculado: misma fuente que el submenú,
                // así una categoría sin ítems disponibles ahora se oculta acá también.
                $items = $items_by_category[$category['id']] ?? [];
                if (count($items) > 0):
                ?>
                    <div id="category-<?php echo $category['id']; ?>" class="category-section <?php if ($category['name'] == 'Carta Secreta') echo 'secret-category'; ?>">
                        <h3 class="menu-category"><?php echo htmlspecialchars($category['name']); ?></h3>
                        <div class="pizza-list">
                            <?php foreach ($items as $item): ?>
                                <?php include dirname(__DIR__) . '/_item_card.php'; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <img id="secret-image" class="secret-image" src="https://arrabbiata.com.ar/Uploads/csgif.gif" alt="Carta Secreta" onclick="toggleSecretMode(event)" style="cursor:pointer;">

    <?php include dirname(__DIR__) . '/cart_modal.php'; ?>

    <?php include dirname(__DIR__) . '/_variant_modal.php'; ?>

    <footer>
        <div class="footer-content">
            <p>Arrabbiata - La mejor pizza de Alberdi
            <div class="footer-social-icons">
                <a href="https://www.instagram.com/arrabbiata.pizza" target="_blank">
                    <img src="https://arrabbiata.com.ar/ig50.png" alt="Instagram">
                </a>
                <a href="https://maps.app.goo.gl/8QyHubKUr5CT2bC68" target="_blank">
                    <img src="https://arrabbiata.com.ar/gmaps50.png" alt="Google Maps">            
                </a> 
                <a href="https://wa.me/5493517548030?text=Hola%20Arrabbiata%2C%20me%20comunico%20desde%20su%20web%20para%20" target="_blank">
                    <img src="https://arrabbiata.com.ar/wp50.png" alt="Whatsapp">            
                </a>
            </div>
        </div>
    </footer>

    <script>
        const isOpen = <?php echo json_encode($is_open); ?>;
        // true = el carrito está armando un pedido PROGRAMADO porque el local está cerrado ahora
        const isPreorder = !isOpen;

        // Ventana del turno activo (o del PRÓXIMO turno, si está cerrado) para el selector de horario
        const SHIFT_START = <?php echo $shift_start_iso ? json_encode($shift_start_iso) : 'null'; ?>;
        const SHIFT_END   = <?php echo $shift_end_iso   ? json_encode($shift_end_iso)   : 'null'; ?>;
        const PREP_BUFFER_MIN = 15; // min de anticipación antes del 1er horario (poné 0 para ofrecer desde ya)
        const SLOT_MIN = 15;        // tamaño de cada franja (en minutos)
        let selectedPickupTime = null;

        // Datos de transferencia (vienen del editor)
        const TRANSFER_ALIAS  = <?php echo json_encode($transfer_alias); ?>;
        const TRANSFER_HOLDER = <?php echo json_encode($transfer_holder); ?>;

        // Métricas de uso (beacon liviano, no bloquea la navegación)
        function trackEvent(payload) {
            try {
                const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
                if (navigator.sendBeacon) {
                    navigator.sendBeacon('track.php', blob);
                } else {
                    fetch('track.php', { method: 'POST', body: blob, keepalive: true }).catch(() => {});
                }
            } catch (e) { /* tracking no crítico */ }
        }

        let showImageInterval = null;

        document.addEventListener('DOMContentLoaded', function() {
            updateCartUI();
            trackEvent({ type: 'view', source: 'carta', path: location.pathname, ref: document.referrer });
            const paymentMethodSelect = document.getElementById('paymentMethod');
            paymentMethodSelect.addEventListener('change', updateCartUI);

            // Ajusta el "top" del submenú si está visible la franja de cerrado
            (function adjustStickyTop() {
                const submenu = document.querySelector('.category-submenu');
                const notice = document.querySelector('.closed-notice.active');
                function applyTop() {
                    const extra = notice ? (notice.offsetHeight || 0) : 0;
                    submenu.style.top = extra + 'px';
                }
                applyTop();
                window.addEventListener('resize', applyTop);
            })();

            // Imagen carta secreta: aparece 5s cada 10s
            const secretImage = document.getElementById('secret-image');
            if (secretImage) {
                showImageInterval = setInterval(function() {
                    secretImage.classList.add('visible');
                    setTimeout(function() { secretImage.classList.remove('visible'); }, 5000);
                }, 10000);
            }
        });

        function toggleSecretMode(ev) {
            if (ev && ev.preventDefault) ev.preventDefault();
            if (ev && ev.stopPropagation) ev.stopPropagation();
            document.body.classList.toggle('secret-mode');
            const secretImage = document.getElementById('secret-image');

            if (document.body.classList.contains('secret-mode')) {
                // parar el intervalo y ocultar la imagen
                if (showImageInterval) {
                    clearInterval(showImageInterval);
                    showImageInterval = null;
                }
                if (secretImage) secretImage.style.display = 'none';
                const secretSec = document.querySelector('.secret-category');
                if (secretSec) secretSec.scrollIntoView({ behavior: 'smooth' });
            }
        }

        // IMPORTANTE: nueva firma con 'event' para compensar sticky
        function scrollToCategory(categoryId, ev) {
            if (ev) ev.preventDefault();
            const element = document.getElementById(categoryId);
            if (element) {
                const submenu = document.querySelector('.category-submenu');
                const offset = (submenu?.offsetHeight || 0) + 8; 
                const y = element.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top: y, behavior: 'smooth' });
            }
        }

        let currentSubproductItem = null;
        let currentDependentItem = null;

        function selectVariant(pill) {
            const picker = pill.closest('.variant-picker');
            if (!picker) return;
            picker.querySelectorAll('.variant-pill').forEach(p => p.classList.remove('active'));
            pill.classList.add('active');

            const variantPrice = parseFloat(pill.dataset.variantPrice) || 0;
            const desc = pill.dataset.variantDesc || '';

            const descEl = picker.querySelector('.variant-desc');
            if (descEl) descEl.textContent = desc;

            const card = pill.closest('.pizza-item');
            let extrasTotal = 0;
            if (card) {
                const activePills = card.querySelectorAll('.extra-pill.active');
                activePills.forEach(ep => {
                    extrasTotal += parseFloat(ep.dataset.extraPrice) || 0;
                });
            }

            const finalPrice = variantPrice + extrasTotal;
            const priceEl = picker.querySelector('.variant-current-price') || card?.querySelector('.item-price');
            if (priceEl) {
                priceEl.dataset.price = finalPrice;
                priceEl.textContent = finalPrice.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
        }

        function toggleItemExtra(btn, ev) {
            if (ev) {
                ev.stopPropagation();
                ev.preventDefault();
            }
            btn.classList.toggle('active');
            const plusSpan = btn.querySelector('.extra-plus');
            if (plusSpan) {
                plusSpan.textContent = btn.classList.contains('active') ? '✓' : '+';
            }

            const card = btn.closest('.pizza-item');
            if (!card) return;

            // Base price: si tiene variante activa (ej. Fugazzeta), usar precio de la variante; si no, del item
            const activeVariant = card.querySelector('.variant-pill.active') || card.querySelector('.variant-pill');
            const basePrice = activeVariant
                ? (parseFloat(activeVariant.dataset.variantPrice) || 0)
                : (parseFloat(card.dataset.itemPrice) || 0);

            const activePills = card.querySelectorAll('.extra-pill.active');
            let extrasTotal = 0;
            activePills.forEach(pill => {
                extrasTotal += parseFloat(pill.dataset.extraPrice) || 0;
            });

            const finalPrice = basePrice + extrasTotal;
            const priceSpan = card.querySelector('.variant-current-price') || card.querySelector('.item-price');
            if (priceSpan) {
                priceSpan.textContent = finalPrice.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
        }

        function addItemWithExtras(card, isVegan = false) {
            const baseName = card.dataset.itemName;
            const itemId = card.dataset.itemId;

            // Si la tarjeta tiene variantes (ej. Fugazzeta común / queso azul)
            const activeVariant = card.querySelector('.variant-pill.active') || card.querySelector('.variant-pill');
            let basePrice = 0;
            let itemDisplayName = baseName;
            let variantId = null;

            if (activeVariant) {
                basePrice = parseFloat(activeVariant.dataset.variantPrice) || 0;
                const vName = activeVariant.dataset.variantName;
                variantId = activeVariant.dataset.variantId;
                itemDisplayName = `${baseName} - ${vName}`;
            } else {
                basePrice = parseFloat(card.dataset.itemPrice) || 0;
            }

            const activePills = card.querySelectorAll('.extra-pill.active');
            const selectedExtras = [];
            let extrasTotal = 0;
            activePills.forEach(pill => {
                const extraName = pill.dataset.extraName;
                const extraPrice = parseFloat(pill.dataset.extraPrice) || 0;
                selectedExtras.push(extraName);
                extrasTotal += extraPrice;
            });

            let displayName = isVegan ? `${itemDisplayName} (Vegana)` : itemDisplayName;
            if (selectedExtras.length > 0) {
                displayName += ` (+ ${selectedExtras.join(', + ')})`;
            }

            const finalPrice = basePrice + extrasTotal;

            addToCart(displayName, finalPrice, itemId, isVegan, [], null, variantId ? 'v' + variantId : null);

            const toast = card.querySelector('.added-toast');
            if (toast) {
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 1200);
            }

            activePills.forEach(pill => {
                pill.classList.remove('active');
                const plusSpan = pill.querySelector('.extra-plus');
                if (plusSpan) plusSpan.textContent = '+';
            });
            const priceSpan = card.querySelector('.variant-current-price') || card.querySelector('.item-price');
            if (priceSpan) {
                priceSpan.textContent = basePrice.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
        }

        function addItemToCart(name, itemId, price, isVegan, hasSubproducts, requiredSelections, requiresPizza = false) {
            let displayName = isVegan ? `${name} (Vegana)` : name;
            
            if (requiresPizza) {
                openDependentProductModal(itemId, displayName, price, isVegan);
            } else if (hasSubproducts) {
                openSubproductModal(itemId, displayName, price, isVegan, requiredSelections);
            } else {
                addToCart(displayName, price, itemId, isVegan);
            }
        }

        function addToCart(name, price, itemId, isVegan, selectedSubproducts = [], dependentPizza = null) {
            const cart = JSON.parse(localStorage.getItem('cart')) || [];
            const item = {
                id: itemId,
                name: name,
                price: Number(price) || 0,
                quantity: 1,
                isVegan: isVegan,
                subproducts: selectedSubproducts,
                dependentPizza: dependentPizza
            };
            
            const existingItem = cart.find(cartItem => 
                cartItem.id === itemId && 
                cartItem.name === name &&
                cartItem.isVegan === isVegan && 
                JSON.stringify(cartItem.subproducts) === JSON.stringify(selectedSubproducts) &&
                JSON.stringify(cartItem.dependentPizza) === JSON.stringify(dependentPizza)
            );
            if (existingItem) {
                existingItem.quantity += 1;
            } else {
                cart.push(item);
            }
            
            localStorage.setItem('cart', JSON.stringify(cart));
            updateCartUI();
            showCartButton();
        }

        function removeCartItem(index) {
            const cart = JSON.parse(localStorage.getItem('cart')) || [];
            if (index >= 0 && index < cart.length) {
                cart.splice(index, 1);
                localStorage.setItem('cart', JSON.stringify(cart));
                updateCartUI();
                if (cart.length === 0) {
                    closeCart();
                }
            }
        }

        function updateCartUI() {
            const cart = JSON.parse(localStorage.getItem('cart')) || [];
            const cartCount = document.getElementById('cartCount');
            const cartTotal = document.getElementById('cartTotal');
            const cartItems = document.getElementById('cartItems');
            const cartContainer = document.getElementById('cartContainer');
            const paymentMethod = document.getElementById('paymentMethod').value;
            const creditWarning = document.getElementById('creditWarning');
            const transferWarning = document.getElementById('transferWarning');
            
            let total = 0;
            let itemCount = 0;
            
            cartItems.innerHTML = '';
            cart.forEach((item, index) => {
                let itemTotal = item.price * item.quantity;
                itemCount += item.quantity;
                
                const cartItem = document.createElement('div');
                cartItem.className = 'cart-item';
                let displayName = item.name;
                if (item.subproducts && item.subproducts.length > 0) {
                    displayName += ` (${item.subproducts.map(s => s.name).join(', ')})`;
                }
                if (item.dependentPizza) {
                    displayName += ` + ${item.dependentPizza.name}`;
                    itemTotal += item.dependentPizza.price * item.quantity;
                }
                const safeName = displayName.replace(/"/g, '&quot;');
                cartItem.innerHTML = `
                    <div class="cart-item-info">
                        <button type="button" class="cart-item-remove" onclick="removeCartItem(${index})" title="Eliminar este ítem" aria-label="Eliminar ${safeName}">✕</button>
                        <span class="cart-item-name">${displayName} x${item.quantity}</span>
                    </div>
                    <span class="cart-item-price">$${itemTotal.toFixed(2)}</span>
                `;
                cartItems.appendChild(cartItem);
                total += itemTotal;
            });
            
            creditWarning.classList.remove('active');
            transferWarning.classList.remove('active');
            if (paymentMethod === 'Crédito') {
                creditWarning.classList.add('active');
                total *= 1.10;
            } else if (paymentMethod === 'Transferencia') {
                transferWarning.classList.add('active');
            }
            
            cartCount.textContent = itemCount;
            cartTotal.textContent = total.toFixed(2);
            cartContainer.style.display = itemCount > 0 ? 'block' : 'none';
        }

        function showCartButton() {
            const cartContainer = document.getElementById('cartContainer');
            cartContainer.style.display = 'block';
            cartContainer.classList.add('active');
        }

        function toggleCart(ev) {
            if (ev && ev.preventDefault) ev.preventDefault();
            if (ev && ev.stopPropagation) ev.stopPropagation();
            const modal = document.getElementById('cartModal');
            const overlay = document.getElementById('overlay');
            const isVisible = modal.style.display === 'block';
            
            modal.style.display = isVisible ? 'none' : 'block';
            overlay.style.display = isVisible ? 'none' : 'block';
            modal.classList.toggle('active', !isVisible);
            overlay.classList.toggle('active', !isVisible);
            updateCartUI();
        }

        function closeCart(ev) {
            if (ev && ev.preventDefault) ev.preventDefault();
            if (ev && ev.stopPropagation) ev.stopPropagation();
            const modal = document.getElementById('cartModal');
            const overlay = document.getElementById('overlay');
            modal.style.display = 'none';
            overlay.style.display = 'none';
            modal.classList.remove('active');
            overlay.classList.remove('active');
        }

        function clearCart() {
            localStorage.removeItem('cart');
            updateCartUI();
            closeCart();
        }

        // ---- Tipo de entrega ----
        let deliveryType = 'retiro';

        function setDelivery(type) {
            deliveryType = type;
            document.getElementById('btnRetiro').classList.toggle('active', type === 'retiro');
            document.getElementById('btnEnvio').classList.toggle('active', type === 'envio');
            document.getElementById('addressGroup').style.display = type === 'envio' ? 'block' : 'none';
            if (type === 'retiro') document.getElementById('deliveryAddress').value = '';
        }

        // ===== Selector de horario del pedido (franjas de 15') =====
        function ceilToSlot(date, minutes) {
            const d = new Date(date);
            d.setSeconds(0, 0);
            const rem = d.getMinutes() % minutes;
            if (rem !== 0) d.setMinutes(d.getMinutes() + (minutes - rem));
            return d;
        }

        function fmtHM(date, includeDay = false) {
            const hh = String(date.getHours()).padStart(2, '0');
            const mm = String(date.getMinutes()).padStart(2, '0');
            if (!includeDay) return `${hh}:${mm}`;
            const dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
            const isToday = date.toDateString() === new Date().toDateString();
            const dayLabel = isToday ? 'hoy' : dias[date.getDay()];
            return `${dayLabel} ${hh}:${mm}`;
        }

        function appendAsapSlot(slotsWrap, sub, msg) {
            sub.textContent = msg;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pickup-slot pickup-slot-asap';
            btn.textContent = 'Lo antes posible';
            btn.dataset.time = 'Lo antes posible';
            btn.onclick = () => selectPickupSlot(btn);
            slotsWrap.appendChild(btn);
        }

        function buildTimeSlots() {
            const slotsWrap = document.getElementById('pickupTimeSlots');
            const sub = document.getElementById('pickupTimeSub');
            const confirmBtn = document.getElementById('pickupConfirmBtn');
            slotsWrap.innerHTML = '';
            selectedPickupTime = null;
            if (confirmBtn) confirmBtn.disabled = true;

            // Si no se pudo calcular ningún turno (ni actual ni próximo), ofrecemos "lo antes posible"
            if (!SHIFT_END) {
                appendAsapSlot(slotsWrap, sub, isPreorder
                    ? 'Estamos cerrados. Dejanos tu pedido y coordinamos el horario:'
                    : 'Elegí cuándo querés tu pedido:');
                return;
            }

            const now = new Date();
            const shiftStart = SHIFT_START ? new Date(SHIFT_START) : now;
            const shiftEnd = new Date(SHIFT_END);

            // Primer horario ofrecido = ahora + preparación, nunca antes de la apertura del turno
            // (si estamos cerrados, SHIFT_START/END son los del PRÓXIMO turno de apertura)
            let earliest = new Date(now.getTime() + PREP_BUFFER_MIN * 60000);
            if (earliest < shiftStart) earliest = new Date(shiftStart.getTime());
            earliest = ceilToSlot(earliest, SLOT_MIN); // alinear a :00 / :15 / :30 / :45

            const slots = [];
            for (let t = new Date(earliest); t <= shiftEnd; t = new Date(t.getTime() + SLOT_MIN * 60000)) {
                slots.push(new Date(t));
            }

            // Turno terminando: si no quedan franjas, dejamos "lo antes posible"
            if (slots.length === 0) {
                appendAsapSlot(slotsWrap, sub, isPreorder
                    ? 'Estamos cerrados. Dejanos tu pedido y coordinamos el horario:'
                    : 'Estamos por cerrar el turno, pero podemos tomarlo:');
                return;
            }

            // Si el turno ofrecido no es el de hoy, mostramos el día junto al horario
            const showDay = isPreorder && earliest.toDateString() !== now.toDateString();

            sub.textContent = isPreorder
                ? 'Estamos cerrados ahora. Elegí para cuándo querés tu pedido:'
                : 'Elegí a qué hora querés tu pedido:';
            slots.forEach(slot => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'pickup-slot';
                btn.textContent = fmtHM(slot, showDay);
                btn.dataset.time = fmtHM(slot, showDay);
                btn.onclick = () => selectPickupSlot(btn);
                slotsWrap.appendChild(btn);
            });
        }

        function selectPickupSlot(btn) {
            document.querySelectorAll('#pickupTimeSlots .pickup-slot')
                .forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            selectedPickupTime = btn.dataset.time;
            const confirmBtn = document.getElementById('pickupConfirmBtn');
            if (confirmBtn) confirmBtn.disabled = false;
        }

        function openPickupTimeModal() {
            buildTimeSlots();
            const cartModal = document.getElementById('cartModal');
            const modal = document.getElementById('pickupTimeModal');
            const overlay = document.getElementById('overlay');
            // Ocultamos el carrito y mostramos el selector de horario encima
            cartModal.style.display = 'none';
            cartModal.classList.remove('active');
            modal.style.display = 'block';
            modal.classList.add('active');
            overlay.style.display = 'block';
            overlay.classList.add('active');
        }

        function closePickupTimeModal(returnToCart = true) {
            const modal = document.getElementById('pickupTimeModal');
            modal.style.display = 'none';
            modal.classList.remove('active');
            if (returnToCart) {
                // Volvemos al carrito para no perder el pedido cargado
                const cartModal = document.getElementById('cartModal');
                cartModal.style.display = 'block';
                cartModal.classList.add('active');
            } else {
                const overlay = document.getElementById('overlay');
                overlay.style.display = 'none';
                overlay.classList.remove('active');
            }
        }

        function onOverlayClick() {
            const pickup = document.getElementById('pickupTimeModal');
            if (pickup && pickup.classList.contains('active')) {
                closePickupTimeModal(true);
                return;
            }
            closeCart();
        }

        function confirmPickupAndSend() {
            if (!selectedPickupTime) { alert('Elegí un horario para tu pedido'); return; }
            closePickupTimeModal(false);
            submitOrder(selectedPickupTime);
        }

        // Paso 1: validar el pedido y abrir el selector de horario
        function sendOrder() {
            const cart = JSON.parse(localStorage.getItem('cart')) || [];
            const customerName = document.getElementById('customerName').value.trim();
            const customerPhone = document.getElementById('customerPhone')?.value.trim() || '';
            const paymentMethod = document.getElementById('paymentMethod').value;
            const address = document.getElementById('deliveryAddress')?.value.trim() || '';

            if (!customerName) { alert('Por favor ingresa tu nombre para el pedido'); return; }
            if (!customerPhone) { alert('Por favor ingresá tu teléfono o WhatsApp'); return; }
            if (deliveryType === 'envio' && !address) { alert('Por favor ingresá la dirección de entrega'); return; }
            if (!paymentMethod) { alert('Por favor selecciona una forma de pago'); return; }
            if (cart.length === 0) { alert('El carrito está vacío'); return; }

            try {
                localStorage.setItem('rrbb_customer_name', customerName);
                localStorage.setItem('rrbb_customer_phone', customerPhone);
                if (address) localStorage.setItem('rrbb_customer_address', address);
            } catch (e) {}

            openPickupTimeModal();
        }

        // Paso 2: con el horario elegido, armar el mensaje y mandar a WhatsApp
        function submitOrder(pickupTime) {
            const cart = JSON.parse(localStorage.getItem('cart')) || [];
            const customerName = document.getElementById('customerName').value.trim();
            const customerPhone = document.getElementById('customerPhone')?.value.trim() || '';
            const extraComments = document.getElementById('extraComments').value.trim();
            const paymentMethod = document.getElementById('paymentMethod').value;
            const address = document.getElementById('deliveryAddress')?.value.trim() || '';

            if (!customerName || !paymentMethod || cart.length === 0) { return; }

            const entrega = deliveryType === 'envio'
                ? `🛵 *Envío a domicilio*\nDirección: ${address}`
                : `🏪 *Retiro en local* (27 de abril 798)`;

            let message = `Hola, soy *${customerName}*`;
            if (customerPhone) message += ` (${customerPhone})`;
            message += ` y quisiera hacer el siguiente pedido:\n\n`;

            let total = 0;
            const pizzalogItems = [];
            cart.forEach(item => {
                const qty = Number(item.quantity) || 1;
                let unitPrice = Number(item.price) || 0;
                let displayName = item.name;
                if (item.subproducts && item.subproducts.length > 0) {
                    displayName += ` (${item.subproducts.map(s => s.name).join(', ')})`;
                }
                if (item.dependentPizza) {
                    displayName += ` + ${item.dependentPizza.name}`;
                    unitPrice += Number(item.dependentPizza.price) || 0;
                }
                const itemTotal = unitPrice * qty;
                message += `- ${displayName} x${qty}: $${itemTotal.toFixed(2)}\n`;
                total += itemTotal;
                pizzalogItems.push({
                    name: displayName,
                    qty: qty,
                    price: unitPrice
                });
            });

            if (paymentMethod === 'Crédito') {
                const surcharge = total * 0.10;
                total += surcharge;
                message += `- Recargo TC x1: $${surcharge.toFixed(2)}\n`;
                pizzalogItems.push({
                    name: 'Recargo Tarjeta Crédito (10%)',
                    qty: 1,
                    price: Number(surcharge.toFixed(2))
                });
            } else if (paymentMethod === 'Transferencia') {
                message += `\nDatos para la transferencia: Alias: ${TRANSFER_ALIAS}, Nombre: ${TRANSFER_HOLDER}`;
            }

            if (extraComments) {
                message += `\n\n*Comentarios:* ${extraComments}`;
            }

            const horarioLabel = deliveryType === 'envio' ? 'Horario de entrega' : 'Horario de retiro';
            const preorderTag = isPreorder ? '\n📅 *Pedido programado (local cerrado al momento de pedir)*' : '';
            message += `\n\n${entrega}\n🕒 *${horarioLabel}:* ${pickupTime}${preorderTag}\nTotal: $${total.toFixed(2)}\nForma de pago: ${paymentMethod}`;

            // Registramos el pedido en track.php (que además lo sincroniza con Pizzalog)
            trackEvent({
                type: 'order',
                source: 'carta',
                customer_name: customerName,
                customer_phone: customerPhone,
                address: deliveryType === 'envio' ? address : '',
                notes: extraComments,
                delivery_type: deliveryType,
                payment_method: paymentMethod,
                total: Number(total.toFixed(2)),
                pickup_time: pickupTime,
                item_count: cart.reduce((n, it) => n + (Number(it.quantity) || 1), 0),
                items: pizzalogItems
            });

            window.location.href = `https://wa.me/5493517548030?text=${encodeURIComponent(message)}`;
            clearCart();
        }

        function openSubproductModal(itemId, itemName, itemPrice, isVegan, requiredSelections) {
            currentSubproductItem = { id: itemId, name: itemName, price: itemPrice, isVegan: isVegan, requiredSelections: requiredSelections };
            const modal = document.getElementById('subproductModal');
            const selectionsDiv = document.getElementById('subproductSelections');
            const subproducts = <?php echo json_encode($sub_products); ?>[itemId] || [];

            const eligibleItems = <?php echo json_encode($eligible_items); ?>;
            if (eligibleItems.length === 0) {
                selectionsDiv.innerHTML = '<p>No hay pizzas disponibles para seleccionar.</p>';
                modal.style.display = 'block';
                modal.classList.add('active');
                document.getElementById('overlay').style.display = 'block';
                document.getElementById('overlay').classList.add('active');
                return;
            }

            selectionsDiv.innerHTML = '';
            for (let i = 0; i < requiredSelections; i++) {
                const select = document.createElement('select');
                select.id = `subproduct-select-${i}`;
                select.innerHTML = '<option value="">Selecciona una pizza</option>' +
                    eligibleItems.map(item => 
                        `<option value="${item.id}">${item.name}</option>`
                    ).join('');
                selectionsDiv.appendChild(select);
            }

            modal.style.display = 'block';
            modal.classList.add('active');
            document.getElementById('overlay').style.display = 'block';
            document.getElementById('overlay').classList.add('active');
        }

        function closeSubproductModal() {
            const modal = document.getElementById('subproductModal');
            modal.style.display = 'none';
            modal.classList.remove('active');
            document.getElementById('overlay').style.display = 'none';
            document.getElementById('overlay').classList.remove('active');
            currentSubproductItem = null;
        }

        function confirmSubproducts() {
            if (!currentSubproductItem) return;

            const selections = [];
            const selectionsDiv = document.getElementById('subproductSelections');
            const selects = selectionsDiv.querySelectorAll('select');
            
            for (let select of selects) {
                if (select.value) {
                    const item = <?php echo json_encode($eligible_items); ?>.find(i => i.id == select.value);
                    if (item) {
                        selections.push({ id: item.id, name: item.name });
                    }
                }
            }

            if (selections.length !== currentSubproductItem.requiredSelections) {
                alert(`Por favor selecciona exactamente ${currentSubproductItem.requiredSelections} pizzas.`);
                return;
            }

            addToCart(
                currentSubproductItem.name,
                currentSubproductItem.price,
                currentSubproductItem.id,
                currentSubproductItem.isVegan,
                selections
            );
            closeSubproductModal();
        }

        function openDependentProductModal(itemId, itemName, itemPrice, isVegan) {
            currentDependentItem = { id: itemId, name: itemName, price: itemPrice, isVegan: isVegan };
            const modal = document.getElementById('dependentProductModal');
            const select = document.getElementById('dependentPizzaSelect');
            const titleSpan = document.getElementById('dependentProductTitle');
            if (titleSpan) titleSpan.textContent = itemName;

            if (select.options.length <= 1) {
                alert('No hay pizzas disponibles para seleccionar.');
                return;
            }

            modal.style.display = 'block';
            modal.classList.add('active');
            document.getElementById('overlay').style.display = 'block';
            document.getElementById('overlay').classList.add('active');
        }

        function closeDependentProductModal() {
            const modal = document.getElementById('dependentProductModal');
            modal.style.display = 'none';
            modal.classList.remove('active');
            document.getElementById('overlay').style.display = 'none';
            document.getElementById('overlay').classList.remove('active');
            currentDependentItem = null;
        }

        function confirmDependentProduct() {
            if (!currentDependentItem) return;

            const select = document.getElementById('dependentPizzaSelect');
            if (!select.value) {
                alert('Por favor selecciona una pizza.');
                return;
            }

            const selectedOption = select.options[select.selectedIndex];
            const pizzaId = selectedOption.value;
            const pizzaName = selectedOption.dataset.name;
            const pizzaPrice = parseFloat(selectedOption.dataset.price);

            const totalPrice = currentDependentItem.price;
            const combinedName = `${currentDependentItem.name} + ${pizzaName}`;

            addToCart(
                combinedName,
                totalPrice,
                currentDependentItem.id,
                currentDependentItem.isVegan,
                [],
                { id: pizzaId, name: pizzaName, price: pizzaPrice }
            );
            closeDependentProductModal();
        }
    </script>

</body>
</html>
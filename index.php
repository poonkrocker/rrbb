<?php
session_start();
require_once 'db_connect.php';

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

// Verificar si el negocio está abierto y obtener horarios
$is_open = false;
$closed_message = "Estamos cerrados ahora. Horarios de atención:<br>";

try {
    // Horarios del día actual (para saber si está abierto)
    $stmt = $pdo->prepare("SELECT start_time, end_time FROM business_hours WHERE day_of_week = ?");
    $stmt->execute([$current_day_es]);
    $hours = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Todos los horarios (para mostrar en el aviso)
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
        $closed_message = "Estamos cerrados ahora. Horarios de atención:<br>" . formatGroupedHours($all_hours);
    }

    // ¿Está abierto ahora?
    foreach ($hours as $hour) {
        if ($current_time >= $hour['start_time'] && $current_time <= $hour['end_time']) {
            $is_open = true;
            break;
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
} catch (PDOException $e) {
    $categories = [];
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

// Fetch eligible items (visible pizzas)
$eligible_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT mi.id, mi.name, mi.price 
        FROM menu_items mi 
        WHERE mi.is_visible = 1 
        AND mi.category_id = (SELECT id FROM categories WHERE name = 'Pizzas' LIMIT 1)
        AND (
            mi.visible_days IS NULL 
            OR mi.visible_days = '[]' 
            OR JSON_CONTAINS(mi.visible_days, ?)
        )
        AND (
            (mi.visible_start_time IS NULL AND mi.visible_end_time IS NULL)
            OR (
                mi.visible_start_time <= mi.visible_end_time
                AND mi.visible_start_time <= ? 
                AND mi.visible_end_time >= ?
            )
            OR (
                mi.visible_start_time > mi.visible_end_time
                AND (
                    mi.visible_start_time <= ?
                    OR mi.visible_end_time >= ?
                )
            )
        )
        ORDER BY mi.name
    ");
    $stmt->execute([
        json_encode($current_day_es),
        $current_time,
        $current_time,
        $current_time,
        $current_time
    ]);
    $eligible_items = $stmt->fetchAll();
} catch (PDOException $e) {
    $eligible_items = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pizzería Arrabbiata - Córdoba</title>
    <link rel="stylesheet" href="arrabbiata.css">
    <link rel="icon" type="image/png" href="/favicon.png"/>
    <link href="https://fonts.googleapis.com/css2?family=Comic+Neue:wght@400;700&display=swap" rel="stylesheet">
    <!-- Meta Pixel Code -->
    <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '313442609878673');
    fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
    src="https://www.facebook.com/tr?id=313442609878673&ev=PageView&noscript=1"
    /></noscript>
    <!-- Schema JSON-LD -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "LocalBusiness",
        "name": "Arrabbiata",
        "image": "https://arrabbiata.com.ar/wp-content/uploads/2025/05/Identidad-Arrabbiata-19_page-0001-1-scaled-e1746087327655.png",
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
        "description": "Pizzería Arrabbiata, la mejor pizza de Córdoba, ofreciendo auténticas pizzas italianas en el corazón de Alberdi."
    }
    </script>
    <style>
/* Centrar Logo */
.home-content {
    display: flex;
    flex-direction: column;
    align-items: center; /* Center horizontally */
    justify-content: center; /* Center vertically, if desired */
}

.floating-logo {
    max-width: 100%;
}
/* Footer Styles */
footer {
    background: #333;
    color: white;
    text-align: center;
    padding: 20px 0;
    font-family: 'Comic Neue', sans-serif;
}
.footer-content p {
    margin-bottom: 10px;
    font-size: 1rem;
}
.social-icons {
    display: flex;
    justify-content: center;
    gap: 15px;
}
.social-icons a img {
    width: 40px;
    height: 40px;
    transition: transform 0.3s ease;
}
.social-icons a img:hover {
    transform: scale(1.1);
}
/* Pizza Selection Modal */
.pizza-selection-modal {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
    z-index: 1002;
    max-width: 90%;
    width: 400px;
    text-align: center;
}
.pizza-selection-modal.active {
    display: block;
}
.pizza-selection-modal h3 {
    font-family: 'Alberdini', cursive;
    color: #333;
    margin-bottom: 20px;
}
.pizza-selection-modal select {
    width: 100%;
    padding: 10px;
    margin-bottom: 20px;
    border: 2px solid #ddd;
    border-radius: 5px;
    font-size: 16px;
}
.pizza-selection-modal button {
    background: #cc0000;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 25px;
    cursor: pointer;
    margin: 5px;
    font-size: 16px;
}
.pizza-selection-modal button:hover {
    background: #b30000;
}
/* Custom Font */
@font-face {
    font-family: 'Alberdini';
    src: url('dk.woff2') format('woff2'),
         url('dk.woff') format('woff');
    font-weight: normal;
    font-style: normal;
}
/* Closed Notice */
.closed-notice {
    display: none;
    background-color: #cc0000;
    color: white;
    text-align: center;
    padding: 12px;
    font-size: 1.1rem;
    font-family: 'Alberdini', cursive;
    font-weight: 700;
    position: sticky;
    top: 0;
    z-index: 1000;
    border-radius: 0 0 10px 10px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
    line-height: 1.5;
}
.closed-notice.active {
    display: block;
}
/* Disabled State */
.disabled {
    opacity: 0.5;
    pointer-events: none;
    cursor: not-allowed;
}
.disabled .vegan-button,
.disabled .item-price[data-variant],
.disabled .cart-button,
.disabled button {
    cursor: not-allowed;
    background: #ccc !important;
    color: #666 !important;
}
/* Category Submenu */
.category-submenu {
    background: transparent;
    border-radius: 16px;
    padding: 2px 0;
    margin: 15px auto;
    display: flex;
    justify-content: center;
    width: fit-content;
    transition: transform 0.3s ease;
}
.category-submenu:hover {
    transform: translateY(-2px);
}
.category-submenu ul {
    list-style: none;
    display: flex;
    flex-wrap: nowrap;
    justify-content: center;
    gap: 4px;
    margin: 0;
    padding: 0 8px;
    overflow-x: auto;
    white-space: nowrap;
}
.category-submenu li {
    position: relative;
}
.category-submenu a {
    text-decoration: none;
    color: #333;
    font-size: 11px;
    font-weight: 700;
    font-family: 'Alberdini', cursive;
    padding: 4px 6px;
    border-radius: 2px;
    background: linear-gradient(145deg, #ffffff, #e6e6e6);
    box-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    display: inline-block;
    position: relative;
    overflow: hidden;
}
.category-submenu a:hover {
    background: #cc0000;
    color: white;
    transform: scale(1.05);
    box-shadow: 0 4px 12px rgba(204, 0, 0, 0.3);
}
.category-submenu a::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    background: rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    transition: width 0.4s ease, height 0.4s ease;
    z-index: 0;
}
.category-submenu a:active::after {
    width: 20px;
    height: 20px;
}
/* Menu Container */
.menu-container {
    padding: 22px 20px 20px 20px !important;
    background-color: #FDB740;
    max-width: 1200px;
    margin: 0 auto !important;
    position: relative;
    top: 0;
}
.menu-container h2 {
    text-align: center;
    font-size: 2rem;
    margin: 0 0 15px 0 !important;
    color: #333;
    font-family: 'Alberdini', cursive;
    font-weight: 700;
}
/* Menu Category */
.menu-category {
    font-size: 1.5rem;
    color: #333;
    font-family: 'Alberdini', cursive;
    font-weight: 700;
    margin: 20px 0;
}
/* Pizza List */
.pizza-list {
    display: grid !important;
    grid-template-columns: repeat(2, 1fr) !important;
    gap: 20px !important;
    padding: 10px !important;
}
.pizza-item {
    position: relative;
    cursor: pointer;
    padding: 15px !important;
    background: white;
    border-radius: 10px;
    box-shadow: 0 5px 15px rgba(0,0,0, 0.1);
    transition: transform 0.2s;
    box-sizing: border-box;
    width: 100%;
}
.pizza-item:hover {
    transform: scale(1.02);
}
.pizza-item img {
    width: 100%;
    height: 120px;
    object-fit: cover;
    border-radius: 4px;
}
.pizza-item h3 {
    font-size: 1.1rem;
    margin: 8px 0;
    font-family: 'Alberdini';
    color: #333;
}
.price-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 8px;
}
.pizza-item .item-price {
    cursor: default;
    color: #cc0000;
    font-weight: bold;
    font-size: 0.95rem;
}
.pizza-item .burrata-prices .item-price {
    cursor: pointer;
}
.pizza-item .burrata-prices .item-price:hover {
    text-decoration: underline;
}
.pizza-item .vegan-button {
    display: flex;
    align-items: center;
    gap: 5px;
    background: #ccc;
    color: white;
    border: none;
    padding: 4px 8px;
    border-radius: 25px;
    cursor: pointer;
    font-size: 0.85rem;
    font-family: 'Arial', sans-serif;
    transition: background 0.3s ease, transform 0.2s ease;
}
.pizza-item .vegan-button:active {
    background: #28a745;
}
.pizza-item .vegan-button:hover {
    transform: scale(1.05);
}
.pizza-item .burrata-options {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 8px 0;
}
.pizza-item .burrata-options span {
    font-size: 1.1rem;
    font-family: 'Alberdini';
    color: #333;
}
.pizza-item .burrata-prices {
    display: flex;
    justify-content: space-between;
    margin-top: 5px;
}
.pizza-item .burrata-prices .item-price {
    font-size: 0.95rem;
}
.pizza-item ul {
    margin: 8px 0;
    padding-left: 15px;
    font-size: 0.85rem;
    color: #555;
}
/* Weekly Special Label */
.weekly-special-label {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #D73828;
    color: white;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 0.8rem;
    font-weight: bold;
    z-index: 10;
}
/* Responsive Styles */
@media (min-width: 768px) {
    .pizza-list {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 20px !important;
        padding: 10px !important;
        max-width: 100%;
    }
    .pizza-item {
        margin: 0;
        padding: 15px !important;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }
    .pizza-item img {
        height: 120px;
    }
    .pizza-item h3 {
        font-size: 1.1rem;
    }
    .pizza-item .item-price, .pizza-item .burrata-prices .item-price {
        font-size: 0.95rem;
    }
    .pizza-item .vegan-button {
        font-size: 0.85rem;
        padding: 4px 8px;
    }
}
@media (max-width: 767px) {
    .closed-notice {
        font-size: 1rem;
        padding: 10px;
        line-height: 1.4;
    }
    .pizza-list {
        display: grid !important;
        grid-template-columns: 1fr !important;
        gap: 20px !important;
        padding: 15px !important;
    }
    .pizza-item {
        margin: 0;
        padding: 15px !important;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }
    .pizza-item img {
        height: 120px;
    }
    .pizza-item ul {
        font-size: 0.8rem;
    }
    .category-submenu {
        padding: 2px 0;
        border-radius: 14px;
        min-height: 40px;
        width: 100%;
        max-width: 100%;
        margin: 15px auto;
    }
    .category-submenu ul {
        display: flex;
        flex-wrap: nowrap;
        gap: 4px;
        padding: 0 8px;
        overflow-x: auto;
        white-space: nowrap;
    }
    .category-submenu a {
        font-size: 10px;
        padding: 4px 8px;
        border-radius: 12px;
    }
    .pizza-item .burrata-options {
        flex-direction: column;
        align-items: flex-start;
    }
    .pizza-item .burrata-prices {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
    .price-container {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
}
@media (max-width: 480px) {
    .closed-notice {
        font-size: 0.9rem;
        padding: 8px;
        line-height: 1.3;
    }
    .category-submenu a {
        font-size: 9px;
        padding: 3px 6px;
        border-radius: 12px;
    }
    .menu-container h2 {
        font-size: 1.5rem;
    }
    .menu-category {
        font-size: 1.2rem;
    }
}
/* No Items Message */
.no-items-message {
    text-align: center;
    font-size: 1rem;
    color: #555;
    font-family: 'Alberdini', cursive;
    margin: 20px 0;

/* ====== Slider "Nuestra Historia" centrado y prolijo ====== */
.gallery-container{
  position: relative;
  width: 100%;
  /* Alto responsive: ajustá los límites a gusto */
  height: clamp(220px, 40vw, 420px);
  overflow: hidden;
  border-radius: 14px;         /* combina con tus cards */
}

/* Cada slide ocupa toda el área y queda centrado */
.gallery-slide{
  position: absolute;
  inset: 0;
  background-size: cover;      /* recorta sin deformar */
  background-position: center; /* CENTRA la imagen */
  background-repeat: no-repeat;
  opacity: 0;
  transition: opacity .6s ease;
}

/* El slide activo se muestra (tu JS ya agrega .active) */
.gallery-slide.active{
  opacity: 1;
}
}

</style>
</head>
<body>
<div class="container">

    <div class="main-container">
        <!-- Navigation -->
        <nav class="nav-menu">
            <ul>
                <li><a href="#home">Home</a></li>
                <li><a href="#nosotros">Nosotros</a></li>
                <li><a href="#carta">Carta</a></li>
                <li><a href="#ubicacion">Ubicación</a></li>

            </ul>
        </nav>
        
        <!-- Home Section -->
        <section id="home" class="section active">
            <video class="video-background" autoplay muted loop playsinline>
                <source src="https://arrabbiata.com.ar/Uploads/pizzitaloop.mp4" type="video/mp4">
                Tu navegador no soporta videos HTML5.
            </video>
            <div class="home-content">
                <img src="https://arrabbiata.com.ar/Uploads/logolindo.png" 
                     alt="Arrabbiata" 
                     class="floating-logo">
                <p>LA MEJOR PIZZA DE CÓRDOBA</p>
            </div>
        </section>
        
        <!-- About Section -->
        <section id="nosotros" class="section">
            <div class="gallery-container">
                <div class="gallery-slide" style="background-image: url('https://arrabbiata.com.ar/Uploads/Img005.webp');"></div>
                <div class="gallery-slide" style="background-image: url('https://arrabbiata.com.ar/Uploads/Img003.webp');"></div>
                <div class="gallery-slide" style="background-image: url('https://arrabbiata.com.ar/Uploads/Img002.webp');"></div>


            <div class="gallery-slide" style="background-image: url('https://arrabbiata.com.ar/Uploads/Img001.webp');"></div>
                <div class="gallery-slide" style="background-image: url('https://arrabbiata.com.ar/Uploads/Img004.webp');"></div>

</div>

            <div class="nosotros-text">
                <h2>Nuestra Historia</h2>
                <p>Arrabbiata nació en 2022 con una idea clara: hacer pizzas con estilo napolitano jerarquizando los ingredientes de la provincia de Córdoba. Nuestro objetivo es mejorar siempre la propuesta para nuestros clientes, ofreciendo la mejor calidad de la provincia y permitiendonos jugar con los sabores en nuestra "pizza de la semana". Los esperamos de jueves a sabados de 20 a 00 hs para probar las mejores pizzas de la ciudad de Córdoba, y de lunes a viernes de 12 a 15 hs con nuestras pizzas y también nuestros maravillosos panchitos con salchicha alemana ahumada.</p>
            </div>
        </section>
        
        <!-- Menu Section -->
        <section id="carta" class="section">
        <div class="menu-container">
            <?php if (!$is_open): ?>
                <div class="closed-notice active"><?php echo $closed_message; ?></div>
            <?php endif; ?>
            <h2>Nuestra Carta</h2>

            <nav class="category-submenu">
                <ul>
                    <?php foreach ($categories as $category): ?>
                        <?php
                        try {
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*) as count 
                                FROM menu_items mi 
                                WHERE mi.category_id = ? 
                                AND mi.is_visible = 1
                            ");
                            $stmt->execute([$category['id']]);
                            $item_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                        } catch (PDOException $e) {
                            error_log("Error en consulta de conteo de ítems: " . $e->getMessage());
                            $item_count = 0;
                        }
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
                try {
               $stmt = $pdo->prepare("
    SELECT mi.*
    FROM menu_items mi
    WHERE mi.category_id = ?
      AND mi.is_visible = 1
      AND (
        mi.visible_days IS NULL 
        OR mi.visible_days = '[]' 
        OR JSON_CONTAINS(mi.visible_days, ?)
      )
      AND (
        (mi.visible_start_time IS NULL AND mi.visible_end_time IS NULL)
        OR (
          mi.visible_start_time <= mi.visible_end_time
          AND mi.visible_start_time <= ?
          AND mi.visible_end_time   >= ?
        )
        OR (
          mi.visible_start_time > mi.visible_end_time
          AND (
            mi.visible_start_time <= ?
            OR  mi.visible_end_time   >= ?
          )
        )
      )
    ORDER BY mi.display_order
");
$stmt->execute([
    $category['id'],
    json_encode($current_day_es),
    $current_time, $current_time,  // tramo normal
    $current_time, $current_time   // cruce de medianoche
]);

                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log("Error en consulta de ítems de menú: " . $e->getMessage());
                    $items = [];
                }
                if (count($items) > 0):
                ?>
                    <div id="category-<?php echo $category['id']; ?>" class="category-section <?php if ($category['name'] == 'Carta Secreta') echo 'secret-category'; ?>">
                        <h3 class="menu-category"><?php echo htmlspecialchars($category['name']); ?></h3>
                        <div class="pizza-list">
                            <?php foreach ($items as $item): ?>
                                <div class="pizza-item <?php echo !$is_open ? 'disabled' : ''; ?> <?php echo $item['is_secret_menu'] ? 'secret-item' : ''; ?>" 
                                     data-item-id="<?php echo $item['id']; ?>" 
                                     data-item-name="<?php echo htmlspecialchars($item['name']); ?>" 
                                     data-item-price="<?php echo $item['price']; ?>" 
                                     data-has-vegan="<?php echo $item['has_vegan_option'] ? 'true' : 'false'; ?>"
                                     data-has-subproducts="<?php echo !empty($sub_products[$item['id']]) ? 'true' : 'false'; ?>"
                                     data-required-selections="<?php echo $item['required_selections'] ?: '0'; ?>"
                                     <?php if (!$item['requires_pizza'] && $is_open): ?>
                                         onclick="addItemToCart('<?php echo htmlspecialchars($item['name']); ?>', '<?php echo $item['id']; ?>', <?php echo $item['price']; ?>, false, <?php echo !empty($sub_products[$item['id']]) ? 'true' : 'false'; ?>, <?php echo $item['required_selections'] ?: '0'; ?>)"
                                     <?php endif; ?>>

                                   <div class="img-wrapper">
    <?php if ($item['is_weekly_special']): ?>
        <span class="weekly-special-label"><?php echo htmlspecialchars($item['weekly_special_text'] ?: '¡Destacado!'); ?></span>
    <?php endif; ?>
    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
</div>

                                    <?php if ($item['requires_pizza']): ?>
                                        <p class="item-desc">
                                            <?php echo nl2br(htmlspecialchars($item['description'])); ?>
                                        </p>
                                        <div class="burrata-options">
                                            <span><?php echo htmlspecialchars($item['name']); ?></span>
                                            <span>En tu pizza</span>
                                        </div>
                                        <div class="burrata-prices">
                                            <span class="item-price" data-variant="standalone" data-price="<?php echo $item['price']; ?>" <?php if ($is_open): ?>onclick="event.stopPropagation(); addItemToCart('<?php echo htmlspecialchars($item['name']); ?>', '<?php echo $item['id']; ?>', <?php echo $item['price']; ?>, false, false, 0);"<?php endif; ?>>$<?php echo number_format($item['price'], 2); ?></span>
                                            <?php if ($item['secondary_price'] !== null): ?>
                                                <span class="item-price" data-variant="para-tu-pizza" data-price="<?php echo $item['secondary_price']; ?>" data-requires-pizza="true" <?php if ($is_open): ?>onclick="event.stopPropagation(); addItemToCart('<?php echo htmlspecialchars($item['name']); ?> (Para tu pizza)', '<?php echo $item['id']; ?>', <?php echo $item['secondary_price']; ?>, false, false, 0, true);"<?php endif; ?>>$<?php echo number_format($item['secondary_price'], 2); ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($item['description'])): ?>
                                            
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                                        <p class="item-desc">
                                            <?php echo nl2br(htmlspecialchars($item['description'])); ?>
                                        </p>
                                        <div class="price-container">
                                            <p>$<span class="item-price" data-price="<?php echo $item['price']; ?>"><?php echo number_format($item['price'], 2); ?></span></p>
                                            <?php if ($item['has_vegan_option']): ?>
                                                <button class="vegan-button" data-item-id="<?php echo $item['id']; ?>" <?php if ($is_open): ?>onclick="event.stopPropagation(); addItemToCart('<?php echo htmlspecialchars($item['name']); ?>', '<?php echo $item['id']; ?>', <?php echo $item['price']; ?>, true, <?php echo !empty($sub_products[$item['id']]) ? 'true' : 'false'; ?>, <?php echo $item['required_selections'] ?: '0'; ?>)"<?php endif; ?>>🌿 Versión Vegana</button>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($item['description'])): ?>
                                            
                                        <?php endif; ?>

                                    <?php endif; ?>

                                    <?php if (!empty($sub_products[$item['id']])): ?>
                                        <p>Incluye (a elección):</p>
                                        <ul>
                                            <?php foreach ($sub_products[$item['id']] as $sub): ?>
                                                <li><?php echo htmlspecialchars($sub['name']); ?> (x<?php echo $sub['quantity']; ?><?php echo $sub['is_required'] ? ', Obligatorio' : ''; ?>)</li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

        <!-- Floating Cart -->
        <div class="cart-container" id="cartContainer">
            <div class="cart-button <?php echo !$is_open ? 'disabled' : ''; ?>" <?php if ($is_open): ?>onclick="toggleCart()"<?php endif; ?>>
                🛒
                <div class="cart-badge" id="cartCount">0</div>
            </div>
        </div>

        <!-- Cart Modal -->
        <div class="overlay" id="overlay" onclick="closeCart()"></div>
        <div class="cart-modal" id="cartModal">
            <h3>Tu Pedido</h3>
            <div class="input-group">
                <input type="text" id="customerName" placeholder="Nombre para el pedido" required>
            </div>
            <div class="input-group">
                <select id="paymentMethod" required>
                    <option value="">Selecciona forma de pago</option>
                    <option value="Transferencia">Transferencia</option>
                    <option value="Efectivo">Efectivo</option>
                    <option value="Débito">Débito</option>
                    <option value="Crédito">Crédito</option>
                </select>
                <div class="credit-warning" id="creditWarning">
                    Pago con crédito tiene un recargo del <strong>10%</strong>.
                </div>
                <div class="transfer-warning" id="transferWarning">
                    Por favor realiza la transferencia al alias <strong>RRBB.PIZZA</strong> a nombre de Gustavo Emilio Muñoz.
                </div>
            </div>
            <div class="cart-items" id="cartItems"></div>
            <div class="cart-total">
                Total: $<span id="cartTotal">0</span>
            </div>
            <div class="cart-actions">
                <button class="clear-cart <?php echo !$is_open ? 'disabled' : ''; ?>" <?php if ($is_open): ?>onclick="clearCart()"<?php endif; ?>>Limpiar Carrito</button>
                <button class="send-order <?php echo !$is_open ? 'disabled' : ''; ?>" <?php if ($is_open): ?>onclick="sendOrder()"<?php endif; ?>>Enviar por WhatsApp</button>
            </div>
        </div>

        <!-- Subproduct Modal -->
        <div class="subproduct-modal" id="subproductModal">
            <h3>Selecciona tus pizzas</h3>
            <div id="subproductSelections"></div>
            <button onclick="confirmSubproducts()">Agregar al Carrito</button>
            <button onclick="closeSubproductModal()">Cancelar</button>
        </div>

        <!-- Pizza Selection Modal -->
        <div class="pizza-selection-modal" id="pizzaSelectionModal">
            <h3>Selecciona una pizza para tu <?php echo htmlspecialchars($item['name'] ?? 'item'); ?></h3>
            <div id="pizzaSelection">
                <select id="pizza-select">
                    <option value="">Selecciona una pizza</option>
                    <?php foreach ($eligible_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>" data-name="<?php echo htmlspecialchars($item['name']); ?>" data-price="<?php echo $item['price']; ?>">
                            <?php echo htmlspecialchars($item['name']); ?> ($<?php echo number_format($item['price'], 2); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button onclick="confirmPizzaSelection()">Agregar al Carrito</button>
            <button onclick="closePizzaSelectionModal()">Cancelar</button>
        </div>
        
        <!-- Location Section -->
        <section id="ubicacion" class="section">
            <div class="contact-info">        
                <h2>Ubicación</h2>
                <div class="map-container">
                    <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3405.0074799324966!2d-64.197666125057!3d-31.413919996130915!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x943299025f8e592f%3A0xa204fab89226789c!2sArrabbiata%20Pizza%20C%C3%B3rdoba!5e0!3m2!1ses!2sar!4v1747767861650!5m2!1ses!2sar" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
                <h3>Dirección</h3>
                <p>27 de abril 798, Córdoba</p>
                
<h3>Horarios</h3>
<p><?php echo nl2br(str_replace('<br>', "\n", formatGroupedHours($all_hours))); ?></p>
                
                <h3>Contacto</h3>
                <a href="https://www.instagram.com/arrabbiata.pizza" target="_blank">@arrabbiata.pizza</a>
                <a href="mailto:prontoarrabbiata@gmail.com">prontoarrabbiata@gmail.com</a>
                <br><br>
            </div>
        </section>
        

    </div>
    <!-- Footer -->
    <footer>
        <div class="footer-content">
            <p>Arrabbiata - La mejor pizza de Córdoba</p>
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

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Page loaded');
            // Gallery rotation
            const slides = document.querySelectorAll('.gallery-slide');
            let currentSlide = 0;
            
            function showSlide(n) {
                slides.forEach(slide => slide.classList.remove('active'));
                slides[n].classList.add('active');
            }
            
            function nextSlide() {
                currentSlide = (currentSlide + 1) % slides.length;
                showSlide(currentSlide);
            }
            
            showSlide(0);
            setInterval(nextSlide, 5000);
            
            // Smooth scroll
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.querySelector(this.getAttribute('href')).scrollIntoView({
                        behavior: 'smooth'
                    });
                });
            });
            
            // Section visibility
            const sections = document.querySelectorAll('.section');
            function checkScroll() {
                sections.forEach(section => {
                    const sectionTop = section.getBoundingClientRect().top;
                    const sectionHeight = section.offsetHeight;
                    if (sectionTop < window.innerHeight * 0.7 && sectionTop > -sectionHeight * 0.7) {
                        section.classList.add('active');
                    } else {
                        section.classList.remove('active');
                    }
                });
            }
            
            window.addEventListener('load', checkScroll);
            window.addEventListener('scroll', checkScroll);
            
// normaliza acentos y mayúsculas (Crédito / Credito)
function normalizePay(str){
  return (str || '')
    .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
    .trim().toLowerCase();
}

            // Load cart
            updateCartUI();
            document.getElementById('paymentMethod').addEventListener('change', updateCartUI);
        });

        function scrollToCategory(categoryId) {
            event.preventDefault();
            const element = document.getElementById(categoryId);
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // Cart logic
        let currentSubproductItem = null;
        let currentPizzaSelectionItem = null;

        function addItemToCart(name, itemId, price, isVegan, hasSubproducts, requiredSelections, requiresPizza = false, pizzaSelection = null) {
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
            console.log('addItemToCart:', {name, itemId, price, isVegan, hasSubproducts, requiredSelections, requiresPizza, pizzaSelection});
            let displayName = isVegan ? `${name} (Vegana)` : name;
            
            if (hasSubproducts) {
                openSubproductModal(itemId, displayName, price, isVegan, requiredSelections);
            } else if (requiresPizza && !pizzaSelection) {
                openPizzaSelectionModal(itemId, displayName, price, isVegan);
            } else {
                addToCart(displayName, price, itemId, isVegan, [], pizzaSelection);
            }
        }

        function addToCart(name, price, itemId, isVegan, selectedSubproducts = [], pizzaSelection = null) {
            console.log('addToCart:', {name, price, itemId, isVegan, selectedSubproducts, pizzaSelection});
            const cart = JSON.parse(localStorage.getItem('cart')) || [];
            let displayName = name;
            let totalPrice = price;
            
            if (pizzaSelection) {
                displayName = `${name} + ${pizzaSelection.name}`;
                totalPrice += pizzaSelection.price;
            }
            
            const item = {
                id: itemId,
                name: displayName,
                price: totalPrice,
                quantity: 1,
                isVegan: isVegan,
                subproducts: selectedSubproducts,
                pizzaSelection: pizzaSelection
            };
            
            const existingItem = cart.find(cartItem => 
                cartItem.id === itemId && 
                cartItem.isVegan === isVegan && 
                JSON.stringify(cartItem.subproducts) === JSON.stringify(selectedSubproducts) &&
                JSON.stringify(cartItem.pizzaSelection) === JSON.stringify(pizzaSelection)
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

function normalizePay(str){
  return (str || '')
    .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
    .trim().toLowerCase();
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

  cart.forEach(item => {
    let itemTotal = item.price * item.quantity;
    let displayName = item.name;

    if (item.subproducts && item.subproducts.length > 0) {
      displayName += ` (${item.subproducts.map(s => s.name).join(', ')})`;
    }
    if (item.dependentPizza) {
      displayName += ` + ${item.dependentPizza.name}`;
      itemTotal += item.dependentPizza.price * item.quantity;
    }

    const cartItem = document.createElement('div');
    cartItem.className = 'cart-item';
    cartItem.innerHTML = `<span>${displayName} x${item.quantity}</span><span>$${itemTotal.toFixed(2)}</span>`;
    cartItems.appendChild(cartItem);

    total += itemTotal;
    itemCount += item.quantity;
  });

  // Limpiar mensajes y posible recargo previo
  creditWarning.classList.remove('active');
  transferWarning.classList.remove('active');
  const prevSurcharge = cartItems.querySelector('.cart-surcharge');
  if (prevSurcharge) prevSurcharge.remove();

  // Método de pago
  const pm = normalizePay(paymentMethod);

  // Transferencia: solo mensaje
  if (pm === 'transferencia') {
    transferWarning.classList.add('active');
  }

  // Crédito: mensaje + ITEM virtual "Recargo TC"
  if (pm === 'credito') {
    creditWarning.classList.add('active');
    const surcharge = +(total * 0.10).toFixed(2);

    const surchargeRow = document.createElement('div');
    surchargeRow.className = 'cart-item cart-surcharge';
    surchargeRow.innerHTML = `<span>Recargo TC x1</span><span>$${surcharge.toFixed(2)}</span>`;
    cartItems.appendChild(surchargeRow);

    total += surcharge;
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

        function toggleCart() {
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
            const modal = document.getElementById('cartModal');
            const overlay = document.getElementById('overlay');
            const isVisible = modal.style.display === 'block';
            
            modal.style.display = isVisible ? 'none' : 'block';
            overlay.style.display = isVisible ? 'none' : 'block';
            modal.classList.toggle('active', !isVisible);
            overlay.classList.toggle('active', !isVisible);
            updateCartUI();
        }

        function closeCart() {
            const modal = document.getElementById('cartModal');
            const overlay = document.getElementById('overlay');
            modal.style.display = 'none';
            overlay.style.display = 'none';
            modal.classList.remove('active');
            overlay.classList.remove('active');
        }

        function clearCart() {
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
            localStorage.removeItem('cart');
            updateCartUI();
            closeCart();
        }

        function sendOrder() {
  const cart = JSON.parse(localStorage.getItem('cart')) || [];
  const customerName = document.getElementById('customerName').value.trim();
  const extraComments = (document.getElementById('extraComments')?.value || '').trim();
  const paymentMethod = document.getElementById('paymentMethod').value;

  if (!customerName) { alert('Por favor ingresa tu nombre para el pedido'); return; }
  if (!paymentMethod) { alert('Por favor selecciona una forma de pago'); return; }
  if (cart.length === 0) { alert('El carrito está vacío'); return; }

  let message = `Hola, soy *${customerName}* y quisiera hacer el siguiente pedido:\n\n`;
  let total = 0;

  cart.forEach(item => {
    let itemTotal = item.price * item.quantity;
    let displayName = item.name;

    if (item.subproducts && item.subproducts.length > 0) {
      displayName += ` (${item.subproducts.map(s => s.name).join(', ')})`;
    }
    if (item.dependentPizza) {
      displayName += ` + ${item.dependentPizza.name}`;
      itemTotal += item.dependentPizza.price * item.quantity;
    }

    message += `- ${displayName} x${item.quantity}: $${itemTotal.toFixed(2)}\n`;
    total += itemTotal;
  });

  const pm = normalizePay(paymentMethod);

  // Crédito: sumar ítem "Recargo TC" y recalcular total
  if (pm === 'credito') {
    const surcharge = +(total * 0.10).toFixed(2);
    message += `- Recargo TC x1: $${surcharge.toFixed(2)}\n`;
    total += surcharge;
  } else if (pm === 'transferencia') {
    message += `\nDatos para la transferencia: Alias: RRBB.PIZZA, Nombre: Gustavo Emilio Muñoz`;
  }

  if (extraComments) {
    message += `\n\n*Comentarios:* ${extraComments}`;
  }

  message += `\n\nTotal: $${total.toFixed(2)}\nForma de pago: ${paymentMethod}`;

  window.open(`https://wa.me/5493517548030?text=${encodeURIComponent(message)}`, '_blank');
  clearCart();
}

        // Subproduct Modal
        function openSubproductModal(itemId, itemName, itemPrice, isVegan, requiredSelections) {
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
            console.log('openSubproductModal:', {itemId, itemName, itemPrice, isVegan, requiredSelections});
            currentSubproductItem = { id: itemId, name: itemName, price: itemPrice, isVegan: isVegan, requiredSelections: requiredSelections };
            const modal = document.getElementById('subproductModal');
            const selectionsDiv = document.getElementById('subproductSelections');
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
            console.log('closeSubproductModal');
            const modal = document.getElementById('subproductModal');
            modal.style.display = 'none';
            modal.classList.remove('active');
            document.getElementById('overlay').style.display = 'none';
            document.getElementById('overlay').classList.remove('active');
            currentSubproductItem = null;
        }

        function confirmSubproducts() {
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
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

        // Pizza Selection Modal
        function openPizzaSelectionModal(itemId, itemName, price, isVegan) {
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
            console.log('openPizzaSelectionModal:', {itemId, itemName, price, isVegan});
            currentPizzaSelectionItem = { id: itemId, name: itemName, price: price, isVegan: isVegan };
            const modal = document.getElementById('pizzaSelectionModal');
            const overlay = document.getElementById('overlay');
            
            if (!modal) {
                console.error('Pizza selection modal not found');
                alert('Error: No se pudo abrir el modal de selección de pizza.');
                return;
            }

            // Update modal title dynamically
            const modalTitle = modal.querySelector('h3');
            modalTitle.textContent = `Selecciona una pizza para tu ${itemName.split(' (')[0]}`; // Remove "(Para tu pizza)" from title
            
            modal.style.display = 'block';
            modal.classList.add('active');
            overlay.style.display = 'block';
            overlay.classList.add('active');
        }

        function closePizzaSelectionModal() {
            console.log('closePizzaSelectionModal');
            const modal = document.getElementById('pizzaSelectionModal');
            const overlay = document.getElementById('overlay');
            modal.style.display = 'none';
            modal.classList.remove('active');
            overlay.style.display = 'none';
            overlay.classList.remove('active');
            currentPizzaSelectionItem = null;
        }

        function confirmPizzaSelection() {
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
            console.log('confirmPizzaSelection');
            if (!currentPizzaSelectionItem) {
                console.error('No current pizza selection item');
                return;
            }
            
            const pizzaSelect = document.getElementById('pizza-select');
            if (!pizzaSelect) {
                console.error('Pizza select not found');
                alert('Error: No se encontró el selector de pizzas.');
                return;
            }
            
            const selectedOption = pizzaSelect.options[pizzaSelect.selectedIndex];
            if (!selectedOption.value) {
                alert('Por favor selecciona una pizza.');
                return;
            }
            
            const pizzaSelection = {
                id: selectedOption.value,
                name: selectedOption.dataset.name,
                price: parseFloat(selectedOption.dataset.price)
            };
            
            addToCart(
                currentPizzaSelectionItem.name,
                currentPizzaSelectionItem.price,
                currentPizzaSelectionItem.id,
                currentPizzaSelectionItem.isVegan,
                [],
                pizzaSelection
            );
            closePizzaSelectionModal();
        }
    </script>
</div>
<a href="#category-7">
    <img id="secret-image" class="secret-image" src="https://arrabbiata.com.ar/Uploads/csgif.gif" alt="Carta Secreta" onclick="toggleSecretMode()">
</a>


<script>
document.addEventListener('DOMContentLoaded', function() {
  // Secret mode (imagen + toggle)
  var secretImage = document.getElementById('secret-image');
  if (secretImage) {
    window.showImageInterval = setInterval(function() {
      secretImage.classList.add('visible');
      setTimeout(function(){ secretImage.classList.remove('visible'); }, 5000);
    }, 10000);

    window.toggleSecretMode = function() {
      document.body.classList.toggle('secret-mode');
      if (document.body.classList.contains('secret-mode')) {
        if (window.showImageInterval) { clearInterval(window.showImageInterval); window.showImageInterval = null; }
        secretImage.style.display = 'none';
      }
    };
  }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var items = document.querySelectorAll('.category-submenu li');
  items.forEach(function(li){
    var txt = (li.textContent || '').trim().toLowerCase();
    if (txt === 'carta secreta') {
      li.classList.add('secret-link');
    }
  });
});
</script>
</body>
</html>

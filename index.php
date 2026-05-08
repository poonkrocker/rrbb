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

/**
 * Convierte las filas de business_hours a un array de OpeningHoursSpecification
 * (Schema.org / JSON-LD) aplicando la misma lógica de fusión de medianoche
 * que formatGroupedHours().
 *
 * Devuelve un array como:
 * [
 *   ['@type'=>'OpeningHoursSpecification','dayOfWeek'=>['Monday',...],'opens'=>'12:00','closes'=>'15:00'],
 *   ...
 * ]
 */
function buildOpeningHoursSchema($rows) {
    $order = array('Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo');
    $dayToEn = array(
        'Lunes'     => 'Monday',
        'Martes'    => 'Tuesday',
        'Miércoles' => 'Wednesday',
        'Jueves'    => 'Thursday',
        'Viernes'   => 'Friday',
        'Sábado'    => 'Saturday',
        'Domingo'   => 'Sunday'
    );

    // --- Cargar crudo por día ---
    $rawByDay = array();
    foreach ($order as $d) $rawByDay[$d] = array();
    foreach ($rows as $r) {
        $day = isset($r['day_of_week']) ? $r['day_of_week'] : '';
        if (!isset($rawByDay[$day])) continue;
        $rawByDay[$day][] = array(
            's' => isset($r['start_time']) ? $r['start_time'] : '',
            'e' => isset($r['end_time'])   ? $r['end_time']   : ''
        );
    }

    // --- Fusionar cruce de medianoche ---
    for ($i = 0; $i < 7; $i++) {
        $d = $order[$i];
        $next = $order[($i + 1) % 7];
        if (!empty($rawByDay[$d]) && !empty($rawByDay[$next])) {
            $lastIdx = count($rawByDay[$d]) - 1;
            $last = $rawByDay[$d][$lastIdx];
            $firstNext = $rawByDay[$next][0];
            $endsAt2359   = preg_match('/^23:59(?::\d{2})?$/', $last['e']);
            $startsAt0000 = preg_match('/^00:00(?::\d{2})?$/', $firstNext['s']);
            if ($endsAt2359 && $startsAt0000) {
                $rawByDay[$d][$lastIdx]['e'] = $firstNext['e'];
                $rawByDay[$next][0]['_skip'] = true;
            }
        }
    }

    // --- Construir segmentos por día (HH:MM) ---
    $segmentsByDay = array();
    foreach ($order as $d) $segmentsByDay[$d] = array();
    foreach ($order as $d) {
        foreach ($rawByDay[$d] as $seg) {
            if (!empty($seg['_skip'])) continue;
            $start  = date('H:i', strtotime($seg['s']));
            $endRaw = $seg['e'];
            $end    = date('H:i', strtotime($endRaw));
            if ($end === '23:59' || preg_match('/23:59:59$/', (string)$endRaw)) {
                $end = '00:00';
            }
            if ($start === '00:00' && preg_match('/^00:\d{2}$/', $end)) continue;
            if ($start === $end) continue;
            $segmentsByDay[$d][] = $start . '|' . $end;
        }
    }

    // --- Agrupar días por segmento (mismo opens/closes => mismo bloque) ---
    $segmentDays = array();
    foreach ($order as $d) {
        foreach ($segmentsByDay[$d] as $seg) {
            if (!isset($segmentDays[$seg])) $segmentDays[$seg] = array();
            $segmentDays[$seg][] = $dayToEn[$d];
        }
    }

    $result = array();
    foreach ($segmentDays as $seg => $days) {
        list($opens, $closes) = explode('|', $seg);
        $result[] = array(
            '@type'     => 'OpeningHoursSpecification',
            'dayOfWeek' => array_values(array_unique($days)),
            'opens'     => $opens,
            'closes'    => $closes
        );
    }
    return $result;
}

// Verificar si el negocio está abierto y obtener horarios
$is_open = false;
$closed_message = "Estamos cerrados ahora. Horarios de atención:<br>";
$all_hours = array();

try {
    // Horarios del día actual (para saber si está abierto)
    $stmt = $pdo->prepare("SELECT start_time, end_time FROM business_hours WHERE day_of_week = ?");
    $stmt->execute([$current_day_es]);
    $hours = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Todos los horarios (para mostrar en el aviso y armar el schema)
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

// ===========================================================
// Construcción del Schema.org (JSON-LD) - DINÁMICO
// ===========================================================

// 1) Horarios de apertura desde la BD
$opening_hours_schema = buildOpeningHoursSchema($all_hours);

// 2) Carta para el schema: todas las categorías visibles, excluyendo "Carta Secreta"
//    y los ítems marcados como is_secret_menu (no se exponen en SEO).
$schema_menu_sections = [];
try {
    foreach ($categories as $category) {
        if (strcasecmp($category['name'], 'Carta Secreta') === 0) continue;

        $stmt = $pdo->prepare("
            SELECT mi.*
            FROM menu_items mi
            WHERE mi.category_id = ?
              AND mi.is_visible = 1
              AND (mi.is_secret_menu IS NULL OR mi.is_secret_menu = 0)
            ORDER BY mi.display_order
        ");
        $stmt->execute([$category['id']]);
        $cat_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($cat_items) === 0) continue;

        $menu_items_schema = [];
        foreach ($cat_items as $mi) {
            $item_schema = [
                '@type' => 'MenuItem',
                'name'  => (string)$mi['name'],
            ];
            if (!empty($mi['description'])) {
                $desc = strip_tags($mi['description']);
                $desc = preg_replace('/\s+/', ' ', $desc);
                $item_schema['description'] = trim($desc);
            }
            if (!empty($mi['image_url'])) {
                $item_schema['image'] = $mi['image_url'];
            }
            if (isset($mi['price']) && $mi['price'] !== null && $mi['price'] !== '') {
                $item_schema['offers'] = [
                    '@type'         => 'Offer',
                    'price'         => number_format((float)$mi['price'], 2, '.', ''),
                    'priceCurrency' => 'ARS',
                    'availability'  => 'https://schema.org/InStock'
                ];
            }
            // Opción vegana como sufijo informativo en suitableForDiet
            if (!empty($mi['has_vegan_option'])) {
                $item_schema['suitableForDiet'] = 'https://schema.org/VeganDiet';
            }
            $menu_items_schema[] = $item_schema;
        }

        $schema_menu_sections[] = [
            '@type'       => 'MenuSection',
            'name'        => (string)$category['name'],
            'hasMenuItem' => $menu_items_schema
        ];
    }
} catch (PDOException $e) {
    error_log("Error armando schema de menú: " . $e->getMessage());
    $schema_menu_sections = [];
}

// 3) Schema completo
$restaurant_schema = [
    '@context'           => 'https://schema.org',
    '@type'              => 'Restaurant',
    '@id'                => 'https://arrabbiata.com.ar/#restaurant',
    'name'               => 'Arrabbiata',
    'alternateName'      => 'Pizzería Arrabbiata',
    'description'        => 'Pizzería Arrabbiata, la mejor pizza de Córdoba. Auténticas pizzas estilo napolitano con ingredientes de la provincia, en el corazón de Alberdi.',
    'url'                => 'https://arrabbiata.com.ar',
    'logo'               => 'https://arrabbiata.com.ar/Uploads/logolindo.png',
    'image'              => [
        'https://arrabbiata.com.ar/wp-content/uploads/2025/05/Identidad-Arrabbiata-19_page-0001-1-scaled-e1746087327655.png',
        'https://arrabbiata.com.ar/Uploads/Img001.webp',
        'https://arrabbiata.com.ar/Uploads/Img002.webp',
        'https://arrabbiata.com.ar/Uploads/Img003.webp',
        'https://arrabbiata.com.ar/Uploads/Img004.webp',
        'https://arrabbiata.com.ar/Uploads/Img005.webp'
    ],
    'telephone'          => '+5493517548030',
    'email'              => 'prontoarrabbiata@gmail.com',
    'priceRange'         => '$$',
    'servesCuisine'      => ['Italiana', 'Pizza Napolitana'],
    'acceptsReservations'=> 'False',
    'paymentAccepted'    => 'Efectivo, Transferencia, Tarjeta de Débito, Tarjeta de Crédito',
    'currenciesAccepted' => 'ARS',
    'address' => [
        '@type'           => 'PostalAddress',
        'streetAddress'   => '27 de abril 798',
        'addressLocality' => 'Córdoba',
        'addressRegion'   => 'Córdoba',
        'postalCode'      => 'X5000',
        'addressCountry'  => 'AR'
    ],
    'geo' => [
        '@type'    => 'GeoCoordinates',
        'latitude' => -31.415915,
        'longitude'=> -64.188624
    ],
    'hasMap'             => 'https://maps.app.goo.gl/8QyHubKUr5CT2bC68',
    'openingHoursSpecification' => $opening_hours_schema,
    'sameAs' => [
        'https://www.instagram.com/arrabbiata.pizza',
        'https://maps.app.goo.gl/8QyHubKUr5CT2bC68'
    ]
];

// Solo agregar hasMenu si hay secciones reales
if (!empty($schema_menu_sections)) {
    $restaurant_schema['hasMenu'] = [
        '@type'          => 'Menu',
        'name'           => 'Carta Arrabbiata',
        'inLanguage'     => 'es-AR',
        'hasMenuSection' => $schema_menu_sections
    ];
}

// 4) WebSite schema (complementario, ayuda a Google a mostrar nombre del sitio)
$website_schema = [
    '@context' => 'https://schema.org',
    '@type'    => 'WebSite',
    '@id'      => 'https://arrabbiata.com.ar/#website',
    'name'     => 'Arrabbiata',
    'url'      => 'https://arrabbiata.com.ar',
    'inLanguage' => 'es-AR',
    'publisher'  => ['@id' => 'https://arrabbiata.com.ar/#restaurant']
];

$json_flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pizzería Arrabbiata - Córdoba</title>
    <meta name="description" content="Pizzería Arrabbiata, la mejor pizza de Córdoba. Pizzas estilo napolitano con ingredientes locales, en 27 de abril 798, Alberdi. Pedí por WhatsApp.">
    <link rel="canonical" href="https://arrabbiata.com.ar/">
    <!-- Open Graph -->
    <meta property="og:type" content="restaurant.restaurant">
    <meta property="og:locale" content="es_AR">
    <meta property="og:site_name" content="Arrabbiata">
    <meta property="og:title" content="Pizzería Arrabbiata - Córdoba">
    <meta property="og:description" content="La mejor pizza de Córdoba, estilo napolitano. 27 de abril 798, Alberdi.">
    <meta property="og:url" content="https://arrabbiata.com.ar/">
    <meta property="og:image" content="https://arrabbiata.com.ar/wp-content/uploads/2025/05/Identidad-Arrabbiata-19_page-0001-1-scaled-e1746087327655.png">
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Pizzería Arrabbiata - Córdoba">
    <meta name="twitter:description" content="La mejor pizza de Córdoba, estilo napolitano.">
    <meta name="twitter:image" content="https://arrabbiata.com.ar/wp-content/uploads/2025/05/Identidad-Arrabbiata-19_page-0001-1-scaled-e1746087327655.png">

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

    <!-- Schema JSON-LD: Restaurant (dinámico desde BD) -->
    <script type="application/ld+json">
<?php echo json_encode($restaurant_schema, $json_flags); ?>
    </script>
    <!-- Schema JSON-LD: WebSite -->
    <script type="application/ld+json">
<?php echo json_encode($website_schema, $json_flags); ?>
    </script>

    <style>
/* ---- Estilos exclusivos del index, no cubiertos por arrabbiata.css ---- */

/* Cursor por defecto en precio simple (no burrata) */
.pizza-item .item-price { cursor: default; }

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
        <?php include 'cart_modal.php'; ?>

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
                <?php
                if (!empty($all_hours)) {
                    // Mismo origen que el aviso de cerrado: formatGroupedHours($all_hours)
                    $horarios_formateados = formatGroupedHours($all_hours);
                    foreach (explode('<br>', $horarios_formateados) as $linea) {
                        $linea = trim($linea);
                        if ($linea !== '') {
                            echo '<p>' . $linea . '</p>';
                        }
                    }
                } else {
                    echo '<p>Horarios no configurados, contacta al administrador.</p>';
                }
                ?>

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
        let currentDependentItem = null;

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
                openDependentProductModal(itemId, displayName, price, isVegan);
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

        // ---- Tipo de entrega ----
        let deliveryType = 'retiro'; // 'retiro' | 'envio'

        function setDelivery(type) {
            deliveryType = type;
            document.getElementById('btnRetiro').classList.toggle('active', type === 'retiro');
            document.getElementById('btnEnvio').classList.toggle('active', type === 'envio');
            document.getElementById('addressGroup').style.display = type === 'envio' ? 'block' : 'none';
            if (type === 'retiro') document.getElementById('deliveryAddress').value = '';
        }

        function sendOrder() {
  const cart = JSON.parse(localStorage.getItem('cart')) || [];
  const customerName = document.getElementById('customerName').value.trim();
  const paymentMethod = document.getElementById('paymentMethod').value;
  const address = document.getElementById('deliveryAddress')?.value.trim() || '';

  if (!customerName) { alert('Por favor ingresa tu nombre para el pedido'); return; }
  if (deliveryType === 'envio' && !address) { alert('Por favor ingresá la dirección de entrega'); return; }
  if (!paymentMethod) { alert('Por favor selecciona una forma de pago'); return; }
  if (cart.length === 0) { alert('El carrito está vacío'); return; }

  const entrega = deliveryType === 'envio'
    ? `🛵 *Envío a domicilio*\nDirección: ${address}`
    : `🏪 *Retiro en local* (27 de abril 798)`;

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

  if (pm === 'credito') {
    const surcharge = +(total * 0.10).toFixed(2);
    message += `- Recargo TC x1: $${surcharge.toFixed(2)}\n`;
    total += surcharge;
  } else if (pm === 'transferencia') {
    message += `\nDatos para la transferencia: Alias: RRBBPIZZA, Nombre: Ezequiel Urquidi`;
  }

  message += `\n\n${entrega}\nTotal: $${total.toFixed(2)}\nForma de pago: ${paymentMethod}`;

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
        function openDependentProductModal(itemId, itemName, price, isVegan) {
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
            currentDependentItem = { id: itemId, name: itemName, price: price, isVegan: isVegan };
            const modal = document.getElementById('dependentProductModal');
            const select = document.getElementById('dependentPizzaSelect');

            if (select.options.length <= 1) {
                alert('No hay pizzas disponibles para seleccionar.');
                return;
            }

            document.getElementById('dependentProductTitle').textContent = itemName.split(' (')[0];
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
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
            if (!currentDependentItem) return;

            const select = document.getElementById('dependentPizzaSelect');
            if (!select.value) {
                alert('Por favor selecciona una pizza.');
                return;
            }

            const selectedOption = select.options[select.selectedIndex];
            const pizzaSelection = {
                id: selectedOption.value,
                name: selectedOption.dataset.name,
                price: parseFloat(selectedOption.dataset.price)
            };

            addToCart(
                currentDependentItem.name,
                currentDependentItem.price,
                currentDependentItem.id,
                currentDependentItem.isVegan,
                [],
                pizzaSelection
            );
            closeDependentProductModal();
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

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


$visibilityWhere = "
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
                OR  mi.visible_end_time >= ?
            )
        )
    )
";
$visParams = [
    json_encode($current_day_es),
    $current_time, $current_time,
    $current_time, $current_time
];
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

    // ¿Está abierto ahora? (maneja cruces de medianoche y derrame del día anterior)
    $daysOrder = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    $idxDay = array_search($current_day_es, $daysOrder, true);
    $yesterday = $daysOrder[($idxDay + 6) % 7];

    $stmtOpen = $pdo->prepare("
        SELECT 1
        FROM business_hours bh
        WHERE
            (
                bh.day_of_week = ?
                AND bh.start_time <= bh.end_time
                AND ? BETWEEN bh.start_time AND bh.end_time
            )
            OR
            (
                bh.day_of_week = ?
                AND bh.start_time > bh.end_time
                AND ( ? >= bh.start_time OR ? <= bh.end_time )
            )
            OR
            (
                bh.day_of_week = ?
                AND bh.start_time > bh.end_time
                AND ? <= bh.end_time
            )
        LIMIT 1
    ");
    $stmtOpen->execute([
        $current_day_es, $current_time,
        $current_day_es, $current_time, $current_time,
        $yesterday,      $current_time
    ]);
    $is_open = (bool)$stmtOpen->fetchColumn();
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
    <title>Nuestra Carta - Pizzería Arrabbiata</title>
    <link rel="icon" type="image/png" href="/favicon.png"/>
    <link rel="stylesheet" href="arrabbiata.css">
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

/* Secret image visibility control */
.secret-image {
  position: fixed;
  right: 15px;
  bottom: 60px;
  width: 88px;
  height: auto;
  opacity: 0;
  pointer-events: none;
  transition: opacity .35s ease;
  z-index: 1200;
}
.secret-image.visible {
  opacity: 1;
  pointer-events: auto;
}

</style>
</head>
<body>
    <?php if (!$is_open): ?>
        <div class="closed-notice active"><?php echo $closed_message; ?></div>
    <?php endif; ?>
    <section class="section">
        <div class="menu-container">
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
                                " . $visibilityWhere . "
                            ");
                            $stmt->execute(array_merge([$category['id']], $visParams));
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
                          " . $visibilityWhere . " 
                        ORDER BY mi.display_order
                    ");
                    $stmt->execute(array_merge([$category['id']], $visParams));
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

                                    <?php if ($item['is_weekly_special']): ?>
                                        <span class="weekly-special-label"><?php echo htmlspecialchars($item['weekly_special_text'] ?: '¡Destacado!'); ?></span>
                                    <?php endif; ?>

                                    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">

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

<a href="#category-7">
    <img id="secret-image" class="secret-image" src="https://arrabbiata.com.ar/Uploads/csgif.gif" alt="Carta Secreta" onclick="toggleSecretMode()">
</a>

    <div class="cart-container" id="cartContainer">
        <div class="cart-button <?php echo !$is_open ? 'disabled' : ''; ?>" <?php if ($is_open): ?>onclick="toggleCart()"<?php endif; ?>>
            🛒
            <div class="cart-badge" id="cartCount">0</div>
        </div>
    </div>
    <div class="overlay" id="overlay" onclick="closeCart()"></div>
    <div class="cart-modal" id="cartModal">
        <h3>Tu Pedido</h3>
        <div class="input-group">
            <input type="text" id="customerName" placeholder="Nombre para el pedido" required>
        </div>
        <div class="input-group">
            <div class="delivery-toggle">
                <button type="button" class="delivery-btn active" id="btnRetiro" onclick="setDelivery('retiro')">🏪 Retiro en local</button>
                <button type="button" class="delivery-btn" id="btnEnvio" onclick="setDelivery('envio')">🛵 Envío a domicilio</button>
            </div>
            <div id="addressGroup" style="display:none">
                <div class="address-row">
                    <input type="text" id="deliveryAddress" placeholder="Dirección de entrega (ej: Av. Colón 1234)">
                    <button type="button" id="btnVerificar" onclick="verificarDistancia()">Verificar</button>
                </div>
                <div id="deliveryFeedback" class="delivery-feedback" style="display:none"></div>
            </div>
        </div>
        <div class="input-group">
            <textarea id="extraComments" placeholder="Comentarios extra (opcional)" rows="3"></textarea>
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
                Pago con crédito tiene un recargo del 10%.
            </div>
            <div class="transfer-warning" id="transferWarning">
                Alias: <b>RRBB.PIZZA</b> <br>Nombre: Gustavo Emilio Muñoz.
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

    <div class="subproduct-modal" id="subproductModal">
        <h3>Selecciona tus pizzas</h3>
        <div id="subproductSelections"></div>
        <button onclick="confirmSubproducts()">Agregar al Carrito</button>
        <button onclick="closeSubproductModal()">Cancelar</button>
    </div>

    <div class="dependent-product-modal" id="dependentProductModal">
        <h3>Selecciona una pizza para tu <?php echo htmlspecialchars($item['name'] ?? 'item'); ?></h3>
        <div id="dependentProductSelection">
            <select id="dependentPizzaSelect">
                <option value="">Selecciona una pizza</option>
                <?php foreach ($eligible_items as $pizza): ?>
                    <option value="<?php echo $pizza['id']; ?>" data-name="<?php echo htmlspecialchars($pizza['name']); ?>" data-price="<?php echo $pizza['price']; ?>">
                        <?php echo htmlspecialchars($pizza['name']); ?> ($<?php echo number_format($pizza['price'], 2); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button onclick="confirmDependentProduct()">Agregar al Carrito</button>
        <button onclick="closeDependentProductModal()">Cancelar</button>
    </div>

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

        document.addEventListener('DOMContentLoaded', function() {
            updateCartUI();
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
        });

        let showImageInterval = null; // mover arriba, scope global

        function toggleSecretMode() {
            document.body.classList.toggle('secret-mode');
            const secretImage = document.getElementById('secret-image');

            if (document.body.classList.contains('secret-mode')) {
                // parar el intervalo y ocultar la imagen
                if (showImageInterval) {
                    clearInterval(showImageInterval);
                    showImageInterval = null;
                }
                secretImage.style.display = 'none';
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

        function addItemToCart(name, itemId, price, isVegan, hasSubproducts, requiredSelections, requiresPizza = false) {
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
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
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
            const cart = JSON.parse(localStorage.getItem('cart')) || [];
            const item = {
                id: itemId,
                name: name,
                price: price,
                quantity: 1,
                isVegan: isVegan,
                subproducts: selectedSubproducts,
                dependentPizza: dependentPizza
            };
            
            const existingItem = cart.find(cartItem => 
                cartItem.id === itemId && 
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
                itemCount += item.quantity;
                
                const cartItem = document.createElement('div');
                cartItem.className = 'cart-item';
                let displayName = item.name;
                if (item.subproducts.length > 0) {
                    displayName += ` (${item.subproducts.map(s => s.name).join(', ')})`;
                }
                if (item.dependentPizza) {
                    displayName += ` + ${item.dependentPizza.name}`;
                    itemTotal += item.dependentPizza.price * item.quantity;
                }
                cartItem.innerHTML = `
                    <span>${displayName} x${item.quantity}</span>
                    <span>$${itemTotal.toFixed(2)}</span>
                `;
                cartItems.appendChild(cartItem);
                total += itemTotal;
            });
            
            creditWarning.classList.remove('active');
            transferWarning.classList.remove('active');
            const prevShipping = cartItems.querySelector('.cart-shipping');
            if (prevShipping) prevShipping.remove();

            // Ítem de envío
            if (deliveryType === 'envio' && deliveryVerified && deliveryCost > 0) {
                const kmStr = deliveryKm.toFixed(1).replace('.', ',');
                const shippingRow = document.createElement('div');
                shippingRow.className = 'cart-item cart-shipping';
                shippingRow.innerHTML = `<span>🛵 Envío (${kmStr} km)</span><span>$${deliveryCost.toFixed(2)}</span>`;
                cartItems.appendChild(shippingRow);
                total += deliveryCost;
            }

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

        // ---- Coordenadas del local (27 de abril 798, Córdoba) ----
        const LOCAL_LAT = -31.41389;
        const LOCAL_LNG = -64.19514;

        function calcShipping(km) {
            if (km <= 2) return 2500;
            const extra = Math.ceil((km - 2) / 0.5);
            return 2500 + extra * 500;
        }

        let deliveryType     = 'retiro';
        let deliveryVerified = false;
        let deliveryKm       = 0;
        let deliveryCost     = 0;

        function setDelivery(type) {
            deliveryType = type;
            deliveryVerified = false;
            deliveryKm   = 0;
            deliveryCost = 0;
            document.getElementById('btnRetiro').classList.toggle('active', type === 'retiro');
            document.getElementById('btnEnvio').classList.toggle('active', type === 'envio');
            document.getElementById('addressGroup').style.display = type === 'envio' ? 'block' : 'none';
            if (type === 'retiro') {
                document.getElementById('deliveryAddress').value = '';
                hideFeedback();
            }
            updateCartUI();
        }

        function hideFeedback() {
            const fb = document.getElementById('deliveryFeedback');
            fb.style.display = 'none';
            fb.textContent = '';
            fb.className = 'delivery-feedback';
        }

        function showFeedback(msg, type) {
            const fb = document.getElementById('deliveryFeedback');
            fb.style.display = 'block';
            fb.textContent = msg;
            fb.className = 'delivery-feedback df-' + type;
        }

        async function verificarDistancia() {
            const address = document.getElementById('deliveryAddress').value.trim();
            if (!address) { showFeedback('Ingresá una dirección primero.', 'warn'); return; }

            const btn = document.getElementById('btnVerificar');
            btn.disabled = true;
            btn.textContent = '...';
            showFeedback('Buscando dirección…', 'loading');
            deliveryVerified = false;

            try {
                const query = encodeURIComponent(address + ', Córdoba, Argentina');
                const geoRes = await fetch(
                    `https://nominatim.openstreetmap.org/search?q=${query}&format=json&limit=1`,
                    { headers: { 'Accept-Language': 'es' } }
                );
                const geoData = await geoRes.json();

                if (!geoData.length) {
                    showFeedback('No encontramos esa dirección. Probá escribirla de otra forma.', 'error');
                    btn.disabled = false; btn.textContent = 'Verificar';
                    return;
                }

                const destLat = parseFloat(geoData[0].lat);
                const destLng = parseFloat(geoData[0].lon);

                const osrmRes = await fetch(
                    `https://router.project-osrm.org/route/v1/driving/${LOCAL_LNG},${LOCAL_LAT};${destLng},${destLat}?overview=false`
                );
                const osrmData = await osrmRes.json();

                if (osrmData.code !== 'Ok' || !osrmData.routes.length) {
                    showFeedback('No pudimos calcular la ruta. Intentá de nuevo.', 'error');
                    btn.disabled = false; btn.textContent = 'Verificar';
                    return;
                }

                deliveryKm   = osrmData.routes[0].distance / 1000;
                deliveryCost = calcShipping(deliveryKm);
                deliveryVerified = true;

                const kmStr = deliveryKm.toFixed(1).replace('.', ',');
                showFeedback(`📍 ${kmStr} km — Envío: $${deliveryCost.toLocaleString('es-AR')}`, 'ok');
                updateCartUI();

            } catch (e) {
                showFeedback('Error de conexión. Verificá tu internet e intentá de nuevo.', 'error');
            }

            btn.disabled = false;
            btn.textContent = 'Verificar';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const addrInput = document.getElementById('deliveryAddress');
            if (addrInput) {
                addrInput.addEventListener('input', function() {
                    if (deliveryVerified) {
                        deliveryVerified = false;
                        deliveryKm   = 0;
                        deliveryCost = 0;
                        hideFeedback();
                        updateCartUI();
                    }
                });
            }
        });

        function sendOrder() {
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
            const cart = JSON.parse(localStorage.getItem('cart')) || [];
            const customerName = document.getElementById('customerName').value.trim();
            const extraComments = document.getElementById('extraComments').value.trim();
            const paymentMethod = document.getElementById('paymentMethod').value;
            const address = document.getElementById('deliveryAddress')?.value.trim() || '';

            if (!customerName) { alert('Por favor ingresa tu nombre para el pedido'); return; }
            if (deliveryType === 'envio') {
                if (!address) { alert('Por favor ingresá la dirección de entrega'); return; }
                if (!deliveryVerified) { alert('Por favor verificá la dirección de entrega antes de enviar el pedido'); return; }
            }
            if (!paymentMethod) { alert('Por favor selecciona una forma de pago'); return; }
            if (cart.length === 0) { alert('El carrito está vacío'); return; }

            const kmStr  = deliveryKm.toFixed(1).replace('.', ',');
            const entrega = deliveryType === 'envio'
                ? `🛵 *Envío a domicilio* (${kmStr} km)\nDirección: ${address}`
                : `🏪 *Retiro en local* (27 de abril 798)`;

            let message = `Hola, soy *${customerName}* y quisiera hacer el siguiente pedido:\n\n`;
            let total = 0;
            cart.forEach(item => {
                let itemTotal = item.price * item.quantity;
                let displayName = item.name;
                if (item.subproducts.length > 0) {
                    displayName += ` (${item.subproducts.map(s => s.name).join(', ')})`;
                }
                if (item.dependentPizza) {
                    displayName += ` + ${item.dependentPizza.name}`;
                    itemTotal += item.dependentPizza.price * item.quantity;
                }
                message += `- ${displayName} x${item.quantity}: $${itemTotal.toFixed(2)}\n`;
                total += itemTotal;
            });

            if (deliveryType === 'envio' && deliveryVerified) {
                message += `- Envío a domicilio (${kmStr} km) x1: $${deliveryCost.toFixed(2)}\n`;
                total += deliveryCost;
            }

            if (paymentMethod === 'Crédito') {
                const surcharge = +(total * 0.10).toFixed(2);
                total += surcharge;
                message += `- Recargo TC x1: $${surcharge.toFixed(2)}\n`;
            } else if (paymentMethod === 'Transferencia') {
                message += `\nDatos para la transferencia: Alias: RRBB.PIZZA, Nombre: Gustavo Emilio Muñoz`;
            }

            if (extraComments) {
                message += `\n\n*Comentarios:* ${extraComments}`;
            }

            message += `\n\n${entrega}\nTotal: $${total.toFixed(2)}\nForma de pago: ${paymentMethod}`;

            window.open(`https://wa.me/5493517548030?text=${encodeURIComponent(message)}`, '_blank');
            clearCart();
        }

        function openSubproductModal(itemId, itemName, itemPrice, isVegan, requiredSelections) {
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
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

        function openDependentProductModal(itemId, itemName, itemPrice, isVegan) {
            if (!isOpen) {
                alert('Lo sentimos, estamos cerrados en este momento.');
                return;
            }
            currentDependentItem = { id: itemId, name: itemName, price: itemPrice, isVegan: isVegan };
            const modal = document.getElementById('dependentProductModal');
            const select = document.getElementById('dependentPizzaSelect');
            
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
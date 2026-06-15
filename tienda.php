<?php
session_start();
require_once 'db_connect.php';

// Configurar zona horaria y obtener día/hora actual (para visibilidad por franjas)
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

// ---- Helper: verificar si un ítem es visible ahora según sus franjas ----
// (misma lógica que carta.php / index.php; soporta formato nuevo {days,start,end})
if (!function_exists('isItemVisibleNow')) {
function isItemVisibleNow(array $item, string $currentDayEs, string $currentTime): bool {
    $json = $item['visible_days'] ?? null;

    if (!$json || $json === '[]' || $json === 'null') {
        $start = $item['visible_start_time'] ?? null;
        $end   = $item['visible_end_time']   ?? null;
        if (!$start && !$end) return true;
        if ($start && $end) {
            if ($start <= $end) return $currentTime >= $start && $currentTime <= $end;
            return $currentTime >= $start || $currentTime <= $end;
        }
        return true;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded) || empty($decoded)) return true;

    if (isset($decoded[0]['start'])) {
        foreach ($decoded as $franja) {
            $days  = $franja['days']  ?? [];
            $start = $franja['start'] ?? '';
            $end   = $franja['end']   ?? '';
            if (!in_array($currentDayEs, $days, true)) continue;
            if ($start === '' && $end === '') return true;
            if ($start !== '' && $end !== '') {
                if ($start <= $end) {
                    if ($currentTime >= $start && $currentTime <= $end) return true;
                } else {
                    if ($currentTime >= $start || $currentTime <= $end) return true;
                }
            } else {
                return true;
            }
        }
        return false;
    }

    if (!in_array($currentDayEs, $decoded, true)) return false;
    $start = $item['visible_start_time'] ?? null;
    $end   = $item['visible_end_time']   ?? null;
    if (!$start && !$end) return true;
    if ($start && $end) {
        if ($start <= $end) return $currentTime >= $start && $currentTime <= $end;
        return $currentTime >= $start || $currentTime <= $end;
    }
    return true;
}}

// La tienda está disponible siempre (no depende del horario del local)
$is_open = true;

// ---- Categorías de tienda ----
// Se consideran "de tienda" las categorías cuyo nombre contiene "Tienda".
// El admin agrega manualmente la categoría "Tienda" desde el editor.
$store_categories = [];
try {
    $cats_stmt = $pdo->query("SELECT * FROM categories ORDER BY display_order");
    $all_categories = $cats_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_categories as $c) {
        if (stripos($c['name'], 'Tienda') !== false) {
            $store_categories[] = $c;
        }
    }
} catch (PDOException $e) {
    $store_categories = [];
}

// ---- Pre-fetch de productos por categoría de tienda (con filtro de visibilidad) ----
$items_by_category = [];
try {
    $items_stmt = $pdo->prepare("
        SELECT mi.*
        FROM menu_items mi
        WHERE mi.category_id = ?
          AND mi.is_visible = 1
        ORDER BY mi.display_order
    ");
    foreach ($store_categories as $category) {
        $items_stmt->execute([$category['id']]);
        $all_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        $items_by_category[$category['id']] = array_values(array_filter(
            $all_items,
            fn($it) => isItemVisibleNow($it, $current_day_es, $current_time)
        ));
    }
} catch (PDOException $e) {
    error_log("Error pre-fetching productos de tienda: " . $e->getMessage());
    $items_by_category = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tienda - Pizzería Arrabbiata</title>
    <link rel="icon" type="image/png" href="/favicon.png"/>
    <link rel="stylesheet" href="arrabbiata.css">
    <style>
        /* ===== Estilos propios de la tienda (heredan la paleta del sitio) ===== */
        .store-cart-modal .checkout-section { margin-top: 14px; }

        .store-cart-modal .qty-controls {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .store-cart-modal .qty-controls button {
            width: 26px; height: 26px;
            border: 2px solid var(--azul-elec);
            background: #fff;
            color: var(--azul-elec);
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
            line-height: 1;
        }
        .store-cart-modal .qty-controls button:hover {
            background: var(--azul-elec);
            color: #fff;
        }
        .store-cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px dashed var(--ocre-d);
        }
        .store-cart-item .sci-name { flex: 1; font-weight: 600; }
        .store-empty-note {
            text-align: center;
            padding: 60px 20px;
            color: var(--marron);
            font-size: 1.1rem;
        }
        .store-empty-note strong { color: var(--rojo); }

        /* Aviso de stock / etiqueta de producto agotado */
        .store-soldout {
            opacity: .55;
            pointer-events: none;
        }
        .store-soldout-label {
            position: absolute;
            top: 8px; left: 8px;
            background: var(--rojo);
            color: #fff;
            font-size: .75rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 4px;
            z-index: 2;
        }
    </style>
</head>
<body>
    <nav class="nav-menu">
        <ul>
            <li><a href="index.php#home">Home</a></li>
            <li><a href="index.php#carta">Carta</a></li>
            <li><a href="tienda.php">Tienda</a></li>
            <li><a href="index.php#ubicacion">Ubicación</a></li>
        </ul>
    </nav>

    <section class="section">
        <div class="menu-container">
            <h2>Tienda</h2>

            <?php
            // ¿Hay al menos un producto en alguna categoría de tienda?
            $total_store_items = 0;
            foreach ($items_by_category as $arr) { $total_store_items += count($arr); }
            ?>

            <?php if (count($store_categories) > 1): ?>
            <nav class="category-submenu">
                <ul>
                    <?php foreach ($store_categories as $category): ?>
                        <?php if (count($items_by_category[$category['id']] ?? []) > 0): ?>
                            <li>
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
            <?php endif; ?>

            <?php if ($total_store_items === 0): ?>
                <div class="store-empty-note">
                    Todavía no hay productos en la tienda.<br>
                    <strong>Volvé pronto</strong> para ver las novedades.
                </div>
            <?php endif; ?>

            <?php foreach ($store_categories as $category): ?>
                <?php
                $items = $items_by_category[$category['id']] ?? [];
                if (count($items) > 0):
                ?>
                    <div id="category-<?php echo $category['id']; ?>" class="category-section">
                        <?php if (count($store_categories) > 1): ?>
                            <h3 class="menu-category"><?php echo htmlspecialchars($category['name']); ?></h3>
                        <?php endif; ?>
                        <div class="pizza-list">
                            <?php foreach ($items as $item): ?>
                                <?php
                                    // secondary_price se reinterpreta como stock opcional (si está en 0 => agotado).
                                    // Si no usás stock, dejalo en NULL y el producto siempre se puede comprar.
                                    $soldout = ($item['secondary_price'] !== null && (float)$item['secondary_price'] <= 0);
                                ?>
                                <div class="pizza-item <?php echo $soldout ? 'store-soldout' : ''; ?>"
                                     data-item-id="<?php echo $item['id']; ?>"
                                     data-item-name="<?php echo htmlspecialchars($item['name']); ?>"
                                     data-item-price="<?php echo $item['price']; ?>"
                                     data-item-image="<?php echo htmlspecialchars($item['image_url']); ?>">

                                    <div class="img-wrapper">
                                        <?php if ($soldout): ?>
                                            <span class="store-soldout-label">Agotado</span>
                                        <?php elseif ($item['is_weekly_special']): ?>
                                            <span class="weekly-special-label"><?php echo htmlspecialchars($item['weekly_special_text'] ?: '¡Destacado!'); ?></span>
                                        <?php endif; ?>
                                        <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                                    </div>

                                    <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                                    <p class="item-desc">
                                        <?php echo nl2br(htmlspecialchars($item['description'])); ?>
                                    </p>
                                    <div class="price-container">
                                        <p>$<span class="item-price" data-price="<?php echo $item['price']; ?>"><?php echo number_format($item['price'], 2); ?></span></p>
                                        <?php if (!$soldout): ?>
                                            <button class="vegan-button store-add-btn">🛒 Agregar</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ===== Carrito de tienda (separado del de la carta) ===== -->
    <div class="cart-container" id="storeCartContainer" style="display:none;">
        <div class="cart-button" onclick="toggleStoreCart()">
            🛍️
            <div class="cart-badge" id="storeCartCount">0</div>
        </div>
    </div>

    <div class="overlay" id="storeOverlay" onclick="closeStoreCart()"></div>

    <div class="cart-modal store-cart-modal" id="storeCartModal">
        <h3>Tu Compra</h3>

        <div class="cart-items" id="storeCartItems"></div>

        <div class="cart-total">
            Total: $<span id="storeCartTotal">0</span>
        </div>

        <div class="checkout-section">
            <div class="input-group">
                <input type="text" id="storeName" placeholder="Nombre y apellido" required>
            </div>
            <div class="input-group">
                <input type="email" id="storeEmail" placeholder="Email" required>
            </div>
            <div class="input-group">
                <input type="tel" id="storePhone" placeholder="Teléfono / WhatsApp" required>
            </div>
            <div class="input-group">
                <div class="delivery-toggle">
                    <button type="button" class="delivery-btn active" id="storeBtnRetiro" onclick="setStoreDelivery('retiro')">🏪 Retiro en local</button>
                    <button type="button" class="delivery-btn" id="storeBtnEnvio" onclick="setStoreDelivery('envio')">🛵 Envío a domicilio</button>
                </div>
                <div id="storeAddressGroup" style="display:none">
                    <input type="text" id="storeAddress" placeholder="Dirección de entrega">
                </div>
            </div>
        </div>

        <div class="cart-actions">
            <button class="clear-cart" onclick="clearStoreCart()">Vaciar</button>
            <button class="send-order" onclick="checkoutStore()">Pagar online</button>
        </div>
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
        const STORE_CART_KEY = 'store_cart';
        let storeDeliveryType = 'retiro';

        function getStoreCart() {
            return JSON.parse(localStorage.getItem(STORE_CART_KEY)) || [];
        }
        function setStoreCart(cart) {
            localStorage.setItem(STORE_CART_KEY, JSON.stringify(cart));
        }

        function scrollToCategory(id, event) {
            if (event) event.preventDefault();
            const el = document.getElementById(id);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function addStoreItem(id, name, price, image) {
            const cart = getStoreCart();
            const existing = cart.find(i => i.id === id);
            if (existing) {
                existing.quantity += 1;
            } else {
                cart.push({ id, name, price, image, quantity: 1 });
            }
            setStoreCart(cart);
            updateStoreCartUI();
            showStoreCartButton();
        }

        // Listener delegado: lee los datos del .pizza-item padre (robusto ante comillas en nombres)
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.store-add-btn');
            if (!btn) return;
            const card = btn.closest('.pizza-item');
            if (!card) return;
            addStoreItem(
                card.dataset.itemId,
                card.dataset.itemName,
                parseFloat(card.dataset.itemPrice),
                card.dataset.itemImage || ''
            );
        });

        function changeStoreQty(id, delta) {
            let cart = getStoreCart();
            const item = cart.find(i => i.id === id);
            if (!item) return;
            item.quantity += delta;
            if (item.quantity <= 0) {
                cart = cart.filter(i => i.id !== id);
            }
            setStoreCart(cart);
            updateStoreCartUI();
        }

        function updateStoreCartUI() {
            const cart = getStoreCart();
            const count = document.getElementById('storeCartCount');
            const totalEl = document.getElementById('storeCartTotal');
            const itemsEl = document.getElementById('storeCartItems');
            const container = document.getElementById('storeCartContainer');

            let total = 0, itemCount = 0;
            itemsEl.innerHTML = '';

            if (cart.length === 0) {
                itemsEl.innerHTML = '<p style="text-align:center; padding:14px 0;">Tu carrito está vacío.</p>';
            }

            cart.forEach(item => {
                const lineTotal = item.price * item.quantity;
                total += lineTotal;
                itemCount += item.quantity;

                const row = document.createElement('div');
                row.className = 'store-cart-item';
                row.innerHTML = `
                    <span class="sci-name">${item.name}</span>
                    <span class="qty-controls">
                        <button onclick="changeStoreQty('${item.id}', -1)">−</button>
                        <span>${item.quantity}</span>
                        <button onclick="changeStoreQty('${item.id}', 1)">+</button>
                    </span>
                    <span>$${lineTotal.toFixed(2)}</span>
                `;
                itemsEl.appendChild(row);
            });

            count.textContent = itemCount;
            totalEl.textContent = total.toFixed(2);
            container.style.display = itemCount > 0 ? 'block' : 'none';
        }

        function showStoreCartButton() {
            const container = document.getElementById('storeCartContainer');
            container.style.display = 'block';
            container.classList.add('active');
        }

        function toggleStoreCart() {
            const modal = document.getElementById('storeCartModal');
            const overlay = document.getElementById('storeOverlay');
            const isVisible = modal.style.display === 'block';
            modal.style.display = isVisible ? 'none' : 'block';
            overlay.style.display = isVisible ? 'none' : 'block';
            modal.classList.toggle('active', !isVisible);
            overlay.classList.toggle('active', !isVisible);
            updateStoreCartUI();
        }

        function closeStoreCart() {
            document.getElementById('storeCartModal').style.display = 'none';
            document.getElementById('storeOverlay').style.display = 'none';
            document.getElementById('storeCartModal').classList.remove('active');
            document.getElementById('storeOverlay').classList.remove('active');
        }

        function clearStoreCart() {
            localStorage.removeItem(STORE_CART_KEY);
            updateStoreCartUI();
        }

        function setStoreDelivery(type) {
            storeDeliveryType = type;
            document.getElementById('storeBtnRetiro').classList.toggle('active', type === 'retiro');
            document.getElementById('storeBtnEnvio').classList.toggle('active', type === 'envio');
            document.getElementById('storeAddressGroup').style.display = type === 'envio' ? 'block' : 'none';
            if (type === 'retiro') document.getElementById('storeAddress').value = '';
        }

        async function checkoutStore() {
            const cart = getStoreCart();
            if (cart.length === 0) { alert('Tu carrito está vacío.'); return; }

            const name = document.getElementById('storeName').value.trim();
            const email = document.getElementById('storeEmail').value.trim();
            const phone = document.getElementById('storePhone').value.trim();
            const address = document.getElementById('storeAddress')?.value.trim() || '';

            if (!name)  { alert('Ingresá tu nombre.'); return; }
            if (!email) { alert('Ingresá tu email.'); return; }
            if (!phone) { alert('Ingresá tu teléfono.'); return; }
            if (storeDeliveryType === 'envio' && !address) { alert('Ingresá la dirección de entrega.'); return; }

            const payload = {
                items: cart.map(i => ({ id: i.id, name: i.name, price: i.price, quantity: i.quantity })),
                customer: { name, email, phone },
                delivery: { type: storeDeliveryType, address }
            };

            const btn = document.querySelector('#storeCartModal .send-order');
            const prevText = btn ? btn.textContent : '';
            if (btn) { btn.disabled = true; btn.textContent = 'Generando pago...'; }

            try {
                const res = await fetch('tienda_checkout.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();

                if (data.ok && data.checkout_url) {
                    // Redirige a la pasarela (Mercado Pago) para completar el pago
                    localStorage.removeItem(STORE_CART_KEY);
                    window.location.href = data.checkout_url;
                } else {
                    alert(data.error || 'No se pudo iniciar el pago. Intentá de nuevo.');
                    if (btn) { btn.disabled = false; btn.textContent = prevText; }
                }
            } catch (err) {
                console.error(err);
                alert('Error de conexión al iniciar el pago.');
                if (btn) { btn.disabled = false; btn.textContent = prevText; }
            }
        }

        document.addEventListener('DOMContentLoaded', updateStoreCartUI);
    </script>
</body>
</html>

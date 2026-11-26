<?php
/**
 * _store_cart.php — Carrito de TIENDA (modal + JS), separado del de la carta.
 *
 * Compartido por tienda.php e index.php.
 * Usa localStorage 'store_cart' (no se mezcla con el carrito de pizzas).
 */
$__store_ship_cost = isset($store_shipping_cost) ? (float)$store_shipping_cost : 0.0;
?>
<!-- Botón flotante carrito de tienda -->
<div class="cart-container" id="storeCartContainer" style="display:none;">
    <div class="cart-button" onclick="toggleStoreCart()">
        🛍️
        <div class="cart-badge" id="storeCartCount">0</div>
    </div>
</div>

<div class="overlay" id="storeOverlay" onclick="closeStoreCart()"></div>

<div class="cart-modal store-cart-modal" id="storeCartModal">
    <div class="cart-modal-header">
        <h3>Tu Compra</h3>
        <button type="button" class="cart-modal-close" onclick="closeStoreCart()" aria-label="Cerrar ventana">✕</button>
    </div>

    <div class="cart-items" id="storeCartItems"></div>

    <div class="cart-total">
        Total: $<span id="storeCartTotal">0</span>
    </div>

    <div class="checkout-section">
        <div class="input-group">
            <input type="text" id="storeName" placeholder="Nombre y apellido" required autocomplete="name">
        </div>
        <div class="input-group">
            <input type="email" id="storeEmail" placeholder="Email (para el comprobante)" required autocomplete="email">
        </div>
        <div class="input-group">
            <input type="tel" id="storePhone" placeholder="Teléfono / WhatsApp" required autocomplete="tel">
        </div>
        <div class="input-group">
            <div class="delivery-toggle">
                <button type="button" class="delivery-btn active" id="storeBtnRetiro" onclick="setStoreDelivery('retiro')">🏪 Retiro en local</button>
                <button type="button" class="delivery-btn" id="storeBtnEnvio" onclick="setStoreDelivery('envio')">🛵 Envío a domicilio</button>
            </div>
            <div id="storeAddressGroup" style="display:none">
                <input type="text" id="storeAddress" placeholder="Dirección completa de entrega" autocomplete="street-address">
            </div>
            <div id="storeShippingNotice" class="store-shipping-note">
                <?php if ($__store_ship_cost > 0): ?>
                    🛵 Costo de envío a domicilio: <strong>$<?php echo fmtArs($__store_ship_cost); ?></strong> (sumado al total).
                <?php else: ?>
                    🛵 El costo del envío no está incluido en este total y se coordina por WhatsApp luego de la compra.
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="cart-actions">
        <button type="button" class="clear-cart" onclick="clearStoreCart()">Vaciar</button>
        <button type="button" class="send-order" onclick="checkoutStore()">Pagar online</button>
    </div>
</div>

<script>
    const STORE_CART_KEY      = 'store_cart';
    const STORE_SHIPPING_COST = <?php echo json_encode(round($__store_ship_cost, 2)); ?>;
    let storeDeliveryType     = 'retiro';

    function fmtStoreArs(amount) {
        if (typeof window.fmtArs === 'function') return window.fmtArs(amount);
        const n = Math.round((Number(amount) || 0) * 100) / 100;
        const digits = Number.isInteger(n) ? 0 : 2;
        return n.toLocaleString('es-AR', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    }

    function storeCartLineKey(item) {
        return String(item.id) + '::' + String(item.variantId || '');
    }

    function getStoreCart() {
        try {
            const raw = JSON.parse(localStorage.getItem(STORE_CART_KEY)) || [];
            return Array.isArray(raw) ? raw.filter(i => i && i.name && Number(i.price) >= 0) : [];
        } catch (e) {
            return [];
        }
    }
    function setStoreCart(cart) {
        localStorage.setItem(STORE_CART_KEY, JSON.stringify(cart));
    }

    if (typeof window.scrollToCategory !== 'function') {
        window.scrollToCategory = function (id, event) {
            if (event) event.preventDefault();
            const el = document.getElementById(id);
            if (el) {
                const navMenu = document.querySelector('.nav-menu');
                const submenu = document.querySelector('.category-submenu');
                const offset  = (submenu ? submenu.offsetHeight : 0) + (navMenu ? navMenu.offsetHeight + 16 : 8);
                const y = el.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
            }
        };
    }

    function selectStoreVariant(pill) {
        const picker = pill.closest('.store-variant-picker');
        if (!picker) return;
        picker.querySelectorAll('.store-variant-pill').forEach(p => p.classList.remove('active'));
        pill.classList.add('active');

        const price  = parseFloat(pill.dataset.variantPrice) || 0;
        const desc   = pill.dataset.variantDesc || '';
        const descEl = picker.querySelector('.variant-desc');
        if (descEl) descEl.textContent = desc;

        const card = pill.closest('.store-card');
        const priceEl = card ? (card.querySelector('.variant-current-price') || card.querySelector('.item-price')) : null;
        if (priceEl) {
            priceEl.dataset.price = price;
            priceEl.textContent = fmtStoreArs(price);
        }
    }

    function addStoreItem(id, name, price, image, variantId = '') {
        const cart = getStoreCart();
        const key  = String(id) + '::' + String(variantId || '');
        const existing = cart.find(i => storeCartLineKey(i) === key);
        if (existing) {
            existing.quantity = (Number(existing.quantity) || 1) + 1;
        } else {
            cart.push({
                id: String(id),
                variantId: variantId || '',
                name: name,
                price: Number(price) || 0,
                image: image || '',
                quantity: 1
            });
        }
        setStoreCart(cart);
        updateStoreCartUI();
        showStoreCartButton();

        if (typeof window.rrbbTrack === 'function') {
            window.rrbbTrack('add_to_cart', {
                id: variantId ? `${id}_${variantId}` : String(id),
                name: name,
                price: Number(price) || 0,
                quantity: 1
            });
        }
    }

    document.addEventListener('click', function (e) {
        const pill = e.target.closest('.store-variant-pill');
        if (pill) return;

        const card = e.target.closest('.store-card');
        if (!card || card.classList.contains('store-soldout')) return;

        const activeVar = card.querySelector('.store-variant-pill.active') || card.querySelector('.store-variant-pill');
        let itemName  = card.dataset.itemName || '';
        let itemPrice = parseFloat(card.dataset.itemPrice) || 0;
        let variantId = '';

        if (activeVar) {
            itemPrice = parseFloat(activeVar.dataset.variantPrice) || 0;
            const vName = activeVar.dataset.variantName || '';
            variantId = activeVar.dataset.variantId ? ('v' + activeVar.dataset.variantId) : '';
            if (vName) itemName = `${itemName} — ${vName}`;
        }

        addStoreItem(card.dataset.itemId, itemName, itemPrice, card.dataset.itemImage || '', variantId);

        const toast = card.querySelector('.added-toast');
        if (toast) {
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 1200);
        }
    });

    function changeStoreQty(lineKey, delta) {
        let cart = getStoreCart();
        const item = cart.find(i => storeCartLineKey(i) === lineKey);
        if (!item) return;
        item.quantity = (Number(item.quantity) || 1) + delta;
        if (item.quantity <= 0) {
            cart = cart.filter(i => storeCartLineKey(i) !== lineKey);
        }
        setStoreCart(cart);
        updateStoreCartUI();
        if (cart.length === 0) closeStoreCart();
    }

    function updateStoreCartUI() {
        const cart      = getStoreCart();
        const count     = document.getElementById('storeCartCount');
        const totalEl   = document.getElementById('storeCartTotal');
        const itemsEl   = document.getElementById('storeCartItems');
        const container = document.getElementById('storeCartContainer');
        if (!count || !totalEl || !itemsEl || !container) return;

        let total = 0, itemCount = 0;
        itemsEl.innerHTML = '';

        if (cart.length === 0) {
            itemsEl.innerHTML = '<p style="text-align:center; padding:14px 0;">Tu carrito está vacío.</p>';
        }

        cart.forEach(item => {
            const qty       = Math.max(1, Number(item.quantity) || 1);
            const lineTotal = (Number(item.price) || 0) * qty;
            const lineKey   = storeCartLineKey(item);
            total += lineTotal;
            itemCount += qty;

            const row = document.createElement('div');
            row.className = 'store-cart-item';

            const sciName = document.createElement('span');
            sciName.className = 'sci-name';
            sciName.textContent = item.name;

            const qtyWrap = document.createElement('span');
            qtyWrap.className = 'qty-controls';
            const btnMinus = document.createElement('button');
            btnMinus.type = 'button';
            btnMinus.textContent = '−';
            btnMinus.onclick = () => changeStoreQty(lineKey, -1);
            const qtySpan = document.createElement('span');
            qtySpan.textContent = String(qty);
            const btnPlus = document.createElement('button');
            btnPlus.type = 'button';
            btnPlus.textContent = '+';
            btnPlus.onclick = () => changeStoreQty(lineKey, 1);
            qtyWrap.append(btnMinus, qtySpan, btnPlus);

            const priceSpan = document.createElement('span');
            priceSpan.style.fontWeight = '700';
            priceSpan.textContent = '$' + fmtStoreArs(lineTotal);

            row.append(sciName, qtyWrap, priceSpan);
            itemsEl.appendChild(row);
        });

        if (cart.length > 0 && storeDeliveryType === 'envio' && STORE_SHIPPING_COST > 0) {
            const shipRow = document.createElement('div');
            shipRow.className = 'store-cart-item store-shipping-row';
            shipRow.innerHTML = '<span class="sci-name">🛵 Envío a domicilio</span><span>$' + fmtStoreArs(STORE_SHIPPING_COST) + '</span>';
            itemsEl.appendChild(shipRow);
            total += STORE_SHIPPING_COST;
        }

        count.textContent = itemCount;
        totalEl.textContent = fmtStoreArs(total);
        container.style.display = itemCount > 0 ? 'block' : 'none';
    }

    function showStoreCartButton() {
        const container = document.getElementById('storeCartContainer');
        if (!container) return;
        container.style.display = 'block';
        container.classList.add('active');
    }

    function toggleStoreCart() {
        const modal   = document.getElementById('storeCartModal');
        const overlay = document.getElementById('storeOverlay');
        if (!modal || !overlay) return;
        const isVisible = modal.classList.contains('active') || modal.style.display === 'block';
        modal.style.display   = isVisible ? 'none' : 'block';
        overlay.style.display = isVisible ? 'none' : 'block';
        modal.classList.toggle('active', !isVisible);
        overlay.classList.toggle('active', !isVisible);
        updateStoreCartUI();

        if (!isVisible && typeof window.rrbbTrack === 'function') {
            const cart = getStoreCart();
            if (cart.length > 0) {
                const total = cart.reduce((s, i) => s + (Number(i.price) || 0) * (Number(i.quantity) || 1), 0);
                const count = cart.reduce((s, i) => s + (Number(i.quantity) || 1), 0);
                window.rrbbTrack('begin_checkout', {
                    total: total,
                    itemCount: count,
                    items: cart.map(i => ({ item_id: String(i.id), item_name: i.name, price: Number(i.price) || 0, quantity: Number(i.quantity) || 1 }))
                });
            }
        }
    }

    function closeStoreCart() {
        const modal   = document.getElementById('storeCartModal');
        const overlay = document.getElementById('storeOverlay');
        if (modal)   { modal.style.display = 'none'; modal.classList.remove('active'); }
        if (overlay) { overlay.style.display = 'none'; overlay.classList.remove('active'); }
    }

    function clearStoreCart() {
        localStorage.removeItem(STORE_CART_KEY);
        updateStoreCartUI();
        closeStoreCart();
    }

    function setStoreDelivery(type) {
        storeDeliveryType = (type === 'envio') ? 'envio' : 'retiro';
        const btnR = document.getElementById('storeBtnRetiro');
        const btnE = document.getElementById('storeBtnEnvio');
        const addr = document.getElementById('storeAddressGroup');
        const note = document.getElementById('storeShippingNotice');
        if (btnR) btnR.classList.toggle('active', storeDeliveryType === 'retiro');
        if (btnE) btnE.classList.toggle('active', storeDeliveryType === 'envio');
        if (addr) addr.style.display = storeDeliveryType === 'envio' ? 'block' : 'none';
        if (note) note.style.display = storeDeliveryType === 'envio' ? 'block' : 'none';
        updateStoreCartUI();
    }

    async function checkoutStore() {
        const cart = getStoreCart();
        if (cart.length === 0) { alert('Tu carrito está vacío.'); return; }

        const name    = document.getElementById('storeName').value.trim();
        const email   = document.getElementById('storeEmail').value.trim();
        const phone   = document.getElementById('storePhone').value.trim();
        const address = document.getElementById('storeAddress')?.value.trim() || '';

        if (!name)  { alert('Ingresá tu nombre y apellido.'); return; }
        if (!email) { alert('Ingresá tu email.'); return; }
        if (!phone) { alert('Ingresá tu teléfono o WhatsApp.'); return; }
        if (storeDeliveryType === 'envio' && !address) { alert('Ingresá la dirección completa de entrega.'); return; }

        try {
            localStorage.setItem('rrbb_customer_name', name);
            localStorage.setItem('rrbb_customer_email', email);
            localStorage.setItem('rrbb_customer_phone', phone);
            if (address) localStorage.setItem('rrbb_customer_address', address);
        } catch (e) {}

        const payload = {
            items: cart.map(i => ({
                id: i.id,
                variantId: i.variantId || '',
                name: i.name,
                price: i.price,
                quantity: i.quantity
            })),
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
                try {
                    sessionStorage.setItem('rrbb_pending_store_order', JSON.stringify({
                        customer: { name, email, phone },
                        delivery: { type: storeDeliveryType, address },
                        items: cart.map(i => ({
                            name: i.name,
                            qty: i.quantity,
                            price: i.price,
                            item_id: String(i.id),
                            item_name: i.name,
                            quantity: i.quantity
                        }))
                    }));
                } catch (e) {}
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

    document.addEventListener('DOMContentLoaded', function() {
        try {
            const sName  = localStorage.getItem('rrbb_customer_name')    || '';
            const sEmail = localStorage.getItem('rrbb_customer_email')   || '';
            const sPhone = localStorage.getItem('rrbb_customer_phone')   || '';
            const sAddr  = localStorage.getItem('rrbb_customer_address') || '';
            if (sName  && document.getElementById('storeName'))    document.getElementById('storeName').value    = sName;
            if (sEmail && document.getElementById('storeEmail'))   document.getElementById('storeEmail').value   = sEmail;
            if (sPhone && document.getElementById('storePhone'))   document.getElementById('storePhone').value   = sPhone;
            if (sAddr  && document.getElementById('storeAddress')) document.getElementById('storeAddress').value = sAddr;
        } catch (e) {}
        updateStoreCartUI();
    });
</script>

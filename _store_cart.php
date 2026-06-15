<?php
/**
 * _store_cart.php — Carrito de TIENDA (modal + JS), separado del de la carta.
 *
 * Compartido por tienda.php e index.php, así el comportamiento del carrito de
 * tienda es idéntico en la página propia y en la sección integrada.
 *
 * Usa localStorage 'store_cart' (no se mezcla con el carrito de pizzas).
 * Checkout online vía tienda_checkout.php (Mercado Pago / modo demo).
 */
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

<script>
    const STORE_CART_KEY = 'store_cart';
    let storeDeliveryType = 'retiro';

    function getStoreCart() {
        return JSON.parse(localStorage.getItem(STORE_CART_KEY)) || [];
    }
    function setStoreCart(cart) {
        localStorage.setItem(STORE_CART_KEY, JSON.stringify(cart));
    }

    // scrollToCategory puede ya existir (index/carta lo definen). Si no, lo creamos.
    if (typeof window.scrollToCategory !== 'function') {
        window.scrollToCategory = function (id, event) {
            if (event) event.preventDefault();
            const el = document.getElementById(id);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };
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

    // Listener delegado: lee del .pizza-item padre (robusto ante comillas en nombres)
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
        if (!count || !totalEl || !itemsEl || !container) return;

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
        if (!container) return;
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

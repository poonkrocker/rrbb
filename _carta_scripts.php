<?php
/**
 * _carta_scripts.php — Lógica JS unificada de Carta, Carrito, Variantes, Extras,
 *                      Autocompletado de cliente, Selector de horario ("Ahora" + turnos)
 *                      y Eventos de Analytics / Pizzalog.
 *
 * Compartido por index.php (Home) y carta/index.php (Subdominio /carta).
 */
?>
<script>
    const isOpen     = <?php echo json_encode((bool)$is_open); ?>;
    const isPreorder = !isOpen;

    const SHIFT_START     = <?php echo $shift_start_iso ? json_encode($shift_start_iso) : 'null'; ?>;
    const SHIFT_END       = <?php echo $shift_end_iso   ? json_encode($shift_end_iso)   : 'null'; ?>;
    const PREP_BUFFER_MIN = 15;
    const SLOT_MIN        = 15;
    let selectedPickupTime = null;
    let deliveryType       = 'retiro';
    let showImageInterval  = null;

    const TRANSFER_ALIAS  = <?php echo json_encode($transfer_alias); ?>;
    const TRANSFER_HOLDER = <?php echo json_encode($transfer_holder); ?>;

    function escHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function normalizePay(str) {
        return String(str || '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .trim().toLowerCase();
    }

    function restoreSavedCustomerData() {
        try {
            const savedName  = localStorage.getItem('rrbb_customer_name')    || '';
            const savedPhone = localStorage.getItem('rrbb_customer_phone')   || '';
            const savedAddr  = localStorage.getItem('rrbb_customer_address') || '';
            const savedDeliv = localStorage.getItem('rrbb_delivery_type')    || '';

            const nameEl  = document.getElementById('customerName');
            const phoneEl = document.getElementById('customerPhone');
            const addrEl  = document.getElementById('deliveryAddress');
            const notice  = document.getElementById('savedCustomerNotice');

            let restoredAny = false;
            if (nameEl && !nameEl.value && savedName)   { nameEl.value = savedName; restoredAny = true; }
            if (phoneEl && !phoneEl.value && savedPhone){ phoneEl.value = savedPhone; restoredAny = true; }
            if (addrEl && !addrEl.value && savedAddr)   { addrEl.value = savedAddr; }

            if (savedDeliv === 'envio' && savedAddr) {
                setDelivery('envio');
                if (typeof window.applyEnvioPaymentRestriction === 'function') {
                    window.applyEnvioPaymentRestriction();
                }
            }
            if (notice && restoredAny) {
                notice.style.display = 'block';
            }
        } catch (e) {}
    }

    document.addEventListener('DOMContentLoaded', function() {
        restoreSavedCustomerData();
        updateCartUI();

        const paymentMethodSelect = document.getElementById('paymentMethod');
        if (paymentMethodSelect) {
            paymentMethodSelect.addEventListener('change', updateCartUI);
        }

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
            if (showImageInterval) {
                clearInterval(showImageInterval);
                showImageInterval = null;
            }
            if (secretImage) secretImage.style.display = 'none';
            const secretSec = document.querySelector('.secret-category') || document.querySelector('.secret-item');
            if (secretSec) secretSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function scrollToCategory(categoryId, ev) {
        if (ev && ev.preventDefault) ev.preventDefault();
        const element = document.getElementById(categoryId);
        if (element) {
            const submenu = document.querySelector('.category-submenu');
            const navMenu = document.querySelector('.nav-menu');
            const offset = (submenu ? submenu.offsetHeight : 0) + (navMenu ? navMenu.offsetHeight + 16 : 8);
            const y = element.getBoundingClientRect().top + window.pageYOffset - offset;
            window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
        }
    }

    // ── Selección de pills de variantes (1 a 3 variantes) y extras ──
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
            card.querySelectorAll('.extra-pill.active').forEach(ep => {
                extrasTotal += parseFloat(ep.dataset.extraPrice) || 0;
            });
        }

        const finalPrice = variantPrice + extrasTotal;
        const priceEl = picker.querySelector('.variant-current-price') || (card ? card.querySelector('.item-price') : null);
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

        const activeVariant = card.querySelector('.variant-pill.active') || card.querySelector('.variant-pill');
        const basePrice = activeVariant
            ? (parseFloat(activeVariant.dataset.variantPrice) || 0)
            : (parseFloat(card.dataset.itemPrice) || 0);

        let extrasTotal = 0;
        card.querySelectorAll('.extra-pill.active').forEach(pill => {
            extrasTotal += parseFloat(pill.dataset.extraPrice) || 0;
        });

        const finalPrice = basePrice + extrasTotal;
        const priceSpan = card.querySelector('.variant-current-price') || card.querySelector('.item-price');
        if (priceSpan) {
            priceSpan.textContent = finalPrice.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }

    function addCardItemToCart(card, isVegan = false) {
        if (!card) return;
        const baseName = card.dataset.itemName || '';
        const itemId   = card.dataset.itemId || '';

        const activeVariant = card.querySelector('.variant-pill.active') || card.querySelector('.variant-pill');
        let basePrice = 0;
        let itemDisplayName = baseName;
        let variantId = null;

        if (activeVariant) {
            basePrice = parseFloat(activeVariant.dataset.variantPrice) || 0;
            const vName = activeVariant.dataset.variantName || '';
            variantId = activeVariant.dataset.variantId ? ('v' + activeVariant.dataset.variantId) : null;
            itemDisplayName = `${baseName} — ${vName}`;
        } else {
            basePrice = parseFloat(card.dataset.itemPrice) || 0;
        }

        const activePills = card.querySelectorAll('.extra-pill.active');
        const selectedExtras = [];
        let extrasTotal = 0;
        activePills.forEach(pill => {
            const extraName  = pill.dataset.extraName || '';
            const extraPrice = parseFloat(pill.dataset.extraPrice) || 0;
            if (extraName) selectedExtras.push(extraName);
            extrasTotal += extraPrice;
        });

        let displayName = isVegan ? `${itemDisplayName} (Vegana)` : itemDisplayName;
        if (selectedExtras.length > 0) {
            displayName += ` (+ ${selectedExtras.join(', + ')})`;
        }

        const finalPrice = basePrice + extrasTotal;
        addToCart(displayName, finalPrice, String(itemId), Boolean(isVegan), variantId);

        const toast = card.querySelector('.added-toast');
        if (toast) {
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 1200);
        }

        if (activePills.length > 0) {
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
    }

    function getCleanCart() {
        try {
            const raw = JSON.parse(localStorage.getItem('cart')) || [];
            if (!Array.isArray(raw)) return [];
            return raw.filter(it => it && typeof it.name === 'string' && Number(it.price) >= 0);
        } catch (e) {
            return [];
        }
    }

    function addToCart(name, price, itemId, isVegan = false, variantId = null) {
        const cart = getCleanCart();
        const unitPrice = Number(price) || 0;

        const existingItem = cart.find(cartItem =>
            String(cartItem.id) === String(itemId) &&
            cartItem.name === name &&
            Boolean(cartItem.isVegan) === Boolean(isVegan) &&
            (cartItem.variantId ?? null) === (variantId ?? null)
        );

        if (existingItem) {
            existingItem.quantity = (Number(existingItem.quantity) || 1) + 1;
        } else {
            cart.push({
                id: String(itemId),
                variantId: variantId,
                name: name,
                price: unitPrice,
                quantity: 1,
                isVegan: Boolean(isVegan)
            });
        }

        localStorage.setItem('cart', JSON.stringify(cart));
        updateCartUI();
        showCartButton();

        if (typeof window.rrbbTrack === 'function') {
            window.rrbbTrack('add_to_cart', {
                id: variantId ? `${itemId}_${variantId}` : String(itemId),
                name: name,
                price: unitPrice,
                quantity: 1
            });
        }
    }

    function removeCartItem(index) {
        const cart = getCleanCart();
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
        const cart = getCleanCart();
        const cartCount       = document.getElementById('cartCount');
        const cartTotal       = document.getElementById('cartTotal');
        const cartItems       = document.getElementById('cartItems');
        const cartContainer   = document.getElementById('cartContainer');
        const paymentMethodEl = document.getElementById('paymentMethod');
        const creditWarning   = document.getElementById('creditWarning');
        const transferWarning = document.getElementById('transferWarning');
        if (!cartCount || !cartTotal || !cartItems || !cartContainer) return;

        const paymentMethod = paymentMethodEl ? paymentMethodEl.value : '';
        let total = 0;
        let itemCount = 0;

        cartItems.innerHTML = '';
        cart.forEach((item, index) => {
            const qty       = Math.max(1, Number(item.quantity) || 1);
            const unitPrice = Number(item.price) || 0;
            const itemTotal = unitPrice * qty;
            itemCount += qty;
            total += itemTotal;

            const safeLabel = escHtml(item.name);
            const cartItem = document.createElement('div');
            cartItem.className = 'cart-item';
            cartItem.innerHTML = `
                <div class="cart-item-info">
                    <button type="button" class="cart-item-remove" onclick="removeCartItem(${index})" title="Eliminar este ítem" aria-label="Eliminar ${safeLabel}">✕</button>
                    <span class="cart-item-name">${safeLabel} x${qty}</span>
                </div>
                <span class="cart-item-price">$${itemTotal.toFixed(2)}</span>
            `;
            cartItems.appendChild(cartItem);
        });

        if (creditWarning) creditWarning.classList.remove('active');
        if (transferWarning) transferWarning.classList.remove('active');

        const pm = normalizePay(paymentMethod);
        if (pm === 'transferencia' && transferWarning) {
            transferWarning.classList.add('active');
        } else if (pm === 'credito' && creditWarning) {
            creditWarning.classList.add('active');
            const surcharge = Number((total * 0.10).toFixed(2));
            const surchargeRow = document.createElement('div');
            surchargeRow.className = 'cart-item cart-surcharge';
            surchargeRow.innerHTML = `<span class="cart-item-name">Recargo Tarjeta Crédito (10%)</span><span class="cart-item-price">$${surcharge.toFixed(2)}</span>`;
            cartItems.appendChild(surchargeRow);
            total += surcharge;
        }

        cartCount.textContent = itemCount;
        cartTotal.textContent = total.toFixed(2);
        cartContainer.style.display = itemCount > 0 ? 'block' : 'none';
    }

    function showCartButton() {
        const cartContainer = document.getElementById('cartContainer');
        if (!cartContainer) return;
        cartContainer.style.display = 'block';
        cartContainer.classList.add('active');
    }

    function toggleCart(ev) {
        if (ev && ev.preventDefault) ev.preventDefault();
        if (ev && ev.stopPropagation) ev.stopPropagation();
        const modal   = document.getElementById('cartModal');
        const overlay = document.getElementById('overlay');
        if (!modal || !overlay) return;

        const isVisible = modal.classList.contains('active') || modal.style.display === 'block';
        modal.style.display   = isVisible ? 'none' : 'block';
        overlay.style.display = isVisible ? 'none' : 'block';
        modal.classList.toggle('active', !isVisible);
        overlay.classList.toggle('active', !isVisible);
        updateCartUI();

        if (!isVisible && typeof window.rrbbTrack === 'function') {
            const cart = getCleanCart();
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

    function closeCart(ev) {
        if (ev && ev.preventDefault) ev.preventDefault();
        if (ev && ev.stopPropagation) ev.stopPropagation();
        const modal   = document.getElementById('cartModal');
        const overlay = document.getElementById('overlay');
        if (modal)   { modal.style.display = 'none'; modal.classList.remove('active'); }
        if (overlay) { overlay.style.display = 'none'; overlay.classList.remove('active'); }
    }

    function clearCart() {
        localStorage.removeItem('cart');
        updateCartUI();
        closeCart();
    }

    function setDelivery(type) {
        deliveryType = (type === 'envio') ? 'envio' : 'retiro';
        const btnRetiro = document.getElementById('btnRetiro');
        const btnEnvio  = document.getElementById('btnEnvio');
        const addrGroup = document.getElementById('addressGroup');
        if (btnRetiro) btnRetiro.classList.toggle('active', deliveryType === 'retiro');
        if (btnEnvio)  btnEnvio.classList.toggle('active', deliveryType === 'envio');
        if (addrGroup) addrGroup.style.display = deliveryType === 'envio' ? 'block' : 'none';
        try { localStorage.setItem('rrbb_delivery_type', deliveryType); } catch (e) {}
    }

    // ── Selector de Horario ("Ahora" + todos los turnos de 15') ──
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

    function createPickupSlotBtn(label, value, isAsap = false, preselect = false) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pickup-slot' + (isAsap ? ' pickup-slot-asap' : '');
        btn.textContent = label;
        btn.dataset.time = value;
        btn.onclick = () => selectPickupSlot(btn);
        if (preselect) {
            btn.classList.add('selected');
            selectedPickupTime = value;
        }
        return btn;
    }

    function buildTimeSlots() {
        const slotsWrap  = document.getElementById('pickupTimeSlots');
        const sub        = document.getElementById('pickupTimeSub');
        const confirmBtn = document.getElementById('pickupConfirmBtn');
        if (!slotsWrap || !sub) return;

        slotsWrap.innerHTML = '';
        selectedPickupTime = null;
        if (confirmBtn) confirmBtn.disabled = true;

        // Si el local está ABIERTO ahora, siempre ofrecemos "Ahora" arriba de los horarios
        if (isOpen) {
            const asapBtn = createPickupSlotBtn('⚡ Ahora (lo antes posible)', 'Ahora', true, true);
            slotsWrap.appendChild(asapBtn);
            if (confirmBtn) confirmBtn.disabled = false;
        }

        if (!SHIFT_END) {
            if (!isOpen) {
                const coordBtn = createPickupSlotBtn('Apenas abran (coordinar horario)', 'Apenas abran', true, true);
                slotsWrap.appendChild(coordBtn);
                if (confirmBtn) confirmBtn.disabled = false;
                sub.textContent = 'Estamos cerrados ahora. Dejanos tu pedido y coordinamos el horario:';
            } else {
                sub.textContent = 'Pedilo para ahora o coordinamos por WhatsApp:';
            }
            return;
        }

        const now        = new Date();
        const shiftStart = SHIFT_START ? new Date(SHIFT_START) : now;
        const shiftEnd   = new Date(SHIFT_END);

        let earliest = new Date(now.getTime() + PREP_BUFFER_MIN * 60000);
        if (earliest < shiftStart) earliest = new Date(shiftStart.getTime());
        earliest = ceilToSlot(earliest, SLOT_MIN);

        const slots = [];
        for (let t = new Date(earliest); t <= shiftEnd; t = new Date(t.getTime() + SLOT_MIN * 60000)) {
            slots.push(new Date(t));
        }

        if (slots.length === 0) {
            if (!isOpen) {
                const coordBtn = createPickupSlotBtn('Apenas abran (coordinar horario)', 'Apenas abran', true, true);
                slotsWrap.appendChild(coordBtn);
                if (confirmBtn) confirmBtn.disabled = false;
                sub.textContent = 'Estamos cerrados ahora. Dejanos tu pedido y coordinamos el horario:';
            } else {
                sub.textContent = 'Estamos en el último tramo del turno; pedilo para ahora mismo:';
            }
            return;
        }

        const showDay = isPreorder && earliest.toDateString() !== now.toDateString();
        sub.textContent = isPreorder
            ? 'Estamos cerrados ahora. Elegí para qué horario del próximo turno querés tu pedido:'
            : 'Elegí «Ahora» o seleccioná un horario específico de este turno:';

        slots.forEach(slot => {
            const formatted = fmtHM(slot, showDay);
            slotsWrap.appendChild(createPickupSlotBtn(formatted, formatted, false, false));
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
        const modal     = document.getElementById('pickupTimeModal');
        const overlay   = document.getElementById('overlay');
        if (cartModal) { cartModal.style.display = 'none'; cartModal.classList.remove('active'); }
        if (modal)     { modal.style.display = 'block'; modal.classList.add('active'); }
        if (overlay)   { overlay.style.display = 'block'; overlay.classList.add('active'); }
    }

    function closePickupTimeModal(returnToCart = true) {
        const modal = document.getElementById('pickupTimeModal');
        if (modal) { modal.style.display = 'none'; modal.classList.remove('active'); }
        if (returnToCart) {
            const cartModal = document.getElementById('cartModal');
            if (cartModal) { cartModal.style.display = 'block'; cartModal.classList.add('active'); }
        } else {
            const overlay = document.getElementById('overlay');
            if (overlay) { overlay.style.display = 'none'; overlay.classList.remove('active'); }
        }
    }

    function onOverlayClick() {
        const varModal = document.getElementById('variantModal');
        if (varModal && varModal.classList.contains('active')) {
            closeVariantModal();
            return;
        }
        const pickup = document.getElementById('pickupTimeModal');
        if (pickup && pickup.classList.contains('active')) {
            closePickupTimeModal(true);
            return;
        }
        closeCart();
    }

    function confirmPickupAndSend() {
        if (!selectedPickupTime) {
            alert('Elegí un horario para tu pedido');
            return;
        }
        closePickupTimeModal(false);
        submitOrder(selectedPickupTime);
    }

    function sendOrder() {
        const cart          = getCleanCart();
        const customerName  = document.getElementById('customerName').value.trim();
        const customerPhone = document.getElementById('customerPhone')?.value.trim() || '';
        const paymentMethod = document.getElementById('paymentMethod').value;
        const address       = document.getElementById('deliveryAddress')?.value.trim() || '';

        if (!customerName) { alert('Por favor ingresá tu nombre para el pedido'); return; }
        if (!customerPhone) { alert('Por favor ingresá tu teléfono o WhatsApp'); return; }
        if (deliveryType === 'envio' && !address) { alert('Por favor ingresá la dirección de entrega'); return; }
        if (!paymentMethod) { alert('Por favor seleccioná una forma de pago'); return; }
        if (cart.length === 0) { alert('El carrito está vacío'); return; }

        try {
            localStorage.setItem('rrbb_customer_name', customerName);
            localStorage.setItem('rrbb_customer_phone', customerPhone);
            localStorage.setItem('rrbb_delivery_type', deliveryType);
            if (address) localStorage.setItem('rrbb_customer_address', address);
        } catch (e) {}

        if (typeof window.rrbbTrack === 'function') {
            const subTotal = cart.reduce((s, i) => s + (Number(i.price) || 0) * (Number(i.quantity) || 1), 0);
            window.rrbbTrack('open_pickup_slot', {
                total: subTotal,
                deliveryType: deliveryType,
                paymentMethod: paymentMethod
            });
        }

        openPickupTimeModal();
    }

    function generateOrderCode() {
        const d = new Date();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        const rnd = Math.random().toString(36).substring(2, 6).toUpperCase();
        return `RB-${mm}${dd}-${rnd}`;
    }

    function submitOrder(pickupTime) {
        const cart          = getCleanCart();
        const customerName  = document.getElementById('customerName').value.trim();
        const customerPhone = document.getElementById('customerPhone')?.value.trim() || '';
        const extraComments = document.getElementById('extraComments')?.value.trim() || '';
        const paymentMethod = document.getElementById('paymentMethod').value;
        const address       = document.getElementById('deliveryAddress')?.value.trim() || '';

        if (!customerName || !paymentMethod || cart.length === 0) return;

        const orderCode = generateOrderCode();
        const entrega = deliveryType === 'envio'
            ? `🛵 *Envío a domicilio*\nDirección: ${address}`
            : `🏪 *Retiro en local* (27 de abril 798)`;

        let message = `Hola, soy *${customerName}*`;
        if (customerPhone) message += ` (${customerPhone})`;
        message += ` y quisiera hacer el pedido *#${orderCode}*:\n\n`;

        let total = 0;
        let totalUnits = 0;
        const pizzalogItems = [];
        const gaItems = [];

        cart.forEach(item => {
            const qty       = Math.max(1, Number(item.quantity) || 1);
            const unitPrice = Number(item.price) || 0;
            const itemTotal = unitPrice * qty;
            message += `- ${item.name} x${qty}: $${itemTotal.toFixed(2)}\n`;
            total += itemTotal;
            totalUnits += qty;

            pizzalogItems.push({ name: item.name, qty: qty, price: unitPrice });
            gaItems.push({ item_id: String(item.id || item.name), item_name: item.name, price: unitPrice, quantity: qty });
        });

        const pm = normalizePay(paymentMethod);
        if (pm === 'credito') {
            const surcharge = Number((total * 0.10).toFixed(2));
            total += surcharge;
            message += `- Recargo TC x1: $${surcharge.toFixed(2)}\n`;
            pizzalogItems.push({ name: 'Recargo Tarjeta Crédito (10%)', qty: 1, price: surcharge });
        } else if (pm === 'transferencia') {
            message += `\nDatos para la transferencia: Alias: ${TRANSFER_ALIAS}, Nombre: ${TRANSFER_HOLDER}`;
        }

        if (extraComments) {
            message += `\n\n*Comentarios:* ${extraComments}`;
        }

        const horarioLabel = deliveryType === 'envio' ? 'Horario de entrega' : 'Horario de retiro';
        const horarioDisplay = (pickupTime === 'Ahora') ? 'Ahora (lo antes posible)' : pickupTime;
        const preorderTag = isPreorder ? '\n📅 *Pedido programado (local cerrado al momento de pedir)*' : '';
        message += `\n\n${entrega}\n🕒 *${horarioLabel}:* ${horarioDisplay}${preorderTag}\nTotal: $${total.toFixed(2)}\nForma de pago: ${paymentMethod}`;

        const finalTotal = Number(total.toFixed(2));

        // 1. Disparar Purchase en Meta Pixel + Google Tag Manager / GA4
        if (typeof window.rrbbTrack === 'function') {
            window.rrbbTrack('purchase', {
                orderCode: orderCode,
                total: finalTotal,
                itemCount: totalUnits,
                deliveryType: deliveryType,
                paymentMethod: paymentMethod,
                isPreorder: isPreorder,
                items: gaItems
            });
        }

        // 2. Registrar pedido en estadísticas propias + sincronizar con Pizzalog
        if (typeof window.rrbbTrackBeacon === 'function') {
            window.rrbbTrackBeacon({
                type: 'order',
                order_code: orderCode,
                is_preorder: isPreorder ? 1 : 0,
                customer_name: customerName,
                customer_phone: customerPhone,
                address: deliveryType === 'envio' ? address : '',
                notes: extraComments,
                delivery_type: deliveryType,
                payment_method: paymentMethod,
                total: finalTotal,
                pickup_time: pickupTime,
                item_count: totalUnits,
                items: pizzalogItems
            });
        }

        const waUrl = `https://wa.me/5493517548030?text=${encodeURIComponent(message)}`;
        clearCart();

        // Breve margen para garantizar que Meta Pixel, GTM y sendBeacon salgan antes de abrir la app de WhatsApp
        setTimeout(function() {
            window.location.href = waUrl;
        }, 160);
    }
</script>

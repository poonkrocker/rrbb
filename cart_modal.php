<?php
/**
 * cart_modal.php — Modal de carrito compartido (carta + tienda)
 * Requiere que estén definidas en el archivo padre:
 *   - $is_open (bool)
 *   - $eligible_items (array)
 *   - $transfer_alias (string, opcional — default 'RRBBPIZZA')
 *   - $transfer_holder (string, opcional — default 'Ezequiel Urquidi')
 *
 * El archivo padre debe definir además, en su propio <script>, la lógica
 * de carrito específica de esa página: addToCart, updateCartUI, toggleCart,
 * closeCart, clearCart, setDelivery, sendOrder, onOverlayClick,
 * openPickupTimeModal/closePickupTimeModal/confirmPickupAndSend (flujo de
 * pedido programado), y los handlers de subproducto/pizza dependiente
 * (openSubproductModal, confirmSubproducts, closeSubproductModal,
 * openDependentProductModal, confirmDependentProduct, closeDependentProductModal).
 */
?>

<!-- Botón flotante carrito -->
<div class="cart-container" id="cartContainer">
    <div class="cart-button" onclick="toggleCart()">
        🛒
        <div class="cart-badge" id="cartCount">0</div>
    </div>
</div>

<!-- Overlay -->
<div class="overlay" id="overlay" onclick="onOverlayClick()"></div>

<!-- Modal carrito -->
<div class="cart-modal" id="cartModal">
    <div class="cart-modal-header">
        <h3>Tu Pedido</h3>
        <button type="button" class="cart-modal-close" onclick="closeCart()" aria-label="Cerrar ventana">✕</button>
    </div>
    <div class="input-group">
        <input type="text" id="customerName" placeholder="Nombre para el pedido" required autocomplete="name">
    </div>
    <div class="input-group">
        <input type="tel" id="customerPhone" placeholder="Tu teléfono / WhatsApp" required autocomplete="tel">
    </div>
    <div class="input-group">
        <div class="delivery-toggle">
            <button type="button" class="delivery-btn active" id="btnRetiro" onclick="setDelivery('retiro')">🏪 Retiro en local</button>
            <button type="button" class="delivery-btn" id="btnEnvio" onclick="setDelivery('envio')">🛵 Envío a domicilio</button>
        </div>
        <div id="addressGroup" style="display:none">
            <input type="text" id="deliveryAddress" placeholder="Dirección de entrega" autocomplete="street-address">
        </div>
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
            Alias: <b><?php echo htmlspecialchars($transfer_alias ?? 'RRBBPIZZA'); ?></b> <br>Nombre: <?php echo htmlspecialchars($transfer_holder ?? 'Ezequiel Urquidi'); ?>.
        </div>
        <div class="delivery-payment-notice" id="deliveryPaymentNotice" style="display:none; margin-top:8px; font-size:0.9em; color:#b45309;">
            Para envíos a domicilio el pago debe ser por <strong>Transferencia</strong> o <strong>Efectivo</strong>.
        </div>
        <div class="shipping-cost-notice" id="shippingCostNotice" style="display:none; margin-top:8px; font-size:0.9em; color:#b45309;">
            🛵 El costo del envío <strong>no está incluido</strong> en este total. Te confirmamos el valor por WhatsApp.
        </div>
    </div>
    <div class="input-group">
        <textarea id="extraComments" placeholder="Comentarios adicionales (opcional)"></textarea>
    </div>
    <div class="cart-items" id="cartItems"></div>
    <div class="cart-total">
        Total: $<span id="cartTotal">0</span>
    </div>
    <div class="cart-actions">
        <button type="button" class="clear-cart" onclick="clearCart()">Limpiar Carrito</button>
        <button type="button" class="send-order" onclick="sendOrder()">Enviar por WhatsApp</button>
    </div>
</div>

<!-- Modal: horario del pedido (turno actual, franjas de 15') -->
<div class="pickup-time-modal" id="pickupTimeModal" style="display: none;">
    <h3>¿Para qué horario querés tu pedido?</h3>
    <p class="pickup-time-sub" id="pickupTimeSub">Elegí a qué hora querés tu pedido:</p>
    <div class="pickup-time-slots" id="pickupTimeSlots"></div>
    <div class="pickup-time-actions">
        <button type="button" class="pickup-time-cancel" onclick="closePickupTimeModal(true)">Volver</button>
        <button type="button" class="pickup-time-confirm" id="pickupConfirmBtn" onclick="confirmPickupAndSend()" disabled>Confirmar pedido</button>
    </div>
</div>

<!-- Modal subproductos (combos/mitades) -->
<div class="subproduct-modal" id="subproductModal">
    <h3>Selecciona tus pizzas</h3>
    <div id="subproductSelections"></div>
    <button onclick="confirmSubproducts()">Agregar al Carrito</button>
    <button onclick="closeSubproductModal()">Cancelar</button>
</div>

<!-- Modal pizza dependiente (burrata, etc.) -->
<div class="dependent-product-modal" id="dependentProductModal">
    <h3>Selecciona una pizza para tu <span id="dependentProductTitle"></span></h3>
    <div id="dependentProductSelection">
        <select id="dependentPizzaSelect">
            <option value="">Selecciona una pizza</option>
            <?php foreach ($eligible_items as $pizza): ?>
                <option value="<?php echo $pizza['id']; ?>"
                        data-name="<?php echo htmlspecialchars($pizza['name']); ?>"
                        data-price="<?php echo $pizza['price']; ?>">
                    <?php echo htmlspecialchars($pizza['name']); ?> ($<?php echo number_format($pizza['price'], 2); ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button onclick="confirmDependentProduct()">Agregar al Carrito</button>
    <button onclick="closeDependentProductModal()">Cancelar</button>
</div>

<script>
/**
 * Restricción: si la modalidad de entrega es "Envío a domicilio",
 * las únicas formas de pago permitidas son "Transferencia" y "Efectivo".
 *
 * Se engancha a los botones de delivery y deja sólo esas opciones válidas
 * en el <select id="paymentMethod">. Cuando se vuelve a "Retiro en local"
 * se restauran todas las opciones originales.
 */
(function () {
    var ENVIO_ALLOWED = ['Transferencia', 'Efectivo'];

    function applyEnvioPaymentRestriction() {
        var select = document.getElementById('paymentMethod');
        var notice = document.getElementById('deliveryPaymentNotice');
        var shippingNotice = document.getElementById('shippingCostNotice');
        if (!select) return;

        // Guardamos las opciones originales una sola vez
        if (!select.dataset.originalOptions) {
            var original = [];
            for (var i = 0; i < select.options.length; i++) {
                original.push({
                    value: select.options[i].value,
                    text: select.options[i].text
                });
            }
            select.dataset.originalOptions = JSON.stringify(original);
        }

        var currentValue = select.value;

        // Dejamos sólo Transferencia y Efectivo
        select.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.text = 'Selecciona forma de pago';
        select.appendChild(placeholder);

        ENVIO_ALLOWED.forEach(function (value) {
            var opt = document.createElement('option');
            opt.value = value;
            opt.text = value;
            select.appendChild(opt);
        });

        // Si ya había una selección válida (Transferencia/Efectivo), la mantenemos
        select.value = ENVIO_ALLOWED.indexOf(currentValue) !== -1 ? currentValue : '';

        if (notice) notice.style.display = 'block';
        if (shippingNotice) shippingNotice.style.display = 'block';

        // Disparamos change para que cualquier listener existente
        // (por ejemplo el que muestra el aviso de transferencia) reaccione.
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function restorePaymentOptions() {
        var select = document.getElementById('paymentMethod');
        var notice = document.getElementById('deliveryPaymentNotice');
        var shippingNotice = document.getElementById('shippingCostNotice');
        if (!select) return;

        if (select.dataset.originalOptions) {
            var original = JSON.parse(select.dataset.originalOptions);
            var currentValue = select.value;
            select.innerHTML = '';
            original.forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o.value;
                opt.text = o.text;
                select.appendChild(opt);
            });
            // Mantenemos la selección si seguía siendo válida, si no volvemos al placeholder
            select.value = currentValue;
            if (select.value !== currentValue) select.value = '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (notice) notice.style.display = 'none';
        if (shippingNotice) shippingNotice.style.display = 'none';
    }

    function init() {
        var btnEnvio = document.getElementById('btnEnvio');
        var btnRetiro = document.getElementById('btnRetiro');
        if (!btnEnvio || !btnRetiro) return;

        // Usamos addEventListener para no pisar el onclick que llama a setDelivery().
        // Este listener corre después del onclick inline.
        btnEnvio.addEventListener('click', applyEnvioPaymentRestriction);
        btnRetiro.addEventListener('click', restorePaymentOptions);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

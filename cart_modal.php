<?php
/**
 * cart_modal.php — Modal de carrito compartido
 * Requiere que estén definidas en el archivo padre:
 *   - $is_open (bool)
 *   - $eligible_items (array)
 */
?>

<!-- Botón flotante carrito -->
<div class="cart-container" id="cartContainer">
    <div class="cart-button <?php echo !$is_open ? 'disabled' : ''; ?>" <?php if ($is_open): ?>onclick="toggleCart()"<?php endif; ?>>
        🛒
        <div class="cart-badge" id="cartCount">0</div>
    </div>
</div>

<!-- Overlay -->
<div class="overlay" id="overlay" onclick="closeCart()"></div>

<!-- Modal carrito -->
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
            <input type="text" id="deliveryAddress" placeholder="Dirección de entrega">
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
            Pago con crédito tiene un recargo del <strong>10%</strong>.
        </div>
        <div class="transfer-warning" id="transferWarning">
            Por favor realiza la transferencia al alias <strong>RRBBPIZZA</strong> a nombre de Ezequiel Urquidi.
        </div>
        <div class="delivery-payment-notice" id="deliveryPaymentNotice" style="display:none; margin-top:8px; font-size:0.9em; color:#b45309;">
            Para envíos a domicilio el pago debe ser por <strong>Transferencia</strong>.
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
 * la única forma de pago permitida es "Transferencia".
 *
 * Se engancha a los botones de delivery y deja sólo la opción válida
 * en el <select id="paymentMethod">. Cuando se vuelve a "Retiro en local"
 * se restauran todas las opciones originales.
 */
(function () {
    function applyEnvioPaymentRestriction() {
        var select = document.getElementById('paymentMethod');
        var notice = document.getElementById('deliveryPaymentNotice');
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

        // Dejamos sólo Transferencia y la seleccionamos
        select.innerHTML = '';
        var opt = document.createElement('option');
        opt.value = 'Transferencia';
        opt.text = 'Transferencia';
        opt.selected = true;
        select.appendChild(opt);

        if (notice) notice.style.display = 'block';

        // Disparamos change para que cualquier listener existente
        // (por ejemplo el que muestra el aviso de transferencia) reaccione.
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function restorePaymentOptions() {
        var select = document.getElementById('paymentMethod');
        var notice = document.getElementById('deliveryPaymentNotice');
        if (!select) return;

        if (select.dataset.originalOptions) {
            var original = JSON.parse(select.dataset.originalOptions);
            select.innerHTML = '';
            original.forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o.value;
                opt.text = o.text;
                select.appendChild(opt);
            });
            // Por defecto, vuelve al placeholder vacío
            select.value = '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (notice) notice.style.display = 'none';
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

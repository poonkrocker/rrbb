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

<?php
/**
 * _store_item_card.php — Tarjeta de producto de la TIENDA.
 *
 * Partial compartido por tienda.php y la sección de tienda integrada en index.php.
 * Editá esto UNA vez y cambia en los dos lados.
 *
 * Variables esperadas en el scope:
 *   - $item (array) producto actual
 *
 * Nota: secondary_price se reinterpreta como stock opcional.
 *   secondary_price NULL  => sin control de stock (siempre comprable)
 *   secondary_price <= 0  => agotado
 */
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

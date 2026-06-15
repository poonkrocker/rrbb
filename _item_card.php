<?php
/**
 * _item_card.php — Tarjeta de producto de la CARTA (pizza / burrata / vegana / combos).
 *
 * Partial compartido por carta.php e index.php. Editá esto UNA vez y cambia
 * en los dos lados.
 *
 * Variables esperadas en el scope (las define el archivo que hace el include):
 *   - $item         (array)  producto actual
 *   - $is_open      (bool)   si el local está abierto
 *   - $sub_products (array)  subproductos por item_id
 */
?>
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

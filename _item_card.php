<?php
/**
 * _item_card.php — Tarjeta de producto de la CARTA (pizza / burrata / vegana / combos / variantes).
 *
 * Partial compartido por carta.php e index.php. Editá esto UNA vez y cambia
 * en los dos lados.
 *
 * Variables esperadas en el scope (las define el archivo que hace el include):
 *   - $item             (array)  producto actual
 *   - $is_open          (bool)   si el local está abierto
 *   - $sub_products     (array)  subproductos por item_id
 *   - $variants_by_item (array)  OPCIONAL. Variantes con precio propio por item_id
 *                                (lo define _variants_data.php). Si no está, la
 *                                tarjeta funciona como siempre (precio único).
 *
 * NOTA: las variantes NO se muestran como botones en la grilla. La tarjeta
 * muestra "Desde $X" + un botón que abre el modal (_variant_modal.php).
 */

// Degradación segura: si el parent no incluyó _variants_data.php, no hay variantes.
$item_variants = (isset($variants_by_item) && !empty($variants_by_item[$item['id']]))
    ? $variants_by_item[$item['id']]
    : [];
$has_variants  = !empty($item_variants);
$variant_count = count($item_variants);
$variant_min   = $has_variants ? min(array_column($item_variants, 'price')) : null;
// Umbral: hasta 3 variantes -> pills inline en la tarjeta; más de 3 -> modal.
$variants_use_modal = $variant_count > 3;

// Soporte de extras para pizzas (versión interactiva con pills)
$enable_extras = $enable_extras ?? false;
$cat_name = strtolower($category['name'] ?? '');
$is_pizza_category = (strpos($cat_name, 'pizza') !== false || strpos($cat_name, 'secreta') !== false);
$show_extras = $enable_extras && $is_pizza_category && !$item['requires_pizza'] && empty($sub_products[$item['id']]) && !$variants_use_modal && !empty($available_extras);
?>
<div class="pizza-item <?php echo $item['is_secret_menu'] ? 'secret-item' : ''; ?> <?php echo $has_variants ? 'has-variants' : ''; ?>"
     data-item-id="<?php echo $item['id']; ?>"
     data-item-name="<?php echo htmlspecialchars($item['name']); ?>"
     data-item-price="<?php echo $item['price']; ?>"
     data-has-vegan="<?php echo $item['has_vegan_option'] ? 'true' : 'false'; ?>"
     data-has-subproducts="<?php echo !empty($sub_products[$item['id']]) ? 'true' : 'false'; ?>"
     data-has-variants="<?php echo $has_variants ? 'true' : 'false'; ?>"
     data-required-selections="<?php echo $item['required_selections'] ?: '0'; ?>"
     <?php if ($has_variants && $variants_use_modal): ?>
         onclick="openVariantModal('<?php echo $item['id']; ?>')"
     <?php elseif ($show_extras): ?>
         onclick="addItemWithExtras(this)"
     <?php elseif ($has_variants): ?>
         onclick="addSelectedVariant(this)"
     <?php elseif (!$item['requires_pizza']): ?>
         onclick="addItemToCart('<?php echo htmlspecialchars($item['name']); ?>', '<?php echo $item['id']; ?>', <?php echo $item['price']; ?>, false, <?php echo !empty($sub_products[$item['id']]) ? 'true' : 'false'; ?>, <?php echo $item['required_selections'] ?: '0'; ?>)"
     <?php endif; ?>>

    <div class="img-wrapper">
        <?php if ($item['is_weekly_special']): ?>
            <span class="weekly-special-label"><?php echo htmlspecialchars($item['weekly_special_text'] ?: '¡Destacado!'); ?></span>
        <?php endif; ?>
        <?php
            // Normaliza URLs relativas al dominio principal, así las fotos cargan
            // también desde el subdominio carta.arrabbiata.com.ar.
            $imgUrl = $item['image_url'];
            if (!empty($imgUrl) && !preg_match('/^https?:\/\//', $imgUrl)) {
                $imgUrl = 'https://arrabbiata.com.ar/' . ltrim($imgUrl, '/');
            }
        ?>
        <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
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
            <span class="item-price" data-variant="standalone" data-price="<?php echo $item['price']; ?>" onclick="event.stopPropagation(); addItemToCart('<?php echo htmlspecialchars($item['name']); ?>', '<?php echo $item['id']; ?>', <?php echo $item['price']; ?>, false, false, 0);">$<?php echo number_format($item['price'], 2); ?></span>
            <?php if ($item['secondary_price'] !== null): ?>
                <span class="item-price" data-variant="para-tu-pizza" data-price="<?php echo $item['secondary_price']; ?>" data-requires-pizza="true" onclick="event.stopPropagation(); addItemToCart('<?php echo htmlspecialchars($item['name']); ?> (Para tu pizza)', '<?php echo $item['id']; ?>', <?php echo $item['secondary_price']; ?>, false, false, 0, true);">$<?php echo number_format($item['secondary_price'], 2); ?></span>
            <?php endif; ?>
        </div>

    <?php elseif ($has_variants && $variants_use_modal): ?>
        <h3><?php echo htmlspecialchars($item['name']); ?></h3>
        <p class="item-desc">
            <?php echo nl2br(htmlspecialchars($item['description'])); ?>
        </p>
        <div class="price-container variant-price-container">
            <p class="variant-from">Desde $<span class="item-price" data-price="<?php echo $variant_min; ?>"><?php echo number_format($variant_min, 2); ?></span></p>
            <span class="variant-hint"><?php echo $variant_count; ?> variantes · tocá para elegir</span>
        </div>

    <?php elseif ($has_variants): ?>
        <?php if ($show_extras): ?>
            <div class="item-title-row">
                <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                <?php if ($item['has_vegan_option']): ?>
                    <button type="button" class="vegan-badge-btn" data-item-id="<?php echo $item['id']; ?>" onclick="event.stopPropagation(); addItemWithExtras(this.closest('.pizza-item'), true)" title="Pedir en versión vegana">🌿 Vegana</button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <h3><?php echo htmlspecialchars($item['name']); ?></h3>
        <?php endif; ?>
        <p class="item-desc">
            <?php echo nl2br(htmlspecialchars($item['description'])); ?>
        </p>
        <?php
            // Misma estructura/clases que la versión de github, para que
            // arrabbiata.css estilice las pills igual. Sin botón "Agregar":
            // se agrega tocando la tarjeta (onclick del div raíz).
            $__default = $item_variants[0];
            $__variants_json = htmlspecialchars(json_encode($item_variants, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        ?>
        <div class="variant-picker" data-variants="<?php echo $__variants_json; ?>">
            <div class="variant-pills">
                <?php foreach ($item_variants as $vi => $v): ?>
                    <button type="button"
                            class="variant-pill <?php echo $vi === 0 ? 'active' : ''; ?>"
                            data-variant-id="<?php echo $v['id']; ?>"
                            data-variant-name="<?php echo htmlspecialchars($v['name']); ?>"
                            data-variant-price="<?php echo $v['price']; ?>"
                            data-variant-desc="<?php echo htmlspecialchars($v['description'] ?? ''); ?>"
                            onclick="event.stopPropagation(); selectVariant(this);">
                        <?php echo htmlspecialchars($v['name']); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <p class="variant-desc"><?php echo htmlspecialchars($__default['description'] ?? ''); ?></p>
            <?php if ($show_extras): ?>
                <div class="pizza-extras-container" onclick="event.stopPropagation()">
                    <span class="extras-label">Sumale a tu pizza:</span>
                    <div class="extras-pills">
                        <?php foreach ($available_extras as $extra): ?>
                            <button type="button"
                                    class="extra-pill"
                                    data-extra-id="<?php echo htmlspecialchars($extra['id']); ?>"
                                    data-extra-name="<?php echo htmlspecialchars($extra['name']); ?>"
                                    data-extra-price="<?php echo (float)$extra['price']; ?>"
                                    onclick="toggleItemExtra(this, event)">
                                <span class="extra-plus">+</span> <?php echo htmlspecialchars($extra['name']); ?>
                                <span class="extra-price-tag">(+$<?php echo number_format($extra['price'], 0, ',', '.'); ?>)</span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="added-toast">✓ Agregada</div>
            <?php endif; ?>
            <div class="price-container">
                <p>$<span class="item-price variant-current-price" data-price="<?php echo $__default['price']; ?>"><?php echo number_format($__default['price'], 2); ?></span></p>
            </div>
        </div>

    <?php else: ?>
        <?php if ($show_extras): ?>
            <div class="item-title-row">
                <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                <?php if ($item['has_vegan_option']): ?>
                    <button type="button" class="vegan-badge-btn" data-item-id="<?php echo $item['id']; ?>" onclick="event.stopPropagation(); addItemWithExtras(this.closest('.pizza-item'), true)" title="Pedir en versión vegana">🌿 Vegana</button>
                <?php endif; ?>
            </div>
            <p class="item-desc">
                <?php echo nl2br(htmlspecialchars($item['description'])); ?>
            </p>
            <div class="pizza-extras-container" onclick="event.stopPropagation()">
                <span class="extras-label">Sumale a tu pizza:</span>
                <div class="extras-pills">
                    <?php foreach ($available_extras as $extra): ?>
                        <button type="button"
                                class="extra-pill"
                                data-extra-id="<?php echo htmlspecialchars($extra['id']); ?>"
                                data-extra-name="<?php echo htmlspecialchars($extra['name']); ?>"
                                data-extra-price="<?php echo (float)$extra['price']; ?>"
                                onclick="toggleItemExtra(this, event)">
                            <span class="extra-plus">+</span> <?php echo htmlspecialchars($extra['name']); ?>
                            <span class="extra-price-tag">(+$<?php echo number_format($extra['price'], 0, ',', '.'); ?>)</span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="added-toast">✓ Agregada</div>
            <div class="price-container">
                <p>$<span class="item-price" data-price="<?php echo $item['price']; ?>"><?php echo number_format($item['price'], 2); ?></span></p>
            </div>
        <?php else: ?>
            <h3><?php echo htmlspecialchars($item['name']); ?></h3>
            <p class="item-desc">
                <?php echo nl2br(htmlspecialchars($item['description'])); ?>
            </p>
            <div class="price-container">
                <p>$<span class="item-price" data-price="<?php echo $item['price']; ?>"><?php echo number_format($item['price'], 2); ?></span></p>
                <?php if ($item['has_vegan_option']): ?>
                    <button type="button" class="vegan-button" data-item-id="<?php echo $item['id']; ?>" onclick="event.stopPropagation(); addItemToCart('<?php echo htmlspecialchars($item['name']); ?>', '<?php echo $item['id']; ?>', <?php echo $item['price']; ?>, true, <?php echo !empty($sub_products[$item['id']]) ? 'true' : 'false'; ?>, <?php echo $item['required_selections'] ?: '0'; ?>)">🌿 Versión Vegana</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

   
</div>
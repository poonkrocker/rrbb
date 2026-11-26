<?php
/**
 * _item_card.php — Tarjeta unificada de producto de la CARTA (pizzas, variantes, vegana, extras, secreta).
 *
 * Usa atributos data-* en lugar de interpolar nombres dentro de strings JS inline,
 * evitando por completo roturas con comillas simples/dobles o caracteres especiales.
 */

if (!function_exists('fmtArs')) {
    function fmtArs($amount): string {
        $n = round((float)$amount, 2);
        return (floor($n) == $n)
            ? number_format($n, 0, ',', '.')
            : number_format($n, 2, ',', '.');
    }
}

$item_variants      = (isset($variants_by_item) && !empty($variants_by_item[$item['id']]))
    ? $variants_by_item[$item['id']]
    : [];
$has_variants       = !empty($item_variants);
$variant_count      = count($item_variants);
$variant_min        = $has_variants ? min(array_column($item_variants, 'price')) : null;
$variants_use_modal = $variant_count > 3;

$enable_extras     = $enable_extras ?? true;
$cat_name_lower    = mb_strtolower((string)($category['name'] ?? ''), 'UTF-8');
$is_pizza_category = (strpos($cat_name_lower, 'pizza') !== false || strpos($cat_name_lower, 'secreta') !== false);
$show_extras       = $enable_extras && $is_pizza_category && !$variants_use_modal && !empty($available_extras);

// Etiqueta superior derecha: Destacado tiene prioridad de texto; Carta Secreta muestra su badge propio
$badge_text = '';
if (!empty($item['is_weekly_special'])) {
    $badge_text = trim((string)($item['weekly_special_text'] ?? '')) ?: '¡Destacado!';
} elseif (!empty($item['is_secret_menu'])) {
    $badge_text = '¡Carta Secreta!';
}

// Normalizar URL de imagen para que funcione igual en arrabbiata.com.ar y carta.arrabbiata.com.ar
$imgUrl = trim((string)($item['image_url'] ?? ''));
if ($imgUrl !== '' && !preg_match('/^https?:\/\//i', $imgUrl)) {
    $imgUrl = 'https://arrabbiata.com.ar/' . ltrim($imgUrl, '/');
}
?>
<div class="pizza-item <?php echo !empty($item['is_secret_menu']) ? 'secret-item' : ''; ?> <?php echo $has_variants ? 'has-variants' : ''; ?>"
     data-item-id="<?php echo (int)$item['id']; ?>"
     data-item-name="<?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?>"
     data-item-price="<?php echo (float)$item['price']; ?>"
     data-has-vegan="<?php echo !empty($item['has_vegan_option']) ? 'true' : 'false'; ?>"
     data-has-variants="<?php echo $has_variants ? 'true' : 'false'; ?>"
     <?php if ($has_variants && $variants_use_modal): ?>
         onclick="openVariantModal(this.dataset.itemId)"
     <?php else: ?>
         onclick="addCardItemToCart(this, false)"
     <?php endif; ?>>

    <div class="img-wrapper">
        <?php if ($badge_text !== ''): ?>
            <span class="weekly-special-label"><?php echo htmlspecialchars($badge_text, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
        <img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>"
             alt="<?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?>"
             loading="lazy"
             decoding="async">
    </div>

    <?php if ($has_variants && $variants_use_modal): ?>
        <div class="item-title-row">
            <h3><?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
            <?php if (!empty($item['has_vegan_option'])): ?>
                <button type="button" class="vegan-badge-btn" onclick="event.stopPropagation(); openVariantModal(this.closest('.pizza-item').dataset.itemId, true)" title="Elegir variante en versión vegana">🌿 Vegana</button>
            <?php endif; ?>
        </div>
        <p class="item-desc"><?php echo nl2br(htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
        <div class="added-toast">✓ Agregada</div>
        <div class="price-container variant-price-container">
            <p class="variant-from">Desde $<span class="item-price" data-price="<?php echo (float)$variant_min; ?>"><?php echo fmtArs($variant_min); ?></span></p>
            <span class="variant-hint"><?php echo (int)$variant_count; ?> variantes · tocá para elegir</span>
        </div>

    <?php elseif ($has_variants): ?>
        <div class="item-title-row">
            <h3><?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
            <?php if (!empty($item['has_vegan_option'])): ?>
                <button type="button" class="vegan-badge-btn" onclick="event.stopPropagation(); addCardItemToCart(this.closest('.pizza-item'), true)" title="Pedir en versión vegana">🌿 Vegana</button>
            <?php endif; ?>
        </div>
        <p class="item-desc"><?php echo nl2br(htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
        <?php
            $__default = $item_variants[0];
            $__variants_json = htmlspecialchars((string)json_encode($item_variants, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="variant-picker" data-variants="<?php echo $__variants_json; ?>">
            <div class="variant-pills">
                <?php foreach ($item_variants as $vi => $v): ?>
                    <button type="button"
                            class="variant-pill <?php echo $vi === 0 ? 'active' : ''; ?>"
                            data-variant-id="<?php echo (int)$v['id']; ?>"
                            data-variant-name="<?php echo htmlspecialchars((string)$v['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-variant-price="<?php echo (float)$v['price']; ?>"
                            data-variant-desc="<?php echo htmlspecialchars((string)($v['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            onclick="event.stopPropagation(); selectVariant(this);">
                        <?php echo htmlspecialchars((string)$v['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <p class="variant-desc"><?php echo htmlspecialchars((string)($__default['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($show_extras): ?>
                <div class="pizza-extras-container" onclick="event.stopPropagation()">
                    <span class="extras-label">Sumale a tu pizza:</span>
                    <div class="extras-pills">
                        <?php foreach ($available_extras as $extra): ?>
                            <button type="button"
                                    class="extra-pill"
                                    data-extra-id="<?php echo htmlspecialchars((string)$extra['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-extra-name="<?php echo htmlspecialchars((string)$extra['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-extra-price="<?php echo (float)$extra['price']; ?>"
                                    onclick="toggleItemExtra(this, event)">
                                <span class="extra-plus">+</span> <?php echo htmlspecialchars((string)$extra['name'], ENT_QUOTES, 'UTF-8'); ?>
                                <span class="extra-price-tag">(+$<?php echo fmtArs($extra['price']); ?>)</span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="added-toast">✓ Agregada</div>
            <div class="price-container">
                <p>$<span class="item-price variant-current-price" data-price="<?php echo (float)$__default['price']; ?>"><?php echo fmtArs($__default['price']); ?></span></p>
            </div>
        </div>

    <?php else: ?>
        <?php if ($show_extras): ?>
            <div class="item-title-row">
                <h3><?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                <?php if (!empty($item['has_vegan_option'])): ?>
                    <button type="button" class="vegan-badge-btn" onclick="event.stopPropagation(); addCardItemToCart(this.closest('.pizza-item'), true)" title="Pedir en versión vegana">🌿 Vegana</button>
                <?php endif; ?>
            </div>
            <p class="item-desc"><?php echo nl2br(htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
            <div class="pizza-extras-container" onclick="event.stopPropagation()">
                <span class="extras-label">Sumale a tu pizza:</span>
                <div class="extras-pills">
                    <?php foreach ($available_extras as $extra): ?>
                        <button type="button"
                                class="extra-pill"
                                data-extra-id="<?php echo htmlspecialchars((string)$extra['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-extra-name="<?php echo htmlspecialchars((string)$extra['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-extra-price="<?php echo (float)$extra['price']; ?>"
                                onclick="toggleItemExtra(this, event)">
                            <span class="extra-plus">+</span> <?php echo htmlspecialchars((string)$extra['name'], ENT_QUOTES, 'UTF-8'); ?>
                            <span class="extra-price-tag">(+$<?php echo fmtArs($extra['price']); ?>)</span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="added-toast">✓ Agregada</div>
            <div class="price-container">
                <p>$<span class="item-price" data-price="<?php echo (float)$item['price']; ?>"><?php echo fmtArs($item['price']); ?></span></p>
            </div>
        <?php else: ?>
            <h3><?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
            <p class="item-desc"><?php echo nl2br(htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
            <div class="added-toast">✓ Agregada</div>
            <div class="price-container">
                <p>$<span class="item-price" data-price="<?php echo (float)$item['price']; ?>"><?php echo fmtArs($item['price']); ?></span></p>
                <?php if (!empty($item['has_vegan_option'])): ?>
                    <button type="button" class="vegan-button" onclick="event.stopPropagation(); addCardItemToCart(this.closest('.pizza-item'), true)">🌿 Versión Vegana</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

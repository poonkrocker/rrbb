<?php
/**
 * _store_item_card.php — Tarjeta de producto de la TIENDA (con soporte de variantes / talles y stock).
 *
 * Partial compartido por tienda.php e index.php.
 *
 * Nota sobre stock:
 *   secondary_price NULL  => sin límite de stock (siempre comprable)
 *   secondary_price <= 0  => agotado
 *   secondary_price > 0   => unidades disponibles (se descuenta al aprobarse el pago)
 */

if (!function_exists('fmtArs')) {
    function fmtArs($amount): string {
        $n = round((float)$amount, 2);
        return (floor($n) == $n)
            ? number_format($n, 0, ',', '.')
            : number_format($n, 2, ',', '.');
    }
}

$item_variants = (isset($variants_by_item) && !empty($variants_by_item[$item['id']]))
    ? $variants_by_item[$item['id']]
    : [];
$has_variants  = !empty($item_variants);
$soldout       = !$has_variants && ($item['secondary_price'] !== null && (float)$item['secondary_price'] <= 0);
$stock_left    = (!$has_variants && $item['secondary_price'] !== null && (float)$item['secondary_price'] > 0)
    ? (int)$item['secondary_price']
    : null;

$imgUrl = trim((string)($item['image_url'] ?? ''));
if ($imgUrl !== '' && !preg_match('/^https?:\/\//i', $imgUrl)) {
    $imgUrl = 'https://arrabbiata.com.ar/' . ltrim($imgUrl, '/');
}
?>
<div class="pizza-item store-card <?php echo $soldout ? 'store-soldout' : ''; ?> <?php echo $has_variants ? 'has-variants' : ''; ?>"
     data-item-id="<?php echo (int)$item['id']; ?>"
     data-item-name="<?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?>"
     data-item-price="<?php echo (float)$item['price']; ?>"
     data-item-image="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>">

    <div class="img-wrapper">
        <?php if ($soldout): ?>
            <span class="store-soldout-label">Agotado</span>
        <?php elseif (!empty($item['is_weekly_special'])): ?>
            <span class="weekly-special-label"><?php echo htmlspecialchars(trim((string)($item['weekly_special_text'] ?? '')) ?: '¡Destacado!', ENT_QUOTES, 'UTF-8'); ?></span>
        <?php elseif ($stock_left !== null && $stock_left <= 5): ?>
            <span class="weekly-special-label">¡Últimas <?php echo $stock_left; ?>!</span>
        <?php endif; ?>
        <img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>"
             alt="<?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?>"
             loading="lazy"
             decoding="async">
    </div>

    <h3><?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
    <p class="item-desc"><?php echo nl2br(htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>

    <?php if ($has_variants):
        $__default = $item_variants[0];
    ?>
        <div class="variant-picker store-variant-picker">
            <div class="variant-pills">
                <?php foreach ($item_variants as $vi => $v): ?>
                    <button type="button"
                            class="variant-pill store-variant-pill <?php echo $vi === 0 ? 'active' : ''; ?>"
                            data-variant-id="<?php echo (int)$v['id']; ?>"
                            data-variant-name="<?php echo htmlspecialchars((string)$v['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-variant-price="<?php echo (float)$v['price']; ?>"
                            data-variant-desc="<?php echo htmlspecialchars((string)($v['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            onclick="event.stopPropagation(); selectStoreVariant(this);">
                        <?php echo htmlspecialchars((string)$v['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <p class="variant-desc"><?php echo htmlspecialchars((string)($__default['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <div class="added-toast">✓ Agregado</div>
        <div class="price-container">
            <p>$<span class="item-price variant-current-price" data-price="<?php echo (float)$__default['price']; ?>"><?php echo fmtArs($__default['price']); ?></span></p>
            <?php if (!$soldout): ?>
                <button type="button" class="vegan-button store-add-btn">🛒 Agregar</button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="added-toast">✓ Agregado</div>
        <div class="price-container">
            <p>$<span class="item-price" data-price="<?php echo (float)$item['price']; ?>"><?php echo fmtArs($item['price']); ?></span></p>
            <?php if (!$soldout): ?>
                <button type="button" class="vegan-button store-add-btn">🛒 Agregar</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

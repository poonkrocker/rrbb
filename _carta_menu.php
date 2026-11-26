<?php
/**
 * _carta_menu.php — Renderizado compartido de la Carta (aviso de cerrado/preventa,
 *                   submenú de categorías y grilla de productos).
 *
 * Variables opcionales antes del include:
 *   - $carta_show_brand_header (bool) true en carta/index.php standalone, false en index.php
 */
$__show_header = !empty($carta_show_brand_header);
?>
<div class="menu-container">
    <?php if ($__show_header): ?>
        <header class="menu-header">
            <a href="https://www.arrabbiata.com.ar"><img class="menu-logo" src="https://arrabbiata.com.ar/Uploads/logoborde.png" alt="Pizzería Arrabbiata"></a>
            <div class="menu-header-info">
                <a href="https://maps.app.goo.gl/weS5JgVfJapUXhRj9" target="_blank" rel="noopener">27 de Abril 798</a>
                <span class="sep">·</span>
                <a href="https://wa.me/5493517548030" target="_blank" rel="noopener">3517548030</a>
                <span class="sep">·</span>
                <a href="https://instagram.com/arrabbiata.pizza" target="_blank" rel="noopener">@arrabbiata.pizza</a>
            </div>
        </header>
    <?php endif; ?>

    <?php if (!$is_open): ?>
        <div class="closed-notice active"><?php echo $closed_message; ?></div>
    <?php endif; ?>

    <h2>Nuestra Carta</h2>

    <nav class="category-submenu">
        <ul>
            <?php foreach ($categories as $category): ?>
                <?php
                $cid = (int)$category['id'];
                $item_count = count($items_by_category[$cid] ?? []);
                $is_sec_cat = !empty($secret_category_ids[$cid]);
                if ($item_count > 0):
                ?>
                    <li class="<?php echo $is_sec_cat ? 'secret-link' : ''; ?>">
                        <a href="#category-<?php echo $cid; ?>"
                           onclick="scrollToCategory('category-<?php echo $cid; ?>', event)"
                           aria-label="Navegar a la categoría <?php echo htmlspecialchars((string)$category['name'], ENT_QUOTES, 'UTF-8'); ?>">
                           <?php echo htmlspecialchars((string)$category['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </nav>

    <?php foreach ($categories as $category): ?>
        <?php
        $cid   = (int)$category['id'];
        $items = $items_by_category[$cid] ?? [];
        $is_sec_cat = !empty($secret_category_ids[$cid]);
        if (count($items) > 0):
        ?>
            <div id="category-<?php echo $cid; ?>" class="category-section <?php echo $is_sec_cat ? 'secret-category' : ''; ?>">
                <h3 class="menu-category"><?php echo htmlspecialchars((string)$category['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                <div class="pizza-list">
                    <?php foreach ($items as $item): ?>
                        <?php include __DIR__ . '/_item_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php if (!empty($has_any_secret)): ?>
    <img id="secret-image" class="secret-image" src="https://arrabbiata.com.ar/Uploads/csgif.gif" alt="Carta Secreta" onclick="toggleSecretMode(event)" style="cursor:pointer;">
<?php endif; ?>

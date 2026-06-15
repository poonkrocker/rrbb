<?php
/**
 * _store_section.php — Marcado de la sección TIENDA (grid de productos).
 *
 * Compartido por tienda.php (página propia) e index.php (sección integrada).
 * Editá una vez, cambia en los dos lados.
 *
 * Requiere en el scope:
 *   - $store_categories, $store_items_by_cat, $store_total_items (de _store_data.php)
 */
?>
<h2>Tienda</h2>

<?php if (count($store_categories) > 1): ?>
<nav class="category-submenu">
    <ul>
        <?php foreach ($store_categories as $category): ?>
            <?php if (count($store_items_by_cat[$category['id']] ?? []) > 0): ?>
                <li>
                    <a href="#store-category-<?php echo $category['id']; ?>"
                       onclick="scrollToCategory('store-category-<?php echo $category['id']; ?>', event)"
                       aria-label="Navegar a la categoría <?php echo htmlspecialchars($category['name']); ?>">
                       <?php echo htmlspecialchars($category['name']); ?>
                    </a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
</nav>
<?php endif; ?>

<?php if ($store_total_items === 0): ?>
    <div class="store-empty-note">
        Todavía no hay productos en la tienda.<br>
        <strong>Volvé pronto</strong> para ver las novedades.
    </div>
<?php endif; ?>

<?php foreach ($store_categories as $category): ?>
    <?php
    $items = $store_items_by_cat[$category['id']] ?? [];
    if (count($items) > 0):
    ?>
        <div id="store-category-<?php echo $category['id']; ?>" class="category-section">
            <?php if (count($store_categories) > 1): ?>
                <h3 class="menu-category"><?php echo htmlspecialchars($category['name']); ?></h3>
            <?php endif; ?>
            <div class="pizza-list">
                <?php foreach ($items as $item): ?>
                    <?php include '_store_item_card.php'; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

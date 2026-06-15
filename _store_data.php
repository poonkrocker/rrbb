<?php
/**
 * _store_data.php — Carga los datos de la TIENDA (categorías + productos).
 *
 * Partial compartido por tienda.php e index.php, para que ambos muestren
 * exactamente los mismos productos sin duplicar la consulta.
 *
 * Requiere en el scope:
 *   - $pdo (PDO)
 *   - $current_day_es, $current_time (definidos para el filtro de visibilidad)
 *   - función isItemVisibleNow() (incluí _visibility.php antes)
 *
 * Define:
 *   - $store_categories      (array) categorías cuyo nombre contiene "Tienda"
 *   - $store_items_by_cat    (array) productos visibles por id de categoría
 *   - $store_total_items     (int)   total de productos de tienda visibles
 */

$store_categories   = [];
$store_items_by_cat = [];
$store_total_items  = 0;

try {
    $__cats_stmt = $pdo->query("SELECT * FROM categories ORDER BY display_order");
    $__all_categories = $__cats_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($__all_categories as $__c) {
        if (stripos($__c['name'], 'Tienda') !== false) {
            $store_categories[] = $__c;
        }
    }
} catch (PDOException $e) {
    $store_categories = [];
}

try {
    $__items_stmt = $pdo->prepare("
        SELECT mi.*
        FROM menu_items mi
        WHERE mi.category_id = ?
          AND mi.is_visible = 1
        ORDER BY mi.display_order
    ");
    foreach ($store_categories as $__category) {
        $__items_stmt->execute([$__category['id']]);
        $__all_items = $__items_stmt->fetchAll(PDO::FETCH_ASSOC);
        $__visible = array_values(array_filter(
            $__all_items,
            fn($it) => isItemVisibleNow($it, $current_day_es, $current_time)
        ));
        $store_items_by_cat[$__category['id']] = $__visible;
        $store_total_items += count($__visible);
    }
} catch (PDOException $e) {
    error_log("Error pre-fetching productos de tienda: " . $e->getMessage());
    $store_items_by_cat = [];
}

<?php
/**
 * _store_data.php — Carga los datos de la TIENDA (categorías, productos, variantes y envío).
 *
 * Partial compartido por tienda.php e index.php.
 *
 * Requiere en el scope:
 *   - $pdo (PDO)
 *   - $current_day_es, $current_time (definidos para el filtro de visibilidad)
 *   - función isItemVisibleNow() (_visibility.php)
 */

require_once __DIR__ . '/_visibility.php';
require_once __DIR__ . '/_variants_data.php';

if (!function_exists('fmtArs')) {
    function fmtArs($amount): string {
        $n = round((float)$amount, 2);
        return (floor($n) == $n)
            ? number_format($n, 0, ',', '.')
            : number_format($n, 2, ',', '.');
    }
}

$store_categories    = [];
$store_items_by_cat  = [];
$store_total_items   = 0;
$store_shipping_cost = 0.0;

try {
    $__ship_st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'store_shipping_cost' LIMIT 1");
    $__ship_st->execute();
    $__ship_val = $__ship_st->fetchColumn();
    if ($__ship_val !== false && is_numeric($__ship_val)) {
        $store_shipping_cost = max(0.0, round((float)$__ship_val, 2));
    }
} catch (Throwable $e) {
    $store_shipping_cost = 0.0;
}

try {
    $__cats_stmt = $pdo->query("SELECT * FROM categories ORDER BY display_order");
    $__all_categories = $__cats_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($__all_categories as $__c) {
        if (stripos((string)$__c['name'], 'Tienda') !== false) {
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

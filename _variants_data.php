<?php
/**
 * _variants_data.php — Carga las VARIANTES de producto (precio propio).
 *
 * Partial compartido por index.php y carta.php. Igual que $sub_products,
 * arma un mapa item_id => [variantes...] en UNA sola consulta, para no
 * pegarle a la BD por cada tarjeta.
 *
 * Requiere en el scope:
 *   - $pdo (PDO)
 *
 * Define:
 *   - $variants_by_item (array)  variantes ordenadas por item_id
 *
 * Cada variante: ['id','item_id','name','description','price'].
 *
 * Si la tabla todavía no existe (migración no corrida) o un item no tiene
 * variantes, queda vacío y el sistema funciona como siempre (precio único).
 */

$variants_by_item = [];

try {
    $__var_stmt = $pdo->query("
        SELECT id, item_id, name, description, price
        FROM menu_item_variants
        ORDER BY item_id, display_order, id
    ");
    foreach ($__var_stmt->fetchAll(PDO::FETCH_ASSOC) as $__v) {
        $variants_by_item[$__v['item_id']][] = [
            'id'          => (int)$__v['id'],
            'item_id'     => (int)$__v['item_id'],
            'name'        => $__v['name'],
            'description' => $__v['description'],
            'price'       => (float)$__v['price'],
        ];
    }
} catch (PDOException $e) {
    // Tabla inexistente o error: degradar silenciosamente a "sin variantes".
    $variants_by_item = [];
}

<?php
session_start();
require_once 'db_connect.php';

// ─── Seguridad básica: solo si hay sesión admin ───────────────────────────────
// Descomentá esto para proteger el editor:
// if (empty($_SESSION['admin'])) { header('Location: login.php'); exit; }

// ─── API AJAX ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'];

    // ── Actualizar precio individual ──────────────────────────────────────────
    if ($action === 'update_price') {
        $id    = (int)$_POST['id'];
        $price = (float)str_replace(',', '.', $_POST['price']);
        try {
            $stmt = $pdo->prepare("UPDATE menu_items SET price = ? WHERE id = ?");
            $stmt->execute([$price, $id]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── Actualización MASIVA de precios ───────────────────────────────────────
    if ($action === 'bulk_update_prices') {
        $items = json_decode($_POST['items'], true);
        if (!is_array($items)) { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit; }
        try {
            $stmt = $pdo->prepare("UPDATE menu_items SET price = ? WHERE id = ?");
            $pdo->beginTransaction();
            foreach ($items as $it) {
                $stmt->execute([(float)$it['price'], (int)$it['id']]);
            }
            $pdo->commit();
            echo json_encode(['ok' => true, 'updated' => count($items)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── Ajuste porcentual masivo ───────────────────────────────────────────────
    if ($action === 'bulk_percent') {
        $pct        = (float)$_POST['percent'];
        $category   = (int)($_POST['category'] ?? 0);
        try {
            if ($category > 0) {
                $stmt = $pdo->prepare("UPDATE menu_items SET price = ROUND(price * (1 + ?/100), 2) WHERE category_id = ?");
                $stmt->execute([$pct, $category]);
            } else {
                $stmt = $pdo->prepare("UPDATE menu_items SET price = ROUND(price * (1 + ?/100), 2)");
                $stmt->execute([$pct]);
            }
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── Togglear visibilidad ──────────────────────────────────────────────────
    if ($action === 'toggle_visibility') {
        $id = (int)$_POST['id'];
        try {
            $stmt = $pdo->prepare("UPDATE menu_items SET is_visible = NOT is_visible WHERE id = ?");
            $stmt->execute([$id]);
            $new = $pdo->query("SELECT is_visible FROM menu_items WHERE id = $id")->fetchColumn();
            echo json_encode(['ok' => true, 'is_visible' => (bool)$new]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── Borrar producto ───────────────────────────────────────────────────────
    if ($action === 'delete_item') {
        $id = (int)$_POST['id'];
        try {
            // Primero borrar subproductos relacionados
            $pdo->prepare("DELETE FROM menu_item_subproducts WHERE parent_item_id = ? OR sub_item_id = ?")->execute([$id, $id]);
            $pdo->prepare("DELETE FROM menu_items WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── Reordenar dentro de categoría ────────────────────────────────────────
    if ($action === 'reorder_items') {
        $order = json_decode($_POST['order'], true); // array de IDs en orden nuevo
        if (!is_array($order)) { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit; }
        try {
            $stmt = $pdo->prepare("UPDATE menu_items SET display_order = ? WHERE id = ?");
            $pdo->beginTransaction();
            foreach ($order as $idx => $itemId) {
                $stmt->execute([$idx + 1, (int)$itemId]);
            }
            $pdo->commit();
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Acción desconocida']);
    exit;
}

// ─── Fetch datos para la vista ────────────────────────────────────────────────
try {
    $cats  = $pdo->query("SELECT * FROM categories ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);
    $items = $pdo->query("
        SELECT mi.*, c.name as cat_name
        FROM menu_items mi
        JOIN categories c ON mi.category_id = c.id
        ORDER BY c.display_order, mi.display_order, mi.name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cats = []; $items = [];
}

// Agrupar items por categoría
$byCategory = [];
foreach ($cats as $cat) { $byCategory[$cat['id']] = ['cat' => $cat, 'items' => []]; }
foreach ($items as $it) { $byCategory[$it['category_id']]['items'][] = $it; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Editor de Productos — Arrabbiata</title>
<link rel="icon" type="image/png" href="/favicon.png">
<link rel="stylesheet" href="arrabbiata.css">
<style>
/* ── Layout principal ──────────────────────────────────────────────────────── */
body { background: var(--crema); font-family: var(--font-body); color: var(--marron); padding-bottom: 60px; }
.editor-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }

/* ── Encabezado ───────────────────────────────────────────────────────────── */
.editor-header {
    background: var(--rojo);
    color: #fff;
    padding: 18px 28px;
    display: flex; align-items: center; gap: 16px;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 3px 0 var(--rojo-d);
    font-family: var(--font-display);
}
.editor-header h1 { font-size: 1.6rem; margin: 0; }
.editor-header a { color: rgba(255,255,255,.8); font-size:.9rem; margin-left: auto; text-decoration:underline; }

/* ── Sección card ─────────────────────────────────────────────────────────── */
.editor-card {
    background: #fff;
    border: 3px solid var(--rojo);
    border-radius: 2px;
    box-shadow: 5px 5px 0 var(--rojo-d);
    margin-bottom: 32px;
    overflow: hidden;
}
.editor-card-header {
    background: var(--rojo);
    color: #fff;
    padding: 10px 18px;
    font-family: var(--font-display);
    font-size: 1.15rem;
    display: flex; align-items: center; gap: 10px;
}
.editor-card-body { padding: 20px; }

/* ── Ajuste masivo de porcentaje ─────────────────────────────────────────── */
.bulk-pct-row {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;
    margin-bottom: 20px; padding-bottom: 18px;
    border-bottom: 2px dashed var(--ocre-d);
}
.bulk-pct-row label { font-size:.85rem; font-weight:600; display:block; margin-bottom:3px; }
.bulk-pct-row input[type=number], .bulk-pct-row select {
    padding: 8px 10px;
    border: 2px solid var(--azul-elec);
    border-radius: 2px;
    font-size: .95rem;
    font-family: var(--font-body);
    min-width: 140px;
}
.btn-pct {
    background: var(--azul-barra);
    color: #fff; border: 2px solid var(--azul-elec);
    padding: 8px 18px; border-radius: 2px;
    font-family: var(--font-display); font-size:.95rem;
    cursor:pointer; box-shadow: 2px 2px 0 var(--azul-elec);
    transition: all .15s;
}
.btn-pct:hover { background: var(--azul-elec); transform: translate(1px,1px); box-shadow: 1px 1px 0 var(--azul-barra-d); }

/* ── Tabla de productos ──────────────────────────────────────────────────── */
.cat-block { margin-bottom: 28px; }
.cat-title {
    font-family: var(--font-display);
    font-size: 1.2rem; color: var(--rojo);
    padding: 6px 0; margin-bottom: 8px;
    border-bottom: 2px solid var(--rojo);
    display: flex; align-items: center; gap: 8px;
}
.cat-title small { font-size:.8rem; color:#888; font-family: var(--font-body); }

/* Tabla */
.items-table {
    width: 100%; border-collapse: collapse;
    font-size: .92rem;
}
.items-table th {
    background: var(--ocre); color: var(--marron);
    padding: 7px 10px; text-align: left;
    font-family: var(--font-display); font-weight: normal;
    font-size:.85rem; border-bottom: 2px solid var(--ocre-d);
}
.items-table td {
    padding: 7px 10px; border-bottom: 1px solid rgba(0,0,0,.07);
    vertical-align: middle;
}
.items-table tr:hover td { background: rgba(212,180,131,.12); }

/* ── Drag handle ──────────────────────────────────────────────────────────── */
.drag-handle {
    cursor: grab; color: #aaa; font-size:1.1rem;
    padding: 0 4px; user-select: none;
}
.drag-handle:active { cursor: grabbing; }
.sortable-ghost { opacity: .35; background: var(--ocre) !important; }
.sortable-chosen { background: rgba(212,180,131,.3) !important; }

/* ── Input de precio inline ──────────────────────────────────────────────── */
.price-input {
    width: 100px; padding: 5px 8px;
    border: 2px solid var(--azul-elec);
    border-radius: 2px;
    font-size: .92rem;
    font-family: var(--font-body);
    transition: border-color .15s;
}
.price-input:focus { outline:none; border-color: var(--rojo); }
.price-input.saved { border-color: #2d8a4e; background: #edfaf1; }
.price-input.error { border-color: var(--rojo); background: #fdecea; }

/* ── Botón guardar precio ─────────────────────────────────────────────────── */
.btn-save-price {
    background: var(--azul-barra); color:#fff;
    border: 2px solid var(--azul-elec);
    padding: 4px 10px; border-radius: 2px;
    font-size:.8rem; cursor:pointer;
    box-shadow: 1px 1px 0 var(--azul-elec);
    transition: all .12s;
    white-space: nowrap;
}
.btn-save-price:hover { background: var(--azul-elec); }

/* ── Botón ojito ─────────────────────────────────────────────────────────── */
.btn-visibility {
    background: none; border: 2px solid #ccc;
    border-radius: 2px; padding: 4px 8px;
    cursor: pointer; font-size: 1.1rem;
    transition: all .15s; line-height: 1;
}
.btn-visibility.visible   { border-color: #2d8a4e; background: #edfaf1; }
.btn-visibility.hidden-p  { border-color: #aaa;    background: #f5f5f5; opacity:.6; }
.btn-visibility:hover      { transform: scale(1.12); }

/* ── Botón borrar ─────────────────────────────────────────────────────────── */
.btn-delete {
    background: none; border: 2px solid var(--rojo);
    color: var(--rojo); border-radius: 2px;
    padding: 4px 8px; cursor: pointer;
    font-size: .95rem; font-weight: bold;
    transition: all .15s; line-height: 1;
}
.btn-delete:hover { background: var(--rojo); color:#fff; }

/* ── Nombre truncado ─────────────────────────────────────────────────────── */
.item-name { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.item-hidden-row td { opacity: .5; }

/* ── Toast de notificación ───────────────────────────────────────────────── */
#toast {
    position: fixed; bottom: 24px; right: 24px;
    background: var(--marron); color:#fff;
    padding: 12px 20px; border-radius: 2px;
    font-size:.9rem; z-index:9999;
    box-shadow: 4px 4px 0 rgba(0,0,0,.25);
    opacity:0; transform: translateY(10px);
    transition: all .25s ease;
    pointer-events: none;
}
#toast.show { opacity:1; transform: translateY(0); }
#toast.ok   { border-left: 4px solid #2d8a4e; }
#toast.err  { border-left: 4px solid var(--rojo); }

/* ── Botón guardar masivo ─────────────────────────────────────────────────── */
.btn-bulk-save {
    background: var(--rojo); color:#fff;
    border: 2px solid var(--rojo-d);
    padding: 10px 24px; border-radius: 2px;
    font-family: var(--font-display); font-size:1rem;
    cursor:pointer; box-shadow: 3px 3px 0 var(--rojo-d);
    transition: all .15s;
}
.btn-bulk-save:hover { background: var(--rojo-d); transform: translate(1px,1px); box-shadow: 2px 2px 0 var(--rojo-d); }

/* ── Responsive ───────────────────────────────────────────────────────────── */
@media (max-width: 600px) {
    .items-table th:nth-child(4),
    .items-table td:nth-child(4) { display:none; } /* ocultar "orden" */
    .price-input { width: 80px; }
    .item-name { max-width: 130px; }
}
</style>
</head>
<body>

<div class="editor-header">
    <h1>🍕 Editor de Productos</h1>
    <a href="carta.php">← Ver carta</a>
</div>

<div class="editor-wrap">

<!-- ═══════════════════════════════════════════════════════════════
     SECCIÓN 1 — ACTUALIZACIÓN MASIVA DE PRECIOS
══════════════════════════════════════════════════════════════════ -->
<div class="editor-card">
    <div class="editor-card-header">📊 Actualización Masiva de Precios</div>
    <div class="editor-card-body">

        <!-- Ajuste porcentual rápido -->
        <div class="bulk-pct-row">
            <div>
                <label>% de ajuste</label>
                <input type="number" id="pctValue" value="10" step="0.1" min="-100" max="500" style="width:100px">
            </div>
            <div>
                <label>Categoría</label>
                <select id="pctCategory">
                    <option value="0">— Todas las categorías —</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>&nbsp;</label>
                <button class="btn-pct" onclick="applyPercent()">Aplicar ajuste %</button>
            </div>
            <div style="font-size:.82rem;color:#888;align-self:center;max-width:220px">
                Ej: +10 sube 10%, −5 baja 5%. Los precios se redondean a 2 decimales.
            </div>
        </div>

        <!-- Tabla editable de todos los precios -->
        <p style="font-size:.85rem;color:#666;margin-bottom:12px">
            Editá los precios directamente en la tabla y hacé clic en <strong>Guardar todos los cambios</strong>.
        </p>

        <?php foreach ($byCategory as $catId => $group):
            if (empty($group['items'])) continue;
        ?>
        <div class="cat-block">
            <div class="cat-title">
                <?= htmlspecialchars($group['cat']['name']) ?>
                <small>(<?= count($group['items']) ?> productos)</small>
            </div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Precio actual</th>
                        <th>Nuevo precio</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($group['items'] as $it): ?>
                    <tr data-id="<?= $it['id'] ?>">
                        <td class="item-name" title="<?= htmlspecialchars($it['name']) ?>">
                            <?= htmlspecialchars($it['name']) ?>
                            <?php if (!$it['is_visible']): ?><span style="color:#aaa;font-size:.75rem"> (oculto)</span><?php endif; ?>
                        </td>
                        <td class="current-price" style="color:var(--rojo);font-weight:600">
                            $<?= number_format($it['price'], 2) ?>
                        </td>
                        <td>
                            <input
                                type="number"
                                class="price-input bulk-price-input"
                                data-id="<?= $it['id'] ?>"
                                data-original="<?= $it['price'] ?>"
                                value="<?= $it['price'] ?>"
                                step="0.01" min="0"
                                placeholder="<?= $it['price'] ?>"
                            >
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <div style="margin-top:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <button class="btn-bulk-save" onclick="bulkSavePrices()">💾 Guardar todos los cambios</button>
            <button style="background:none;border:2px solid #ccc;padding:9px 18px;border-radius:2px;cursor:pointer;font-family:var(--font-display)"
                    onclick="resetBulkInputs()">↩ Resetear cambios</button>
            <span id="bulkStatus" style="font-size:.85rem;color:#666"></span>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     SECCIÓN 2 — GESTIÓN POR CATEGORÍA (visibilidad, borrar, orden)
══════════════════════════════════════════════════════════════════ -->
<div class="editor-card">
    <div class="editor-card-header">🗂 Gestión de Productos por Categoría</div>
    <div class="editor-card-body">
        <p style="font-size:.85rem;color:#666;margin-bottom:18px">
            👁 = visible en carta &nbsp;|&nbsp;
            🚫 = oculto en carta &nbsp;|&nbsp;
            ✕ = eliminar (con confirmación) &nbsp;|&nbsp;
            <span style="color:#888">⠿</span> = arrastrá para reordenar dentro de la categoría
        </p>

        <?php foreach ($byCategory as $catId => $group):
            if (empty($group['items'])) continue;
        ?>
        <div class="cat-block">
            <div class="cat-title">
                <?= htmlspecialchars($group['cat']['name']) ?>
                <small>(<?= count($group['items']) ?> productos)</small>
            </div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width:32px"></th><!-- handle -->
                        <th>Producto</th>
                        <th>Precio</th>
                        <th style="width:50px;text-align:center">Visible</th>
                        <th style="width:50px;text-align:center">Borrar</th>
                    </tr>
                </thead>
                <tbody class="sortable-body" data-category="<?= $catId ?>">
                <?php foreach ($group['items'] as $it): ?>
                    <tr data-id="<?= $it['id'] ?>" class="<?= !$it['is_visible'] ? 'item-hidden-row' : '' ?>">
                        <td><span class="drag-handle" title="Arrastrar para reordenar">⠿</span></td>
                        <td class="item-name" title="<?= htmlspecialchars($it['name']) ?>">
                            <?= htmlspecialchars($it['name']) ?>
                        </td>
                        <td style="color:var(--rojo);font-weight:600">
                            $<?= number_format($it['price'], 2) ?>
                        </td>
                        <td style="text-align:center">
                            <button
                                class="btn-visibility <?= $it['is_visible'] ? 'visible' : 'hidden-p' ?>"
                                data-id="<?= $it['id'] ?>"
                                data-visible="<?= $it['is_visible'] ? '1' : '0' ?>"
                                onclick="toggleVisibility(this)"
                                title="<?= $it['is_visible'] ? 'Visible — clic para ocultar' : 'Oculto — clic para mostrar' ?>"
                            ><?= $it['is_visible'] ? '👁' : '🚫' ?></button>
                        </td>
                        <td style="text-align:center">
                            <button
                                class="btn-delete"
                                data-id="<?= $it['id'] ?>"
                                data-name="<?= htmlspecialchars($it['name']) ?>"
                                onclick="deleteItem(this)"
                                title="Eliminar producto"
                            >✕</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div><!-- /.editor-wrap -->

<div id="toast"></div>

<!-- SortableJS para drag & drop -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>

<script>
// ─── Toast ────────────────────────────────────────────────────────────────────
let toastTimer;
function toast(msg, type = 'ok') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'show ' + type;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.className = ''; }, 3200);
}

// ─── Helper fetch POST ────────────────────────────────────────────────────────
async function postAction(data) {
    const fd = new FormData();
    for (const [k,v] of Object.entries(data)) fd.append(k, v);
    const res = await fetch('editor_productos.php', { method: 'POST', body: fd });
    return await res.json();
}

// ─── Ajuste porcentual ────────────────────────────────────────────────────────
async function applyPercent() {
    const pct = parseFloat(document.getElementById('pctValue').value);
    const cat = parseInt(document.getElementById('pctCategory').value);
    if (isNaN(pct)) { toast('Ingresá un porcentaje válido', 'err'); return; }

    const catName = cat > 0
        ? document.querySelector(`#pctCategory option[value="${cat}"]`).textContent
        : 'todos los productos';
    const sign = pct >= 0 ? '+' : '';
    if (!confirm(`¿Aplicar ${sign}${pct}% a ${catName}?\nEsta acción modifica los precios en la base de datos.`)) return;

    try {
        const r = await postAction({ action: 'bulk_percent', percent: pct, category: cat });
        if (r.ok) {
            toast(`✓ Ajuste de ${sign}${pct}% aplicado. Recargando…`, 'ok');
            setTimeout(() => location.reload(), 1200);
        } else {
            toast('Error: ' + (r.error || 'desconocido'), 'err');
        }
    } catch(e) { toast('Error de conexión', 'err'); }
}

// ─── Guardar precios masivos ──────────────────────────────────────────────────
async function bulkSavePrices() {
    const inputs = document.querySelectorAll('.bulk-price-input');
    const changed = [];
    inputs.forEach(inp => {
        const orig = parseFloat(inp.dataset.original);
        const val  = parseFloat(inp.value);
        if (!isNaN(val) && val !== orig) {
            changed.push({ id: inp.dataset.id, price: val });
        }
    });
    if (changed.length === 0) {
        toast('No hay precios modificados', 'ok');
        return;
    }
    document.getElementById('bulkStatus').textContent = `Guardando ${changed.length} precio(s)…`;
    try {
        const r = await postAction({ action: 'bulk_update_prices', items: JSON.stringify(changed) });
        if (r.ok) {
            toast(`✓ ${r.updated} precio(s) actualizados`, 'ok');
            // Actualizar originales y celdas de precio actual
            inputs.forEach(inp => {
                const val = parseFloat(inp.value);
                inp.dataset.original = val;
                inp.classList.add('saved');
                setTimeout(() => inp.classList.remove('saved'), 2000);
                // Actualizar celda "precio actual"
                const row = inp.closest('tr');
                if (row) {
                    const cell = row.querySelector('.current-price');
                    if (cell) cell.textContent = '$' + val.toFixed(2);
                }
            });
            document.getElementById('bulkStatus').textContent = '';
        } else {
            toast('Error: ' + (r.error || 'desconocido'), 'err');
            document.getElementById('bulkStatus').textContent = '';
        }
    } catch(e) {
        toast('Error de conexión', 'err');
        document.getElementById('bulkStatus').textContent = '';
    }
}

// ─── Resetear inputs bulk ─────────────────────────────────────────────────────
function resetBulkInputs() {
    document.querySelectorAll('.bulk-price-input').forEach(inp => {
        inp.value = inp.dataset.original;
        inp.classList.remove('saved', 'error');
    });
    toast('Precios reseteados (sin guardar)', 'ok');
}

// ─── Resaltar inputs modificados ──────────────────────────────────────────────
document.addEventListener('input', function(e) {
    if (!e.target.classList.contains('bulk-price-input')) return;
    const orig = parseFloat(e.target.dataset.original);
    const val  = parseFloat(e.target.value);
    if (val !== orig) {
        e.target.style.borderColor = 'var(--amarillo)';
        e.target.style.background  = '#fffdf0';
    } else {
        e.target.style.borderColor = '';
        e.target.style.background  = '';
    }
});

// ─── Toggle visibilidad ───────────────────────────────────────────────────────
async function toggleVisibility(btn) {
    const id = btn.dataset.id;
    try {
        const r = await postAction({ action: 'toggle_visibility', id });
        if (r.ok) {
            const vis = r.is_visible;
            btn.dataset.visible = vis ? '1' : '0';
            btn.textContent     = vis ? '👁' : '🚫';
            btn.className       = 'btn-visibility ' + (vis ? 'visible' : 'hidden-p');
            btn.title           = vis ? 'Visible — clic para ocultar' : 'Oculto — clic para mostrar';
            // Dim row
            const row = btn.closest('tr');
            if (row) row.className = vis ? '' : 'item-hidden-row';
            toast(vis ? '👁 Producto visible en carta' : '🚫 Producto oculto en carta', 'ok');
        } else {
            toast('Error: ' + (r.error || 'desconocido'), 'err');
        }
    } catch(e) { toast('Error de conexión', 'err'); }
}

// ─── Borrar producto ──────────────────────────────────────────────────────────
async function deleteItem(btn) {
    const id   = btn.dataset.id;
    const name = btn.dataset.name;
    if (!confirm(`¿Eliminar "${name}"?\n\nEsta acción no se puede deshacer.`)) return;

    try {
        const r = await postAction({ action: 'delete_item', id });
        if (r.ok) {
            const row = btn.closest('tr');
            if (row) {
                row.style.transition = 'opacity .3s, height .3s';
                row.style.opacity = '0';
                setTimeout(() => row.remove(), 320);
            }
            toast(`✓ "${name}" eliminado`, 'ok');
        } else {
            toast('Error al eliminar: ' + (r.error || 'desconocido'), 'err');
        }
    } catch(e) { toast('Error de conexión', 'err'); }
}

// ─── Drag & drop para reordenar ───────────────────────────────────────────────
document.querySelectorAll('.sortable-body').forEach(tbody => {
    Sortable.create(tbody, {
        handle: '.drag-handle',
        animation: 150,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        onEnd: async function(evt) {
            // Recalcular orden del tbody afectado
            const rows  = [...evt.to.querySelectorAll('tr[data-id]')];
            const order = rows.map(r => r.dataset.id);
            try {
                const r = await postAction({ action: 'reorder_items', order: JSON.stringify(order) });
                if (r.ok) {
                    toast('✓ Orden guardado', 'ok');
                } else {
                    toast('Error al guardar orden: ' + (r.error || ''), 'err');
                }
            } catch(e) { toast('Error de conexión', 'err'); }
        }
    });
});
</script>
</body>
</html>

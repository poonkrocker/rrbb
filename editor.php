<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// Initialize error and success arrays
$errors = [];
$success = [];


/* ==== HOURS_PATCH_24PLUS_START ==== */
/* Helpers y manejador temprano para business_hours con soporte 24+ y cruces de medianoche */

// ---- Helpers ----
if (!function_exists('normTime')) {
function normTime(?string $t): string {
    $t = trim((string)$t);
    if ($t === '') return $t;
    if (preg_match('/^\d{1,3}:\d{2}$/', $t)) return $t . ':00';
    if (preg_match('/^\d{1,3}:\d{2}:\d{2}$/', $t)) return $t;
    $parts = explode(':', $t);
    $h = str_pad((string)max(0, (int)($parts[0] ?? 0)), 2, '0', STR_PAD_LEFT);
    $m = str_pad((string)max(0, (int)($parts[1] ?? 0)), 2, '0', STR_PAD_LEFT);
    $s = str_pad((string)max(0, (int)($parts[2] ?? 0)), 2, '0', STR_PAD_LEFT);
    return "$h:$m:$s";
}}

if (!function_exists('timeToSeconds')) {
function timeToSeconds(string $hhmmss): int {
    [$h,$m,$s] = array_map('intval', explode(':', $hhmmss));
    return $h*3600 + $m*60 + $s;
}}

if (!function_exists('secondsToHms')) {
function secondsToHms(int $secs): string {
    $secs = $secs % 86400;
    $h = str_pad((string)intdiv($secs, 3600), 2, '0', STR_PAD_LEFT);
    $m = str_pad((string)intdiv($secs % 3600, 60), 2, '0', STR_PAD_LEFT);
    $s = str_pad((string)($secs % 60), 2, '0', STR_PAD_LEFT);
    return "$h:$m:$s";
}}

if (!function_exists('nextDayOfWeek')) {
function nextDayOfWeek(string $day): string {
    $days = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    $i = array_search($day, $days, true);
    if ($i === false) throw new Exception("Día inválido: $day");
    return $days[($i + 1) % 7];
}}

if (!function_exists('hasOverlap')) {
function hasOverlap(PDO $pdo, string $day, string $startHms, string $endHms, ?int $excludeId = null): bool {
    $sql = "SELECT COUNT(*) FROM business_hours WHERE day_of_week = :day ";
    if ($excludeId) $sql .= "AND id != :id ";
    $sql .= "AND NOT (CAST(end_time AS TIME) <= CAST(:start AS TIME) OR CAST(start_time AS TIME) >= CAST(:end AS TIME))";
    $st = $pdo->prepare($sql);
    $st->bindValue(':day', $day);
    if ($excludeId) $st->bindValue(':id', $excludeId, PDO::PARAM_INT);
    $st->bindValue(':start', $startHms);
    $st->bindValue(':end', $endHms);
    $st->execute();
    return (int)$st->fetchColumn() > 0;
}}

if (!function_exists('normalizeAndSplit')) {
function normalizeAndSplit(string $day, string $startHms, string $endHms): array {
    $dayOut = $day;
    $s = timeToSeconds($startHms);
    $e = timeToSeconds($endHms);
    while ($s >= 86400) {
        $s -= 86400; $e -= 86400; $dayOut = nextDayOfWeek($dayOut);
    }
    if ($e < 86400 && $e > $s) {
        return [[ $dayOut, secondsToHms($s), secondsToHms($e) ]];
    }
    $firstEnd = 86399;
    $secondStart = 0;
    $secondEnd = $e;
    if ($secondEnd >= 86400) $secondEnd -= 86400;
    $next = nextDayOfWeek($dayOut);
    return [
        [ $dayOut, secondsToHms($s), secondsToHms($firstEnd) ],
        [ $next, secondsToHms($secondStart), secondsToHms($secondEnd) ],
    ];
}}

// ---- Manejador temprano ----
if (isset($_POST['action']) && in_array($_POST['action'], ['add_business_hours','update_business_hours','delete_business_hours'], true)) {
    try {
        if ($_POST['action'] === 'add_business_hours') {
            $day        = isset($_POST['day_of_week']) ? trim($_POST['day_of_week']) : '';
            $start_time = isset($_POST['start_time']) ? normTime($_POST['start_time']) : '';
            $end_time   = isset($_POST['end_time']) ? normTime($_POST['end_time']) : '';
            if ($day === '' || $start_time === '' || $end_time === '') {
                throw new Exception("Día, inicio y fin son obligatorios.");
            }
            $spans = normalizeAndSplit($day, $start_time, $end_time);
            foreach ($spans as [$d,$a,$b]) {
                if (hasOverlap($pdo, $d, $a, $b, null)) {
                    throw new Exception("Error: El horario se superpone con otro horario existente para $d.");
                }
            }
            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare("INSERT INTO business_hours (day_of_week, start_time, end_time) VALUES (?,?,?)");
                foreach ($spans as [$d,$a,$b]) { $ins->execute([$d,$a,$b]); }
                $pdo->commit();
                $success[] = count($spans)===2 ? "Horario agregado (cruza medianoche)." : "Horario agregado correctamente.";
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        } elseif ($_POST['action'] === 'update_business_hours') {
            $id         = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $day        = isset($_POST['day_of_week']) ? trim($_POST['day_of_week']) : '';
            $start_time = isset($_POST['start_time']) ? normTime($_POST['start_time']) : '';
            $end_time   = isset($_POST['end_time']) ? normTime($_POST['end_time']) : '';
            if (!$id) throw new Exception("ID inválido.");
            if ($day === '' || $start_time === '' || $end_time === '') {
                throw new Exception("Día, inicio y fin son obligatorios.");
            }
            $spans = normalizeAndSplit($day, $start_time, $end_time);
            foreach ($spans as $idx => [$d,$a,$b]) {
                $exclude = ($idx==0) ? $id : null;
                if (hasOverlap($pdo, $d, $a, $b, $exclude)) {
                    throw new Exception("Error: El horario se superpone con otro horario existente para $d.");
                }
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM business_hours WHERE id = ?")->execute([$id]);
                $ins = $pdo->prepare("INSERT INTO business_hours (day_of_week, start_time, end_time) VALUES (?,?,?)");
                foreach ($spans as [$d,$a,$b]) { $ins->execute([$d,$a,$b]); }
                $pdo->commit();
                $success[] = count($spans)===2 ? "Horario actualizado (cruza medianoche)." : "Horario actualizado correctamente.";
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        } elseif ($_POST['action'] === 'delete_business_hours') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$id) throw new Exception("ID inválido.");
            $pdo->prepare("DELETE FROM business_hours WHERE id = ?")->execute([$id]);
            $success[] = "Horario eliminado.";
        }
        // Evitamos que el resto del archivo procese estas acciones nuevamente
        $_POST['action'] = '__hours_handled__';
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
        $_POST['action'] = '__hours_handled__';
    }
}
/* ==== HOURS_PATCH_24PLUS_END ==== */

// ---- Upload seguro de imágenes ----
function uploadImageSecure(array $file, string $target_dir): string {
    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $allowed_ext  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    // Verificar errores de upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Error al recibir el archivo (código {$file['error']}).");
    }

    // Verificar MIME real con finfo (no confiar en el del cliente)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed_mime, true)) {
        throw new Exception("Tipo de archivo no permitido. Solo se aceptan imágenes (JPG, PNG, WEBP, GIF).");
    }

    // Obtener extensión real desde el MIME (ignorar la del nombre original)
    $ext_map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    $ext = $ext_map[$mime];

    // Nombre completamente generado — sin datos del cliente
    $image_name = uniqid('img_', true) . '.' . $ext;

    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $dest = $target_dir . $image_name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new Exception("Error al guardar la imagen en el servidor.");
    }
    return $dest;
}

// ---- Token CSRF ----
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Petición no válida (CSRF).');
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    try {
        if ($_POST['action'] === 'add_item') {
            $name = trim($_POST['name']);
            $price = (float)$_POST['price'];
            $secondary_price = !empty($_POST['secondary_price']) ? (float)$_POST['secondary_price'] : null;
            $category_id = (int)$_POST['category_id'];
            $description = trim($_POST['description']);
            $is_visible = isset($_POST['is_visible']) ? 1 : 0;
            $has_vegan_option = isset($_POST['has_vegan_option']) ? 1 : 0;
            $requires_pizza = isset($_POST['requires_pizza']) ? 1 : 0;
            $is_weekly_special = isset($_POST['is_weekly_special']) ? 1 : 0;
            $weekly_special_text = !empty($_POST['weekly_special_text']) ? trim($_POST['weekly_special_text']) : '¡Pizza de la semana!';
            $is_secret_menu = isset($_POST['is_secret_menu']) ? 1 : 0;
            // Si es carta secreta, forzar el texto
            if ($is_secret_menu) {
                $weekly_special_text = 'Carta Secreta!';
            }
            $visible_start_time = $_POST['visible_start_time'] ?: null;
            $visible_end_time = $_POST['visible_end_time'] ?: null;
            $visible_days = !empty($_POST['visible_days']) ? json_encode($_POST['visible_days']) : null;
            $display_order = (int)$_POST['display_order'];
            $required_selections = !empty($_POST['required_selections']) ? (int)$_POST['required_selections'] : null;

            if (empty($name) || $price <= 0 || $category_id <= 0) {
                throw new Exception("Nombre, precio y categoría son obligatorios y deben ser válidos.");
            }
            if ($secondary_price !== null && $secondary_price <= 0) {
                throw new Exception("El precio secundario debe ser mayor que 0 si se especifica.");
            }

            $image_url = '';
            if (!empty($_POST['image_url'])) {
                $image_url = filter_var($_POST['image_url'], FILTER_SANITIZE_URL);
                if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
                    throw new Exception("La URL de la imagen no es válida.");
                }
            } elseif (!empty($_FILES['image']['name'])) {
                $image_url = uploadImageSecure($_FILES['image'], "Uploads/");
            }

            $stmt = $pdo->prepare("
                INSERT INTO menu_items (name, price, secondary_price, category_id, image_url, description, is_visible, has_vegan_option, requires_pizza, is_weekly_special, weekly_special_text, visible_start_time, visible_end_time, visible_days, display_order, required_selections, is_secret_menu)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $price, $secondary_price, $category_id, $image_url, $description, $is_visible,
                $has_vegan_option, $requires_pizza, $is_weekly_special, $weekly_special_text, $visible_start_time, $visible_end_time, $visible_days,
                $display_order, $required_selections, $is_secret_menu
            ]);

            $item_id = $pdo->lastInsertId();

            if (!empty($_POST['sub_item_ids']) && !empty($_POST['sub_item_quantities'])) {
                $stmt = $pdo->prepare("INSERT INTO menu_item_subproducts (parent_item_id, sub_item_id, quantity, is_required) VALUES (?, ?, ?, ?)");
                foreach ($_POST['sub_item_ids'] as $index => $sub_item_id) {
                    $sub_item_id = (int)$_POST['sub_item_ids'][$index];
                    $quantity = (int)$_POST['sub_item_quantities'][$index];
                    $is_required = isset($_POST['sub_item_required'][$index]) ? 1 : 0;
                    if ($sub_item_id && $quantity > 0 && $sub_item_id != $item_id) {
                        $stmt->execute([$item_id, $sub_item_id, $quantity, $is_required]);
                    }
                }
            }

            $success[] = "Producto agregado correctamente.";
        } elseif ($_POST['action'] === 'delete_item') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM menu_item_subproducts WHERE parent_item_id = ?");
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM menu_items WHERE id = ?");
            $stmt->execute([$id]);
            $success[] = "Producto eliminado correctamente.";
        } elseif ($_POST['action'] === 'update_item') {
            $id = (int)$_POST['id'];
            $name = trim($_POST['name']);
            $price = (float)$_POST['price'];
            $secondary_price = !empty($_POST['secondary_price']) ? (float)$_POST['secondary_price'] : null;
            $category_id = (int)$_POST['category_id'];
            $description = trim($_POST['description']);
            $is_visible = isset($_POST['is_visible']) ? 1 : 0;
            $has_vegan_option = isset($_POST['has_vegan_option']) ? 1 : 0;
            $requires_pizza = isset($_POST['requires_pizza']) ? 1 : 0;
            $is_weekly_special = isset($_POST['is_weekly_special']) ? 1 : 0;
            $weekly_special_text = !empty($_POST['weekly_special_text']) ? trim($_POST['weekly_special_text']) : '¡Pizza de la semana!';
            $is_secret_menu = isset($_POST['is_secret_menu']) ? 1 : 0;
            // Si es carta secreta, forzar el texto
            if ($is_secret_menu) {
                $weekly_special_text = 'Carta Secreta!';
            }
            $visible_start_time = $_POST['visible_start_time'] ?: null;
            $visible_end_time = $_POST['visible_end_time'] ?: null;
            $visible_days = !empty($_POST['visible_days']) ? json_encode($_POST['visible_days']) : null;
            $display_order = (int)$_POST['display_order'];
            $required_selections = !empty($_POST['required_selections']) ? (int)$_POST['required_selections'] : null;

            if (empty($name) || $price <= 0 || $category_id <= 0) {
                throw new Exception("Nombre, precio y categoría son obligatorios y deben ser válidos.");
            }
            if ($secondary_price !== null && $secondary_price <= 0) {
                throw new Exception("El precio secundario debe ser mayor que 0 si se especifica.");
            }

            $image_url = $_POST['existing_image'];
            if (!empty($_POST['image_url'])) {
                $image_url = filter_var($_POST['image_url'], FILTER_SANITIZE_URL);
                if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
                    throw new Exception("La URL de la imagen no es válida.");
                }
            } elseif (!empty($_FILES['image']['name'])) {
                $image_url = uploadImageSecure($_FILES['image'], "Uploads/");
            }

            $stmt = $pdo->prepare("
                UPDATE menu_items SET name = ?, price = ?, secondary_price = ?, category_id = ?, image_url = ?, description = ?,
                is_visible = ?, has_vegan_option = ?, requires_pizza = ?, is_weekly_special = ?, weekly_special_text = ?, visible_start_time = ?, visible_end_time = ?,
                visible_days = ?, display_order = ?, required_selections = ?, is_secret_menu = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $price, $secondary_price, $category_id, $image_url, $description, $is_visible,
                $has_vegan_option, $requires_pizza, $is_weekly_special, $weekly_special_text, $visible_start_time, $visible_end_time, $visible_days,
                $display_order, $required_selections, $is_secret_menu, $id
            ]);

            $stmt = $pdo->prepare("DELETE FROM menu_item_subproducts WHERE parent_item_id = ?");
            $stmt->execute([$id]);
            if (!empty($_POST['sub_item_ids']) && !empty($_POST['sub_item_quantities'])) {
                $stmt = $pdo->prepare("INSERT INTO menu_item_subproducts (parent_item_id, sub_item_id, quantity, is_required) VALUES (?, ?, ?, ?)");
                foreach ($_POST['sub_item_ids'] as $index => $sub_item_id) {
                    $sub_item_id = (int)$_POST['sub_item_ids'][$index];
                    $quantity = (int)$_POST['sub_item_quantities'][$index];
                    $is_required = isset($_POST['sub_item_required'][$index]) ? 1 : 0;
                    if ($sub_item_id && $quantity > 0 && $sub_item_id != $id) {
                        $stmt->execute([$id, $sub_item_id, $quantity, $is_required]);
                    }
                }
            }

            $success[] = "Producto actualizado correctamente.";
        } elseif ($_POST['action'] === 'add_category') {
            $name = trim($_POST['category_name']);
            $display_order = (int)$_POST['category_display_order'];
            if (empty($name)) {
                throw new Exception("El nombre de la categoría es obligatorio.");
            }
            $stmt = $pdo->prepare("INSERT INTO categories (name, display_order) VALUES (?, ?)");
            $stmt->execute([$name, $display_order]);
            $success[] = "Categoría agregada correctamente.";
        } elseif ($_POST['action'] === 'update_category') {
            $id = (int)$_POST['category_id'];
            $name = trim($_POST['category_name']);
            $display_order = (int)$_POST['category_display_order'];
            if (empty($name)) {
                throw new Exception("El nombre de la categoría es obligatorio.");
            }
            $stmt = $pdo->prepare("UPDATE categories SET name = ?, display_order = ? WHERE id = ?");
            $stmt->execute([$name, $display_order, $id]);
            $success[] = "Categoría actualizada correctamente.";
        } elseif ($_POST['action'] === 'delete_category') {
            $id = (int)$_POST['category_id'];
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE category_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() == 0) {
                $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
                $stmt->execute([$id]);
                $success[] = "Categoría eliminada correctamente.";
            } else {
                $errors[] = "No se puede eliminar una categoría con productos asociados.";
            }
        } elseif ($_POST['action'] === 'add_business_hours') {
            $day = trim($_POST['day_of_week']);
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];

            if (empty($day) || empty($start_time) || empty($end_time)) {
                throw new Exception("Día, hora de inicio y hora de fin son obligatorios.");
            }

            // Permitir horarios que crucen medianoche
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM business_hours WHERE day_of_week = ? AND (
                (start_time <= ? AND end_time >= ?) OR 
                (start_time <= ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )");
            $stmt->execute([$day, $end_time, $start_time, $start_time, $end_time, $start_time, $end_time]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("El horario se superpone con otro horario existente para el mismo día.");
            }

            $stmt = $pdo->prepare("INSERT INTO business_hours (day_of_week, start_time, end_time) VALUES (?, ?, ?)");
            $stmt->execute([$day, $start_time, $end_time]);
            $success[] = "Horario agregado correctamente.";
        } elseif ($_POST['action'] === 'update_business_hours') {
            $id = (int)$_POST['id'];
            $day = trim($_POST['day_of_week']);
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];

            if (empty($day) || empty($start_time) || empty($end_time)) {
                throw new Exception("Día, hora de inicio y hora de fin son obligatorios.");
            }

            // Permitir horarios que crucen medianoche
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM business_hours WHERE day_of_week = ? AND id != ? AND (
                (start_time <= ? AND end_time >= ?) OR 
                (start_time <= ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )");
            $stmt->execute([$day, $id, $end_time, $start_time, $start_time, $end_time, $start_time, $end_time]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("El horario se superpone con otro horario existente para el mismo día.");
            }

            $stmt = $pdo->prepare("UPDATE business_hours SET day_of_week = ?, start_time = ?, end_time = ? WHERE id = ?");
            $stmt->execute([$day, $start_time, $end_time, $id]);
            $success[] = "Horario actualizado correctamente.";
        } elseif ($_POST['action'] === 'delete_business_hours') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM business_hours WHERE id = ?");
            $stmt->execute([$id]);
            $success[] = "Horario eliminado correctamente.";
        } elseif ($_POST['action'] === 'update_order') {
            if (!empty($_POST['order']) && is_array($_POST['order'])) {
                $stmt = $pdo->prepare("UPDATE menu_items SET display_order = ? WHERE id = ?");
                foreach ($_POST['order'] as $index => $item_id) {
                    $stmt->execute([(int)$index, (int)$item_id]);
                }
                $success[] = "Orden de productos actualizado correctamente.";
            } else {
                $errors[] = "No se recibieron datos válidos para actualizar el orden.";
            }
        }
    } catch (Exception $e) {
        $errors[] = "Error: " . $e->getMessage();
        file_put_contents('error_log.txt', "Error: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// Fetch all categories
$categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY display_order");
$categories = $categories_stmt->fetchAll();

// Fetch all menu items, grouped by category
$menu_items_by_category = [];
$stmt = $pdo->query("
    SELECT mi.*, c.name AS category_name
    FROM menu_items mi
    JOIN categories c ON mi.category_id = c.id
    ORDER BY c.display_order, mi.display_order
");
$menu_items = $stmt->fetchAll();
foreach ($menu_items as $item) {
    $menu_items_by_category[$item['category_id']][] = $item;
}

// Fetch sub-products with required flag — una sola query
$sub_products = [];
if (!empty($menu_items)) {
    $ids = array_column($menu_items, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT ms.parent_item_id, mi.id, mi.name, ms.quantity, ms.is_required
        FROM menu_item_subproducts ms
        JOIN menu_items mi ON ms.sub_item_id = mi.id
        WHERE ms.parent_item_id IN ($placeholders)
        ORDER BY ms.parent_item_id
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $sub_products[$row['parent_item_id']][] = $row;
    }
}

// Fetch business hours
$business_hours_stmt = $pdo->query("SELECT * FROM business_hours ORDER BY FIELD(day_of_week, 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'), start_time");
$business_hours = $business_hours_stmt->fetchAll();

// Days of the week
$days_of_week = [
    'Lunes' => 'L',
    'Martes' => 'M',
    'Miércoles' => 'X',
    'Jueves' => 'J',
    'Viernes' => 'V',
    'Sábado' => 'S',
    'Domingo' => 'D'
];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor de Menú - Pizzería Arrabbiata</title>
    <link rel="icon" type="image/png" href="/favicon.png"/>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        @font-face {
            font-family: 'Alberdi';
            src: url('dk.woff2') format('woff2'),
                 url('dk.woff') format('woff');
            font-weight: normal;
            font-style: normal;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }

        html {
            scroll-behavior: smooth;
            overflow-x: hidden;
        }

        body {
            overflow-x: hidden;
            background: #f5f5f5;
        }

        .nav-menu {
            position: fixed;
            top: 30px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            background-color: rgba(255, 255, 255, 0.98);
            border-radius: 25px;
            padding: 10px 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            display: inline-block;
            transition: all 0.3s ease;
        }

        .nav-menu ul {
            list-style: none;
            display: flex;
            gap: 20px;
            margin: 0;
        }

        .nav-menu a {
            text-decoration: none;
            color: #333;
            font-size: 15px;
            font-weight: 600;
            padding: 8px 15px;
            border-radius: 20px;
            transition: all 0.3s ease;
        }

        .nav-menu a:hover {
            background-color: #cc0000;
            color: white;
            transform: translateY(-2px);
        }

        .section {
            width: 100%;
            padding: 20px;
            background-color: #FDB740;
            min-height: auto;
        }

        .editor-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: rgba(255, 255, 255, 0.9);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .editor-container h2 {
            text-align: center;
            font-size: 2rem;
            margin-bottom: 40px;
            color: #333;
            font-family: 'Alberdi', cursive;
            font-weight: 700;
            padding-top: 40px;
        }

        details {
            margin-bottom: 20px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        details summary {
            padding: 15px;
            font-size: 1.5rem;
            color: #333;
            font-family: 'Alberdi', cursive;
            font-weight: 700;
            cursor: pointer;
            border-bottom: 2px solid #333;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        details summary:hover {
            background-color: #cc0000;
            color: white;
        }

        details[open] summary {
            background-color: #cc0000;
            color: white;
        }

        .editor-form {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .editor-form input:not([type="url"]):not([type="file"]),
        .editor-form select,
        .modal-form input:not([type="url"]):not([type="file"]),
        .modal-form select {
            width: 100%;
            max-width: 30%;
            padding: 12px;
            margin: 10px 0;
            border: 2px solid #ddd;
            border-radius: 25px;
            font-size: 16px;
            font-family: 'Arial', sans-serif;
            color: #555;
            background: #fff;
            transition: border-color 0.3s ease;
        }

        .editor-form textarea,
        .editor-form input[type="url"],
        .modal-form textarea,
        .modal-form input[type="url"] {
            width: 100%;
            max-width: 60%;
            padding: 12px;
            margin: 10px 0;
            border: 2px solid #ddd;
            border-radius: 25px;
            font-size: 16px;
            font-family: 'Arial', sans-serif;
            color: #555;
            background: #fff;
            transition: border-color 0.3s ease;
        }

        .editor-form input:focus,
        .editor-form select:focus,
        .editor-form textarea:focus,
        .modal-form input:focus,
        .modal-form select:focus,
        .modal-form textarea:focus {
            border-color: #cc0000;
            outline: none;
        }

        .editor-form input[type="file"],
        .modal-form input[type="file"] {
            width: 100%;
            max-width: 30%;
            padding: 12px 0;
            margin: 10px 0;
        }

        .editor-form label,
        .modal-form label {
            font-size: 1rem;
            color: #333;
            font-weight: 600;
            margin: 10px 5px;
            display: inline-block;
            font-family: 'Arial', sans-serif;
        }

        .editor-form p.note,
        .modal-form p.note {
            font-size: 0.9rem;
            color: #555;
            margin: 5px 0;
        }

        .toggle-group {
            display: flex;
            gap: 20px;
            align-items: center;
            margin: 10px 0;
        }

        .days-inputs,
        .time-inputs,
        .sub-items-inputs,
        .toggle-inputs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 10px 0;
            flex-direction: column;
        }

        .days-selector,
        .toggle-selector {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .day-toggle,
        .option-toggle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #e0e0e0;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .day-toggle.active,
        .option-toggle.active {
            background-color: #cc0000;
            color: white;
            border-color: #b30000;
        }

        .day-toggle:hover,
        .option-toggle:hover {
            background-color: #b30000;
            color: white;
        }

        .day-toggle:focus,
        .option-toggle:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(204, 0, 0, 0.3);
        }

        .sub-item {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            width: 100%;
        }

        .sub-item select,
        .sub-item input[type="number"],
        .sub-item input[type="checkbox"] {
            flex: 1;
            max-width: 30%;
            min-width: 120px;
        }

        .sub-item input[type="checkbox"] {
            max-width: 50px;
        }

        .sub-item label {
            font-size: 0.9rem;
            color: #333;
            font-weight: 600;
            margin: 10px 5px;
        }

        .sub-item .required-label {
            color: #cc0000;
            font-weight: bold;
        }

        .editor-form button,
        .modal-form button {
            background: #cc0000;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
            font-family: 'Arial', sans-serif;
            align-self: flex-start;
            max-width: none;
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .editor-form button:hover,
        .modal-form button:hover {
            background: #b30000;
            transform: translateY(-2px);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
        }

        .item-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 20px;
            padding: 20px;
        }

        .item-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            cursor: move;
            position: relative;
        }

        .item-card:hover {
            transform: translateY(-5px);
        }

        .item-card img {
            width: 100%;
            height: 100px;
            object-fit: cover;
        }

        .item-card h3 {
            font-size: 1rem;
            color: #333;
            margin: 10px;
            font-family: 'Alberdi', cursive;
            font-weight: 700;
            text-align: center;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            overflow-y: auto;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 20px;
            max-width: 600px;
            width: 90%;
            position: relative;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-close {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 1.5rem;
            cursor: pointer;
            color: #333;
        }

        .modal-close:hover {
            color: #cc0000;
        }

        .modal-content img {
            max-width: 100%;
            height: auto;
            max-height: 200px;
            object-fit: contain;
            border-radius: 10px;
            margin-bottom: 15px;
            display: block;
        }

        .category-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            padding: 20px;
        }

        .category-item {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .category-item:hover {
            transform: translateY(-5px);
        }

        .category-item input,
        .category-item button {
            margin-bottom: 10px;
        }

        .error {
            color: #cc0000;
            font-size: 1rem;
            margin: 10px 0;
            text-align: center;
        }

        .success {
            color: #28a745;
            font-size: 1rem;
            margin: 10px 0;
            text-align: center;
        }

        .hours-table-container {
            overflow-x: auto;
        }

        .hours-table {
            width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            table-layout: fixed;
        }

        .hours-table th, .hours-table td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: left;
            font-size: 13px;
            word-wrap: break-word;
        }

        .hours-table th {
            background-color: #cc0000;
            color: white;
        }

        .hours-table td form {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            align-items: center;
        }

        .hours-table select, .hours-table input[type="time"] {
            padding: 5px;
            border-radius: 5px;
            border: 1px solid #ddd;
            font-size: 12px;
            max-width: 100px;
        }

        .hours-table button {
            padding: 5px 8px;
            font-size: 11px;
        }

        /* Nuevos estilos para el campo de texto destacado */
        .weekly-special-text {
            width: 100%;
            max-width: 60%;
            padding: 12px;
            margin: 10px 0;
            border: 2px solid #ddd;
            border-radius: 25px;
            font-size: 16px;
            font-family: 'Arial', sans-serif;
            color: #555;
            background: #fff;
            transition: border-color 0.3s ease;
        }

        .weekly-special-text:focus {
            border-color: #cc0000;
            outline: none;
        }

        @media (max-width: 768px) {
            .nav-menu {
                top: 20px;
                padding: 8px 15px;
            }

            .nav-menu ul {
                gap: 10px;
            }

            .nav-menu a {
                font-size: 14px;
                padding: 6px 12px;
            }

            .section {
                padding: 15px;
            }

            .editor-container {
                padding: 20px;
            }

            .editor-container h2 {
                padding-top: 60px;
            }

            .editor-form input,
            .editor-form select,
            .editor-form textarea,
            .modal-form input,
            .modal-form select,
            .modal-form textarea,
            .sub-item select,
            .sub-item input[type="number"],
            .sub-item input[type="checkbox"],
            .hours-table select,
            .hours-table input[type="time"],
            .weekly-special-text {
                max-width: 100%;
                min-width: unset;
                font-size: 12px;
                padding: 8px;
            }

            .hours-table th, .hours-table td {
                font-size: 11px;
                padding: 4px;
            }

            .hours-table select, .hours-table input[type="time"] {
                max-width: 80px;
                font-size: 11px;
            }

            .hours-table button {
                padding: 4px 6px;
                font-size: 10px;
            }

            .days-selector,
            .toggle-selector {
                justify-content: space-between;
            }

            .day-toggle,
            .option-toggle {
                width: 35px;
                height: 35px;
                font-size: 14px;
            }

            .sub-item {
                flex-direction: column;
                align-items: stretch;
            }

            .sub-item select,
            .sub-item input[type="number"],
            .sub-item input[type="checkbox"],
            .sub-item button {
                width: 100%;
                max-width: none;
                min-width: unset;
            }

            .toggle-group {
                flex-direction: column;
                align-items: flex-start;
            }

            .hours-table td form {
                flex-direction: column;
                align-items: stretch;
            }

            .item-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }

            .item-card img {
                height: 80px;
            }

            .item-card h3 {
                font-size: 0.9rem;
            }

            .modal-content {
                width: 95%;
                padding: 15px;
            }

            .modal-content img {
                max-height: 150px;
            }
        }

        @media (max-width: 480px) {
            .nav-menu {
                width: 90%;
                padding: 6px 10px;
            }

            .nav-menu a {
                font-size: 12px;
                padding: 5px 8px;
            }

            .editor-container h2,
            details summary {
                font-size: 1.3rem;
            }

            .editor-form input,
            .editor-form select,
            .editor-form textarea,
            .modal-form input,
            .modal-form select,
            .modal-form textarea,
            .weekly-special-text {
                font-size: 12px;
                padding: 8px;
            }

            .editor-form button,
            .modal-form button {
                padding: 8px 12px;
                font-size: 12px;
            }

            .day-toggle,
            .option-toggle {
                width: 30px;
                height: 30px;
                font-size: 12px;
            }

            .hours-table th, .hours-table td {
                font-size: 10px;
                padding: 3px;
            }

            .hours-table select, .hours-table input[type="time"] {
                max-width: 70px;
                font-size: 10px;
            }

            .hours-table button {
                padding: 3px 5px;
                font-size: 9px;
            }

            .item-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }

            .item-card img {
                height: 60px;
            }

            .item-card h3 {
                font-size: 0.8rem;
            }

            .modal-content {
                padding: 10px;
            }

            .modal-content img {
                max-height: 120px;
            }
        }
    </style>
</head>
<body>
    <nav class="nav-menu">
        <ul>
            <li><a href="index.php#home">Home</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </nav>

    <section class="section">
        <div class="editor-container">
            <h2>Editor de Menú</h2>
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $error): ?>
                    <p class="error"><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
                <?php foreach ($success as $msg): ?>
                    <p class="success"><?php echo htmlspecialchars($msg); ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
 <!-- Business Hours Management -->
            <details>
                <summary>Administrar Horarios de Atención</summary>
                <div class="editor-form">
                    <h3>Agregar Nuevo Horario</h3>
                    <form method="POST" onsubmit="return validateBusinessHoursForm(this)">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="add_business_hours">
                        <select name="day_of_week" required>
                            <option value="">Selecciona un día</option>
                            <?php foreach ($days_of_week as $day => $abbr): ?>
                                <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="time" name="start_time" required>
                        <input type="time" name="end_time" required>
                        <p class="note">Nota: Puedes ingresar horarios que crucen la medianoche (ej. 20:00 a 00:30).</p>
                        <button type="submit">Agregar Horario</button>
                    </form>
                </div>
                <div class="hours-table-container">
                    <h3>Horarios Existentes</h3>
                    <table class="hours-table">
                        <thead>
                            <tr>
                                <th>Día</th>
                                <th>Hora de Inicio</th>
                                <th>Hora de Fin</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($business_hours as $hours): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($hours['day_of_week']); ?></td>
                                    <td><?php echo htmlspecialchars($hours['start_time']); ?></td>
                                    <td><?php echo htmlspecialchars($hours['end_time']); ?></td>
                                    <td>
                                        <form method="POST" class="hours-form" onsubmit="return validateBusinessHoursForm(this)">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="update_business_hours">
                                            <input type="hidden" name="id" value="<?php echo $hours['id']; ?>">
                                            <select name="day_of_week" required>
                                                <option value="">Selecciona un día</option>
                                                <?php foreach ($days_of_week as $day => $abbr): ?>
                                                    <option value="<?php echo $day; ?>" <?php if ($hours['day_of_week'] === $day) echo 'selected'; ?>>
                                                        <?php echo $day; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="time" name="start_time" value="<?php echo $hours['start_time']; ?>" required>
                                            <input type="time" name="end_time" value="<?php echo $hours['end_time']; ?>" required>
                                            <button type="submit">Actualizar</button>
                                            <button type="submit" name="action" value="delete_business_hours">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <!-- Category Management -->
            <details>
                <summary>Administrar Categorías</summary>
                <div class="editor-form">
                    <h3>Agregar Nueva Categoría</h3>
                    <form method="POST" onsubmit="return validateCategoryForm(this)">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="add_category">
                        <input type="text" name="category_name" placeholder="Nombre de la Categoría" required>
                        <input type="number" name="category_display_order" placeholder="Orden de Visualización" value="0" required>
                        <button type="submit">Agregar Categoría</button>
                    </form>
                </div>
                <div class="category-list">
                    <h3>Categorías Existentes</h3>
                    <?php foreach ($categories as $category): ?>
                        <div class="category-item">
                            <form method="POST" onsubmit="return validateCategoryForm(this)">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="update_category">
                                <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                <input type="text" name="category_name" value="<?php echo htmlspecialchars($category['name']); ?>" required>
                                <input type="number" name="category_display_order" value="<?php echo $category['display_order']; ?>" required>
                                <button type="submit">Actualizar</button>
                                <button type="submit" name="action" value="delete_category">Eliminar</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>

            <!-- Add Item -->
            <details>
                <summary>Agregar Nuevo Producto</summary>
                <div class="editor-form">
                    <form method="POST" enctype="multipart/form-data" onsubmit="return validateItemForm(this)">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="add_item">
                        <input type="text" name="name" placeholder="Nombre" required>
                        <input type="number" step="0.01" name="price" placeholder="Precio" min="0.01" required>
                        <input type="number" step="0.01" name="secondary_price" placeholder="Precio Secundario (Para tu pizza, opcional)" min="0.01">
                        <select name="category_id" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <textarea name="description" placeholder="Descripción"></textarea>
                        <label>Imagen (Subir archivo)</label>
                        <input type="file" name="image" accept="image/*">
                        <label>Imagen (URL)</label>
                        <input type="url" name="image_url" placeholder="https://example.com/image.jpg">
                        <div class="toggle-group">
                            <div class="toggle-inputs">
                                <label>Visible en la Web:</label>
                                <div class="toggle-selector" data-input="is_visible">
                                    <div class="option-toggle active" data-option="is_visible" tabindex="0" role="button" aria-label="Activar visibilidad en la web">V</div>
                                </div>
                                <input type="hidden" name="is_visible" value="1">
                            </div>
                            <div class="toggle-inputs">
                                <label>Ofrece Opción Vegana:</label>
                                <div class="toggle-selector" data-input="has_vegan_option">
                                    <div class="option-toggle" data-option="has_vegan_option" tabindex="0" role="button" aria-label="Activar opción vegana">VG</div>
                                </div>
                                <input type="hidden" name="has_vegan_option" value="0" disabled>
                            </div>
                            <div class="toggle-inputs">
                                <label>Requiere Pizza:</label>
                                <div class="toggle-selector" data-input="requires_pizza">
                                    <div class="option-toggle" data-option="requires_pizza" tabindex="0" role="button" aria-label="Activar requerimiento de pizza">P</div>
                                </div>
                                <input type="hidden" name="requires_pizza" value="0" disabled>
                            </div>
                            <div class="toggle-inputs">
                                <label>Destacado:</label>
                                <div class="toggle-selector" data-input="is_weekly_special">
                                    <div class="option-toggle" data-option="is_weekly_special" tabindex="0" role="button" aria-label="Activar destacado">D</div>
                                </div>
                                <input type="hidden" name="is_weekly_special" value="0" disabled>
                            </div>
                            <div class="toggle-inputs">
                                <label>Carta Secreta:</label>
                                <div class="toggle-selector" data-input="is_secret_menu">
                                    <div class="option-toggle" data-option="is_secret_menu" tabindex="0" role="button" aria-label="Activar carta secreta">CS</div>
                                </div>
                                <input type="hidden" name="is_secret_menu" value="0" disabled>
                            </div>
                        </div>
                        <div id="weekly_special_text_container" style="display: none;">
                            <input type="text" name="weekly_special_text" class="weekly-special-text" placeholder="Texto Destacado" value="¡Pizza de la semana!">
                        </div>
                        <div class="time-inputs">
                            <input type="time" name="visible_start_time" placeholder="Hora de Inicio (opcional)">
                            <input type="time" name="visible_end_time" placeholder="Hora de Fin (opcional)">
                        </div>
                        <div class="days-inputs">
                            <label>Días de Visibilidad:</label>
                            <div class="days-selector" data-input="visible_days">
                                <?php foreach ($days_of_week as $day => $abbr): ?>
                                    <div class="day-toggle active" data-day="<?php echo $day; ?>" tabindex="0" role="button" aria-label="Activar <?php echo $day; ?>">
                                        <?php echo $abbr; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php foreach ($days_of_week as $day => $abbr): ?>
                                <input type="hidden" name="visible_days[]" value="<?php echo $day; ?>">
                            <?php endforeach; ?>
                        </div>
                        <input type="number" name="required_selections" placeholder="Selecciones Requeridas (ej. 3 para Combo 3 Pizzas)" min="0">
                        <div class="sub-items-inputs">
                            <label>Productos Incluidos (opcional):</label>
                            <div id="sub-items-add">
                                <div class="sub-item">
                                    <select name="sub_item_ids[]">
                                        <option value="">Selecciona un producto</option>
                                        <?php foreach ($menu_items as $sub_item): ?>
                                            <option value="<?php echo $sub_item['id']; ?>"><?php echo htmlspecialchars($sub_item['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" name="sub_item_quantities[]" placeholder="Cantidad" min="1" value="1">
                                    <input type="checkbox" name="sub_item_required[]" value="1">
                                    <label>Requerido</label>
                                    <button type="button" onclick="this.parentElement.remove()">Eliminar</button>
                                </div>
                            </div>
                            <button type="button" onclick="addSubItem('sub-items-add')">Agregar otro sub-producto</button>
                        </div>
                        <input type="number" name="display_order" placeholder="Orden de Visualización" value="0" required>
                        <button type="submit">Agregar Producto</button>
                    </form>
                </div>
            </details>

            <!-- Item List (Drag and Drop) -->
            <details>
                <summary>Productos Existentes</summary>
                <div class="item-grid" id="item-grid">
                    <?php foreach ($menu_items_by_category as $category_id => $items): ?>
                        <?php foreach ($items as $item): ?>
                            <div class="item-card" data-id="<?php echo $item['id']; ?>">
                                <img src="<?php echo htmlspecialchars($item['image_url'] ?: 'https://via.placeholder.com/150?text=Sin+Imagen'); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                                <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </details>

            <!-- Modal for Editing Item -->
            <div class="modal" id="edit-item-modal">
                <div class="modal-content">
                    <span class="modal-close" onclick="closeModal()">×</span>
                    <div class="modal-form" id="modal-form-content">
                        <!-- Form will be loaded dynamically via JavaScript -->
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        // Image Preview for File Upload
        function setupImagePreview(input) {
            input.addEventListener('change', function(e) {
                if (e.target.files[0]) {
                    const preview = document.createElement('img');
                    preview.style.maxWidth = '100%';
                    preview.style.maxHeight = '200px';
                    preview.style.objectFit = 'contain';
                    preview.style.borderRadius = '10px';
                    preview.style.marginBottom = '15px';
                    preview.src = URL.createObjectURL(e.target.files[0]);
                    const existingPreview = input.parentNode.querySelector('img:not([src*="Uploads"])');
                    if (existingPreview) existingPreview.remove();
                    input.parentNode.insertBefore(preview, input.nextSibling);
                }
            });
        }

        document.querySelectorAll('input[type="file"]').forEach(setupImagePreview);

        // Image Preview for URL Input
        function setupImageUrlPreview(input) {
            input.addEventListener('input', function(e) {
                const url = e.target.value;
                if (url) {
                    const preview = document.createElement('img');
                    preview.style.maxWidth = '100%';
                    preview.style.maxHeight = '200px';
                    preview.style.objectFit = 'contain';
                    preview.style.borderRadius = '10px';
                    preview.style.marginBottom = '15px';
                    preview.src = url;
                    preview.onerror = () => preview.src = 'https://via.placeholder.com/150?text=Imagen+No+Disponible';
                    const existingPreview = input.parentNode.querySelector('img:not([src*="Uploads"])');
                    if (existingPreview) existingPreview.remove();
                    input.parentNode.insertBefore(preview, input.nextSibling);
                }
            });
        }

        document.querySelectorAll('input[name="image_url"]').forEach(setupImageUrlPreview);

        // Days Selector Toggle
        function setupDaysSelector(selector) {
            const toggles = selector.querySelectorAll('.day-toggle');
            const inputs = selector.parentNode.querySelectorAll('input[name="visible_days[]"]');
            toggles.forEach(toggle => {
                toggle.addEventListener('click', () => {
                    toggle.classList.toggle('active');
                    const day = toggle.dataset.day;
                    const input = Array.from(inputs).find(inp => inp.value === day);
                    if (input) input.disabled = !toggle.classList.contains('active');
                });
                toggle.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        toggle.click();
                    }
                });
            });
        }

        document.querySelectorAll('.days-selector').forEach(setupDaysSelector);

        // Option Toggles (Visible, Vegan, Requires Pizza, Weekly Special, Secret Menu)
        function setupOptionToggle(selector) {
            const toggle = selector.querySelector('.option-toggle');
            const input = selector.parentNode.querySelector('input[type="hidden"]');
            if (toggle && input) {
                toggle.addEventListener('click', () => {
                    toggle.classList.toggle('active');
                    input.disabled = !toggle.classList.contains('active');
                    
                    // Mostrar/ocultar el campo de texto para destacado
                    if (toggle.dataset.option === 'is_weekly_special') {
                        const textContainer = document.getElementById('weekly_special_text_container');
                        if (textContainer) {
                            textContainer.style.display = toggle.classList.contains('active') ? 'block' : 'none';
                        }
                        
                        // Si activamos destacado, desactivar carta secreta
                        if (toggle.classList.contains('active')) {
                            const secretMenuToggle = document.querySelector('[data-option="is_secret_menu"]');
                            const secretMenuInput = document.querySelector('input[name="is_secret_menu"][type="hidden"]');
                            if (secretMenuToggle && secretMenuToggle.classList.contains('active')) {
                                secretMenuToggle.classList.remove('active');
                                secretMenuInput.disabled = true;
                            }
                        }
                    }
                    
                    // Si es carta secreta, deshabilitar el campo de texto destacado
                    if (toggle.dataset.option === 'is_secret_menu') {
                        const weeklySpecialToggle = document.querySelector('[data-option="is_weekly_special"]');
                        const weeklySpecialInput = document.querySelector('input[name="is_weekly_special"][type="hidden"]');
                        const weeklySpecialText = document.querySelector('input[name="weekly_special_text"]');
                        
                        if (toggle.classList.contains('active')) {
                            // Si activamos carta secreta, forzar el texto y deshabilitar edición
                            if (weeklySpecialText) {
                                weeklySpecialText.value = 'Carta Secreta!';
                                weeklySpecialText.disabled = true;
                            }
                            // Desactivar el toggle de destacado si está activo
                            if (weeklySpecialToggle && weeklySpecialToggle.classList.contains('active')) {
                                weeklySpecialToggle.classList.remove('active');
                                weeklySpecialInput.disabled = true;
                                
                                // Ocultar el campo de texto destacado
                                const textContainer = document.getElementById('weekly_special_text_container');
                                if (textContainer) {
                                    textContainer.style.display = 'none';
                                }
                            }
                        } else {
                            // Si desactivamos carta secreta, habilitar edición del texto
                            if (weeklySpecialText) {
                                weeklySpecialText.disabled = false;
                            }
                        }
                    }
                });
                toggle.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        toggle.click();
                    }
                });
            }
        }

        document.querySelectorAll('.toggle-selector').forEach(setupOptionToggle);

        // Add Sub-Item
        function addSubItem(containerId) {
            const container = document.getElementById(containerId);
            if (container) {
                const subItemDiv = document.createElement('div');
                subItemDiv.className = 'sub-item';
                subItemDiv.innerHTML = `
                    <select name="sub_item_ids[]">
                        <option value="">Selecciona un producto</option>
                        <?php foreach ($menu_items as $sub_item): ?>
                            <option value="<?php echo $sub_item['id']; ?>"><?php echo htmlspecialchars($sub_item['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="sub_item_quantities[]" placeholder="Cantidad" min="1" value="1">
                    <input type="checkbox" name="sub_item_required[]" value="1">
                    <label>Requerido</label>
                    <button type="button" onclick="this.parentElement.remove()">Eliminar</button>
                `;
                container.appendChild(subItemDiv);
            }
        }

        // Client-side Form Validation
        function validateItemForm(form) {
            const name = form.querySelector('input[name="name"]').value.trim();
            const price = parseFloat(form.querySelector('input[name="price"]').value);
            const secondary_price = form.querySelector('input[name="secondary_price"]').value;
            const category = form.querySelector('select[name="category_id"]').value;
            const imageUrl = form.querySelector('input[name="image_url"]').value;

            if (!name) {
                alert('El nombre del producto es obligatorio.');
                return false;
            }
            if (isNaN(price) || price <= 0) {
                alert('El precio debe ser un número mayor que 0.');
                return false;
            }
            if (secondary_price && (isNaN(secondary_price) || secondary_price <= 0)) {
                alert('El precio secundario debe ser un número mayor que 0 si se especifica.');
                return false;
            }
            if (!category) {
                alert('Debes seleccionar una categoría.');
                return false;
            }
            if (imageUrl && !/^(https?:\/\/)/i.test(imageUrl)) {
                alert('La URL de la imagen no es válida.');
                return false;
            }
            return true;
        }

        function validateCategoryForm(form) {
            const name = form.querySelector('input[name="category_name"]').value.trim();
            if (!name) {
                alert('El nombre de la categoría es obligatorio.');
                return false;
            }
            return true;
        }

        function validateBusinessHoursForm(form) {
            const day = form.querySelector('select[name="day_of_week"]').value;
            const startTime = form.querySelector('input[name="start_time"]').value;
            const endTime = form.querySelector('input[name="end_time"]').value;

            if (!day) {
                alert('Debes seleccionar un día.');
                return false;
            }
            if (!startTime || !endTime) {
                alert('Las horas de inicio y fin son obligatorias.');
                return false;
            }
            return true;
        }

        // Drag and Drop with SortableJS
        const itemGrid = document.getElementById('item-grid');
        if (itemGrid) {
            new Sortable(itemGrid, {
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function(evt) {
                    const items = Array.from(itemGrid.children).map(item => item.dataset.id);
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=update_order&csrf_token=<?php echo $_SESSION['csrf_token']; ?>&order=${encodeURIComponent(JSON.stringify(items))}`
                    }).then(response => {
                        if (response.ok) {
                            console.log('Orden actualizado');
                        } else {
                            console.error('Error al actualizar el orden');
                        }
                    });
                }
            });
        }

        // Modal Handling
        function openModal(itemId) {
            const modal = document.getElementById('edit-item-modal');
            const modalContent = document.getElementById('modal-form-content');
            const item = <?php echo json_encode($menu_items); ?>.find(i => i.id == itemId);
            const subProducts = <?php echo json_encode($sub_products); ?>[itemId] || [];

            if (item) {
                const visibleDays = JSON.parse(item.visible_days || '[]');
                modalContent.innerHTML = `
                    <h3>Editar ${item.name}</h3>
                    <form method="POST" enctype="multipart/form-data" class="modal-form" onsubmit="return validateItemForm(this)">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="update_item">
                        <input type="hidden" name="id" value="${item.id}">
                        <input type="hidden" name="existing_image" value="${item.image_url}">
                        <img src="${item.image_url || 'https://via.placeholder.com/150?text=Sin+Imagen'}" alt="${item.name}">
                        <input type="text" name="name" value="${item.name}" required>
                        <input type="number" step="0.01" name="price" value="${item.price}" min="0.01" required>
                        <input type="number" step="0.01" name="secondary_price" value="${item.secondary_price || ''}" placeholder="Precio Secundario (Para tu pizza, opcional)" min="0.01">
                        <select name="category_id" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" ${item.category_id == <?php echo $category['id']; ?> ? 'selected' : ''}>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <textarea name="description">${item.description || ''}</textarea>
                        <label>Imagen (Subir archivo)</label>
                        <input type="file" name="image" accept="image/*">
                        <label>Imagen (URL)</label>
                        <input type="url" name="image_url" value="${item.image_url || ''}" placeholder="https://example.com/image.jpg">
                        <div class="toggle-group">
                            <div class="toggle-inputs">
                                <label>Visible en la Web:</label>
                                <div class="toggle-selector" data-input="is_visible">
                                    <div class="option-toggle ${item.is_visible ? 'active' : ''}" data-option="is_visible" tabindex="0" role="button" aria-label="Activar visibilidad en la web">V</div>
                                </div>
                                <input type="hidden" name="is_visible" value="1" ${!item.is_visible ? 'disabled' : ''}>
                            </div>
                            <div class="toggle-inputs">
                                <label>Ofrece Opción Vegana:</label>
                                <div class="toggle-selector" data-input="has_vegan_option">
                                    <div class="option-toggle ${item.has_vegan_option ? 'active' : ''}" data-option="has_vegan_option" tabindex="0" role="button" aria-label="Activar opción vegana">VG</div>
                                </div>
                                <input type="hidden" name="has_vegan_option" value="1" ${!item.has_vegan_option ? 'disabled' : ''}>
                            </div>
                            <div class="toggle-inputs">
                                <label>Requiere Pizza:</label>
                                <div class="toggle-selector" data-input="requires_pizza">
                                    <div class="option-toggle ${item.requires_pizza ? 'active' : ''}" data-option="requires_pizza" tabindex="0" role="button" aria-label="Activar requerimiento de pizza">P</div>
                                </div>
                                <input type="hidden" name="requires_pizza" value="1" ${!item.requires_pizza ? 'disabled' : ''}>
                            </div>
                            <div class="toggle-inputs">
                                <label>Destacado:</label>
                                <div class="toggle-selector" data-input="is_weekly_special">
                                    <div class="option-toggle ${item.is_weekly_special ? 'active' : ''}" data-option="is_weekly_special" tabindex="0" role="button" aria-label="Activar destacado">D</div>
                                </div>
                                <input type="hidden" name="is_weekly_special" value="1" ${!item.is_weekly_special ? 'disabled' : ''}>
                            </div>
                            <div class="toggle-inputs">
                                <label>Carta Secreta:</label>
                                <div class="toggle-selector" data-input="is_secret_menu">
                                    <div class="option-toggle ${item.is_secret_menu ? 'active' : ''}" data-option="is_secret_menu" tabindex="0" role="button" aria-label="Activar carta secreta">CS</div>
                                </div>
                                <input type="hidden" name="is_secret_menu" value="1" ${!item.is_secret_menu ? 'disabled' : ''}>
                            </div>
                        </div>
                        <div id="weekly_special_text_container_${item.id}" style="${item.is_weekly_special ? '' : 'display: none;'}">
                            <input type="text" name="weekly_special_text" class="weekly-special-text" value="${item.weekly_special_text || '¡Pizza de la semana!'}" placeholder="Texto Destacado" ${item.is_secret_menu ? 'disabled' : ''}>
                        </div>
                        <div class="time-inputs">
                            <input type="time" name="visible_start_time" value="${item.visible_start_time || ''}">
                            <input type="time" name="visible_end_time" value="${item.visible_end_time || ''}">
                        </div>
                        <div class="days-inputs">
                            <label>Días de Visibilidad:</label>
                            <div class="days-selector" data-input="visible_days">
                                <?php foreach ($days_of_week as $day => $abbr): ?>
                                    <div class="day-toggle ${visibleDays.includes('<?php echo $day; ?>') ? 'active' : ''}" data-day="<?php echo $day; ?>" tabindex="0" role="button" aria-label="Activar <?php echo $day; ?>">
                                        <?php echo $abbr; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php foreach ($days_of_week as $day => $abbr): ?>
                                <input type="hidden" name="visible_days[]" value="<?php echo $day; ?>" ${!visibleDays.includes('<?php echo $day; ?>') ? 'disabled' : ''}>
                            <?php endforeach; ?>
                        </div>
                        <input type="number" name="required_selections" value="${item.required_selections || ''}" placeholder="Selecciones Requeridas (ej. 3 para Combo 3 Pizzas)" min="0">
                        <div class="sub-items-inputs">
                            <label>Productos Incluidos (opcional):</label>
                            <div id="sub-items-${item.id}">
                                ${subProducts.map((sub, index) => `
                                    <div class="sub-item">
                                        <select name="sub_item_ids[]">
                                            <option value="">Selecciona un producto</option>
                                            <?php foreach ($menu_items as $menu_sub_item): ?>
                                                <?php if ($menu_sub_item['id'] != $item['id']): ?>
                                                    <option value="<?php echo $menu_sub_item['id']; ?>" ${sub.id == <?php echo $menu_sub_item['id']; ?> ? 'selected' : ''}>
                                                        <?php echo htmlspecialchars($menu_sub_item['name']); ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="number" name="sub_item_quantities[]" value="${sub.quantity}" placeholder="Cantidad" min="1">
                                        <input type="checkbox" name="sub_item_required[${index}]" value="1" ${sub.is_required ? 'checked' : ''}>
                                        <label>Requerido${sub.is_required ? ' <span class="required-label">(Obligatorio)</span>' : ''}</label>
                                        <button type="button" onclick="this.parentElement.remove()">Eliminar</button>
                                    </div>
                                `).join('')}
                                <div class="sub-item">
                                    <select name="sub_item_ids[]">
                                        <option value="">Selecciona un producto</option>
                                        <?php foreach ($menu_items as $menu_sub_item): ?>
                                            <?php if ($menu_sub_item['id'] != $item['id']): ?>
                                                <option value="<?php echo $menu_sub_item['id']; ?>">
                                                    <?php echo htmlspecialchars($menu_sub_item['name']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" name="sub_item_quantities[]" placeholder="Cantidad" min="1" value="1">
                                    <input type="checkbox" name="sub_item_required[]" value="1">
                                    <label>Requerido</label>
                                    <button type="button" onclick="this.parentElement.remove()">Eliminar</button>
                                </div>
                            </div>
                            <button type="button" onclick="addSubItem('sub-items-${item.id}')">Agregar otro sub-producto</button>
                        </div>
                        <input type="number" name="display_order" value="${item.display_order}" required>
                        <button type="submit">Actualizar</button>
                        <button type="submit" name="action" value="delete_item">Eliminar</button>
                    </form>
                `;
                modal.style.display = 'flex';
                // Setup event listeners for the modal form
                const form = modalContent.querySelector('form');
                setupImagePreview(form.querySelector('input[type="file"]'));
                setupImageUrlPreview(form.querySelector('input[name="image_url"]'));
                setupDaysSelector(form.querySelector('.days-selector'));
                form.querySelectorAll('.toggle-selector').forEach(setupOptionToggle);
                
                // Configurar el evento para mostrar/ocultar el campo de texto destacado
                const weeklySpecialToggle = form.querySelector('[data-option="is_weekly_special"]');
                if (weeklySpecialToggle) {
                    weeklySpecialToggle.addEventListener('click', () => {
                        const textContainer = document.getElementById(`weekly_special_text_container_${item.id}`);
                        if (textContainer) {
                            textContainer.style.display = weeklySpecialToggle.classList.contains('active') ? 'block' : 'none';
                        }
                    });
                }
            }
        }

        function closeModal() {
            const modal = document.getElementById('edit-item-modal');
            modal.style.display = 'none';
            document.getElementById('modal-form-content').innerHTML = '';
        }

        // Open modal on item click
        document.querySelectorAll('.item-card').forEach(card => {
            card.addEventListener('click', () => {
                const itemId = card.dataset.id;
                openModal(itemId);
            });
        });

        // Close modal on outside click
        document.getElementById('edit-item-modal').addEventListener('click', (e) => {
            if (e.target === document.getElementById('edit-item-modal')) {
                closeModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>
</html>
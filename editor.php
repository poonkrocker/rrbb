<?php
session_start();
require_once 'db_connect.php';

// ── Carpeta de uploads compartida entre producción y git ──────────────────────
// UPLOAD_DIR: ruta física absoluta donde se guardan las imágenes.
//             Debe ser la misma para ambas instalaciones.
// UPLOAD_URL: URL pública absoluta con la que se accede a esas imágenes.
//             Siempre desde la raíz del dominio, sin importar desde qué
//             instalación se sube.
define('UPLOAD_DIR', $_SERVER['DOCUMENT_ROOT'] . '/Uploads/');
define('UPLOAD_URL', '/Uploads/');

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

// ---- Inserta metadatos XMP en un archivo WebP ya guardado ----
function writeXmpToWebP(string $filepath, string $title, string $description = ''): void {
    $data = @file_get_contents($filepath);
    if ($data === false || strlen($data) < 12) return;
    if (substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WEBP') return;

    $titleEsc = htmlspecialchars($title, ENT_XML1, 'UTF-8');
    $descEsc  = htmlspecialchars($description, ENT_XML1, 'UTF-8');

    $xmp =
        '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' .
        '<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="XMP Core 6.0">' .
        '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' .
        '<rdf:Description rdf:about=""' .
        ' xmlns:dc="http://purl.org/dc/elements/1.1/"' .
        ' xmlns:xmp="http://ns.adobe.com/xap/1.0/">' .
        '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $titleEsc . '</rdf:li></rdf:Alt></dc:title>' .
        ($descEsc !== '' ? '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . $descEsc . '</rdf:li></rdf:Alt></dc:description>' : '') .
        '<xmp:CreatorTool>Arrabbiata Menu Editor</xmp:CreatorTool>' .
        '</rdf:Description></rdf:RDF></x:xmpmeta>' .
        '<?xpacket end="w"?>';

    $xmpLen = strlen($xmp);
    // WebP chunks deben tener tamaño par; si es impar se agrega un byte de padding
    $xmpPadded = ($xmpLen % 2 === 1) ? $xmp . "\x00" : $xmp;
    $chunk = 'XMP ' . pack('V', $xmpLen) . $xmpPadded;

    // Insertar el chunk XMP justo después del header RIFF????WEBP (primeros 12 bytes)
    $newData = substr($data, 0, 12) . $chunk . substr($data, 12);

    // Actualizar el tamaño del RIFF (bytes 4-7, little-endian, = tamaño total - 8)
    $riffSize = strlen($newData) - 8;
    $newData  = substr($newData, 0, 4) . pack('V', $riffSize) . substr($newData, 8);

    file_put_contents($filepath, $newData);
}

// ---- Upload seguro de imágenes con procesamiento a 800×800 WebP ----
function uploadImageSecure(array $file, string $target_dir, string $itemName = '', string $itemDescription = ''): string {
    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

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

    // Usar siempre la carpeta compartida, ignorar $target_dir
    $save_dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : $target_dir;
    $pub_dir  = defined('UPLOAD_URL') ? UPLOAD_URL : $target_dir;

    // Nombre de salida siempre .webp
    $image_name = uniqid('img_', true) . '.webp';

    if (!file_exists($save_dir)) {
        mkdir($save_dir, 0755, true);
    }

    // Crear recurso GD según el MIME de origen
    switch ($mime) {
        case 'image/jpeg': $src = imagecreatefromjpeg($file['tmp_name']); break;
        case 'image/png':  $src = imagecreatefrompng($file['tmp_name']);  break;
        case 'image/webp': $src = imagecreatefromwebp($file['tmp_name']); break;
        case 'image/gif':  $src = imagecreatefromgif($file['tmp_name']);  break;
        default: throw new Exception("No se pudo procesar la imagen.");
    }
    if (!$src) {
        throw new Exception("No se pudo leer la imagen subida.");
    }

    $orig_w = imagesx($src);
    $orig_h = imagesy($src);

    // Destino cuadrado 800×800
    $out_size = 800;
    $dst = imagecreatetruecolor($out_size, $out_size);

    // Preservar transparencia para PNG/WebP/GIF
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);

    // Escalar manteniendo relación de aspecto y centrar (letterbox/pillarbox)
    $scale = min($out_size / $orig_w, $out_size / $orig_h);
    $scaled_w = (int)round($orig_w * $scale);
    $scaled_h = (int)round($orig_h * $scale);
    $offset_x = (int)(($out_size - $scaled_w) / 2);
    $offset_y = (int)(($out_size - $scaled_h) / 2);

    imagecopyresampled($dst, $src, $offset_x, $offset_y, 0, 0, $scaled_w, $scaled_h, $orig_w, $orig_h);

    $dest = $save_dir . $image_name;

    // Guardar como WebP calidad 80
    if (!imagewebp($dst, $dest, 80)) {
        throw new Exception("Error al guardar la imagen procesada en el servidor.");
    }

    imagedestroy($src);
    imagedestroy($dst);

    // Escribir metadatos XMP (título del producto para SEO)
    if ($itemName !== '') {
        writeXmpToWebP($dest, $itemName, $itemDescription);
    }

    // Devolver URL pública absoluta (accesible desde cualquier instalación)
    return $pub_dir . $image_name;
}

// ---- Upload desde datos base64 (imagen recortada en el cliente) ----
function uploadImageFromBase64(string $base64data, string $target_dir, string $itemName = '', string $itemDescription = ''): string {
    // Espera formato: data:image/TYPE;base64,XXXXX
    if (!preg_match('/^data:(image\/(?:jpeg|png|webp|gif));base64,(.+)$/s', $base64data, $m)) {
        throw new Exception("Formato de imagen recortada inválido.");
    }
    $mime = $m[1];
    $raw  = base64_decode($m[2], true);
    if ($raw === false || strlen($raw) < 100) {
        throw new Exception("Datos de imagen recortada corruptos.");
    }

    // Guardar temporalmente para reusar uploadImageSecure con GD
    $tmp = tempnam(sys_get_temp_dir(), 'crop_');
    file_put_contents($tmp, $raw);

    // Usar GD directamente (misma lógica que uploadImageSecure)
    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowed_mime, true)) {
        unlink($tmp);
        throw new Exception("Tipo MIME de imagen recortada no permitido.");
    }

    $fake_file = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp];
    $result = uploadImageSecure($fake_file, $target_dir, $itemName, $itemDescription);
    unlink($tmp);
    return $result;
}

// ---- Helper: parsear visible_days en formato franjas ----
if (!function_exists('parseSchedules')) {
function parseSchedules(?string $json): array {
    if (!$json) return [['days' => [], 'start' => '', 'end' => '']];
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return [['days' => [], 'start' => '', 'end' => '']];
    // Formato nuevo: array de objetos con clave 'start'
    if (!empty($decoded) && isset($decoded[0]['start'])) return $decoded;
    // Formato legado: array plano de días → migrar a franja sin horario
    $days = array_filter($decoded, 'is_string');
    return [['days' => array_values($days), 'start' => '', 'end' => '']];
}}

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
            $visible_start_time = null; // ya no se usa; las franjas van en visible_days
            $visible_end_time   = null;
            $visible_days       = null;
            if (!empty($_POST['schedule_days']) && is_array($_POST['schedule_days'])) {
                $schedules = [];
                foreach ($_POST['schedule_days'] as $idx => $days) {
                    if (empty($days)) continue;
                    $start = $_POST['schedule_start'][$idx] ?? '';
                    $end   = $_POST['schedule_end'][$idx]   ?? '';
                    $schedules[] = [
                        'days'  => array_values(array_filter((array)$days)),
                        'start' => $start,
                        'end'   => $end,
                    ];
                }
                if (!empty($schedules)) {
                    $visible_days = json_encode($schedules, JSON_UNESCAPED_UNICODE);
                }
            }
            $display_order = (int)$_POST['display_order'];
            $required_selections = !empty($_POST['required_selections']) ? (int)$_POST['required_selections'] : null;

            if (empty($name) || $price <= 0 || $category_id <= 0) {
                throw new Exception("Nombre, precio y categoría son obligatorios y deben ser válidos.");
            }
            if ($secondary_price !== null && $secondary_price <= 0) {
                throw new Exception("El precio secundario debe ser mayor que 0 si se especifica.");
            }

            $image_url = '';
            if (!empty($_POST['cropped_image_data'])) {
                // Imagen recortada desde el cliente (base64)
                $image_url = uploadImageFromBase64($_POST['cropped_image_data'], "Uploads/", $name, $description);
            } elseif (!empty($_POST['image_url'])) {
                $image_url = filter_var($_POST['image_url'], FILTER_SANITIZE_URL);
                if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
                    throw new Exception("La URL de la imagen no es válida.");
                }
            } elseif (!empty($_FILES['image']['name'])) {
                $image_url = uploadImageSecure($_FILES['image'], "Uploads/", $name, $description);
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
            $visible_start_time = null; // ya no se usa; las franjas van en visible_days
            $visible_end_time   = null;
            $visible_days       = null;
            if (!empty($_POST['schedule_days']) && is_array($_POST['schedule_days'])) {
                $schedules = [];
                foreach ($_POST['schedule_days'] as $idx => $days) {
                    if (empty($days)) continue;
                    $start = $_POST['schedule_start'][$idx] ?? '';
                    $end   = $_POST['schedule_end'][$idx]   ?? '';
                    $schedules[] = [
                        'days'  => array_values(array_filter((array)$days)),
                        'start' => $start,
                        'end'   => $end,
                    ];
                }
                if (!empty($schedules)) {
                    $visible_days = json_encode($schedules, JSON_UNESCAPED_UNICODE);
                }
            }
            $display_order = (int)$_POST['display_order'];
            $required_selections = !empty($_POST['required_selections']) ? (int)$_POST['required_selections'] : null;

            if (empty($name) || $price <= 0 || $category_id <= 0) {
                throw new Exception("Nombre, precio y categoría son obligatorios y deben ser válidos.");
            }
            if ($secondary_price !== null && $secondary_price <= 0) {
                throw new Exception("El precio secundario debe ser mayor que 0 si se especifica.");
            }

            $image_url = $_POST['existing_image'];
            if (!empty($_POST['cropped_image_data'])) {
                // Imagen recortada desde el cliente (base64)
                $image_url = uploadImageFromBase64($_POST['cropped_image_data'], "Uploads/", $name, $description);
            } elseif (!empty($_POST['image_url'])) {
                $candidate = filter_var($_POST['image_url'], FILTER_SANITIZE_URL);
                // Solo procesar como URL externa si empieza con http(s)
                if (!preg_match('/^https?:\/\//i', $candidate)) {
                    throw new Exception("La URL de la imagen no es válida.");
                }
                if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
                    throw new Exception("La URL de la imagen no es válida.");
                }
                $image_url = $candidate;
            } elseif (!empty($_FILES['image']['name'])) {
                $image_url = uploadImageSecure($_FILES['image'], "Uploads/", $name, $description);
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
        } elseif ($_POST['action'] === 'bulk_update_prices') {
            if (!empty($_POST['prices']) && is_array($_POST['prices'])) {
                // Primero eliminar los marcados
                if (!empty($_POST['delete_ids']) && is_array($_POST['delete_ids'])) {
                    $del_stmt = $pdo->prepare("DELETE FROM menu_item_subproducts WHERE parent_item_id = ?");
                    $del_item = $pdo->prepare("DELETE FROM menu_items WHERE id = ?");
                    foreach ($_POST['delete_ids'] as $del_id) {
                        $del_id = (int)$del_id;
                        if ($del_id > 0) {
                            $del_stmt->execute([$del_id]);
                            $del_item->execute([$del_id]);
                            // Quitar del array de precios para no intentar actualizarlo
                            unset($_POST['prices'][$del_id]);
                        }
                    }
                    $success[] = count($_POST['delete_ids']) . " producto(s) eliminado(s).";
                }
                $stmt = $pdo->prepare("UPDATE menu_items SET price = ?, secondary_price = ? WHERE id = ?");
                $updated = 0;
                foreach ($_POST['prices'] as $item_id => $price_val) {
                    $item_id = (int)$item_id;
                    $price_val = (float)$price_val;
                    if ($item_id <= 0 || $price_val <= 0) continue;
                    $sec = isset($_POST['secondary_prices'][$item_id]) && $_POST['secondary_prices'][$item_id] !== ''
                        ? (float)$_POST['secondary_prices'][$item_id]
                        : null;
                    if ($sec !== null && $sec <= 0) $sec = null;
                    $stmt->execute([$price_val, $sec, $item_id]);
                    $updated++;
                }
                $success[] = "Precios actualizados correctamente ($updated productos).";
            } else {
                $errors[] = "No se recibieron precios para actualizar.";
            }
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
        } elseif ($_POST['action'] === 'toggle_visibility') {
            // AJAX — responde JSON
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit; }
            $pdo->prepare("UPDATE menu_items SET is_visible = NOT is_visible WHERE id = ?")->execute([$id]);
            $new = (bool)$pdo->query("SELECT is_visible FROM menu_items WHERE id = $id")->fetchColumn();
            echo json_encode(['ok'=>true,'is_visible'=>$new]);
            exit;
        } elseif ($_POST['action'] === 'reorder_items') {
            // AJAX — responde JSON
            $order = json_decode($_POST['order'] ?? '[]', true);
            if (!is_array($order) || empty($order)) { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit; }
            $stmt = $pdo->prepare("UPDATE menu_items SET display_order = ? WHERE id = ?");
            $pdo->beginTransaction();
            try {
                foreach ($order as $idx => $itemId) { $stmt->execute([$idx + 1, (int)$itemId]); }
                $pdo->commit();
                echo json_encode(['ok'=>true]);
            } catch (Throwable $e) { $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
            exit;
        }
        // ... (resto de las acciones se mantienen iguales)
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// ... (el resto del archivo HTML/PHP se mantiene, pero actualizamos la parte JS y formularios)

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editor de Menú</title>
    <!-- ... estilos existentes ... -->
    <style>
        /* ... estilos existentes ... */
        .btn-add-schedule {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1.5px dashed #66bb6a;
            padding: 7px 18px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.2s ease;
            margin-top: 4px;
        }
        .btn-add-schedule:hover {
            background: #c8e6c9;
            border-color: #388e3c;
        }
        .schedule-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            background: #f9f9f9;
            padding: 8px;
            border-radius: 8px;
            margin-bottom: 8px;
        }
        .day-toggle {
            /* estilos existentes para day-toggle */
        }
    </style>
</head>
<body>
    <!-- Formulario Agregar Nuevo Producto -->
    <form method="POST" enctype="multipart/form-data">
        <!-- ... campos existentes ... -->
        <div class="schedules-section">
            <label style="font-weight:700;font-size:1rem;color:#333;display:block;margin-bottom:8px;">
                Franjas de disponibilidad:
            </label>
            <div class="schedules-container" id="schedules-add">
                <!-- Las franjas se insertan aquí por JS -->
            </div>
            <button type="button" class="btn-add-schedule" onclick="addScheduleRow(document.getElementById('schedules-add'))">
                + Agregar franja horaria
            </button>
        </div>
        <!-- ... resto del formulario ... -->
    </form>

    <!-- ... resto del HTML ... -->

    <script>
        // Variables globales para schedules
        var _scheduleAllDays = <?php echo json_encode(array_keys($days_of_week ?? [])); ?>;
        var _scheduleAllAbbr = <?php echo json_encode(array_values($days_of_week ?? [])); ?>;

        // Función addScheduleRow
        function addScheduleRow(container, allDays, allAbbr, activeDays, startVal, endVal) {
            allDays    = allDays  || _scheduleAllDays;
            allAbbr    = allAbbr  || _scheduleAllAbbr;
            activeDays = activeDays || allDays; // Por defecto todos activos
            startVal   = startVal || '';
            endVal     = endVal   || '';

            var idx = container.querySelectorAll('.schedule-row').length;
            var row = document.createElement('div');
            row.className = 'schedule-row';
            row.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#f9f9f9;padding:8px;border-radius:8px;margin-bottom:8px;';

            // Toggles de días
            var daysWrap = document.createElement('div');
            daysWrap.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap;align-items:center;';
            var daysLabel = document.createElement('span');
            daysLabel.textContent = 'Días:';
            daysLabel.style.cssText = 'font-size:0.85rem;font-weight:600;color:#555;margin-right:4px;';
            daysWrap.appendChild(daysLabel);

            allDays.forEach(function(day, di) {
                var isActive = activeDays.length === 0 || activeDays.indexOf(day) !== -1;
                var toggle = document.createElement('div');
                toggle.className = 'day-toggle' + (isActive ? ' active' : '');
                toggle.textContent = allAbbr[di];
                toggle.dataset.day = day;
                toggle.title = day;
                toggle.tabIndex = 0;
                toggle.style.cssText = 'width:32px;height:32px;font-size:13px;cursor:pointer;border:1px solid #ccc;border-radius:4px;display:flex;align-items:center;justify-content:center;';
                toggle.addEventListener('click', function() { toggle.classList.toggle('active'); updateHiddenDays(); });
                toggle.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle.click(); }
                });

                // Hidden input para este día en esta franja
                var hiddenDay = document.createElement('input');
                hiddenDay.type = 'hidden';
                hiddenDay.name = 'schedule_days[' + idx + '][]';
                hiddenDay.value = day;
                hiddenDay.disabled = !isActive;

                function updateHiddenDays() {
                    hiddenDay.disabled = !toggle.classList.contains('active');
                }

                toggle.addEventListener('click', updateHiddenDays);

                daysWrap.appendChild(toggle);
                daysWrap.appendChild(hiddenDay);
            });
            row.appendChild(daysWrap);

            // Hora inicio
            var startLabel = document.createElement('label');
            startLabel.textContent = 'Desde:';
            startLabel.style.cssText = 'font-size:0.85rem;font-weight:600;color:#555;';
            row.appendChild(startLabel);

            var startInput = document.createElement('input');
            startInput.type = 'time';
            startInput.name = 'schedule_start[' + idx + ']';
            startInput.value = startVal;
            startInput.style.cssText = 'padding:6px 10px;border:1px solid #ddd;border-radius:20px;';
            row.appendChild(startInput);

            // Hora fin
            var endLabel = document.createElement('label');
            endLabel.textContent = 'Hasta:';
            endLabel.style.cssText = 'font-size:0.85rem;font-weight:600;color:#555;';
            row.appendChild(endLabel);

            var endInput = document.createElement('input');
            endInput.type = 'time';
            endInput.name = 'schedule_end[' + idx + ']';
            endInput.value = endVal;
            endInput.style.cssText = 'padding:6px 10px;border:1px solid #ddd;border-radius:20px;';
            row.appendChild(endInput);

            // Botón eliminar
            if (container.querySelectorAll('.schedule-row').length > 0) {
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.textContent = '✕';
                removeBtn.title = 'Eliminar esta franja';
                removeBtn.style.cssText = 'background:none;border:1px solid #cc0000;color:#cc0000;border-radius:50%;width:24px;height:24px;cursor:pointer;';
                removeBtn.addEventListener('click', function() { row.remove(); });
                row.appendChild(removeBtn);
            }

            container.appendChild(row);
        }

        // Inicializar formulario de agregar
        (function() {
            var c = document.getElementById('schedules-add');
            if (c) {
                addScheduleRow(c);
            }
        })();

        // Función openModal actualizada
        function openModal(itemId) {
            // ... código existente ...
            if (item) {
                // ... 
                modalContent.innerHTML = `
                    <!-- ... campos existentes ... -->
                    <div class="schedules-section">
                        <label style="font-weight:700;font-size:1rem;color:#333;display:block;margin-bottom:8px;">
                            Franjas de disponibilidad:
                        </label>
                        <div class="schedules-container" id="schedules-edit-${item.id}"></div>
                        <button type="button" class="btn-add-schedule" 
                            onclick="addScheduleRow(document.getElementById('schedules-edit-${item.id}'))">
                            + Agregar franja horaria
                        </button>
                    </div>
                    <!-- ... resto ... -->
                `;

                modal.style.display = 'flex';

                // Poblar franjas de horario existentes
                (function() {
                    var schedContainer = document.getElementById(`schedules-edit-${item.id}`);
                    if (!schedContainer) return;
                    var rawSchedules = [];
                    try {
                        var parsed = JSON.parse(item.visible_days || '[]');
                        if (Array.isArray(parsed) && parsed.length > 0) {
                            if (typeof parsed[0] === 'object' && parsed[0] !== null && 'start' in parsed[0]) {
                                rawSchedules = parsed;
                            } else if (typeof parsed[0] === 'string') {
                                // Legado
                                rawSchedules = [{ days: parsed, start: item.visible_start_time || '', end: item.visible_end_time || '' }];
                            }
                        }
                    } catch(e) {}
                    if (rawSchedules.length === 0) {
                        rawSchedules = [{ days: _scheduleAllDays, start: '', end: '' }];
                    }
                    rawSchedules.forEach(function(s) {
                        addScheduleRow(schedContainer, null, null, s.days || [], s.start || '', s.end || '');
                    });
                })();
                // ... resto de setup ...
            }
        }

        // ... resto del script ...
    </script>
</body>
</html>
<?php
session_start();
require_once 'db_connect.php';

// ── Carpeta de uploads compartida entre producción y git ──────────────────────
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
... (mantengo todo el bloque HOURS_PATCH_24PLUS sin cambios) ...

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
    $xmpPadded = ($xmpLen % 2 === 1) ? $xmp . "\x00" : $xmp;
    $chunk = 'XMP ' . pack('V', $xmpLen) . $xmpPadded;

    $newData = substr($data, 0, 12) . $chunk . substr($data, 12);
    $riffSize = strlen($newData) - 8;
    $newData  = substr($newData, 0, 4) . pack('V', $riffSize) . substr($newData, 8);

    file_put_contents($filepath, $newData);
}

// ---- Upload seguro de imágenes con procesamiento a 800×800 WebP ----
function uploadImageSecure(array $file, string $target_dir, string $itemName = '', string $itemDescription = ''): string {
    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Error al recibir el archivo (código {$file['error']}).");
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed_mime, true)) {
        throw new Exception("Tipo de archivo no permitido.");
    }

    $save_dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : $target_dir;
    $pub_dir  = defined('UPLOAD_URL') ? UPLOAD_URL : $target_dir;

    $image_name = uniqid('img_', true) . '.webp';

    if (!file_exists($save_dir)) {
        mkdir($save_dir, 0755, true);
    }

    switch ($mime) {
        case 'image/jpeg': $src = imagecreatefromjpeg($file['tmp_name']); break;
        case 'image/png':  $src = imagecreatefrompng($file['tmp_name']);  break;
        case 'image/webp': $src = imagecreatefromwebp($file['tmp_name']); break;
        case 'image/gif':  $src = imagecreatefromgif($file['tmp_name']);  break;
        default: throw new Exception("No se pudo procesar la imagen.");
    }
    if (!$src) throw new Exception("No se pudo leer la imagen subida.");

    $orig_w = imagesx($src);
    $orig_h = imagesy($src);
    $out_size = 800;
    $dst = imagecreatetruecolor($out_size, $out_size);

    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);

    $scale = min($out_size / $orig_w, $out_size / $orig_h);
    $scaled_w = (int)round($orig_w * $scale);
    $scaled_h = (int)round($orig_h * $scale);
    $offset_x = (int)(($out_size - $scaled_w) / 2);
    $offset_y = (int)(($out_size - $scaled_h) / 2);

    imagecopyresampled($dst, $src, $offset_x, $offset_y, 0, 0, $scaled_w, $scaled_h, $orig_w, $orig_h);

    $dest = $save_dir . $image_name;

    if (!imagewebp($dst, $dest, 80)) {
        throw new Exception("Error al guardar la imagen procesada.");
    }

    imagedestroy($src);
    imagedestroy($dst);

    // Metadatos XMP
    if ($itemName !== '') {
        writeXmpToWebP($dest, $itemName, $itemDescription);
    }

    return $pub_dir . $image_name;
}

// ---- Upload desde datos base64 ----
function uploadImageFromBase64(string $base64data, string $target_dir, string $itemName = '', string $itemDescription = ''): string {
    if (!preg_match('/^data:(image\/(?:jpeg|png|webp|gif));base64,(.+)$/s', $base64data, $m)) {
        throw new Exception("Formato de imagen recortada inválido.");
    }
    $mime = $m[1];
    $raw  = base64_decode($m[2], true);
    if ($raw === false || strlen($raw) < 100) {
        throw new Exception("Datos de imagen recortada corruptos.");
    }

    $tmp = tempnam(sys_get_temp_dir(), 'crop_');
    file_put_contents($tmp, $raw);

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
    if (!empty($decoded) && isset($decoded[0]['start'])) return $decoded;
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
            if ($is_secret_menu) $weekly_special_text = 'Carta Secreta!';

            // === Franjas múltiples ===
            $visible_start_time = null;
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
                throw new Exception("Nombre, precio y categoría son obligatorios.");
            }

            $image_url = '';
            if (!empty($_POST['cropped_image_data'])) {
                $image_url = uploadImageFromBase64($_POST['cropped_image_data'], "Uploads/", $name, $description);
            } elseif (!empty($_POST['image_url'])) {
                $image_url = filter_var($_POST['image_url'], FILTER_SANITIZE_URL);
                if (!filter_var($image_url, FILTER_VALIDATE_URL)) throw new Exception("URL de imagen inválida.");
            } elseif (!empty($_FILES['image']['name'])) {
                $image_url = uploadImageSecure($_FILES['image'], "Uploads/", $name, $description);
            }

            // INSERT ...
            $stmt = $pdo->prepare("INSERT INTO menu_items (...) VALUES (...)"); // mismo SQL
            $stmt->execute([...]); // mismo orden

            // subproductos ...
            $success[] = "Producto agregado correctamente.";
        }

        // === UPDATE_ITEM (copia idéntica del bloque anterior con los mismos cambios) ===
        elseif ($_POST['action'] === 'update_item') {
            // ... mismo código que add_item pero con UPDATE y $id ...
            $id = (int)$_POST['id'];
            // ... variables name, price, etc.
            // === Franjas múltiples (igual que arriba) ===
            $visible_start_time = null;
            $visible_end_time   = null;
            $visible_days       = null;
            if (!empty($_POST['schedule_days']) && is_array($_POST['schedule_days'])) {
                $schedules = [];
                foreach ($_POST['schedule_days'] as $idx => $days) {
                    if (empty($days)) continue;
                    $start = $_POST['schedule_start'][$idx] ?? '';
                    $end   = $_POST['schedule_end'][$idx]   ?? '';
                    $schedules[] = ['days' => array_values(array_filter((array)$days)), 'start' => $start, 'end' => $end];
                }
                if (!empty($schedules)) $visible_days = json_encode($schedules, JSON_UNESCAPED_UNICODE);
            }

            $image_url = $_POST['existing_image'];
            if (!empty($_POST['cropped_image_data'])) {
                $image_url = uploadImageFromBase64($_POST['cropped_image_data'], "Uploads/", $name, $description);
            } elseif (!empty($_FILES['image']['name'])) {
                $image_url = uploadImageSecure($_FILES['image'], "Uploads/", $name, $description);
            } // ... resto igual

            $stmt = $pdo->prepare("UPDATE menu_items SET ...");
            $stmt->execute([... , $id]);
            $success[] = "Producto actualizado correctamente.";
        }

        // resto de acciones (add_category, etc.) sin cambios
        // ... (mantengo todo el resto del bloque try-catch tal como estaba)

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// ... (todo el código de fetches: categories, menu_items, sub_products, business_hours, $days_of_week) ...

// === HTML + JS actualizado ===
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <!-- ... tu head completo ... -->
</head>
<body>
    <!-- ... todo tu HTML hasta el formulario de Agregar ... -->

    <!-- Agregar Nuevo Producto - Sección de Franjas -->
    <div class="schedules-section">
        <label style="font-weight:700;font-size:1rem;color:#333;display:block;margin-bottom:8px;">Franjas de disponibilidad:</label>
        <div class="schedules-container" id="schedules-add"></div>
        <button type="button" class="btn-add-schedule" onclick="addScheduleRow(document.getElementById('schedules-add'))">+ Agregar franja horaria</button>
    </div>

    <!-- ... resto del formulario ... -->

    <script>
    var _scheduleAllDays = <?php echo json_encode(array_keys($days_of_week)); ?>;
    var _scheduleAllAbbr = <?php echo json_encode(array_values($days_of_week)); ?>;

    function addScheduleRow(container, allDays = null, allAbbr = null, activeDays = null, startVal = '', endVal = '') {
        allDays = allDays || _scheduleAllDays;
        allAbbr = allAbbr || _scheduleAllAbbr;
        activeDays = activeDays || allDays;

        var idx = container.querySelectorAll('.schedule-row').length;
        var row = document.createElement('div');
        row.className = 'schedule-row';
        // ... (el resto de la función addScheduleRow completa tal como en el PDF) ...
        // (incluye toggles de días, inputs time, botón eliminar)
    }

    // Inicializar agregar
    (function() {
        var c = document.getElementById('schedules-add');
        if (c) addScheduleRow(c);
    })();

    // === openModal actualizado con franjas ===
    function openModal(itemId) {
        // ... código existente ...
        const item = <?php echo json_encode($menu_items); ?>.find(i => i.id == itemId);
        if (item) {
            // ... resto del template ...
            // Reemplazar la parte de time-inputs + days-inputs por:
            `<div class="schedules-section">
                <label style="font-weight:700;font-size:1rem;color:#333;display:block;margin-bottom:8px;">Franjas de disponibilidad:</label>
                <div class="schedules-container" id="schedules-edit-${item.id}"></div>
                <button type="button" class="btn-add-schedule" onclick="addScheduleRow(document.getElementById('schedules-edit-${item.id}'))">+ Agregar franja horaria</button>
            </div>`

            // Después de insertar el HTML:
            const schedContainer = document.getElementById(`schedules-edit-${item.id}`);
            const schedules = parseSchedules(item.visible_days);
            schedules.forEach(s => {
                addScheduleRow(schedContainer, null, null, s.days || [], s.start || '', s.end || '');
            });
        }
    }

    // ... resto de tu JS (cropper, etc.)
    </script>
</body>
</html>
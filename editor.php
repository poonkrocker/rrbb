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
// (Tu bloque completo de HOURS_PATCH se mantiene sin cambios)
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
// ... (todo el resto del bloque HOURS_PATCH_24PLUS se mantiene exactamente igual a tu archivo original) ...
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

// ---- Upload seguro de imágenes con XMP ----
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

    $save_dir = UPLOAD_DIR;
    $pub_dir  = UPLOAD_URL;
    $image_name = uniqid('img_', true) . '.webp';

    if (!file_exists($save_dir)) mkdir($save_dir, 0755, true);

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

    if ($itemName !== '') {
        writeXmpToWebP($dest, $itemName, $itemDescription);
    }

    return $pub_dir . $image_name;
}

// ---- Upload desde base64 ----
function uploadImageFromBase64(string $base64data, string $target_dir, string $itemName = '', string $itemDescription = ''): string {
    if (!preg_match('/^data:(image\/(?:jpeg|png|webp|gif));base64,(.+)$/s', $base64data, $m)) {
        throw new Exception("Formato de imagen recortada inválido.");
    }
    $raw = base64_decode($m[2], true);
    if ($raw === false || strlen($raw) < 100) throw new Exception("Datos de imagen corruptos.");

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

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Petición no válida (CSRF).');
    }
}

// ====================== PROCESAMIENTO POST ======================
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

            // Franjas múltiples
            $visible_start_time = null;
            $visible_end_time = null;
            $visible_days = null;
            if (!empty($_POST['schedule_days']) && is_array($_POST['schedule_days'])) {
                $schedules = [];
                foreach ($_POST['schedule_days'] as $idx => $days) {
                    if (empty($days)) continue;
                    $start = $_POST['schedule_start'][$idx] ?? '';
                    $end   = $_POST['schedule_end'][$idx] ?? '';
                    $schedules[] = [
                        'days' => array_values(array_filter((array)$days)),
                        'start' => $start,
                        'end' => $end
                    ];
                }
                if (!empty($schedules)) $visible_days = json_encode($schedules, JSON_UNESCAPED_UNICODE);
            }

            $display_order = (int)$_POST['display_order'];
            $required_selections = !empty($_POST['required_selections']) ? (int)$_POST['required_selections'] : null;

            if (empty($name) || $price <= 0 || $category_id <= 0) throw new Exception("Nombre, precio y categoría son obligatorios.");

            $image_url = '';
            if (!empty($_POST['cropped_image_data'])) {
                $image_url = uploadImageFromBase64($_POST['cropped_image_data'], "Uploads/", $name, $description);
            } elseif (!empty($_POST['image_url'])) {
                $image_url = filter_var($_POST['image_url'], FILTER_SANITIZE_URL);
            } elseif (!empty($_FILES['image']['name'])) {
                $image_url = uploadImageSecure($_FILES['image'], "Uploads/", $name, $description);
            }

            // INSERT (mismo que antes)
            $stmt = $pdo->prepare("INSERT INTO menu_items (name, price, secondary_price, category_id, image_url, description, is_visible, has_vegan_option, requires_pizza, is_weekly_special, weekly_special_text, visible_start_time, visible_end_time, visible_days, display_order, required_selections, is_secret_menu) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $price, $secondary_price, $category_id, $image_url, $description, $is_visible, $has_vegan_option, $requires_pizza, $is_weekly_special, $weekly_special_text, $visible_start_time, $visible_end_time, $visible_days, $display_order, $required_selections, $is_secret_menu]);

            $item_id = $pdo->lastInsertId();

            // Subproductos
            if (!empty($_POST['sub_item_ids'])) {
                $stmt = $pdo->prepare("INSERT INTO menu_item_subproducts (parent_item_id, sub_item_id, quantity, is_required) VALUES (?, ?, ?, ?)");
                foreach ($_POST['sub_item_ids'] as $index => $sub_item_id) {
                    $sub_item_id = (int)$sub_item_id;
                    $quantity = (int)($_POST['sub_item_quantities'][$index] ?? 1);
                    $is_required = isset($_POST['sub_item_required'][$index]) ? 1 : 0;
                    if ($sub_item_id && $quantity > 0 && $sub_item_id != $item_id) {
                        $stmt->execute([$item_id, $sub_item_id, $quantity, $is_required]);
                    }
                }
            }

            $success[] = "Producto agregado correctamente.";
        } elseif ($_POST['action'] === 'update_item') {
            // Similar a add_item pero con UPDATE
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
            if ($is_secret_menu) $weekly_special_text = 'Carta Secreta!';

            $visible_start_time = null;
            $visible_end_time = null;
            $visible_days = null;
            if (!empty($_POST['schedule_days']) && is_array($_POST['schedule_days'])) {
                $schedules = [];
                foreach ($_POST['schedule_days'] as $idx => $days) {
                    if (empty($days)) continue;
                    $schedules[] = [
                        'days' => array_values(array_filter((array)$days)),
                        'start' => $_POST['schedule_start'][$idx] ?? '',
                        'end' => $_POST['schedule_end'][$idx] ?? ''
                    ];
                }
                if (!empty($schedules)) $visible_days = json_encode($schedules, JSON_UNESCAPED_UNICODE);
            }

            $display_order = (int)$_POST['display_order'];
            $required_selections = !empty($_POST['required_selections']) ? (int)$_POST['required_selections'] : null;

            $image_url = $_POST['existing_image'] ?? '';
            if (!empty($_POST['cropped_image_data'])) {
                $image_url = uploadImageFromBase64($_POST['cropped_image_data'], "Uploads/", $name, $description);
            } elseif (!empty($_FILES['image']['name'])) {
                $image_url = uploadImageSecure($_FILES['image'], "Uploads/", $name, $description);
            } elseif (!empty($_POST['image_url'])) {
                $image_url = filter_var($_POST['image_url'], FILTER_SANITIZE_URL);
            }

            $stmt = $pdo->prepare("UPDATE menu_items SET name = ?, price = ?, secondary_price = ?, category_id = ?, image_url = ?, description = ?, is_visible = ?, has_vegan_option = ?, requires_pizza = ?, is_weekly_special = ?, weekly_special_text = ?, visible_start_time = ?, visible_end_time = ?, visible_days = ?, display_order = ?, required_selections = ?, is_secret_menu = ? WHERE id = ?");
            $stmt->execute([$name, $price, $secondary_price, $category_id, $image_url, $description, $is_visible, $has_vegan_option, $requires_pizza, $is_weekly_special, $weekly_special_text, $visible_start_time, $visible_end_time, $visible_days, $display_order, $required_selections, $is_secret_menu, $id]);

            $pdo->prepare("DELETE FROM menu_item_subproducts WHERE parent_item_id = ?")->execute([$id]);
            // subproductos (igual que add_item)
            if (!empty($_POST['sub_item_ids'])) {
                $stmt = $pdo->prepare("INSERT INTO menu_item_subproducts (parent_item_id, sub_item_id, quantity, is_required) VALUES (?, ?, ?, ?)");
                foreach ($_POST['sub_item_ids'] as $index => $sub_item_id) {
                    $sub_item_id = (int)$sub_item_id;
                    $quantity = (int)($_POST['sub_item_quantities'][$index] ?? 1);
                    $is_required = isset($_POST['sub_item_required'][$index]) ? 1 : 0;
                    if ($sub_item_id && $quantity > 0 && $sub_item_id != $id) {
                        $stmt->execute([$id, $sub_item_id, $quantity, $is_required]);
                    }
                }
            }

            $success[] = "Producto actualizado correctamente.";
        }
        // Resto de acciones (add_category, bulk_update_prices, reorder, etc.) se mantienen iguales a tu archivo original
        // (copia aquí el resto de tu bloque original si hace falta)

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// Fetch data (tu código original)
$categories = $pdo->query("SELECT * FROM categories ORDER BY display_order")->fetchAll();
$menu_items = $pdo->query("SELECT mi.*, c.name AS category_name FROM menu_items mi JOIN categories c ON mi.category_id = c.id ORDER BY c.display_order, mi.display_order")->fetchAll();

// ... resto de fetches ...

$days_of_week = [
    'Lunes' => 'L', 'Martes' => 'M', 'Miércoles' => 'X', 'Jueves' => 'J',
    'Viernes' => 'V', 'Sábado' => 'S', 'Domingo' => 'D'
];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <!-- Tu head completo -->
</head>
<body>
    <!-- Tu HTML completo -->

    <!-- En el formulario "Agregar Nuevo Producto" reemplaza la sección time-inputs + days-inputs por: -->
    <div class="schedules-section">
        <label style="font-weight:700;font-size:1rem;color:#333;display:block;margin-bottom:8px;">Franjas de disponibilidad:</label>
        <div class="schedules-container" id="schedules-add"></div>
        <button type="button" class="btn-add-schedule" onclick="addScheduleRow(document.getElementById('schedules-add'))">+ Agregar franja horaria</button>
    </div>

    <!-- El resto del HTML se mantiene -->

    <script>
    // JS para franjas (completo según PDF)
    var _scheduleAllDays = <?php echo json_encode(array_keys($days_of_week)); ?>;
    var _scheduleAllAbbr = <?php echo json_encode(array_values($days_of_week)); ?>;

    function addScheduleRow(container, allDays = null, allAbbr = null, activeDays = null, startVal = '', endVal = '') {
        allDays = allDays || _scheduleAllDays;
        allAbbr = allAbbr || _scheduleAllAbbr;
        activeDays = activeDays || allDays;

        var idx = container.querySelectorAll('.schedule-row').length;
        var row = document.createElement('div');
        row.className = 'schedule-row';
        row.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#f9f9f9;padding:10px;border-radius:8px;margin-bottom:8px;';

        // Días
        var daysWrap = document.createElement('div');
        daysWrap.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap;align-items:center;';
        var label = document.createElement('span');
        label.textContent = 'Días:';
        daysWrap.appendChild(label);

        allDays.forEach(function(day, di) {
            var isActive = activeDays.indexOf(day) !== -1;
            var toggle = document.createElement('div');
            toggle.className = 'day-toggle' + (isActive ? ' active' : '');
            toggle.textContent = allAbbr[di];
            toggle.dataset.day = day;
            toggle.style.cssText = 'width:32px;height:32px;border:1px solid #ccc;border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;';
            toggle.addEventListener('click', function() { this.classList.toggle('active'); });
            daysWrap.appendChild(toggle);
        });
        row.appendChild(daysWrap);

        // Horas
        ['Desde:', 'Hasta:'].forEach((text, i) => {
            var lbl = document.createElement('label');
            lbl.textContent = text;
            row.appendChild(lbl);
            var inp = document.createElement('input');
            inp.type = 'time';
            inp.name = (i === 0 ? 'schedule_start' : 'schedule_end') + '[' + idx + ']';
            inp.value = (i === 0 ? startVal : endVal);
            row.appendChild(inp);
        });

        // Eliminar
        var del = document.createElement('button');
        del.textContent = '✕';
        del.onclick = () => row.remove();
        row.appendChild(del);

        container.appendChild(row);
    }

    // Inicializar agregar
    (function() {
        var c = document.getElementById('schedules-add');
        if (c) addScheduleRow(c);
    })();

    // Actualiza también openModal para el modal de edición (reemplaza la sección de horarios)
    // ... (puedes copiar la lógica de openModal de tu archivo original y reemplazar la parte de time-inputs + days-inputs por la sección de schedules)

    </script>
</body>
</html>
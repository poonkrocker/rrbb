<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/db_connect.php';

define('UPLOAD_DIR', $_SERVER['DOCUMENT_ROOT'] . '/Uploads/');
define('UPLOAD_URL', '/Uploads/');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$errors  = [];
$success = [];

// ── Token CSRF (definido ANTES de cualquier acción POST) ────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function verifyCsrf(): void {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Petición no válida (CSRF).');
    }
}

// ── Ajustes del sitio (site_settings): transferencia + trackers ─────────────
function ensureSiteSettings(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_settings (
            setting_key   VARCHAR(64) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $seed = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
    $seed->execute(['transfer_alias',      'RRBBPIZZA']);
    $seed->execute(['transfer_holder',     'Ezequiel Urquidi']);
    $seed->execute(['gtm_id',              'GTM-5JWLLKC7']);
    $seed->execute(['meta_pixel_id',       '313442609878673']);
    $seed->execute(['ga4_id',              '']);
    $seed->execute(['store_shipping_cost', '0']);
}
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false) ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}
try { ensureSiteSettings($pdo); } catch (Throwable $e) { error_log('site_settings bootstrap: ' . $e->getMessage()); }

// ── Limpieza automática de variantes y subproductos huérfanos en la BD ──────
try {
    $pdo->exec("DELETE FROM menu_item_variants WHERE item_id NOT IN (SELECT id FROM menu_items)");
} catch (Throwable $e) { /* tabla opcional si aún no existiera */ }
try {
    $pdo->exec("DELETE FROM menu_item_subproducts WHERE parent_item_id NOT IN (SELECT id FROM menu_items) OR sub_item_id NOT IN (SELECT id FROM menu_items)");
} catch (Throwable $e) { /* tabla opcional */ }

// ── Motor de Publicación 1-Click desde /github hacia public_html (sin FTP) ───
function resolveDeployPaths(): array {
    $here = rtrim(str_replace('\\', '/', __DIR__), '/');
    if (strtolower(basename($here)) === 'github') {
        return [
            'is_running_in_github' => true,
            'src'                  => $here,
            'dst'                  => dirname($here),
        ];
    }
    return [
        'is_running_in_github' => false,
        'src'                  => $here . '/github',
        'dst'                  => $here,
    ];
}

function getGitRepoMeta(string $srcDir): ?array {
    $gitDir = $srcDir . '/.git';
    if (!is_dir($gitDir)) return null;

    $hash = '';
    $msg  = '';
    $date = '';

    $logFile = $gitDir . '/logs/HEAD';
    if (is_readable($logFile)) {
        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!empty($lines)) {
            $last = end($lines);
            if (preg_match('/^[0-9a-f]{40}\s+([0-9a-f]{40})\s+.*?\s+(\d{9,12})\s+[+\-]\d{4}\t(.*)$/i', (string)$last, $m)) {
                $hash = substr($m[1], 0, 7);
                $ts   = (int)$m[2];
                $dt   = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('America/Argentina/Cordoba'));
                $date = $dt->format('d/m/Y H:i');
                $msg  = trim($m[3]);
            }
        }
    }

    if ($hash === '' && is_readable($gitDir . '/HEAD')) {
        $head = trim((string)@file_get_contents($gitDir . '/HEAD'));
        if (strpos($head, 'ref: ') === 0) {
            $refPath = $gitDir . '/' . trim(substr($head, 5));
            if (is_readable($refPath)) {
                $hash = substr(trim((string)@file_get_contents($refPath)), 0, 7);
            }
        } else {
            $hash = substr($head, 0, 7);
        }
    }

    return [
        'hash' => $hash ?: 'main',
        'date' => $date,
        'msg'  => $msg,
    ];
}

function isDeployableRepoFile(string $filename): bool {
    if ($filename === '' || $filename === '.' || $filename === '..') return false;
    if ($filename === '.htaccess') return true;
    if ($filename[0] === '.') return false;

    $lower = strtolower($filename);
    $never = ['readme.md', 'config.php', 'error_log', 'error_log.txt'];
    if (in_array($lower, $never, true)) return false;
    if (preg_match('/(\.tmp_deploy$|\-old\.|\.(log|sql|bak|env|md|zip|tar|gz|bkp|lk)$)/i', $filename)) return false;

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['php', 'css', 'js', 'html', 'woff', 'woff2', 'png', 'webp', 'gif', 'ico', 'json'], true);
}

function inspectDeployStatus(): array {
    $paths  = resolveDeployPaths();
    $srcDir = $paths['src'];
    $dstDir = $paths['dst'];

    if (!is_dir($srcDir) || !is_file($srcDir . '/index.php')) {
        return [
            'available'   => false,
            'in_github'   => $paths['is_running_in_github'],
            'src'         => $srcDir,
            'dst'         => $dstDir,
            'git'         => null,
            'pending'     => [],
            'obsolete'    => [],
            'total_files' => 0,
        ];
    }

    $candidates = [];
    foreach (@scandir($srcDir) ?: [] as $f) {
        if (!is_file($srcDir . '/' . $f) || !isDeployableRepoFile($f)) continue;
        // No pisar caché viva de reseñas en producción si ya existe
        if ($f === 'cache_google_reviews.json' && is_file($dstDir . '/' . $f)) continue;
        $candidates[] = $f;
    }

    if (is_dir($srcDir . '/carta')) {
        foreach (@scandir($srcDir . '/carta') ?: [] as $cf) {
            if (!is_file($srcDir . '/carta/' . $cf) || !isDeployableRepoFile($cf)) continue;
            $candidates[] = 'carta/' . $cf;
        }
    }
    sort($candidates);

    $pending = [];
    foreach ($candidates as $rel) {
        $sFile = $srcDir . '/' . $rel;
        $dFile = $dstDir . '/' . $rel;
        if (!is_file($dFile)) {
            $pending[] = ['file' => $rel, 'status' => 'nuevo'];
        } elseif (@sha1_file($sFile) !== @sha1_file($dFile)) {
            $pending[] = ['file' => $rel, 'status' => 'modificado'];
        }
    }

    // Detectar archivos obsoletos / inseguros en producción (sin tocar Uploads ni carpetas ajenas)
    $obsolete = [];
    foreach (@scandir($dstDir) ?: [] as $df) {
        if (!is_file($dstDir . '/' . $df)) continue;
        if (in_array($df, ['carta.php', 'info.php', 'genhash.php', 'error_log.txt'], true)
            || preg_match('/^(diag.*|.*\-old)\.(php|css|html)$/i', $df)
            || preg_match('/\.(zip|tar\.gz|BKP)$/i', $df)
        ) {
            $obsolete[] = $df;
        }
    }
    if (is_dir($dstDir . '/carta')) {
        foreach (@scandir($dstDir . '/carta') ?: [] as $cdf) {
            if (!is_file($dstDir . '/carta/' . $cdf)) continue;
            if (in_array($cdf, ['index_old.php', 'test.php'], true)
                || preg_match('/^(diag.*|.*\-old)\.(php|css|html)$/i', $cdf)
                || preg_match('/\.zip$/i', $cdf)
            ) {
                $obsolete[] = 'carta/' . $cdf;
            }
        }
    }
    sort($obsolete);

    return [
        'available'   => true,
        'in_github'   => $paths['is_running_in_github'],
        'src'         => $srcDir,
        'dst'         => $dstDir,
        'git'         => getGitRepoMeta($srcDir),
        'candidates'  => $candidates,
        'pending'     => $pending,
        'obsolete'    => $obsolete,
        'total_files' => count($candidates),
    ];
}

function executeDeployFromGithub(bool $cleanObsolete, bool $forceAll = false): array {
    $plan = inspectDeployStatus();
    if (!$plan['available']) {
        throw new Exception("No se encontró el directorio del repositorio en {$plan['src']}. Verificá que el clon de Ferozo esté en /public_html/github.");
    }

    $srcDir = $plan['src'];
    $dstDir = $plan['dst'];

    if (!is_dir($dstDir) || !is_writable($dstDir)) {
        throw new Exception("El directorio de destino ({$dstDir}) no tiene permisos de escritura.");
    }
    if (!is_dir($dstDir . '/carta') && !@mkdir($dstDir . '/carta', 0755, true)) {
        throw new Exception("No se pudo crear la carpeta {$dstDir}/carta.");
    }

    $filesToCopy = $forceAll
        ? $plan['candidates']
        : array_column($plan['pending'], 'file');

    $copied  = 0;
    $deleted = 0;

    foreach ($filesToCopy as $rel) {
        $sFile   = $srcDir . '/' . $rel;
        $dFile   = $dstDir . '/' . $rel;
        $tmpFile = $dFile . '.tmp_deploy';

        if (!@copy($sFile, $tmpFile)) {
            throw new Exception("Error al copiar archivo temporal para {$rel}.");
        }
        if (!@rename($tmpFile, $dFile)) {
            if (!@copy($sFile, $dFile)) {
                @unlink($tmpFile);
                throw new Exception("No se pudo reemplazar {$rel} en producción.");
            }
            @unlink($tmpFile);
        }
        @chmod($dFile, 0644);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($dFile, true);
        }
        $copied++;
    }

    if ($cleanObsolete && !empty($plan['obsolete'])) {
        foreach ($plan['obsolete'] as $obsRel) {
            // Doble control: jamás borrar fuera de raíz o carta/
            if (strpos($obsRel, '..') !== false) continue;
            $obsPath = $dstDir . '/' . $obsRel;
            if (is_file($obsPath) && @unlink($obsPath)) {
                $deleted++;
            }
        }
    }

    return ['copied' => $copied, 'deleted' => $deleted];
}

// ── Horarios de Atención (unificados en una sola fila aunque crucen medianoche) ──
function normTime(?string $t): string {
    $t = trim((string)$t);
    if ($t === '') return '';
    $parts = explode(':', $t);
    $h = max(0, min(23, (int)($parts[0] ?? 0)));
    $m = max(0, min(59, (int)($parts[1] ?? 0)));
    return str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ':00';
}

function nextDayOfWeek(string $day): string {
    $days = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    $i = array_search($day, $days, true);
    if ($i === false) throw new Exception("Día inválido: $day");
    return $days[($i + 1) % 7];
}

function hhmmToMinutes(string $hms): int {
    $parts = explode(':', $hms);
    return ((int)($parts[0] ?? 0)) * 60 + ((int)($parts[1] ?? 0));
}

/**
 * Convierte un turno (día + inicio + fin) en 1 o 2 intervalos [startMin, endMin)
 * sobre el círculo semanal de 0..10080 minutos, soportando cruces de medianoche
 * (ej. Viernes 20:30 a 00:30, o Domingo 20:30 a Lunes 00:30).
 */
function shiftToWeekIntervals(string $day, string $startHms, string $endHms): array {
    $days = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    $dayIdx = array_search($day, $days, true);
    if ($dayIdx === false) throw new Exception("Día inválido: $day");

    $sMin = hhmmToMinutes($startHms);
    $eMin = hhmmToMinutes($endHms);
    if ($sMin === $eMin) {
        throw new Exception("La hora de inicio y la de fin no pueden ser iguales.");
    }

    // Si end_time era legado 23:59:59, equivale al cierre 00:00 (24:00 del mismo día)
    if (preg_match('/^23:59(?::\d{2})?$/', $endHms)) {
        $absStart = $dayIdx * 1440 + $sMin;
        $absEnd   = ($dayIdx + 1) * 1440;
    } elseif ($eMin > $sMin) {
        $absStart = $dayIdx * 1440 + $sMin;
        $absEnd   = $dayIdx * 1440 + $eMin;
    } else {
        // Cruza medianoche hacia la madrugada del día siguiente (ej. 20:30 -> 00:30 o 20:00 -> 00:00)
        $absStart = $dayIdx * 1440 + $sMin;
        $absEnd   = ($dayIdx + 1) * 1440 + $eMin;
    }

    $weekTotal = 7 * 1440; // 10080
    if ($absEnd <= $weekTotal) {
        return [[$absStart, $absEnd]];
    }
    return [
        [$absStart, $weekTotal],
        [0, $absEnd - $weekTotal],
    ];
}

function hasShiftOverlapWeek(PDO $pdo, string $day, string $startHms, string $endHms, ?int $excludeId = null): ?string {
    $candidateSegs = shiftToWeekIntervals($day, $startHms, $endHms);
    $rows = $pdo->query("SELECT id, day_of_week, start_time, end_time FROM business_hours")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        if ($excludeId !== null && (int)$r['id'] === $excludeId) continue;
        try {
            $existingSegs = shiftToWeekIntervals((string)$r['day_of_week'], (string)$r['start_time'], (string)$r['end_time']);
        } catch (Throwable $e) {
            continue;
        }
        foreach ($candidateSegs as [$a1, $b1]) {
            foreach ($existingSegs as [$a2, $b2]) {
                if (max($a1, $a2) < min($b1, $b2)) {
                    return "{$r['day_of_week']} (" . substr((string)$r['start_time'], 0, 5) . " a " . substr((string)$r['end_time'], 0, 5) . ")";
                }
            }
        }
    }
    return null;
}

/**
 * Fusiona automáticamente cualquier par legado partido en dos filas
 * (ej. Viernes 20:30 - 23:59:59 + Sábado 00:00:00 - 00:30:00) en una única fila
 * limpia: Viernes 20:30:00 - 00:30:00.
 */
function consolidateLegacyMidnightHours(PDO $pdo): void {
    $days = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    $rows = $pdo->query("
        SELECT id, day_of_week, start_time, end_time
        FROM business_hours
        ORDER BY FIELD(day_of_week,'Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'), start_time
    ")->fetchAll(PDO::FETCH_ASSOC);

    $byDay = [];
    foreach ($days as $d) $byDay[$d] = [];
    foreach ($rows as $r) {
        if (isset($byDay[$r['day_of_week']])) {
            $byDay[$r['day_of_week']][] = $r;
        }
    }

    $consumedIds = [];
    for ($i = 0; $i < 7; $i++) {
        $d     = $days[$i];
        $nextD = $days[($i + 1) % 7];
        foreach ($byDay[$d] as $row) {
            $rid = (int)$row['id'];
            if (isset($consumedIds[$rid])) continue;
            $e = trim((string)$row['end_time']);
            if (!preg_match('/^23:59(?::\d{2})?$/', $e)) continue;

            $merged = false;
            foreach ($byDay[$nextD] as $nextRow) {
                $nid = (int)$nextRow['id'];
                if (isset($consumedIds[$nid])) continue;
                $ns = trim((string)$nextRow['start_time']);
                $ne = trim((string)$nextRow['end_time']);
                if (preg_match('/^00:00(?::\d{2})?$/', $ns) && $ne <= '06:00:00') {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("UPDATE business_hours SET end_time = ? WHERE id = ?")->execute([$ne, $rid]);
                        $pdo->prepare("DELETE FROM business_hours WHERE id = ?")->execute([$nid]);
                        $pdo->commit();
                        $consumedIds[$nid] = true;
                        $merged = true;
                    } catch (Throwable $ex) {
                        $pdo->rollBack();
                    }
                    break;
                }
            }
            if (!$merged && $e === '23:59:59') {
                $pdo->prepare("UPDATE business_hours SET end_time = '00:00:00' WHERE id = ?")->execute([$rid]);
            }
        }
    }
}
try { consolidateLegacyMidnightHours($pdo); } catch (Throwable $e) { error_log('consolidateLegacyMidnightHours: ' . $e->getMessage()); }

// ── Upload seguro de imágenes con procesamiento a 800×800 WebP ──────────────
function uploadImageSecure(array $file, string $target_dir): string {
    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Error al recibir el archivo (código {$file['error']}).");
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed_mime, true)) {
        throw new Exception("Tipo de archivo no permitido. Solo se aceptan imágenes (JPG, PNG, WEBP, GIF).");
    }

    $save_dir   = defined('UPLOAD_DIR') ? UPLOAD_DIR : $target_dir;
    $pub_dir    = defined('UPLOAD_URL') ? UPLOAD_URL : $target_dir;
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
    if (!$src) {
        throw new Exception("No se pudo leer la imagen subida.");
    }

    $orig_w   = imagesx($src);
    $orig_h   = imagesy($src);
    $out_size = 800;
    $dst      = imagecreatetruecolor($out_size, $out_size);

    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);

    $scale    = min($out_size / $orig_w, $out_size / $orig_h);
    $scaled_w = (int)round($orig_w * $scale);
    $scaled_h = (int)round($orig_h * $scale);
    $offset_x = (int)(($out_size - $scaled_w) / 2);
    $offset_y = (int)(($out_size - $scaled_h) / 2);

    imagecopyresampled($dst, $src, $offset_x, $offset_y, 0, 0, $scaled_w, $scaled_h, $orig_w, $orig_h);

    $dest = $save_dir . $image_name;
    if (!imagewebp($dst, $dest, 80)) {
        throw new Exception("Error al guardar la imagen procesada en el servidor.");
    }

    imagedestroy($src);
    imagedestroy($dst);

    return $pub_dir . $image_name;
}

function uploadImageFromBase64(string $base64data, string $target_dir): string {
    if (!preg_match('/^data:(image\/(?:jpeg|png|webp|gif));base64,(.+)$/s', $base64data, $m)) {
        throw new Exception("Formato de imagen recortada inválido.");
    }
    $mime = $m[1];
    $raw  = base64_decode($m[2], true);
    if ($raw === false || strlen($raw) < 100) {
        throw new Exception("Datos de imagen recortada corruptos.");
    }

    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowed_mime, true)) {
        throw new Exception("Tipo MIME de imagen recortada no permitido.");
    }

    $tmp = tempnam(sys_get_temp_dir(), 'crop_');
    file_put_contents($tmp, $raw);
    try {
        $fake_file = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp];
        return uploadImageSecure($fake_file, $target_dir);
    } finally {
        @unlink($tmp);
    }
}

// ── Procesamiento de TODAS las acciones POST (protegidas por CSRF) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    $action = (string)$_POST['action'];

    try {
        if ($action === 'add_business_hours') {
            $day        = trim((string)($_POST['day_of_week'] ?? ''));
            $start_time = normTime($_POST['start_time'] ?? '');
            $end_time   = normTime($_POST['end_time']   ?? '');
            if ($day === '' || $start_time === '' || $end_time === '') {
                throw new Exception("Día, hora de inicio y hora de fin son obligatorios.");
            }
            $conflict = hasShiftOverlapWeek($pdo, $day, $start_time, $end_time, null);
            if ($conflict !== null) {
                throw new Exception("El horario se superpone con otro turno existente: $conflict.");
            }
            $pdo->prepare("INSERT INTO business_hours (day_of_week, start_time, end_time) VALUES (?, ?, ?)")
                ->execute([$day, $start_time, $end_time]);
            $crosses = ($end_time <= $start_time);
            $success[] = $crosses
                ? "Horario agregado en un solo paso ({$day} de " . substr($start_time,0,5) . " a " . substr($end_time,0,5) . " del " . nextDayOfWeek($day) . ")."
                : "Horario agregado correctamente.";

        } elseif ($action === 'update_business_hours') {
            $id         = (int)($_POST['id'] ?? 0);
            $day        = trim((string)($_POST['day_of_week'] ?? ''));
            $start_time = normTime($_POST['start_time'] ?? '');
            $end_time   = normTime($_POST['end_time']   ?? '');
            if ($id <= 0) throw new Exception("ID de horario inválido.");
            if ($day === '' || $start_time === '' || $end_time === '') {
                throw new Exception("Día, hora de inicio y hora de fin son obligatorios.");
            }
            $conflict = hasShiftOverlapWeek($pdo, $day, $start_time, $end_time, $id);
            if ($conflict !== null) {
                throw new Exception("El horario se superpone con otro turno existente: $conflict.");
            }
            $pdo->prepare("UPDATE business_hours SET day_of_week = ?, start_time = ?, end_time = ? WHERE id = ?")
                ->execute([$day, $start_time, $end_time, $id]);
            $success[] = "Horario actualizado correctamente.";

        } elseif ($action === 'delete_business_hours') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception("ID de horario inválido.");
            $pdo->prepare("DELETE FROM business_hours WHERE id = ?")->execute([$id]);
            $success[] = "Horario eliminado correctamente.";

        } elseif ($action === 'add_item') {
            $name               = trim((string)($_POST['name'] ?? ''));
            $price              = (float)($_POST['price'] ?? 0);
            $category_id        = (int)($_POST['category_id'] ?? 0);
            $description        = trim((string)($_POST['description'] ?? ''));
            $is_visible         = isset($_POST['is_visible']) ? 1 : 0;
            $has_vegan_option   = isset($_POST['has_vegan_option']) ? 1 : 0;
            $is_weekly_special  = isset($_POST['is_weekly_special']) ? 1 : 0;
            $is_secret_menu     = isset($_POST['is_secret_menu']) ? 1 : 0;
            $weekly_special_text = trim((string)($_POST['weekly_special_text'] ?? ''));
            if ($weekly_special_text === '') {
                $weekly_special_text = $is_secret_menu && !$is_weekly_special ? '¡Carta Secreta!' : '¡Pizza de la semana!';
            }

            $visible_days = null;
            if (!empty($_POST['schedule_days']) && is_array($_POST['schedule_days'])) {
                $schedules = [];
                foreach ($_POST['schedule_days'] as $idx => $days) {
                    if (empty($days)) continue;
                    $start = trim((string)($_POST['schedule_start'][$idx] ?? ''));
                    $end   = trim((string)($_POST['schedule_end'][$idx]   ?? ''));
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
            $display_order = (int)($_POST['display_order'] ?? 0);

            if ($name === '' || $price <= 0 || $category_id <= 0) {
                throw new Exception("Nombre, precio y categoría son obligatorios y deben ser válidos.");
            }

            $image_url = '';
            if (!empty($_POST['cropped_image_data'])) {
                $image_url = uploadImageFromBase64((string)$_POST['cropped_image_data'], "Uploads/");
            } elseif (!empty($_POST['image_url'])) {
                $candidate = filter_var((string)$_POST['image_url'], FILTER_SANITIZE_URL);
                if (!preg_match('/^https?:\/\//i', $candidate) || !filter_var($candidate, FILTER_VALIDATE_URL)) {
                    throw new Exception("La URL de la imagen no es válida.");
                }
                $image_url = $candidate;
            } elseif (!empty($_FILES['image']['name'])) {
                $image_url = uploadImageSecure($_FILES['image'], "Uploads/");
            }

            $stmt = $pdo->prepare("
                INSERT INTO menu_items
                    (name, price, secondary_price, category_id, image_url, description, is_visible, has_vegan_option, requires_pizza, is_weekly_special, weekly_special_text, visible_start_time, visible_end_time, visible_days, display_order, required_selections, is_secret_menu)
                VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 0, ?, ?, NULL, NULL, ?, ?, NULL, ?)
            ");
            $stmt->execute([
                $name, $price, $category_id, $image_url, $description, $is_visible,
                $has_vegan_option, $is_weekly_special, $weekly_special_text, $visible_days,
                $display_order, $is_secret_menu,
            ]);

            $item_id = (int)$pdo->lastInsertId();

            if (!empty($_POST['variant_names']) && is_array($_POST['variant_names'])) {
                $vstmt = $pdo->prepare("INSERT INTO menu_item_variants (item_id, name, description, price, display_order) VALUES (?, ?, ?, ?, ?)");
                foreach ($_POST['variant_names'] as $vi => $vname) {
                    $vname  = trim((string)$vname);
                    $vprice = isset($_POST['variant_prices'][$vi]) ? (float)$_POST['variant_prices'][$vi] : 0;
                    $vdesc  = trim((string)($_POST['variant_descriptions'][$vi] ?? ''));
                    if ($vname === '' || $vprice <= 0) continue;
                    $vstmt->execute([$item_id, $vname, ($vdesc !== '' ? $vdesc : null), $vprice, (int)$vi]);
                }
            }

            $success[] = "Producto agregado correctamente.";

        } elseif ($action === 'delete_item') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM menu_item_subproducts WHERE parent_item_id = ? OR sub_item_id = ?")->execute([$id, $id]);
                $pdo->prepare("DELETE FROM menu_item_variants WHERE item_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM menu_items WHERE id = ?")->execute([$id]);
                $success[] = "Producto eliminado correctamente.";
            }

        } elseif ($action === 'update_item') {
            $id                 = (int)($_POST['id'] ?? 0);
            $name               = trim((string)($_POST['name'] ?? ''));
            $price              = (float)($_POST['price'] ?? 0);
            $category_id        = (int)($_POST['category_id'] ?? 0);
            $description        = trim((string)($_POST['description'] ?? ''));
            $is_visible         = isset($_POST['is_visible']) ? 1 : 0;
            $has_vegan_option   = isset($_POST['has_vegan_option']) ? 1 : 0;
            $is_weekly_special  = isset($_POST['is_weekly_special']) ? 1 : 0;
            $is_secret_menu     = isset($_POST['is_secret_menu']) ? 1 : 0;
            $weekly_special_text = trim((string)($_POST['weekly_special_text'] ?? ''));
            if ($weekly_special_text === '') {
                $weekly_special_text = $is_secret_menu && !$is_weekly_special ? '¡Carta Secreta!' : '¡Pizza de la semana!';
            }

            $visible_days = null;
            if (!empty($_POST['schedule_days']) && is_array($_POST['schedule_days'])) {
                $schedules = [];
                foreach ($_POST['schedule_days'] as $idx => $days) {
                    if (empty($days)) continue;
                    $start = trim((string)($_POST['schedule_start'][$idx] ?? ''));
                    $end   = trim((string)($_POST['schedule_end'][$idx]   ?? ''));
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
            $display_order = (int)($_POST['display_order'] ?? 0);

            if ($id <= 0 || $name === '' || $price <= 0 || $category_id <= 0) {
                throw new Exception("Nombre, precio y categoría son obligatorios y deben ser válidos.");
            }

            $image_url = (string)($_POST['existing_image'] ?? '');
            if (!empty($_POST['cropped_image_data'])) {
                $image_url = uploadImageFromBase64((string)$_POST['cropped_image_data'], "Uploads/");
            } elseif (!empty($_POST['image_url'])) {
                $candidate = filter_var((string)$_POST['image_url'], FILTER_SANITIZE_URL);
                if (!preg_match('/^https?:\/\//i', $candidate) || !filter_var($candidate, FILTER_VALIDATE_URL)) {
                    throw new Exception("La URL de la imagen no es válida.");
                }
                $image_url = $candidate;
            } elseif (!empty($_FILES['image']['name'])) {
                $image_url = uploadImageSecure($_FILES['image'], "Uploads/");
            }

            $stmt = $pdo->prepare("
                UPDATE menu_items SET
                    name = ?, price = ?, category_id = ?, image_url = ?, description = ?,
                    is_visible = ?, has_vegan_option = ?, requires_pizza = 0,
                    is_weekly_special = ?, weekly_special_text = ?,
                    visible_start_time = NULL, visible_end_time = NULL, visible_days = ?,
                    display_order = ?, required_selections = NULL, is_secret_menu = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $price, $category_id, $image_url, $description,
                $is_visible, $has_vegan_option,
                $is_weekly_special, $weekly_special_text,
                $visible_days, $display_order, $is_secret_menu, $id,
            ]);

            // Limpiar cualquier subproducto legado y regenerar variantes
            $pdo->prepare("DELETE FROM menu_item_subproducts WHERE parent_item_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM menu_item_variants WHERE item_id = ?")->execute([$id]);
            if (!empty($_POST['variant_names']) && is_array($_POST['variant_names'])) {
                $vstmt = $pdo->prepare("INSERT INTO menu_item_variants (item_id, name, description, price, display_order) VALUES (?, ?, ?, ?, ?)");
                foreach ($_POST['variant_names'] as $vi => $vname) {
                    $vname  = trim((string)$vname);
                    $vprice = isset($_POST['variant_prices'][$vi]) ? (float)$_POST['variant_prices'][$vi] : 0;
                    $vdesc  = trim((string)($_POST['variant_descriptions'][$vi] ?? ''));
                    if ($vname === '' || $vprice <= 0) continue;
                    $vstmt->execute([$id, $vname, ($vdesc !== '' ? $vdesc : null), $vprice, (int)$vi]);
                }
            }

            $success[] = "Producto actualizado correctamente.";

        } elseif ($action === 'add_category') {
            $name = trim((string)($_POST['category_name'] ?? ''));
            if ($name === '') throw new Exception("El nombre de la categoría es obligatorio.");
            $maxOrd = (int)$pdo->query("SELECT COALESCE(MAX(display_order), 0) FROM categories")->fetchColumn();
            $display_order = isset($_POST['category_display_order']) && (int)$_POST['category_display_order'] > 0
                ? (int)$_POST['category_display_order']
                : ($maxOrd + 1);
            $pdo->prepare("INSERT INTO categories (name, display_order) VALUES (?, ?)")->execute([$name, $display_order]);
            $success[] = "Categoría agregada correctamente.";

        } elseif ($action === 'add_category_ajax') {
            header('Content-Type: application/json; charset=utf-8');
            $name = trim((string)($_POST['category_name'] ?? ''));
            if ($name === '') {
                echo json_encode(['ok' => false, 'error' => 'El nombre de la categoría es obligatorio.']);
                exit;
            }
            $maxOrd = (int)$pdo->query("SELECT COALESCE(MAX(display_order), 0) FROM categories")->fetchColumn();
            $nextOrd = $maxOrd + 1;
            $pdo->prepare("INSERT INTO categories (name, display_order) VALUES (?, ?)")->execute([$name, $nextOrd]);
            $newId = (int)$pdo->lastInsertId();
            echo json_encode([
                'ok'       => true,
                'category' => ['id' => $newId, 'name' => $name, 'display_order' => $nextOrd],
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif ($action === 'rename_category_ajax') {
            header('Content-Type: application/json; charset=utf-8');
            $id   = (int)($_POST['category_id'] ?? 0);
            $name = trim((string)($_POST['category_name'] ?? ''));
            if ($id <= 0 || $name === '') {
                echo json_encode(['ok' => false, 'error' => 'Datos de categoría inválidos.']);
                exit;
            }
            $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?")->execute([$name, $id]);
            echo json_encode(['ok' => true, 'id' => $id, 'name' => $name], JSON_UNESCAPED_UNICODE);
            exit;

        } elseif ($action === 'delete_category_ajax') {
            header('Content-Type: application/json; charset=utf-8');
            $id          = (int)($_POST['category_id'] ?? 0);
            $confirmWith = !empty($_POST['confirm_with_items']);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Categoría inválida.']);
                exit;
            }
            $idStmt = $pdo->prepare("SELECT id FROM menu_items WHERE category_id = ?");
            $idStmt->execute([$id]);
            $itemIds = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN));
            $count   = count($itemIds);

            if ($count > 0 && !$confirmWith) {
                echo json_encode(['ok' => false, 'needs_confirm' => true, 'count' => $count]);
                exit;
            }

            $pdo->beginTransaction();
            try {
                if ($count > 0) {
                    $ph = implode(',', array_fill(0, $count, '?'));
                    $pdo->prepare("DELETE FROM menu_item_subproducts WHERE parent_item_id IN ($ph) OR sub_item_id IN ($ph)")
                        ->execute(array_merge($itemIds, $itemIds));
                    $pdo->prepare("DELETE FROM menu_item_variants WHERE item_id IN ($ph)")
                        ->execute($itemIds);
                    $pdo->prepare("DELETE FROM menu_items WHERE category_id = ?")->execute([$id]);
                }
                $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
                $pdo->commit();
                echo json_encode([
                    'ok'               => true,
                    'deleted_items'    => $count,
                    'deleted_item_ids' => $itemIds,
                ]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;

        } elseif ($action === 'update_category') {
            $id            = (int)($_POST['category_id'] ?? 0);
            $name          = trim((string)($_POST['category_name'] ?? ''));
            $display_order = (int)($_POST['category_display_order'] ?? 0);
            if ($id <= 0 || $name === '') throw new Exception("El nombre de la categoría es obligatorio.");
            $pdo->prepare("UPDATE categories SET name = ?, display_order = ? WHERE id = ?")->execute([$name, $display_order, $id]);
            $success[] = "Categoría actualizada correctamente.";

        } elseif ($action === 'delete_category') {
            $id   = (int)($_POST['category_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE category_id = ?");
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
                $success[] = "Categoría eliminada correctamente.";
            } else {
                $errors[] = "No se puede eliminar una categoría con productos asociados.";
            }

        } elseif ($action === 'bulk_update_prices') {
            if (!empty($_POST['prices']) && is_array($_POST['prices'])) {
                if (!empty($_POST['delete_ids']) && is_array($_POST['delete_ids'])) {
                    $del_sub  = $pdo->prepare("DELETE FROM menu_item_subproducts WHERE parent_item_id = ? OR sub_item_id = ?");
                    $del_var  = $pdo->prepare("DELETE FROM menu_item_variants WHERE item_id = ?");
                    $del_item = $pdo->prepare("DELETE FROM menu_items WHERE id = ?");
                    $deletedCount = 0;
                    foreach ($_POST['delete_ids'] as $del_id) {
                        $del_id = (int)$del_id;
                        if ($del_id > 0) {
                            $del_sub->execute([$del_id, $del_id]);
                            $del_var->execute([$del_id]);
                            $del_item->execute([$del_id]);
                            unset($_POST['prices'][$del_id]);
                            $deletedCount++;
                        }
                    }
                    if ($deletedCount > 0) {
                        $success[] = "{$deletedCount} producto(s) eliminado(s) junto con sus variantes.";
                    }
                }
                $stmt    = $pdo->prepare("UPDATE menu_items SET price = ? WHERE id = ?");
                $updated = 0;
                foreach ($_POST['prices'] as $item_id => $price_val) {
                    $item_id   = (int)$item_id;
                    $price_val = (float)$price_val;
                    if ($item_id <= 0 || $price_val <= 0) continue;
                    $stmt->execute([$price_val, $item_id]);
                    $updated++;
                }
                $success[] = "Precios actualizados correctamente ({$updated} productos).";
            } else {
                $errors[] = "No se recibieron precios para actualizar.";
            }

        } elseif ($action === 'toggle_visibility') {
            header('Content-Type: application/json; charset=utf-8');
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'ID inválido']); exit; }
            $pdo->prepare("UPDATE menu_items SET is_visible = NOT is_visible WHERE id = ?")->execute([$id]);
            $stVis = $pdo->prepare("SELECT is_visible FROM menu_items WHERE id = ?");
            $stVis->execute([$id]);
            echo json_encode(['ok' => true, 'is_visible' => (bool)$stVis->fetchColumn()]);
            exit;

        } elseif ($action === 'reorder_items' || $action === 'update_order') {
            header('Content-Type: application/json; charset=utf-8');
            $rawOrder = $_POST['order'] ?? '[]';
            $order = is_array($rawOrder) ? $rawOrder : json_decode((string)$rawOrder, true);
            if (!is_array($order) || empty($order)) {
                echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE menu_items SET display_order = ? WHERE id = ?");
            $pdo->beginTransaction();
            try {
                foreach (array_values($order) as $idx => $itemId) {
                    $stmt->execute([$idx + 1, (int)$itemId]);
                }
                $pdo->commit();
                echo json_encode(['ok' => true]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;

        } elseif ($action === 'reorder_categories') {
            header('Content-Type: application/json; charset=utf-8');
            $order = json_decode((string)($_POST['order'] ?? '[]'), true);
            if (!is_array($order) || empty($order)) {
                echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE categories SET display_order = ? WHERE id = ?");
            $pdo->beginTransaction();
            try {
                foreach (array_values($order) as $idx => $catId) {
                    $stmt->execute([$idx + 1, (int)$catId]);
                }
                $pdo->commit();
                echo json_encode(['ok' => true]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;

        } elseif ($action === 'update_payments_and_store') {
            $alias   = trim((string)($_POST['transfer_alias']  ?? ''));
            $holder  = trim((string)($_POST['transfer_holder'] ?? ''));
            $rawShip = str_replace(',', '.', trim((string)($_POST['store_shipping_cost'] ?? '0')));
            if ($rawShip === '') $rawShip = '0';

            if ($alias === '' || $holder === '') {
                throw new Exception("El alias y el titular de transferencia no pueden quedar vacíos.");
            }
            if (!is_numeric($rawShip) || (float)$rawShip < 0) {
                throw new Exception("El costo de envío de Tienda debe ser 0 o un monto positivo.");
            }

            $a         = substr($alias,  0, 190);
            $h         = substr($holder, 0, 190);
            $shipClean = (string)round((float)$rawShip, 2);

            $up = $pdo->prepare("
                INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $up->execute(['transfer_alias',      $a,         $a]);
            $up->execute(['transfer_holder',     $h,         $h]);
            $up->execute(['store_shipping_cost', $shipClean, $shipClean]);
            $success[] = "Datos de transferencia y configuración de Tienda actualizados.";

        } elseif ($action === 'update_transfer_settings') {
            $alias  = trim((string)($_POST['transfer_alias']  ?? ''));
            $holder = trim((string)($_POST['transfer_holder'] ?? ''));
            if ($alias === '' || $holder === '') {
                $errors[] = "El alias y el titular no pueden quedar vacíos.";
            } else {
                $up = $pdo->prepare("
                    INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = ?
                ");
                $a = substr($alias,  0, 190);
                $h = substr($holder, 0, 190);
                $up->execute(['transfer_alias',  $a, $a]);
                $up->execute(['transfer_holder', $h, $h]);
                $success[] = "Datos de transferencia actualizados.";
            }

        } elseif ($action === 'update_tracking_settings') {
            $gtmId   = strtoupper(trim((string)($_POST['gtm_id'] ?? '')));
            $pixelId = trim((string)($_POST['meta_pixel_id'] ?? ''));
            $ga4Id   = strtoupper(trim((string)($_POST['ga4_id'] ?? '')));

            if ($gtmId !== '' && !preg_match('/^GTM-[A-Z0-9]+$/', $gtmId)) {
                throw new Exception("El ID de Google Tag Manager debe tener formato GTM-XXXXXXX (o quedar vacío).");
            }
            if ($pixelId !== '' && !preg_match('/^\d{8,24}$/', $pixelId)) {
                throw new Exception("El ID del Pixel de Meta debe contener solo números (ej. 313442609878673).");
            }
            if ($ga4Id !== '' && !preg_match('/^G-[A-Z0-9]+$/', $ga4Id)) {
                throw new Exception("El ID de Google Analytics 4 debe tener formato G-XXXXXXXXXX (o quedar vacío si ya se carga vía GTM).");
            }

            $up = $pdo->prepare("
                INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $up->execute(['gtm_id',        $gtmId,   $gtmId]);
            $up->execute(['meta_pixel_id', $pixelId, $pixelId]);
            $up->execute(['ga4_id',        $ga4Id,   $ga4Id]);
            $success[] = "Configuración de Meta Pixel y Google Analytics actualizada.";

        } elseif ($action === 'update_store_settings') {
            $rawShip = str_replace(',', '.', trim((string)($_POST['store_shipping_cost'] ?? '0')));
            if ($rawShip === '') $rawShip = '0';
            if (!is_numeric($rawShip) || (float)$rawShip < 0) {
                throw new Exception("El costo de envío debe ser 0 o un monto positivo.");
            }
            $shipClean = (string)round((float)$rawShip, 2);
            $up = $pdo->prepare("
                INSERT INTO site_settings (setting_key, setting_value) VALUES ('store_shipping_cost', ?)
                ON DUPLICATE KEY UPDATE setting_value = ?
            ");
            $up->execute([$shipClean, $shipClean]);
            $success[] = "Configuración de envío de Tienda actualizada.";

        } elseif ($action === 'deploy_from_github') {
            $cleanObs = !empty($_POST['clean_obsolete']);
            $forceAll = !empty($_POST['force_all']);
            $res = executeDeployFromGithub($cleanObs, $forceAll);
            $msgParts = ["¡Publicación completada! {$res['copied']} archivo(s) sincronizados desde /github hacia public_html y /carta"];
            if ($res['deleted'] > 0) {
                $msgParts[] = "{$res['deleted']} archivo(s) obsoleto(s) eliminado(s)";
            }
            $success[] = implode(' · ', $msgParts) . ". Tus fotos (/Uploads), config.php y demás subcarpetas no fueron tocadas.";
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        error_log('editor.php error: ' . $e->getMessage());
    }
}

// ── Sembrar categoría 'Extras' solo si todavía no existe en absoluto ─────────
try {
    $stmtExt = $pdo->query("SELECT id FROM categories WHERE LOWER(TRIM(name)) = 'extras' LIMIT 1");
    if (!$stmtExt->fetchColumn()) {
        $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(display_order), 0) FROM categories")->fetchColumn();
        $insCat   = $pdo->prepare("INSERT INTO categories (name, display_order) VALUES ('Extras', ?)");
        $insCat->execute([$maxOrder + 1]);
        $catId = (int)$pdo->lastInsertId();
        $insItem = $pdo->prepare("
            INSERT INTO menu_items (name, price, category_id, is_visible, display_order, description)
            VALUES (?, ?, ?, 1, ?, 'Extra para sumar a tu pizza')
        ");
        $insItem->execute(['Stracciatella',   5000.00, $catId, 1]);
        $insItem->execute(['Anchoas',         3000.00, $catId, 2]);
        $insItem->execute(['Dip aceite oliva', 1500.00, $catId, 3]);
    }
} catch (Throwable $e) {
    error_log("Extras seed check: " . $e->getMessage());
}

// ── Consultas de lectura para renderizar el editor ──────────────────────────
$categories = $pdo->query("SELECT * FROM categories ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);

$menu_items_by_category = [];
$menu_items = $pdo->query("
    SELECT mi.*, c.name AS category_name
    FROM menu_items mi
    JOIN categories c ON mi.category_id = c.id
    ORDER BY c.display_order, mi.display_order
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($menu_items as $item) {
    $menu_items_by_category[$item['category_id']][] = $item;
}

$variants = [];
if (!empty($menu_items)) {
    $ids = array_column($menu_items, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT id, item_id, name, description, price
        FROM menu_item_variants
        WHERE item_id IN ($placeholders)
        ORDER BY item_id, display_order, id
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $variants[$row['item_id']][] = $row;
    }
}

$business_hours = $pdo->query("
    SELECT * FROM business_hours
    ORDER BY FIELD(day_of_week, 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'), start_time
")->fetchAll(PDO::FETCH_ASSOC);

$transfer_alias      = getSetting($pdo, 'transfer_alias',      'RRBBPIZZA');
$transfer_holder     = getSetting($pdo, 'transfer_holder',     'Ezequiel Urquidi');
$gtm_id              = getSetting($pdo, 'gtm_id',              'GTM-5JWLLKC7');
$meta_pixel_id       = getSetting($pdo, 'meta_pixel_id',       '313442609878673');
$ga4_id              = getSetting($pdo, 'ga4_id',              '');
$store_shipping_cost = getSetting($pdo, 'store_shipping_cost', '0');

$deploy_status = inspectDeployStatus();

$mp_token_raw = trim((string)(
    $config['mercadopago']['access_token']
    ?? $config['mp_access_token']
    ?? getenv('MP_ACCESS_TOKEN')
    ?: ''
));
$mp_token_configured = ($mp_token_raw !== '');
$mp_token_preview    = $mp_token_configured ? (substr($mp_token_raw, 0, 12) . '••••••••') : '';

$store_orders = [];
try {
    $chkSo = $pdo->query("SHOW TABLES LIKE 'store_orders'")->fetchColumn();
    if ($chkSo) {
        $store_orders = $pdo->query("SELECT * FROM store_orders ORDER BY created_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $store_orders = [];
}

$days_of_week = [
    'Lunes'     => 'L',
    'Martes'    => 'M',
    'Miércoles' => 'X',
    'Jueves'    => 'J',
    'Viernes'   => 'V',
    'Sábado'    => 'S',
    'Domingo'   => 'D',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Editor de Menú - Pizzería Arrabbiata</title>
    <link rel="icon" type="image/png" href="/favicon.png"/>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
    <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
    <style>
        #crop-modal {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.75); z-index: 2000;
            justify-content: center; align-items: center;
        }
        #crop-modal.open { display: flex; }
        #crop-modal-inner {
            background: #fff; border-radius: 12px; padding: 20px;
            max-width: 520px; width: 94%; display: flex; flex-direction: column; gap: 14px;
        }
        #crop-modal-inner h4 {
            margin: 0; font-family: 'Alberdini', 'Alberdi', serif;
            font-size: 1.4rem; color: var(--rojo, #CC1414);
        }
        #crop-container { width: 100%; max-height: 380px; overflow: hidden; background: #111; border-radius: 8px; }
        #crop-container img { display: block; max-width: 100%; }
        #crop-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
        #crop-modal-actions button {
            padding: 9px 22px; border-radius: 25px; border: none;
            cursor: pointer; font-weight: 600; font-size: 0.95rem;
        }
        #btn-crop-confirm { background: #CC1414; color: #fff; }
        #btn-crop-cancel  { background: #ddd; color: #333; }
        #btn-crop-confirm:hover { background: #D73828; }
        #btn-crop-cancel:hover  { background: #bbb; }
        .crop-preview-thumb {
            width: 60px; height: 60px; object-fit: cover; border-radius: 6px;
            border: 2px solid #cc0000; display: inline-block; vertical-align: middle; margin-left: 8px;
        }

        @font-face {
            font-family: 'Alberdini';
            src: url('rrbb.woff2') format('woff2'), url('rrbb.woff') format('woff');
            font-weight: normal; font-style: normal;
        }
        @font-face {
            font-family: 'Alberdi';
            src: url('dk.woff2') format('woff2'), url('dk.woff') format('woff');
            font-weight: normal; font-style: normal;
        }
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,500;0,9..40,700&display=swap');

        :root {
            --ocre: #D4B483; --ocre-d: #B8955A; --azul-elec: #1A5FBF;
            --azul-barra: #2F7BD6; --azul-barra-d: #1A5FAA;
            --rojo: #CC1414; --rojo-d: #A50E0E; --rojo-f: #D73828;
            --blanco: #F8F6F2; --crema: #F0E6D0; --marron: #2b1a0a;
            --amarillo: #FDB740; --verde-wa: #25D366; --verde-wa-d: #128C7E;
            --radius: 10px;
            --font-display: 'Alberdini', 'DM Serif Display', serif;
            --font-body: 'DM Sans', Arial, sans-serif;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; overflow-x: hidden; }
        body {
            overflow-x: hidden; background: var(--crema);
            font-family: var(--font-body); color: var(--marron);
            -webkit-font-smoothing: antialiased;
        }

        .nav-menu {
            position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
            z-index: 10000; background: var(--ocre); border: 3px solid var(--azul-elec);
            border-radius: 4px; padding: 8px 22px; box-shadow: 4px 4px 0 var(--azul-elec);
            display: inline-block; transition: all .2s ease;
        }
        .nav-menu ul { list-style: none; display: flex; gap: 4px; margin: 0; }
        .nav-menu a {
            text-decoration: none; color: var(--marron); font-family: var(--font-display);
            font-size: 21px; padding: 6px 14px; border-radius: 2px; letter-spacing: .03em; transition: all .2s ease;
        }
        .nav-menu a:hover { background: var(--rojo); color: #fff; transform: translateY(-1px); }

        .section {
            width: 100%; padding: 110px 20px 40px;
            background: repeating-linear-gradient(45deg, var(--ocre) 0 22px, var(--ocre-d) 22px 44px);
            min-height: 100vh;
        }
        .editor-container {
            max-width: 880px; margin: 0 auto; background: var(--blanco);
            border: 3px solid var(--azul-elec); border-radius: var(--radius);
            padding: 34px 30px; box-shadow: 6px 6px 0 var(--azul-elec);
        }
        .editor-container h2 {
            text-align: center; font-size: 2.6rem; margin-bottom: 28px;
            color: var(--rojo); font-family: var(--font-display); font-weight: normal;
            letter-spacing: .02em; text-shadow: 2px 2px 0 var(--ocre);
        }

        details {
            margin-bottom: 20px; background: var(--crema);
            border: 2px solid var(--azul-elec); border-radius: var(--radius);
            box-shadow: 3px 3px 0 var(--azul-barra); overflow: hidden;
        }
        details summary {
            padding: 15px 18px; font-size: 1.35rem; color: var(--marron);
            font-family: var(--font-display); cursor: pointer;
            border-bottom: 2px solid var(--azul-elec); text-transform: uppercase;
            letter-spacing: 1px; list-style: none;
        }
        details summary::-webkit-details-marker { display: none; }
        details summary:hover, details[open] summary { background-color: var(--rojo); color: #fff; }

        .editor-form { padding: 20px; display: flex; flex-direction: column; gap: 10px; }
        .editor-form input:not([type="url"]):not([type="file"]):not([type="checkbox"]),
        .editor-form select,
        .modal-form input:not([type="url"]):not([type="file"]):not([type="checkbox"]),
        .modal-form select {
            width: 100%; max-width: 340px; padding: 10px 14px; margin: 6px 0;
            border: 2px solid var(--ocre-d); border-radius: 6px; font-size: 15px;
            font-family: var(--font-body); color: var(--marron); background: #fff;
        }
        .editor-form textarea, .editor-form input[type="url"],
        .modal-form textarea, .modal-form input[type="url"] {
            width: 100%; max-width: 560px; padding: 10px 14px; margin: 6px 0;
            border: 2px solid var(--ocre-d); border-radius: 6px; font-size: 15px;
            font-family: var(--font-body); color: var(--marron); background: #fff;
        }
        .editor-form input:focus, .editor-form select:focus, .editor-form textarea:focus,
        .modal-form input:focus, .modal-form select:focus, .modal-form textarea:focus {
            border-color: var(--azul-elec); outline: none; box-shadow: 0 0 0 3px rgba(26, 95, 191, 0.18);
        }
        .editor-form label, .modal-form label {
            font-size: 0.95rem; color: var(--marron); font-weight: 700; margin: 6px 0 2px; display: inline-block;
        }
        .editor-form p.note, .modal-form p.note { font-size: 0.88rem; color: #6b5a45; margin: 4px 0; line-height: 1.4; }

        .toggle-group { display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap; margin: 10px 0; }
        .toggle-inputs { display: flex; flex-direction: column; gap: 6px; }
        .days-selector, .toggle-selector { display: flex; gap: 10px; flex-wrap: wrap; }
        .day-toggle, .option-toggle {
            width: 40px; height: 40px; border-radius: 50%;
            background-color: var(--crema); color: var(--marron);
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; font-weight: 700; cursor: pointer; user-select: none;
            transition: all 0.18s ease; border: 2px solid var(--ocre-d);
        }
        .day-toggle.active, .option-toggle.active { background-color: var(--rojo); color: #fff; border-color: var(--rojo-d); }
        .day-toggle:hover, .option-toggle:hover { background-color: var(--rojo-f); color: #fff; border-color: var(--rojo-d); }

        .editor-form button, .modal-form button {
            background: var(--rojo); color: #fff; border: 2px solid var(--rojo-d);
            padding: 10px 22px; border-radius: 6px; cursor: pointer;
            font-size: 15px; font-weight: 700; font-family: var(--font-body);
            align-self: flex-start; box-shadow: 3px 3px 0 var(--rojo-d); transition: all 0.15s ease;
        }
        .editor-form button:hover, .modal-form button:hover {
            background: var(--rojo-f); transform: translate(-1px, -1px); box-shadow: 4px 4px 0 var(--rojo-d);
        }

        .item-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 16px; padding: 20px;
        }
        .item-card {
            background: #fff; border: 2px solid var(--azul-elec); border-radius: var(--radius);
            overflow: hidden; box-shadow: 3px 3px 0 var(--azul-barra);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            cursor: pointer; position: relative; display: flex; flex-direction: column;
        }
        .item-card.is-hidden-item { opacity: 0.55; }
        .item-card:hover { transform: translate(-2px, -2px); box-shadow: 5px 5px 0 var(--azul-barra); }
        .item-card img { width: 100%; height: 105px; object-fit: cover; }
        .item-card h3 { font-size: 1rem; color: var(--marron); margin: 8px 8px 4px; font-family: var(--font-display); font-weight: normal; text-align: center; }
        .item-card-badges { display: flex; flex-wrap: wrap; gap: 4px; justify-content: center; padding: 0 6px 8px; }
        .mini-badge { font-size: 0.68rem; font-weight: 700; padding: 2px 6px; border-radius: 99px; }
        .mini-badge.dest { background: #fee2e2; color: #b91c1c; }
        .mini-badge.sec  { background: #ede9fe; color: #6d28d9; }
        .mini-badge.veg  { background: #dcfce7; color: #15803d; }
        .mini-badge.hid  { background: #e5e7eb; color: #374151; }
        .mini-badge.cat  { background: #f7efdf; color: #6b5a45; }

        .modal {
            display: none; position: fixed; inset: 0;
            background: rgba(43, 26, 10, 0.65); z-index: 1000;
            justify-content: center; align-items: center; overflow-y: auto;
        }
        .modal-content {
            background: var(--blanco); border: 3px solid var(--azul-elec);
            border-radius: var(--radius); padding: 24px; max-width: 640px; width: 92%;
            position: relative; box-shadow: 6px 6px 0 var(--azul-elec);
            max-height: 86vh; overflow-y: auto;
        }
        .modal-close {
            position: absolute; top: 10px; right: 14px; font-size: 1.7rem;
            cursor: pointer; color: var(--marron); line-height: 1;
        }
        .modal-close:hover { color: var(--rojo); }
        .modal-content img.current-item-img {
            max-width: 100%; max-height: 180px; object-fit: contain;
            border-radius: 8px; margin-bottom: 12px; display: block;
        }

        .category-list { display: grid; grid-template-columns: 1fr; gap: 16px; padding: 20px; }
        .error {
            color: #fff; background: var(--rojo); border: 2px solid var(--rojo-d);
            border-radius: 6px; font-size: 0.96rem; margin: 10px 0; padding: 10px 14px;
            text-align: center; font-weight: 600;
        }
        .success {
            color: #fff; background: var(--verde-wa-d); border: 2px solid #0e6e5f;
            border-radius: 6px; font-size: 0.96rem; margin: 10px 0; padding: 10px 14px;
            text-align: center; font-weight: 600;
        }

        .hours-table-container { overflow-x: auto; padding: 0 20px 20px; }
        .hours-table {
            width: 100%; border-collapse: collapse; margin: 12px 0;
            background: #fff; border: 2px solid var(--azul-elec); border-radius: var(--radius); overflow: hidden;
        }
        .hours-table th, .hours-table td {
            border: 1px solid var(--ocre-d); padding: 8px; text-align: left; font-size: 13px; vertical-align: middle;
        }
        .hours-table th { background-color: var(--azul-elec); color: #fff; font-family: var(--font-display); font-weight: normal; letter-spacing: .03em; }
        .hours-table td form { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .hours-table select, .hours-table input[type="time"] {
            padding: 6px 8px; border-radius: 5px; border: 1px solid var(--ocre-d); font-size: 13px; max-width: 125px;
        }
        .hours-table button { padding: 6px 10px; font-size: 12px; }
        .midnight-badge {
            display: inline-block; background: #dbeafe; color: #1e40af;
            border: 1px solid #93c5fd; border-radius: 99px; font-size: 0.72rem;
            font-weight: 700; padding: 2px 8px; margin-top: 3px;
        }

        .weekly-special-text {
            width: 100%; max-width: 420px; padding: 10px 14px; margin: 4px 0 8px;
            border: 2px solid var(--ocre-d); border-radius: 6px; font-size: 15px; background: #fff;
        }
        .btn-add-schedule {
            background: var(--crema) !important; color: var(--azul-elec) !important;
            border: 1.5px dashed var(--azul-elec) !important; box-shadow: none !important;
            padding: 7px 16px !important; font-size: 0.88rem !important; margin-top: 4px;
        }
        .btn-add-schedule:hover { background: var(--azul-elec) !important; color: #fff !important; border-style: solid !important; }

        .deploy-box {
            padding: 18px 20px; background: #eef6ff;
        }
        .deploy-box.has-changes { background: #fff8eb; }
        .deploy-summary-badge {
            display: inline-block; margin-left: 10px; padding: 2px 10px;
            border-radius: 99px; font-family: var(--font-body); font-size: 0.78rem;
            font-weight: 700; text-transform: none; letter-spacing: 0; vertical-align: middle;
            background: #fef3c7; color: #92400e; border: 1.5px solid #f59e0b;
        }
        .deploy-summary-badge.ok {
            background: #dcfce7; color: #166534; border-color: #86efac;
        }
        .deploy-meta { font-size: 0.85rem; color: #475569; margin-bottom: 10px; line-height: 1.45; }
        .deploy-pill-list { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 12px; }
        .deploy-pill {
            font-size: 0.78rem; font-weight: 700; padding: 3px 9px; border-radius: 99px;
            background: #fff; border: 1px solid #cbd5e1; color: #1e293b; font-family: monospace;
        }
        .deploy-pill.nuevo { background: #dcfce7; border-color: #86efac; color: #166534; }
        .deploy-pill.obs { background: #fee2e2; border-color: #fca5a5; color: #991b1b; }
        .btn-deploy-now {
            background: #16a34a !important; color: #fff !important; border: 2px solid #15803d !important;
            box-shadow: 3px 3px 0 #166534 !important; padding: 11px 22px !important;
            font-size: 1rem !important; font-weight: 700 !important; border-radius: 8px !important; cursor: pointer;
        }
        .btn-deploy-now:hover { background: #15803d !important; transform: translate(-1px,-1px); }

        .bulk-cat-header {
            font-weight: 700; margin: 14px 0 4px; font-size: 0.98rem; color: var(--marron);
            border-bottom: 1.5px solid #d6cbb8; padding-bottom: 6px;
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        }
        .bulk-cat-title {
            cursor: pointer; padding: 2px 7px; border-radius: 5px;
            transition: background 0.15s ease; user-select: none;
        }
        .bulk-cat-title:hover {
            background: #e0ecff; color: var(--azul-elec);
        }
        .cat-inline-edit-input {
            padding: 4px 8px !important; margin: 0 !important; width: 220px !important;
            max-width: 260px !important; font-size: 0.95rem !important; font-weight: 700 !important;
            border: 2px solid var(--azul-elec) !important; border-radius: 5px !important;
        }
        .bulk-cat-del-btn {
            background: #fff !important; color: #cc0000 !important;
            border: 1.5px solid #fca5a5 !important; border-radius: 20px !important;
            padding: 2px 10px !important; font-size: 0.82rem !important; font-weight: 700 !important;
            cursor: pointer !important; box-shadow: none !important; line-height: 1.3 !important;
        }
        .bulk-cat-del-btn:hover {
            background: #cc0000 !important; color: #fff !important; border-color: #991b1b !important;
        }
        .empty-cat-note {
            font-size: 0.84rem; color: #7c6f5b; font-style: italic;
            padding: 8px 12px; background: #faf6ee; border: 1px dashed #d6cbb8;
            border-radius: 6px; margin-bottom: 8px;
        }
        .sub-divider {
            border: none; border-top: 2px dashed var(--ocre-d); margin: 18px 0 12px;
        }

        .status-badge {
            display: inline-block; padding: 3px 10px; border-radius: 99px;
            font-size: 0.76rem; font-weight: 700; text-transform: uppercase;
        }
        .status-badge.approved { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .status-badge.pending  { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .status-badge.demo     { background: #e0e7ff; color: #3730a3; border: 1px solid #a5b4fc; }
        .status-badge.rejected { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        @media (max-width: 768px) {
            .section { padding: 90px 12px 24px; }
            .editor-container { padding: 18px 14px; }
            .nav-menu a { font-size: 15px; padding: 5px 10px; }
            .editor-form input, .editor-form select, .editor-form textarea,
            .modal-form input, .modal-form select, .modal-form textarea { max-width: 100% !important; }
        }
    </style>
</head>
<body>
    <nav class="nav-menu">
        <ul>
            <li><a href="index.php#home">Home</a></li>
            <li><a href="carta/" target="_blank">Ver Carta</a></li>
            <li><a href="panel.php">Estadísticas</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </nav>

    <section class="section">
        <div class="editor-container">
            <h2>Editor de Menú</h2>
            <?php foreach ($errors as $error): ?>
                <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endforeach; ?>
            <?php foreach ($success as $msg): ?>
                <p class="success"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endforeach; ?>

            <!-- 1. Horarios de Atención (un solo paso con cruce de medianoche) -->
            <details>
                <summary>Administrar Horarios de Atención</summary>
                <div class="editor-form">
                    <h3>Agregar Nuevo Horario</h3>
                    <p class="note">
                        Podés cargar horarios nocturnos en <strong>una sola fila</strong>: por ejemplo, si ponés
                        <strong>Viernes de 20:30 a 00:30</strong>, el sistema entiende automáticamente que sigue abierto hasta las 00:30 de la madrugada del Sábado (sin tener que partirlo en 23:59 + 00:00).
                    </p>
                    <form method="POST" onsubmit="return validateBusinessHoursForm(this)" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="add_business_hours">
                        <select name="day_of_week" required style="max-width:170px;">
                            <option value="">Día de apertura</option>
                            <?php foreach ($days_of_week as $day => $abbr): ?>
                                <option value="<?php echo htmlspecialchars($day, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($day, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label>Desde:</label>
                        <input type="time" name="start_time" required style="max-width:130px;">
                        <label>Hasta:</label>
                        <input type="time" name="end_time" required style="max-width:130px;">
                        <button type="submit">Agregar Horario</button>
                    </form>
                </div>
                <div class="hours-table-container">
                    <h3>Horarios Existentes</h3>
                    <table class="hours-table">
                        <thead>
                            <tr>
                                <th>Día</th>
                                <th>Franja actual</th>
                                <th>Editar / Eliminar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($business_hours as $hours):
                                $sFmt = substr((string)$hours['start_time'], 0, 5);
                                $eFmt = substr((string)$hours['end_time'], 0, 5);
                                $crossesMidnight = ($hours['end_time'] <= $hours['start_time']);
                                $nextDayLabel = $crossesMidnight ? nextDayOfWeek((string)$hours['day_of_week']) : '';
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars((string)$hours['day_of_week'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($sFmt . ' — ' . $eFmt, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <?php if ($crossesMidnight): ?>
                                            <span class="midnight-badge">🌙 Termina <?php echo htmlspecialchars($nextDayLabel . ' ' . $eFmt, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" class="hours-form" onsubmit="return validateBusinessHoursForm(this)">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="update_business_hours">
                                            <input type="hidden" name="id" value="<?php echo (int)$hours['id']; ?>">
                                            <select name="day_of_week" required>
                                                <?php foreach ($days_of_week as $day => $abbr): ?>
                                                    <option value="<?php echo htmlspecialchars($day, ENT_QUOTES, 'UTF-8'); ?>" <?php if ($hours['day_of_week'] === $day) echo 'selected'; ?>>
                                                        <?php echo htmlspecialchars($day, ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="time" name="start_time" value="<?php echo htmlspecialchars($sFmt, ENT_QUOTES, 'UTF-8'); ?>" required>
                                            <input type="time" name="end_time" value="<?php echo htmlspecialchars($eFmt, ENT_QUOTES, 'UTF-8'); ?>" required>
                                            <button type="submit">Guardar</button>
                                            <button type="submit" name="action" value="delete_business_hours" onclick="return confirm('¿Eliminar este horario?');" style="background:#6b7280;border-color:#4b5563;box-shadow:2px 2px 0 #4b5563;">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>

            <!-- 2. Categorías y Actualización Masiva (Unificado) -->
            <details>
                <summary>Categorías y Actualización Masiva</summary>
                <div class="editor-form">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                        <h3 style="margin:0;">Categorías, orden, visibilidad y precios</h3>
                        <button type="button" id="btn-toggle-add-cat" style="margin:0;padding:8px 18px;font-size:0.9rem;">
                            + Agregar Categoría
                        </button>
                    </div>

                    <div id="add-cat-inline-bar" style="display:none;background:#fff;border:2px solid var(--azul-elec);border-radius:8px;padding:12px 14px;margin-top:6px;">
                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                            <input type="text" id="new-cat-name-input" placeholder="Nombre de la nueva categoría (ej: Postres, Tienda...)" maxlength="120" style="flex:1;min-width:200px;max-width:none;margin:0;">
                            <button type="button" id="btn-save-new-cat" style="margin:0;">Crear Categoría</button>
                            <button type="button" id="btn-cancel-new-cat" style="margin:0;background:#6b7280;border-color:#4b5563;box-shadow:2px 2px 0 #4b5563;">Cancelar</button>
                        </div>
                    </div>

                    <p class="note" style="margin:6px 0 10px;">
                        <strong>⠿</strong> = arrastrar categorías o productos &nbsp;|&nbsp;
                        <strong>Doble clic en categoría</strong> = renombrar &nbsp;|&nbsp;
                        <strong>👁 / 🚫</strong> = mostrar / ocultar al instante &nbsp;|&nbsp;
                        <strong>✕</strong> = eliminar
                    </p>

                    <form method="POST" id="bulk-price-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="bulk_update_prices">
                        <div style="overflow-x:auto;">
                        <div id="bulk-cat-sortable">
                        <?php
                        $items_by_cat = [];
                        foreach ($menu_items as $it) {
                            $items_by_cat[$it['category_id']][] = $it;
                        }
                        foreach ($categories as $cat):
                            $cid         = (int)$cat['id'];
                            $catItems    = $items_by_cat[$cid] ?? [];
                            $hasCatItems = !empty($catItems);
                        ?>
                        <div class="bulk-cat-block" data-cat-id="<?php echo $cid; ?>" style="margin-bottom:6px;">
                            <div class="bulk-cat-header">
                                <span class="bulk-cat-handle" title="Arrastrá para reordenar categorías"
                                    style="cursor:grab;color:#888;font-size:1.15rem;user-select:none;flex-shrink:0;">⠿</span>
                                <span class="bulk-cat-title" data-cat-id="<?php echo $cid; ?>" title="Doble clic para cambiar el nombre de la categoría"><?php echo htmlspecialchars((string)$cat['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="bulk-cat-status" style="font-size:0.78rem;color:#16a34a;font-weight:700;"></span>
                                <span style="margin-left:auto;display:flex;align-items:center;gap:6px;">
                                    <?php if ($hasCatItems): ?>
                                        <button type="button" class="bulk-raise-btn" data-category="<?php echo $cid; ?>" data-amount="-1000"
                                            style="background:#e65c00;color:white;border:none;border-radius:20px;padding:3px 12px;font-size:0.8rem;cursor:pointer;font-weight:600;box-shadow:none;">
                                            -$1000
                                        </button>
                                        <button type="button" class="bulk-raise-btn" data-category="<?php echo $cid; ?>" data-amount="1000"
                                            style="background:#007bff;color:white;border:none;border-radius:20px;padding:3px 12px;font-size:0.8rem;cursor:pointer;font-weight:600;box-shadow:none;">
                                            +$1000
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="bulk-cat-del-btn"
                                        data-cat-id="<?php echo $cid; ?>"
                                        data-cat-name="<?php echo htmlspecialchars((string)$cat['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        title="Eliminar categoría">✕</button>
                                </span>
                            </div>
                            <?php if ($hasCatItems): ?>
                            <table class="hours-table" style="width:100%;min-width:440px;margin-bottom:8px;">
                                <thead>
                                    <tr>
                                        <th style="width:28px;"></th>
                                        <th style="text-align:left;">Producto</th>
                                        <th style="width:130px;">Precio ($)</th>
                                        <th style="width:42px;text-align:center;">👁</th>
                                        <th style="width:42px;text-align:center;">✕</th>
                                    </tr>
                                </thead>
                                <tbody class="bulk-sortable" data-category="<?php echo $cid; ?>">
                                    <?php foreach ($catItems as $item): ?>
                                    <tr data-id="<?php echo (int)$item['id']; ?>" style="<?php echo !$item['is_visible'] ? 'opacity:0.5;' : ''; ?>">
                                        <td style="cursor:grab;color:#aaa;font-size:1.1rem;text-align:center;user-select:none;" class="bulk-drag-handle" title="Arrastrá para reordenar">⠿</td>
                                        <td>
                                            <?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?>
                                            <?php if (!empty($item['is_weekly_special'])): ?><span class="mini-badge dest">⭐ Destacado</span><?php endif; ?>
                                            <?php if (!empty($item['is_secret_menu'])): ?><span class="mini-badge sec">🕵️ Secreta</span><?php endif; ?>
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0.01" required
                                                name="prices[<?php echo (int)$item['id']; ?>]"
                                                value="<?php echo htmlspecialchars((string)$item['price'], ENT_QUOTES, 'UTF-8'); ?>"
                                                style="width:115px;max-width:115px;padding:6px;border:1px solid #ccc;border-radius:5px;text-align:right;">
                                        </td>
                                        <td style="text-align:center;">
                                            <button type="button"
                                                class="bulk-vis-btn"
                                                data-id="<?php echo (int)$item['id']; ?>"
                                                data-csrf="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>"
                                                title="<?php echo $item['is_visible'] ? 'Visible — clic para ocultar' : 'Oculto — clic para mostrar'; ?>"
                                                style="background:none;border:none;box-shadow:none;cursor:pointer;font-size:1.2rem;padding:2px 6px;">
                                                <?php echo $item['is_visible'] ? '👁' : '🚫'; ?>
                                            </button>
                                        </td>
                                        <td style="text-align:center;">
                                            <button type="button"
                                                data-name="<?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                class="bulk-delete-btn"
                                                style="background:none;border:none;box-shadow:none;cursor:pointer;font-size:1.2rem;color:#cc0000;padding:2px 6px;" title="Eliminar producto">✕</button>
                                            <input type="checkbox" class="bulk-delete-input" name="delete_ids[]" value="<?php echo (int)$item['id']; ?>" style="display:none;">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php else: ?>
                            <div class="empty-cat-note">Categoría sin productos todavía — podés cargarle ítems desde «Agregar Nuevo Producto».</div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        </div>
                        </div>
                        <button type="submit" style="margin-top:14px;">Guardar todos los precios</button>
                    </form>
                </div>
            </details>

            <!-- 3. Agregar Nuevo Producto -->
            <details>
                <summary>Agregar Nuevo Producto</summary>
                <div class="editor-form">
                    <form method="POST" enctype="multipart/form-data" onsubmit="return validateItemForm(this)">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="add_item">
                        <label>Nombre del producto:</label>
                        <input type="text" name="name" placeholder="Ej: Pizza Margherita / L'Arrabbiata" required>
                        <label>Precio base ($):</label>
                        <input type="number" step="0.01" name="price" placeholder="Precio" min="0.01" required>
                        <label>Categoría:</label>
                        <select name="category_id" id="add-item-cat-select" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo (int)$category['id']; ?>"><?php echo htmlspecialchars((string)$category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label>Descripción / Ingredientes:</label>
                        <textarea name="description" rows="3" placeholder="Descripción de ingredientes"></textarea>

                        <label>Imagen (Subir archivo y recortar 1:1):</label>
                        <input type="file" name="image" accept="image/*" class="crop-file-input">
                        <input type="hidden" name="cropped_image_data" class="cropped-data-field">
                        <div class="crop-preview-wrap" style="margin:4px 0 8px;min-height:0;"></div>

                        <label>O URL externa de imagen (opcional):</label>
                        <input type="url" name="image_url" placeholder="https://...">

                        <div class="toggle-group">
                            <div class="toggle-inputs">
                                <label>Visible en la Web:</label>
                                <div class="toggle-selector">
                                    <div class="option-toggle active" data-option="is_visible" tabindex="0" role="button" aria-label="Visible en la web">V</div>
                                </div>
                                <input type="hidden" name="is_visible" value="1">
                            </div>
                            <div class="toggle-inputs">
                                <label>Opción Vegana:</label>
                                <div class="toggle-selector">
                                    <div class="option-toggle" data-option="has_vegan_option" tabindex="0" role="button" aria-label="Opción vegana">VG</div>
                                </div>
                                <input type="hidden" name="has_vegan_option" value="1" disabled>
                            </div>
                            <div class="toggle-inputs">
                                <label title="Muestra una cinta roja destacada arriba de la foto">Destacado (cinta):</label>
                                <div class="toggle-selector">
                                    <div class="option-toggle" data-option="is_weekly_special" tabindex="0" role="button" aria-label="Destacado">D</div>
                                </div>
                                <input type="hidden" name="is_weekly_special" value="1" disabled>
                            </div>
                            <div class="toggle-inputs">
                                <label title="Oculta el producto hasta que el cliente descubre y toca el personaje secreto">Carta Secreta:</label>
                                <div class="toggle-selector">
                                    <div class="option-toggle" data-option="is_secret_menu" tabindex="0" role="button" aria-label="Carta Secreta">CS</div>
                                </div>
                                <input type="hidden" name="is_secret_menu" value="1" disabled>
                            </div>
                        </div>

                        <div class="weekly-special-text-wrap" style="display: none;">
                            <label>Texto de la cinta destacada:</label>
                            <input type="text" name="weekly_special_text" class="weekly-special-text" placeholder="Ej: ¡Pizza de la semana!" value="¡Pizza de la semana!">
                        </div>

                        <div class="schedules-section" style="margin-top:8px;">
                            <label>Franjas de disponibilidad (dejá todos los días sin horas para mostrar siempre):</label>
                            <div class="schedules-container" id="schedules-add"></div>
                            <button type="button" class="btn-add-schedule" onclick="addScheduleRow(document.getElementById('schedules-add'), null, null, [], '', '')">
                                + Agregar otra franja horaria
                            </button>
                        </div>

                        <div class="variants-inputs" style="margin:12px 0;padding:12px;border:1px dashed #aaa;border-radius:8px;background:#fff;">
                            <label style="margin-top:0;">Variantes con precio propio (opcional):</label>
                            <p class="note">Ej: Fugazzeta → Común / Queso Azul (hasta 3 se ven como botones en la tarjeta; 4 o más abren selector). Funcionan también para pedidos programados fuera de horario.</p>
                            <div id="variants-add"></div>
                            <button type="button" class="btn-add-schedule" onclick="addVariantRow('variants-add')">+ Agregar variante</button>
                        </div>

                        <label>Orden de visualización:</label>
                        <input type="number" name="display_order" placeholder="Orden" value="0" required style="max-width:140px;">
                        <div style="margin-top:10px;"><button type="submit">Agregar Producto</button></div>
                    </form>
                </div>
            </details>

            <!-- 4. Productos Existentes (Clic para editar / Arrastrar para ordenar) -->
            <details>
                <summary>Productos Existentes</summary>
                <p class="note" style="padding:14px 20px 0;">Tocá cualquier tarjeta para editar el producto o arrastrala para cambiar su orden.</p>
                <div class="item-grid" id="item-grid">
                    <?php foreach ($menu_items_by_category as $category_id => $items): ?>
                        <?php foreach ($items as $item): ?>
                            <div class="item-card <?php echo !$item['is_visible'] ? 'is-hidden-item' : ''; ?>"
                                 data-id="<?php echo (int)$item['id']; ?>"
                                 data-cat-id="<?php echo (int)$item['category_id']; ?>">
                                <img src="<?php echo htmlspecialchars($item['image_url'] ?: 'https://via.placeholder.com/150?text=Sin+Imagen', ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
                                <h3><?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                <div class="item-card-badges">
                                    <span class="mini-badge cat"><?php echo htmlspecialchars((string)$item['category_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if (!$item['is_visible']): ?><span class="mini-badge hid">🚫 Oculto</span><?php endif; ?>
                                    <?php if (!empty($item['is_weekly_special'])): ?><span class="mini-badge dest">⭐ <?php echo htmlspecialchars((string)($item['weekly_special_text'] ?: 'Destacado'), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                    <?php if (!empty($item['is_secret_menu'])): ?><span class="mini-badge sec">🕵️ Secreta</span><?php endif; ?>
                                    <?php if (!empty($item['has_vegan_option'])): ?><span class="mini-badge veg">🌿 Vegana</span><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </details>

            <!-- 5. Datos de Transferencia y Tienda Online (Unificado) -->
            <details>
                <summary>Datos de Transferencia y Tienda Online</summary>
                <div class="editor-form">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_payments_and_store">

                        <h3>Datos de Transferencia (Carta / WhatsApp)</h3>
                        <p class="note">Se muestran en el carrito y en el mensaje de WhatsApp cuando el cliente elige pagar por transferencia.</p>
                        <label>Alias CBU/CVU:</label><br>
                        <input type="text" name="transfer_alias" value="<?php echo htmlspecialchars($transfer_alias, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Alias" maxlength="190" required><br>
                        <label>Titular de la cuenta:</label><br>
                        <input type="text" name="transfer_holder" value="<?php echo htmlspecialchars($transfer_holder, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Titular de la cuenta" maxlength="190" required>

                        <hr class="sub-divider">

                        <h3>Tienda Online y Pasarela Mercado Pago</h3>
                        <?php if ($mp_token_configured): ?>
                            <p style="background:#dcfce7;border:1.5px solid #86efac;color:#166534;padding:10px 14px;border-radius:8px;font-weight:700;margin:8px 0;">
                                🟢 Cobro Real Activo — Access Token detectado en <code>config.php</code> (<code><?php echo htmlspecialchars($mp_token_preview, ENT_QUOTES, 'UTF-8'); ?></code>).
                            </p>
                        <?php else: ?>
                            <div style="background:#fef3c7;border:1.5px solid #fcd34d;color:#92400e;padding:12px 14px;border-radius:8px;font-size:0.9rem;line-height:1.5;margin:8px 0;">
                                <strong>🟡 Modo Demo (Sin cobro real todavía):</strong> Falta agregar tu <strong>Access Token de Producción</strong> de Mercado Pago dentro de <code>/home/c2652217/config.php</code>:<br>
                                <code style="display:inline-block;margin-top:6px;background:#fff;padding:4px 8px;border-radius:4px;color:#1e293b;">'mp_access_token' =&gt; 'APP_USR-tu-access-token-de-produccion',</code>
                            </div>
                        <?php endif; ?>

                        <label style="margin-top:10px;">Costo fijo de envío a domicilio en Tienda (ARS):</label>
                        <p class="note">Si ponés <strong>0</strong>, el carrito avisa que el envío se coordina por WhatsApp. Si ponés un monto mayor a 0 (ej. <code>4500</code>), se suma automáticamente al cobro de Mercado Pago.</p>
                        <input type="number" step="0.01" min="0" name="store_shipping_cost" value="<?php echo htmlspecialchars($store_shipping_cost, ENT_QUOTES, 'UTF-8'); ?>" placeholder="0" required>

                        <p class="note" style="margin-top:10px;">
                            <strong>¿Cómo mostrar productos en la Tienda?</strong> Creá una categoría cuyo nombre contenga la palabra <strong>Tienda</strong> (ej. <em>Tienda</em> o <em>Tienda Merch</em>) y cargale productos. Aparecerá sola en el Home y en <a href="tienda.php" target="_blank" style="color:var(--azul-elec);text-decoration:underline;">tienda.php</a>.
                        </p>

                        <div style="margin-top:12px;"><button type="submit">Guardar Transferencia y Tienda</button></div>
                    </form>
                </div>

                <?php if (!empty($store_orders)): ?>
                <div class="hours-table-container">
                    <h3>Últimas Compras en Tienda</h3>
                    <table class="hours-table">
                        <thead>
                            <tr>
                                <th>Fecha / Ref</th>
                                <th>Cliente</th>
                                <th>Entrega</th>
                                <th>Detalle</th>
                                <th>Total / Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($store_orders as $so):
                                $soItems = json_decode((string)$so['items_json'], true) ?: [];
                                $stClass = in_array($so['status'], ['approved', 'pending', 'demo'], true) ? $so['status'] : 'rejected';
                                $stLabels = ['approved' => 'Pagado', 'pending' => 'Pendiente', 'demo' => 'Demo', 'rejected' => 'Rechazado', 'cancelled' => 'Cancelado'];
                                $stLabel = $stLabels[$so['status']] ?? $so['status'];
                                $waDigits = preg_replace('/\D+/', '', (string)$so['customer_phone']);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars((string)$so['reference'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                    <span style="font-size:11px;color:#666;"><?php echo htmlspecialchars((string)$so['created_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars((string)$so['customer_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                    <span style="font-size:12px;"><?php echo htmlspecialchars((string)$so['customer_email'], ENT_QUOTES, 'UTF-8'); ?></span><br>
                                    <?php if ($waDigits !== ''): ?>
                                        <a href="https://wa.me/<?php echo htmlspecialchars($waDigits, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" style="color:#15803d;font-weight:700;font-size:12px;">📱 <?php echo htmlspecialchars((string)$so['customer_phone'], ENT_QUOTES, 'UTF-8'); ?></a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars((string)$so['customer_phone'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($so['delivery_type'] === 'envio'): ?>
                                        <strong>🛵 Envío</strong><br>
                                        <span style="font-size:12px;"><?php echo htmlspecialchars((string)$so['address'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php else: ?>
                                        <strong>🏪 Retiro en local</strong>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px;">
                                    <?php foreach ($soItems as $sit): ?>
                                        <div>• <?php echo htmlspecialchars((string)($sit['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> x<?php echo (int)($sit['qty'] ?? 1); ?></div>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <strong>$<?php echo number_format((float)$so['total'], 0, ',', '.'); ?></strong><br>
                                    <span class="status-badge <?php echo htmlspecialchars($stClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </details>

            <!-- 6. Pixel de Meta y Google Analytics / Tag Manager -->
            <details>
                <summary>Pixel de Meta y Google Analytics</summary>
                <div class="editor-form">
                    <h3>Trackers activos en Home, Carta y Tienda</h3>
                    <p class="note">
                        Estos IDs se inyectan automáticamente en <strong>arrabbiata.com.ar</strong>, <strong>carta.arrabbiata.com.ar</strong> y <strong>tienda.php</strong>, disparando eventos estándar de comercio electrónico:
                        <code>PageView</code>, <code>AddToCart</code>, <code>InitiateCheckout</code>, <code>Purchase</code> (con código único y monto en ARS) y <code>Contact</code>.
                    </p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_tracking_settings">
                        <label>Google Tag Manager ID (Contenedor):</label>
                        <input type="text" name="gtm_id" value="<?php echo htmlspecialchars($gtm_id, ENT_QUOTES, 'UTF-8'); ?>" placeholder="GTM-5JWLLKC7" maxlength="32">
                        <label>Meta Pixel ID (Facebook / Instagram Ads):</label>
                        <input type="text" name="meta_pixel_id" value="<?php echo htmlspecialchars($meta_pixel_id, ENT_QUOTES, 'UTF-8'); ?>" placeholder="313442609878673" maxlength="32">
                        <label>Google Analytics 4 ID directo (opcional si ya lo cargás dentro de GTM):</label>
                        <input type="text" name="ga4_id" value="<?php echo htmlspecialchars($ga4_id, ENT_QUOTES, 'UTF-8'); ?>" placeholder="G-XXXXXXXXXX (opcional)" maxlength="32">
                        <div style="margin-top:8px;"><button type="submit">Guardar Trackers</button></div>
                    </form>
                </div>
            </details>

            <!-- 7. Publicar desde GitHub a Producción (Colapsable, al final) -->
            <?php if ($deploy_status['available']):
                $pendingCount = count($deploy_status['pending']);
                $obsCount     = count($deploy_status['obsolete']);
                $hasWork      = ($pendingCount > 0 || $obsCount > 0);
            ?>
            <details>
                <summary>
                    🚀 Publicar desde GitHub a Producción (Sin FTP)
                    <?php if ($pendingCount > 0): ?>
                        <span class="deploy-summary-badge"><?php echo $pendingCount; ?> cambio(s)</span>
                    <?php else: ?>
                        <span class="deploy-summary-badge ok">Al día</span>
                    <?php endif; ?>
                </summary>
                <div class="deploy-box <?php echo $hasWork ? 'has-changes' : ''; ?>">
                    <div class="deploy-meta">
                        <?php if ($deploy_status['in_github']): ?>
                            <strong>Estás en el editor de pruebas (<code>/github/editor.php</code>).</strong> Al publicar, también se actualizará <code>arrabbiata.com.ar/editor.php</code>.<br>
                        <?php endif; ?>
                        Origen: <code><?php echo htmlspecialchars($deploy_status['src'], ENT_QUOTES, 'UTF-8'); ?></code> &rarr;
                        Destino: <code><?php echo htmlspecialchars($deploy_status['dst'], ENT_QUOTES, 'UTF-8'); ?></code>
                        <?php if (!empty($deploy_status['git']['hash'])): ?>
                            <br>Última versión en <code>/github</code>: <strong><code><?php echo htmlspecialchars($deploy_status['git']['hash'], ENT_QUOTES, 'UTF-8'); ?></code></strong>
                            <?php if (!empty($deploy_status['git']['date'])): ?>(<?php echo htmlspecialchars($deploy_status['git']['date'], ENT_QUOTES, 'UTF-8'); ?>)<?php endif; ?>
                            <?php if (!empty($deploy_status['git']['msg'])): ?> — <em><?php echo htmlspecialchars($deploy_status['git']['msg'], ENT_QUOTES, 'UTF-8'); ?></em><?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($pendingCount > 0): ?>
                        <p style="font-size:0.9rem;font-weight:700;color:#92400e;">
                            Hay <?php echo $pendingCount; ?> archivo(s) con cambios listos para pasar a producción:
                        </p>
                        <div class="deploy-pill-list">
                            <?php foreach ($deploy_status['pending'] as $pf): ?>
                                <span class="deploy-pill <?php echo htmlspecialchars($pf['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo ($pf['status'] === 'nuevo' ? '+ ' : '↻ ') . htmlspecialchars($pf['file'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="font-size:0.92rem;font-weight:700;color:#166534;margin-bottom:8px;">
                            ✓ Producción (<code>public_html</code> y <code>/carta</code>) está 100% al día con <code>/github</code> (<?php echo (int)$deploy_status['total_files']; ?> archivos verificados).
                        </p>
                    <?php endif; ?>

                    <?php if ($obsCount > 0): ?>
                        <p style="font-size:0.86rem;font-weight:700;color:#991b1b;margin-top:6px;">
                            Archivos viejos / inseguros detectados en producción:
                        </p>
                        <div class="deploy-pill-list">
                            <?php foreach ($deploy_status['obsolete'] as $of): ?>
                                <span class="deploy-pill obs">🗑 <?php echo htmlspecialchars($of, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" onsubmit="return confirm('¿Publicar ahora los archivos desde /github hacia public_html y /carta? (Tus fotos en /Uploads, config.php y otras carpetas no se tocan)');" style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-top:8px;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="deploy_from_github">
                        <?php if ($pendingCount === 0 && $obsCount === 0): ?>
                            <input type="hidden" name="force_all" value="1">
                        <?php endif; ?>
                        <?php if ($obsCount > 0): ?>
                            <label style="display:inline-flex;align-items:center;gap:6px;font-size:0.86rem;font-weight:600;color:#991b1b;cursor:pointer;margin:0;">
                                <input type="checkbox" name="clean_obsolete" value="1" checked style="width:auto;margin:0;">
                                Eliminar también los <?php echo $obsCount; ?> archivo(s) viejos/inseguros listados arriba
                            </label>
                        <?php endif; ?>
                        <button type="submit" class="btn-deploy-now">
                            <?php echo ($pendingCount > 0 || $obsCount > 0) ? '🚀 Publicar cambios a Producción ahora' : '🔄 Re-sincronizar todos los archivos'; ?>
                        </button>
                    </form>
                </div>
            </details>
            <?php endif; ?>

            <!-- Modal de Edición -->
            <div class="modal" id="edit-item-modal">
                <div class="modal-content">
                    <span class="modal-close" onclick="closeModal()">×</span>
                    <div class="modal-form" id="modal-form-content"></div>
                </div>
            </div>

            <!-- Modal de Confirmación para Eliminar Categoría con Productos -->
            <div class="modal" id="delete-cat-modal">
                <div class="modal-content" style="max-width:460px;">
                    <span class="modal-close" onclick="closeDeleteCatModal()">×</span>
                    <h3 style="margin-bottom:10px;font-family:var(--font-display);color:var(--rojo);font-size:1.5rem;">⚠️ Eliminar Categoría</h3>
                    <p id="delete-cat-modal-msg" style="font-size:0.95rem;line-height:1.5;color:var(--marron);margin-bottom:18px;"></p>
                    <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
                        <button type="button" onclick="closeDeleteCatModal()" style="background:#6b7280;border-color:#4b5563;box-shadow:2px 2px 0 #4b5563;">Cancelar</button>
                        <button type="button" id="btn-confirm-delete-cat" style="background:var(--rojo);border-color:var(--rojo-d);">Sí, eliminar categoría y productos</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Modal de Recorte 1:1 -->
    <div id="crop-modal">
        <div id="crop-modal-inner">
            <h4>Recortar imagen (cuadrado 1:1)</h4>
            <div id="crop-container">
                <img id="crop-image" src="" alt="Recortar">
            </div>
            <div id="crop-modal-actions">
                <button id="btn-crop-cancel" type="button">Cancelar</button>
                <button id="btn-crop-confirm" type="button">✔ Usar imagen recortada</button>
            </div>
        </div>
    </div>

    <script>
        const ALL_ITEMS    = <?php echo json_encode($menu_items, JSON_UNESCAPED_UNICODE); ?> || [];
        const ALL_CATS     = <?php echo json_encode($categories, JSON_UNESCAPED_UNICODE); ?> || [];
        const ALL_VARIANTS = <?php echo json_encode($variants, JSON_UNESCAPED_UNICODE); ?> || {};
        const CSRF_TOKEN   = <?php echo json_encode($_SESSION['csrf_token']); ?>;

        var _scheduleAllDays = <?php echo json_encode(array_keys($days_of_week), JSON_UNESCAPED_UNICODE); ?>;
        var _scheduleAllAbbr = <?php echo json_encode(array_values($days_of_week), JSON_UNESCAPED_UNICODE); ?>;

        function escAttr(s) {
            return String(s ?? '')
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        // ── Toggles de opciones (acotados siempre a SU propio formulario: Add o Modal Edit) ──
        function setupOptionToggle(selector) {
            const toggle = selector.querySelector('.option-toggle');
            const input  = selector.parentNode.querySelector('input[type="hidden"]');
            const form   = selector.closest('form');
            if (!toggle || !input || !form) return;

            toggle.addEventListener('click', () => {
                toggle.classList.toggle('active');
                const isActive = toggle.classList.contains('active');
                input.disabled = !isActive;

                if (toggle.dataset.option === 'is_weekly_special') {
                    const textWrap = form.querySelector('.weekly-special-text-wrap');
                    if (textWrap) {
                        textWrap.style.display = isActive ? 'block' : 'none';
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
        document.querySelectorAll('.toggle-selector').forEach(setupOptionToggle);

        function setupImageUrlPreview(input) {
            if (!input) return;
            input.addEventListener('input', function(e) {
                const url = e.target.value.trim();
                const existingPreview = input.parentNode.querySelector('img.url-preview-img');
                if (existingPreview) existingPreview.remove();
                if (url && /^https?:\/\//i.test(url)) {
                    const preview = document.createElement('img');
                    preview.className = 'url-preview-img';
                    preview.style.cssText = 'max-width:100%;max-height:160px;object-fit:contain;border-radius:8px;margin:8px 0;display:block;';
                    preview.src = url;
                    input.parentNode.insertBefore(preview, input.nextSibling);
                }
            });
        }
        document.querySelectorAll('input[name="image_url"]').forEach(setupImageUrlPreview);

        // ── Variantes con precio propio ──
        function variantRowHtml(name = '', price = '', desc = '') {
            return `
                <div class="variant-row" style="display:flex;gap:6px;margin-bottom:6px;flex-wrap:wrap;align-items:center;">
                    <input type="text"   name="variant_names[]"        placeholder="Nombre (ej. Queso Azul)" value="${escAttr(name)}"  style="flex:1;min-width:130px;max-width:none;">
                    <input type="number" name="variant_prices[]"       step="0.01" min="0.01" placeholder="Precio" value="${escAttr(price)}" style="width:110px;max-width:110px;">
                    <input type="text"   name="variant_descriptions[]" placeholder="Descripción (opcional)" value="${escAttr(desc)}" style="flex:2;min-width:150px;max-width:none;">
                    <button type="button" onclick="this.parentElement.remove()" style="padding:7px 12px;font-size:13px;background:#6b7280;border-color:#4b5563;box-shadow:none;">✕</button>
                </div>`;
        }
        function addVariantRow(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const div = document.createElement('div');
            div.innerHTML = variantRowHtml();
            container.appendChild(div.firstElementChild);
        }

        // ── Validaciones ──
        function validateItemForm(form) {
            const name     = form.querySelector('input[name="name"]').value.trim();
            const price    = parseFloat(form.querySelector('input[name="price"]').value);
            const category = form.querySelector('select[name="category_id"]').value;
            const imageUrl = form.querySelector('input[name="image_url"]').value.trim();

            if (!name) { alert('El nombre del producto es obligatorio.'); return false; }
            if (isNaN(price) || price <= 0) { alert('El precio debe ser mayor que 0.'); return false; }
            if (!category) { alert('Debes seleccionar una categoría.'); return false; }
            if (imageUrl && !/^https?:\/\//i.test(imageUrl)) {
                alert('La URL externa de la imagen debe comenzar con http:// o https://');
                return false;
            }
            return true;
        }

        function validateCategoryForm(form) {
            if (!form.querySelector('input[name="category_name"]').value.trim()) {
                alert('El nombre de la categoría es obligatorio.');
                return false;
            }
            return true;
        }

        function validateBusinessHoursForm(form) {
            const day       = form.querySelector('select[name="day_of_week"]').value;
            const startTime = form.querySelector('input[name="start_time"]').value;
            const endTime   = form.querySelector('input[name="end_time"]').value;
            if (!day || !startTime || !endTime) {
                alert('Día, hora de inicio y hora de fin son obligatorios.');
                return false;
            }
            if (startTime === endTime) {
                alert('La hora de inicio y fin no pueden ser exactamente la misma.');
                return false;
            }
            return true;
        }

        // ── Bulk Editor: -$1000 / +$1000, eliminar, visibilidad y drag & drop ──
        document.querySelectorAll('.bulk-raise-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var catId  = btn.dataset.category;
                var amount = parseFloat(btn.dataset.amount);
                var tbody  = document.querySelector('.bulk-sortable[data-category="' + catId + '"]');
                if (!tbody) return;
                tbody.querySelectorAll('input[name^="prices["]').forEach(function(input) {
                    var current = parseFloat(input.value) || 0;
                    var result  = current + amount;
                    input.value = (result < 0.01 ? 0.01 : result).toFixed(2);
                });
            });
        });

        function syncCategorySelects() {
            const sel = document.getElementById('add-item-cat-select');
            if (!sel) return;
            const prevVal = sel.value;
            sel.innerHTML = ALL_CATS.map(c =>
                `<option value="${Number(c.id)}">${escAttr(c.name)}</option>`
            ).join('');
            if (prevVal && ALL_CATS.some(c => String(c.id) === String(prevVal))) {
                sel.value = prevVal;
            }
        }

        (function() {
            var bulkCatContainer = document.getElementById('bulk-cat-sortable');
            if (!bulkCatContainer) return;
            Sortable.create(bulkCatContainer, {
                handle: '.bulk-cat-handle',
                animation: 150,
                onEnd: async function() {
                    var blocks = Array.from(bulkCatContainer.querySelectorAll('.bulk-cat-block[data-cat-id]'));
                    var order  = blocks.map(function(b) { return b.dataset.catId; });
                    // Reordenar también ALL_CATS en memoria
                    ALL_CATS.sort(function(a, b) {
                        return order.indexOf(String(a.id)) - order.indexOf(String(b.id));
                    });
                    syncCategorySelects();

                    var fd = new FormData();
                    fd.append('action',     'reorder_categories');
                    fd.append('order',      JSON.stringify(order));
                    fd.append('csrf_token', CSRF_TOKEN);
                    try {
                        var res  = await fetch('editor.php', { method: 'POST', body: fd });
                        var data = await res.json();
                        if (!data.ok) alert('Error al guardar orden de categorías: ' + (data.error || ''));
                    } catch (e) {
                        alert('Error de conexión al guardar el orden de categorías.');
                    }
                }
            });
        })();

        document.querySelectorAll('.bulk-delete-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var name = btn.dataset.name || 'este producto';
                if (!confirm('¿Marcar "' + name + '" para eliminar al guardar los precios?')) return;
                var row = btn.closest('tr');
                var chk = row.querySelector('.bulk-delete-input');
                chk.disabled = false;
                chk.checked  = true;
                row.style.opacity = '0.35';
                btn.disabled = true;
            });
        });

        document.querySelectorAll('.bulk-vis-btn').forEach(function(btn) {
            btn.addEventListener('click', async function() {
                var fd = new FormData();
                fd.append('action',     'toggle_visibility');
                fd.append('id',         btn.dataset.id);
                fd.append('csrf_token', CSRF_TOKEN);
                try {
                    var res  = await fetch('editor.php', { method: 'POST', body: fd });
                    var data = await res.json();
                    if (data.ok) {
                        var row = btn.closest('tr');
                        btn.textContent = data.is_visible ? '👁' : '🚫';
                        btn.title = data.is_visible ? 'Visible — clic para ocultar' : 'Oculto — clic para mostrar';
                        row.style.opacity = data.is_visible ? '' : '0.5';
                    } else {
                        alert('Error al cambiar visibilidad: ' + (data.error || ''));
                    }
                } catch (e) {
                    alert('Error de conexión al cambiar visibilidad.');
                }
            });
        });

        document.querySelectorAll('.bulk-sortable').forEach(function(tbody) {
            Sortable.create(tbody, {
                handle: '.bulk-drag-handle',
                animation: 150,
                onEnd: async function(evt) {
                    var rows  = Array.from(evt.to.querySelectorAll('tr[data-id]'));
                    var order = rows.map(function(r) { return r.dataset.id; });
                    var fd = new FormData();
                    fd.append('action',     'reorder_items');
                    fd.append('order',      JSON.stringify(order));
                    fd.append('csrf_token', CSRF_TOKEN);
                    try {
                        var res  = await fetch('editor.php', { method: 'POST', body: fd });
                        var data = await res.json();
                        if (!data.ok) alert('Error al guardar orden: ' + (data.error || ''));
                    } catch (e) {
                        alert('Error de conexión al guardar el orden.');
                    }
                }
            });
        });

        // ── Gestión unificada de Categorías: Agregar (+), Doble clic renombrar y Eliminar (✕) ──
        var _pendingDeleteCat = null;

        function closeDeleteCatModal() {
            var m = document.getElementById('delete-cat-modal');
            if (m) m.style.display = 'none';
            _pendingDeleteCat = null;
        }

        async function performDeleteCategory(catId, confirmWithItems, blockEl) {
            var fd = new FormData();
            fd.append('action',             'delete_category_ajax');
            fd.append('category_id',        String(catId));
            fd.append('confirm_with_items', confirmWithItems ? '1' : '');
            fd.append('csrf_token',         CSRF_TOKEN);

            try {
                var res  = await fetch('editor.php', { method: 'POST', body: fd });
                var data = await res.json();

                if (!data.ok && data.needs_confirm) {
                    var catObj  = ALL_CATS.find(function(c) { return String(c.id) === String(catId); });
                    var catName = catObj ? catObj.name : 'esta categoría';
                    openDeleteCatConfirmModal(catId, catName, data.count || 1, blockEl);
                    return;
                }

                if (!data.ok) {
                    alert(data.error || 'No se pudo eliminar la categoría.');
                    return;
                }

                closeDeleteCatModal();
                if (blockEl) blockEl.remove();

                var idx = ALL_CATS.findIndex(function(c) { return String(c.id) === String(catId); });
                if (idx !== -1) ALL_CATS.splice(idx, 1);
                syncCategorySelects();

                if (Array.isArray(data.deleted_item_ids) && data.deleted_item_ids.length > 0) {
                    var delSet = new Set(data.deleted_item_ids.map(String));
                    for (var i = ALL_ITEMS.length - 1; i >= 0; i--) {
                        if (delSet.has(String(ALL_ITEMS[i].id))) ALL_ITEMS.splice(i, 1);
                    }
                    document.querySelectorAll('#item-grid .item-card').forEach(function(card) {
                        if (delSet.has(String(card.dataset.id))) card.remove();
                    });
                }
            } catch (e) {
                alert('Error de conexión al eliminar la categoría.');
            }
        }

        function openDeleteCatConfirmModal(catId, catName, itemCount, blockEl) {
            _pendingDeleteCat = { catId: catId, blockEl: blockEl };
            var msgEl = document.getElementById('delete-cat-modal-msg');
            if (msgEl) {
                msgEl.innerHTML = 'La categoría <strong>«' + escAttr(catName) + '»</strong> contiene <strong>' +
                    Number(itemCount) + ' producto(s)</strong>.<br><br>' +
                    'Si confirmás la eliminación, se borrarán la categoría y todos los productos que están dentro de ella.';
            }
            var modal = document.getElementById('delete-cat-modal');
            if (modal) modal.style.display = 'flex';
        }

        document.getElementById('btn-confirm-delete-cat')?.addEventListener('click', function() {
            if (!_pendingDeleteCat) return;
            performDeleteCategory(_pendingDeleteCat.catId, true, _pendingDeleteCat.blockEl);
        });

        document.getElementById('delete-cat-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeDeleteCatModal();
        });

        function bindBulkCategoryTitle(titleSpan) {
            if (!titleSpan || titleSpan.dataset.boundDbl === '1') return;
            titleSpan.dataset.boundDbl = '1';

            titleSpan.addEventListener('dblclick', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (titleSpan.querySelector('input')) return;

                var catId       = titleSpan.dataset.catId;
                var currentName = titleSpan.textContent.trim();
                var block       = titleSpan.closest('.bulk-cat-block');
                var statusEl    = block ? block.querySelector('.bulk-cat-status') : null;

                var input = document.createElement('input');
                input.type      = 'text';
                input.className = 'cat-inline-edit-input';
                input.value     = currentName;
                input.maxLength = 120;

                titleSpan.textContent = '';
                titleSpan.appendChild(input);
                input.focus();
                input.select();

                var finished = false;
                async function commitRename(cancel) {
                    if (finished) return;
                    finished = true;
                    var nextName = input.value.trim();
                    if (cancel || !nextName || nextName === currentName) {
                        titleSpan.textContent = currentName;
                        return;
                    }
                    titleSpan.textContent = nextName;

                    var fd = new FormData();
                    fd.append('action',        'rename_category_ajax');
                    fd.append('category_id',   String(catId));
                    fd.append('category_name', nextName);
                    fd.append('csrf_token',    CSRF_TOKEN);
                    try {
                        var res  = await fetch('editor.php', { method: 'POST', body: fd });
                        var data = await res.json();
                        if (!data.ok) {
                            titleSpan.textContent = currentName;
                            alert(data.error || 'No se pudo renombrar la categoría.');
                            return;
                        }
                        var catObj = ALL_CATS.find(function(c) { return String(c.id) === String(catId); });
                        if (catObj) catObj.name = data.name;
                        syncCategorySelects();

                        var delBtn = block ? block.querySelector('.bulk-cat-del-btn') : null;
                        if (delBtn) delBtn.dataset.catName = data.name;

                        document.querySelectorAll('#item-grid .item-card[data-cat-id="' + catId + '"] .mini-badge.cat').forEach(function(b) {
                            b.textContent = data.name;
                        });

                        if (statusEl) {
                            statusEl.textContent = '✔ Guardado';
                            setTimeout(function() { statusEl.textContent = ''; }, 2000);
                        }
                    } catch (err) {
                        titleSpan.textContent = currentName;
                        alert('Error de conexión al renombrar la categoría.');
                    }
                }

                input.addEventListener('keydown', function(ke) {
                    if (ke.key === 'Enter') {
                        ke.preventDefault();
                        commitRename(false);
                    } else if (ke.key === 'Escape') {
                        ke.preventDefault();
                        commitRename(true);
                    }
                });
                input.addEventListener('blur', function() {
                    commitRename(false);
                });
            });
        }

        function bindBulkCategoryDeleteBtn(btn) {
            if (!btn || btn.dataset.boundDel === '1') return;
            btn.dataset.boundDel = '1';

            btn.addEventListener('click', function() {
                var catId   = btn.dataset.catId;
                var catName = btn.dataset.catName || 'esta categoría';
                var block   = btn.closest('.bulk-cat-block');
                var rowCnt  = block ? block.querySelectorAll('tbody.bulk-sortable tr[data-id]').length : 0;

                if (rowCnt > 0) {
                    openDeleteCatConfirmModal(catId, catName, rowCnt, block);
                } else {
                    performDeleteCategory(catId, false, block);
                }
            });
        }

        document.querySelectorAll('.bulk-cat-title').forEach(bindBulkCategoryTitle);
        document.querySelectorAll('.bulk-cat-del-btn').forEach(bindBulkCategoryDeleteBtn);

        (function() {
            var btnToggle = document.getElementById('btn-toggle-add-cat');
            var inlineBar = document.getElementById('add-cat-inline-bar');
            var nameInput = document.getElementById('new-cat-name-input');
            var btnSave   = document.getElementById('btn-save-new-cat');
            var btnCancel = document.getElementById('btn-cancel-new-cat');
            var container = document.getElementById('bulk-cat-sortable');
            if (!btnToggle || !inlineBar || !nameInput || !btnSave || !btnCancel || !container) return;

            btnToggle.addEventListener('click', function() {
                var show = (inlineBar.style.display === 'none');
                inlineBar.style.display = show ? 'block' : 'none';
                if (show) { nameInput.value = ''; nameInput.focus(); }
            });

            btnCancel.addEventListener('click', function() {
                inlineBar.style.display = 'none';
                nameInput.value = '';
            });

            async function createNewCategory() {
                var name = nameInput.value.trim();
                if (!name) { alert('Ingresá un nombre para la categoría.'); nameInput.focus(); return; }

                btnSave.disabled = true;
                var fd = new FormData();
                fd.append('action',        'add_category_ajax');
                fd.append('category_name', name);
                fd.append('csrf_token',    CSRF_TOKEN);

                try {
                    var res  = await fetch('editor.php', { method: 'POST', body: fd });
                    var data = await res.json();
                    if (!data.ok || !data.category) {
                        alert(data.error || 'No se pudo crear la categoría.');
                        btnSave.disabled = false;
                        return;
                    }

                    var cat = data.category;
                    ALL_CATS.push(cat);
                    syncCategorySelects();

                    var block = document.createElement('div');
                    block.className   = 'bulk-cat-block';
                    block.dataset.catId = String(cat.id);
                    block.style.marginBottom = '6px';
                    block.innerHTML =
                        '<div class="bulk-cat-header">' +
                            '<span class="bulk-cat-handle" title="Arrastrá para reordenar categorías" style="cursor:grab;color:#888;font-size:1.15rem;user-select:none;flex-shrink:0;">⠿</span>' +
                            '<span class="bulk-cat-title" data-cat-id="' + Number(cat.id) + '" title="Doble clic para cambiar el nombre de la categoría">' + escAttr(cat.name) + '</span>' +
                            '<span class="bulk-cat-status" style="font-size:0.78rem;color:#16a34a;font-weight:700;">✔ Creada</span>' +
                            '<span style="margin-left:auto;display:flex;align-items:center;gap:6px;">' +
                                '<button type="button" class="bulk-cat-del-btn" data-cat-id="' + Number(cat.id) + '" data-cat-name="' + escAttr(cat.name) + '" title="Eliminar categoría">✕</button>' +
                            '</span>' +
                        '</div>' +
                        '<div class="empty-cat-note">Categoría sin productos todavía — podés cargarle ítems desde «Agregar Nuevo Producto».</div>';

                    container.appendChild(block);
                    bindBulkCategoryTitle(block.querySelector('.bulk-cat-title'));
                    bindBulkCategoryDeleteBtn(block.querySelector('.bulk-cat-del-btn'));
                    setTimeout(function() {
                        var st = block.querySelector('.bulk-cat-status');
                        if (st) st.textContent = '';
                    }, 2200);

                    nameInput.value = '';
                    inlineBar.style.display = 'none';
                    block.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } catch (err) {
                    alert('Error de conexión al crear la categoría.');
                }
                btnSave.disabled = false;
            }

            btnSave.addEventListener('click', createNewCategory);
            nameInput.addEventListener('keydown', function(ke) {
                if (ke.key === 'Enter') { ke.preventDefault(); createNewCategory(); }
                else if (ke.key === 'Escape') { ke.preventDefault(); btnCancel.click(); }
            });
        })();

        // ── Drag & drop en grilla de Productos Existentes (arreglado: devuelve JSON real) ──
        const itemGrid = document.getElementById('item-grid');
        if (itemGrid) {
            new Sortable(itemGrid, {
                animation: 150,
                onEnd: async function() {
                    const items = Array.from(itemGrid.children).map(item => item.dataset.id);
                    const fd = new FormData();
                    fd.append('action',     'update_order');
                    fd.append('order',      JSON.stringify(items));
                    fd.append('csrf_token', CSRF_TOKEN);
                    try {
                        const res  = await fetch('editor.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (!data.ok) alert('Error al actualizar el orden: ' + (data.error || ''));
                    } catch (e) {
                        console.error('Error al actualizar el orden', e);
                    }
                }
            });
        }

        // ── Modal de Edición de Producto (100% escapado contra comillas simples y dobles) ──
        function openModal(itemId) {
            const modal        = document.getElementById('edit-item-modal');
            const modalContent = document.getElementById('modal-form-content');
            const item         = ALL_ITEMS.find(i => String(i.id) === String(itemId));
            const itemVariants = ALL_VARIANTS[itemId] || [];
            if (!item) return;

            const isVis    = Number(item.is_visible) === 1;
            const isVeg    = Number(item.has_vegan_option) === 1;
            const isDest   = Number(item.is_weekly_special) === 1;
            const isSecret = Number(item.is_secret_menu) === 1;
            const externalImgUrl = /^https?:\/\//i.test(item.image_url || '') ? item.image_url : '';
            const catOptionsHtml = ALL_CATS.map(c =>
                `<option value="${Number(c.id)}" ${Number(item.category_id) === Number(c.id) ? 'selected' : ''}>${escAttr(c.name)}</option>`
            ).join('');

            modalContent.innerHTML = `
                <h3 style="margin-bottom:12px;font-family:var(--font-display);color:var(--rojo);font-size:1.5rem;">Editar: ${escAttr(item.name)}</h3>
                <form method="POST" enctype="multipart/form-data" class="modal-form" onsubmit="return validateItemForm(this)">
                    <input type="hidden" name="csrf_token" value="${escAttr(CSRF_TOKEN)}">
                    <input type="hidden" name="action" value="update_item">
                    <input type="hidden" name="id" value="${Number(item.id)}">
                    <input type="hidden" name="existing_image" value="${escAttr(item.image_url || '')}">
                    <img class="current-item-img" src="${escAttr(item.image_url || 'https://via.placeholder.com/150?text=Sin+Imagen')}" alt="${escAttr(item.name)}">

                    <label>Nombre:</label>
                    <input type="text" name="name" value="${escAttr(item.name)}" required>

                    <label>Precio ($):</label>
                    <input type="number" step="0.01" name="price" value="${escAttr(item.price)}" min="0.01" required>

                    <label>Categoría:</label>
                    <select name="category_id" required>${catOptionsHtml}</select>

                    <label>Descripción / Ingredientes:</label>
                    <textarea name="description" rows="3">${escAttr(item.description || '')}</textarea>

                    <label>Cambiar Imagen (Subir archivo y recortar 1:1):</label>
                    <input type="file" name="image" accept="image/*" class="crop-file-input">
                    <input type="hidden" name="cropped_image_data" class="cropped-data-field">
                    <div class="crop-preview-wrap" style="margin:4px 0 8px;min-height:0;"></div>

                    <label>O URL externa de imagen:</label>
                    <input type="url" name="image_url" value="${escAttr(externalImgUrl)}" placeholder="https://...">

                    <div class="toggle-group">
                        <div class="toggle-inputs">
                            <label>Visible:</label>
                            <div class="toggle-selector">
                                <div class="option-toggle ${isVis ? 'active' : ''}" data-option="is_visible" tabindex="0" role="button">V</div>
                            </div>
                            <input type="hidden" name="is_visible" value="1" ${!isVis ? 'disabled' : ''}>
                        </div>
                        <div class="toggle-inputs">
                            <label>Opción Vegana:</label>
                            <div class="toggle-selector">
                                <div class="option-toggle ${isVeg ? 'active' : ''}" data-option="has_vegan_option" tabindex="0" role="button">VG</div>
                            </div>
                            <input type="hidden" name="has_vegan_option" value="1" ${!isVeg ? 'disabled' : ''}>
                        </div>
                        <div class="toggle-inputs">
                            <label>Destacado (cinta):</label>
                            <div class="toggle-selector">
                                <div class="option-toggle ${isDest ? 'active' : ''}" data-option="is_weekly_special" tabindex="0" role="button">D</div>
                            </div>
                            <input type="hidden" name="is_weekly_special" value="1" ${!isDest ? 'disabled' : ''}>
                        </div>
                        <div class="toggle-inputs">
                            <label>Carta Secreta:</label>
                            <div class="toggle-selector">
                                <div class="option-toggle ${isSecret ? 'active' : ''}" data-option="is_secret_menu" tabindex="0" role="button">CS</div>
                            </div>
                            <input type="hidden" name="is_secret_menu" value="1" ${!isSecret ? 'disabled' : ''}>
                        </div>
                    </div>

                    <div class="weekly-special-text-wrap" style="${isDest ? 'display:block;' : 'display:none;'}">
                        <label>Texto de la cinta destacada:</label>
                        <input type="text" name="weekly_special_text" class="weekly-special-text" value="${escAttr(item.weekly_special_text || '¡Pizza de la semana!')}" placeholder="¡Pizza de la semana!">
                    </div>

                    <div class="schedules-section" style="margin-top:8px;">
                        <label>Franjas de disponibilidad:</label>
                        <div class="schedules-container" id="schedules-edit-${Number(item.id)}"></div>
                        <button type="button" class="btn-add-schedule"
                            onclick="addScheduleRow(document.getElementById('schedules-edit-${Number(item.id)}'), null, null, [], '', '')">
                            + Agregar franja horaria
                        </button>
                    </div>

                    <div class="variants-inputs" style="margin:12px 0;padding:12px;border:1px dashed #aaa;border-radius:8px;background:#fff;">
                        <label style="margin-top:0;">Variantes con precio propio (opcional):</label>
                        <div id="variants-${Number(item.id)}">
                            ${itemVariants.map(v => variantRowHtml(v.name, v.price, v.description || '')).join('')}
                        </div>
                        <button type="button" class="btn-add-schedule" onclick="addVariantRow('variants-${Number(item.id)}')">+ Agregar variante</button>
                    </div>

                    <label>Orden de visualización:</label>
                    <input type="number" name="display_order" value="${Number(item.display_order || 0)}" required style="max-width:140px;">

                    <div style="display:flex;gap:10px;margin-top:14px;">
                        <button type="submit">Guardar cambios</button>
                        <button type="submit" name="action" value="delete_item" onclick="return confirm('¿Seguro que querés eliminar este producto?');" style="background:#6b7280;border-color:#4b5563;box-shadow:2px 2px 0 #4b5563;">Eliminar producto</button>
                    </div>
                </form>
            `;
            modal.style.display = 'flex';

            const form = modalContent.querySelector('form');
            const schedContainer = form.querySelector('.schedules-container');
            if (schedContainer) {
                let rawSchedules = [];
                try {
                    const parsed = JSON.parse(item.visible_days || '[]');
                    if (Array.isArray(parsed) && parsed.length > 0) {
                        if (typeof parsed[0] === 'object' && parsed[0] !== null && 'start' in parsed[0]) {
                            rawSchedules = parsed;
                        } else if (typeof parsed[0] === 'string') {
                            rawSchedules = [{ days: parsed, start: item.visible_start_time || '', end: item.visible_end_time || '' }];
                        }
                    }
                } catch (e) {}
                if (rawSchedules.length === 0) {
                    rawSchedules = [{ days: _scheduleAllDays, start: '', end: '' }];
                }
                rawSchedules.forEach(s => {
                    addScheduleRow(schedContainer, null, null, s.days || [], s.start || '', s.end || '');
                });
            }

            setupImageUrlPreview(form.querySelector('input[name="image_url"]'));
            form.querySelectorAll('.toggle-selector').forEach(setupOptionToggle);
        }

        function closeModal() {
            const modal = document.getElementById('edit-item-modal');
            modal.style.display = 'none';
            document.getElementById('modal-form-content').innerHTML = '';
        }

        document.querySelectorAll('.item-card').forEach(card => {
            card.addEventListener('click', () => openModal(card.dataset.id));
        });

        document.getElementById('edit-item-modal').addEventListener('click', (e) => {
            if (e.target === document.getElementById('edit-item-modal')) closeModal();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal();
                closeDeleteCatModal();
            }
        });

        // ── Franjas de disponibilidad por producto ──
        function addScheduleRow(container, allDays, allAbbr, activeDays, startVal, endVal) {
            if (!container) return;
            allDays    = allDays  || _scheduleAllDays;
            allAbbr    = allAbbr  || _scheduleAllAbbr;
            activeDays = activeDays || allDays;
            startVal   = (startVal || '').slice(0, 5);
            endVal     = (endVal   || '').slice(0, 5);

            var idx = container.querySelectorAll('.schedule-row').length;
            var row = document.createElement('div');
            row.className = 'schedule-row';
            row.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#f9f9f9;border:1px solid #ddd;border-radius:10px;padding:10px 14px;margin-bottom:8px;';

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
                toggle.style.cssText = 'width:32px;height:32px;font-size:13px;';

                var hiddenDay = document.createElement('input');
                hiddenDay.type = 'hidden';
                hiddenDay.name = 'schedule_days[' + idx + '][]';
                hiddenDay.value = day;
                hiddenDay.disabled = !isActive;

                toggle.addEventListener('click', function() {
                    toggle.classList.toggle('active');
                    hiddenDay.disabled = !toggle.classList.contains('active');
                });
                toggle.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle.click(); }
                });

                daysWrap.appendChild(toggle);
                daysWrap.appendChild(hiddenDay);
            });
            row.appendChild(daysWrap);

            var startLabel = document.createElement('label');
            startLabel.textContent = 'Desde:';
            startLabel.style.cssText = 'font-size:0.85rem;font-weight:600;color:#555;margin:0;';
            row.appendChild(startLabel);
            var startInput = document.createElement('input');
            startInput.type = 'time';
            startInput.name = 'schedule_start[' + idx + ']';
            startInput.value = startVal;
            startInput.style.cssText = 'padding:6px 10px;border:1px solid #ddd;border-radius:20px;font-size:0.9rem;max-width:130px;';
            row.appendChild(startInput);

            var endLabel = document.createElement('label');
            endLabel.textContent = 'Hasta:';
            endLabel.style.cssText = 'font-size:0.85rem;font-weight:600;color:#555;margin:0;';
            row.appendChild(endLabel);
            var endInput = document.createElement('input');
            endInput.type = 'time';
            endInput.name = 'schedule_end[' + idx + ']';
            endInput.value = endVal;
            endInput.style.cssText = 'padding:6px 10px;border:1px solid #ddd;border-radius:20px;font-size:0.9rem;max-width:130px;';
            row.appendChild(endInput);

            if (container.querySelectorAll('.schedule-row').length > 0) {
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.textContent = '✕';
                removeBtn.title = 'Eliminar esta franja';
                removeBtn.style.cssText = 'background:none;border:1px solid #cc0000;color:#cc0000;border-radius:50%;width:28px;height:28px;cursor:pointer;font-size:14px;line-height:1;padding:0;flex-shrink:0;box-shadow:none;';
                removeBtn.addEventListener('click', function() { row.remove(); });
                row.appendChild(removeBtn);
            }

            container.appendChild(row);
        }

        addScheduleRow(document.getElementById('schedules-add'), _scheduleAllDays, _scheduleAllAbbr, [], '', '');
    </script>

    <script>
    // ── Cropper.js 1:1 WebP ──
    (function() {
        var cropModal  = document.getElementById('crop-modal');
        var cropImg    = document.getElementById('crop-image');
        var btnConfirm = document.getElementById('btn-crop-confirm');
        var btnCancel  = document.getElementById('btn-crop-cancel');
        var cropper    = null;

        var activeFileInput    = null;
        var activeCroppedField = null;
        var activePreviewWrap  = null;

        function openCropModal(fileInput, blob) {
            activeFileInput    = fileInput;
            var container      = fileInput.closest('form') || fileInput.parentNode;
            activeCroppedField = container.querySelector('.cropped-data-field');
            activePreviewWrap  = container.querySelector('.crop-preview-wrap');

            cropImg.src = URL.createObjectURL(blob);
            cropModal.classList.add('open');
            if (cropper) { cropper.destroy(); cropper = null; }

            cropImg.onload = function() {
                cropper = new Cropper(cropImg, {
                    aspectRatio: 1,
                    viewMode: 1,
                    dragMode: 'move',
                    autoCropArea: 0.9,
                    responsive: true,
                    restore: false,
                    guides: true,
                    center: true,
                    highlight: false,
                    cropBoxMovable: true,
                    cropBoxResizable: true,
                    toggleDragModeOnDblclick: false,
                });
            };
        }

        function closeCropModal() {
            cropModal.classList.remove('open');
            if (cropper) { cropper.destroy(); cropper = null; }
            cropImg.src = '';
        }

        document.body.addEventListener('change', function(e) {
            var input = e.target;
            if (!input.classList || !input.classList.contains('crop-file-input')) return;
            if (!input.files || !input.files[0]) return;
            var file = input.files[0];
            if (!file.type.startsWith('image/')) return;
            openCropModal(input, file);
        });

        btnConfirm.addEventListener('click', function() {
            if (!cropper || !activeCroppedField) return;
            var canvas  = cropper.getCroppedCanvas({ width: 800, height: 800, imageSmoothingQuality: 'high' });
            var dataUrl = canvas.toDataURL('image/webp', 0.82);

            activeCroppedField.value = dataUrl;
            if (activeFileInput) activeFileInput.disabled = true;

            if (activePreviewWrap) {
                activePreviewWrap.innerHTML = '';
                var thumb = document.createElement('img');
                thumb.src = dataUrl;
                thumb.className = 'crop-preview-thumb';
                var label = document.createElement('span');
                label.textContent = ' Imagen recortada ✔';
                label.style.cssText = 'font-size:0.85rem;color:#28a745;font-weight:600;';
                var reBtn = document.createElement('button');
                reBtn.type = 'button';
                reBtn.textContent = 'Cambiar recorte';
                reBtn.style.cssText = 'margin-left:10px;background:none;border:1px solid #cc0000;color:#cc0000;border-radius:12px;padding:2px 10px;font-size:0.8rem;cursor:pointer;box-shadow:none;';
                reBtn.addEventListener('click', function() {
                    var form = activePreviewWrap.closest('form');
                    activeCroppedField = form.querySelector('.cropped-data-field');
                    var fi = form.querySelector('.crop-file-input');
                    if (fi && fi.files && fi.files[0]) {
                        fi.disabled = false;
                        openCropModal(fi, fi.files[0]);
                    }
                });
                activePreviewWrap.append(thumb, label, reBtn);
            }
            closeCropModal();
        });

        btnCancel.addEventListener('click', function() {
            if (activeFileInput) { activeFileInput.value = ''; activeFileInput.disabled = false; }
            if (activeCroppedField) activeCroppedField.value = '';
            if (activePreviewWrap) activePreviewWrap.innerHTML = '';
            closeCropModal();
        });

        cropModal.addEventListener('click', function(e) {
            if (e.target === cropModal) btnCancel.click();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && cropModal.classList.contains('open')) btnCancel.click();
        });
    })();
    </script>
</body>
</html>

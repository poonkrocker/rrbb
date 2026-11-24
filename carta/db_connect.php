<?php
/**
 * db_connect.php — carpeta del subdominio (/public_html/carta).
 *
 * Usa el MISMO config.php del sitio principal.
 * NO depende de $_SERVER['DOCUMENT_ROOT'], porque ese valor cambia según
 * se entre por arrabbiata.com.ar/carta o por el subdominio, y eso hacía
 * fallar la búsqueda del config (error 500 "Error de configuración").
 *
 * Confirmado por diagnóstico: el config vive en /home/c2652217/config.php
 */

// --- Rutas candidatas para config.php (en orden de prioridad) ---
$__config_paths = ['/home/c2652217/config.php'];   // ruta fija confirmada

// Respaldo dinámico: sube hasta 5 niveles desde la ubicación de ESTE archivo.
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__config_paths[] = $__dir . '/config.php';
    $__parent = dirname($__dir);
    if ($__parent === $__dir) break;   // llegamos a la raíz del filesystem
    $__dir = $__parent;
}

$__config_paths = array_values(array_unique($__config_paths));

// --- Cargar configuración ---
$config = null;
foreach ($__config_paths as $__p) {
    if (is_readable($__p)) {
        $config = require $__p;
        break;
    }
}

if (!is_array($config) || empty($config['db'])) {
    error_log('carta/db_connect: config.php no encontrado. Rutas probadas: ' . implode(' | ', $__config_paths));
    http_response_code(500);
    die('Error de configuración del servidor.');
}

// --- Conexión PDO ---
try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4",
        $config['db']['user'],
        $config['db']['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    error_log('DB connection failed (carta): ' . $e->getMessage());
    http_response_code(500);
    die('Error de conexión. Intentá de nuevo más tarde.');
}

// Sin cierre ?> a propósito: evita salida accidental antes de los headers.
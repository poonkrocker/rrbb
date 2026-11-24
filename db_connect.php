<?php
/**
 * db_connect.php — Conexión PDO usando configuración externa.
 *
 * Busca config.php subiendo desde la ubicación de ESTE archivo.
 * NO usa $_SERVER['DOCUMENT_ROOT']: ese valor cambia según se entre por
 * arrabbiata.com.ar/carta (docroot = public_html) o por el subdominio
 * (docroot = public_html/carta), y hacía fallar la búsqueda -> error 500.
 *
 * Nunca credenciales hardcodeadas en el repo.
 */

$__config_paths = ['/home/c2652217/config.php'];   // ruta confirmada

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__config_paths[] = $__dir . '/config.php';
    $__parent = dirname($__dir);
    if ($__parent === $__dir) break;
    $__dir = $__parent;
}
$__config_paths = array_values(array_unique($__config_paths));

$config = null;
foreach ($__config_paths as $__p) {
    if (is_readable($__p)) {
        $config = require $__p;
        break;
    }
}

if (!is_array($config) || empty($config['db'])) {
    error_log('db_connect: config.php no encontrado. Rutas probadas: ' . implode(' | ', $__config_paths));
    http_response_code(500);
    die('Error de configuración del servidor.');
}

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
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Error de conexión. Intentá de nuevo más tarde.');
}
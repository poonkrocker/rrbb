<?php
/**
 * db_connect.php — Conexión PDO usando configuración externa.
 *
 * Busca config.php en este orden:
 *   1. Un nivel arriba del docroot (recomendado: fuera del sitio público)
 *   2. Junto a este archivo (fallback; debe estar en .gitignore y
 *      bloqueado en .htaccess)
 *
 * Nunca más credenciales hardcodeadas en el repo.
 */

$__config_paths = [
    dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/config.php',
    __DIR__ . '/config.php',
];

$config = null;
foreach ($__config_paths as $__p) {
    if (is_readable($__p)) {
        $config = require $__p;
        break;
    }
}

if (!is_array($config) || empty($config['db'])) {
    http_response_code(500);
    die('Error de configuración del servidor.'); // sin detalles al público
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
    // Loguear el detalle, NUNCA mostrarlo al usuario (revelaba host/usuario).
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Error de conexión. Intentá de nuevo más tarde.');
}

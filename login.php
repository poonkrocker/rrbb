<?php
/**
 * login.php — Con rate limiting persistido en BD (compatible con Cloudflare)
 * y cookies de sesión endurecidas.
 */

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/db_connect.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            ip            VARCHAR(45) NOT NULL PRIMARY KEY,
            attempts      INT NOT NULL DEFAULT 0,
            first_attempt INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    error_log('login_attempts table check: ' . $e->getMessage());
}

$MAX_ATTEMPTS = 10;
$WINDOW       = 600; // 10 minutos

$rawIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (strpos($rawIp, ',') !== false) {
    $rawIp = trim(explode(',', $rawIp)[0]);
}
$ip = substr($rawIp, 0, 45);

function getAttempts(PDO $pdo, string $ip, int $window): array {
    $st = $pdo->prepare("SELECT attempts, first_attempt FROM login_attempts WHERE ip = ?");
    $st->execute([$ip]);
    $row = $st->fetch();
    if (!$row || (time() - (int)$row['first_attempt']) > $window) {
        return ['attempts' => 0, 'first_attempt' => time()];
    }
    return ['attempts' => (int)$row['attempts'], 'first_attempt' => (int)$row['first_attempt']];
}

function recordFailure(PDO $pdo, string $ip): void {
    $now = time();
    $st = $pdo->prepare("
        INSERT INTO login_attempts (ip, attempts, first_attempt)
        VALUES (?, 1, ?)
        ON DUPLICATE KEY UPDATE
            attempts      = IF(? - first_attempt > 600, 1, attempts + 1),
            first_attempt = IF(? - first_attempt > 600, ?, first_attempt)
    ");
    $st->execute([$ip, $now, $now, $now, $now]);
}

function clearAttempts(PDO $pdo, string $ip): void {
    $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
}

$state   = getAttempts($pdo, $ip, $WINDOW);
$blocked = $state['attempts'] >= $MAX_ATTEMPTS;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($blocked) {
        $error = "Demasiados intentos. Esperá unos minutos antes de volver a intentarlo.";
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            clearAttempts($pdo, $ip);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            header('Location: editor.php');
            exit;
        } else {
            recordFailure($pdo, $ip);
            $error = "Credenciales incorrectas.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login - Arrabbiata</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f0e6d0; }
        .login-container { background: white; padding: 24px; border-radius: 10px; border: 3px solid #1a5fbf; box-shadow: 5px 5px 0 #1a5fbf; width: 90%; max-width: 340px; }
        h2 { margin: 0 0 14px; color: #cc1414; }
        input { width: 100%; padding: 10px; margin: 8px 0; border: 1.5px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 15px; }
        button { background: #cc1414; color: white; padding: 11px; border: none; border-radius: 5px; cursor: pointer; width: 100%; font-weight: bold; font-size: 15px; margin-top: 8px; }
        button:hover { background: #a50e0e; }
        .error { color: #b91c1c; background: #fee2e2; padding: 8px 10px; border-radius: 5px; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Admin Login</h2>
        <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</p>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Usuario" required autocomplete="username">
            <input type="password" name="password" placeholder="Contraseña" required autocomplete="current-password">
            <button type="submit">Ingresar</button>
        </form>
    </div>
</body>
</html>

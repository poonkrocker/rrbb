<?php
/**
 * login.php — Con rate limiting REAL (persistido en BD, por IP)
 * y cookies de sesión endurecidas.
 *
 * Requiere una tabla nueva (ver rrbb-migracion.sql):
 *   CREATE TABLE login_attempts (
 *     ip VARCHAR(45) NOT NULL PRIMARY KEY,
 *     attempts INT NOT NULL DEFAULT 0,
 *     first_attempt INT NOT NULL
 *   );
 */

// Cookies de sesión seguras ANTES de session_start()
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,   // solo HTTPS (el .htaccess ya fuerza HTTPS)
    'httponly' => true,   // JS no puede leer la cookie
    'samesite' => 'Lax',
]);
session_start();
require_once 'db_connect.php';

$MAX_ATTEMPTS = 10;
$WINDOW       = 600; // 10 minutos

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// --- Leer intentos desde la BD (sobrevive a que el atacante descarte cookies) ---
function getAttempts(PDO $pdo, string $ip, int $window): array {
    $st = $pdo->prepare("SELECT attempts, first_attempt FROM login_attempts WHERE ip = ?");
    $st->execute([$ip]);
    $row = $st->fetch();
    if (!$row || (time() - (int)$row['first_attempt']) > $window) {
        return ['attempts' => 0, 'first_attempt' => time()];
    }
    return ['attempts' => (int)$row['attempts'], 'first_attempt' => (int)$row['first_attempt']];
}

function recordFailure(PDO $pdo, string $ip, array $state): void {
    $st = $pdo->prepare("
        INSERT INTO login_attempts (ip, attempts, first_attempt)
        VALUES (?, 1, ?)
        ON DUPLICATE KEY UPDATE
            attempts = IF(? - first_attempt > 600, 1, attempts + 1),
            first_attempt = IF(VALUES(first_attempt) - first_attempt > 600, VALUES(first_attempt), first_attempt)
    ");
    $now = time();
    $st->execute([$ip, $now, $now]);
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
            recordFailure($pdo, $ip, $state);
            $error = "Credenciales incorrectas.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background: #f5f5f5; }
        .login-container { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
        button { background: #cc0000; color: white; padding: 10px; border: none; border-radius: 5px; cursor: pointer; width: 100%; }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Admin Login</h2>
        <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Username" required autocomplete="username">
            <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>

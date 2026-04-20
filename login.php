<?php
session_start();
require_once 'db_connect.php';

// Rate limiting: máximo 10 intentos por IP en 10 minutos
$ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$key = 'login_attempts_' . md5($ip);
if (!isset($_SESSION[$key])) {
    $_SESSION[$key] = ['count' => 0, 'first' => time()];
}
$attempts = &$_SESSION[$key];
// Resetear ventana si pasaron más de 10 minutos
if (time() - $attempts['first'] > 600) {
    $attempts = ['count' => 0, 'first' => time()];
}
$blocked = $attempts['count'] >= 10;

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
            // Login exitoso: limpiar contador y regenerar sesión
            unset($_SESSION[$key]);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            header('Location: editor.php');
            exit;
        } else {
            $attempts['count']++;
            $error = "Credenciales incorrectas.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background: #f5f5f5; }
        .login-container { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; }
        button { background: #cc0000; color: white; padding: 10px; border: none; border-radius: 5px; cursor: pointer; width: 100%; }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Admin Login</h2>
        <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>
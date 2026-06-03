<?php
require __DIR__ . '/../config/bootstrap.php';

// Limpiar todas las variables de sesión
$_SESSION = array();

// Destruir la cookie de sesión
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destruir la sesión
session_destroy();

echo "<h1>Sesión Limpiada</h1>";
echo "<p>La sesión ha sido limpiada exitosamente.</p>";
echo "<p><a href='login.php'>Ir al Login</a></p>";
echo "<p><a href='test_auth.php'>Probar Autenticación</a></p>";
?>

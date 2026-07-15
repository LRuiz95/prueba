<?php
require_once 'config/app.php';
$hash = password_hash('admin123', PASSWORD_DEFAULT);
try {
    $stmt = $pdo->prepare("INSERT INTO usuarios_admin (usuario, password, nombre) VALUES ('admin', ?, 'Administrador') ON DUPLICATE KEY UPDATE password = VALUES(password)");
    $stmt->execute([$hash]);
    echo "✅ Admin creado/actualizado.<br>Usuario: <strong>admin</strong> | Contraseña: <strong>admin123</strong><br><a href='login.php'>Ir al login</a>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
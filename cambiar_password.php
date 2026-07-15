<?php
require_once 'config/app.php';
requiere_login();
if (!es_alumno()) redirect('admin/dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    $actual = $_POST['password_actual'] ?? '';
    $nueva = $_POST['password_nueva'] ?? '';
    $confirmar = $_POST['password_confirmar'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM alumnos WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $al = $stmt->fetch();

    if (!$al || !password_verify($actual, $al['password'])) {
        $error = 'Tu contraseña actual no es correcta.';
    } elseif (strlen($nueva) < 8) {
        $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } elseif ($nueva !== $confirmar) {
        $error = 'La confirmación no coincide con la nueva contraseña.';
    } elseif ($nueva === $al['matricula']) {
        $error = 'La nueva contraseña no puede ser igual a tu matrícula.';
    } else {
        $hash = password_hash($nueva, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE alumnos SET password=?, debe_cambiar_password=0, password_actualizada_at=NOW() WHERE id=?");
        $upd->execute([$hash, $al['id']]);
        mensaje_flash('success', '✅ Contraseña actualizada correctamente.');
        redirect('encuesta.php');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Actualizar contraseña – <?= APP_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
<div class="encuesta-container">
    <div class="card">
        <h2>🔒 Actualiza tu contraseña</h2>
    <p class="subtitle">Por seguridad, debes establecer una contraseña propia.</p>

    <div class="alert-info-box">Tu contraseña actual (temporal) es tu matrícula. Elige una nueva de al menos 8 caracteres.</div>

    <?php if ($error): ?><div class="error-msg"><?= limpiar($error) ?></div><?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Contraseña actual</label>
            <input type="password" name="password_actual" required autofocus>
        </div>
        <div class="form-group">
            <label>Nueva contraseña</label>
            <input type="password" name="password_nueva" required minlength="8">
        </div>
        <div class="form-group">
            <label>Confirmar nueva contraseña</label>
            <input type="password" name="password_confirmar" required minlength="8">
        </div>
        <button type="submit" class="btn btn-primary">Guardar nueva contraseña</button>
    </form>
</div>
</body>
</html>

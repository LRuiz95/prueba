<?php
require_once 'config/app.php';
$periodo_eval = periodo_evaluacion_abierto($pdo);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= APP_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>
<div class="welcome-card">
    <div class="logo">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#f97316" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
    </div>
    <h1>Sistema de Evaluación Docente</h1>
    <p class="subtitle">Tu opinión es confidencial y ayuda a mejorar la calidad educativa.</p>

    <?php if ($periodo_eval): ?>
        <div class="periodo-badge">
            ✅ Periodo de evaluación abierto: <?= limpiar($periodo_eval['periodo_nombre']) ?>
        </div>
    <?php else: ?>
        <div class="periodo-badge closed">
            ⚠️ Actualmente no hay periodo de evaluación abierto.
        </div>
    <?php endif; ?>

    <?php if (esta_logueado()): ?>
        <a href="<?= es_admin() ? 'admin/dashboard.php' : 'encuesta.php' ?>" class="btn btn-primary">Ingresar al sistema</a>
<a href="admin/logout.php" class="btn btn-secondary">Cerrar sesión</a>
    <?php else: ?>
        <a href="login.php" class="btn-primary">Iniciar sesión</a>
    <?php endif; ?>
</div>
</body>
</html>



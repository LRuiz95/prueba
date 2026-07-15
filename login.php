<?php
require_once 'config/app.php';
if (esta_logueado()) redirect(es_admin() ? 'admin/dashboard.php' : 'encuesta.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    if (isset($_POST['matricula'])) {
        $mat = trim($_POST['matricula']);
        $pass = $_POST['password'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM alumnos WHERE matricula=? AND activo=1");
        $stmt->execute([$mat]);
        $al = $stmt->fetch();

        if ($al) {
            $passwordValida = false;

            if (!empty($al['password'])) {
                // El alumno ya tiene contraseña propia establecida
                $passwordValida = password_verify($pass, $al['password']);
            } else {
                // Primer ingreso: la contraseña temporal es su propia matrícula
                $passwordValida = hash_equals($al['matricula'], $pass);
                if ($passwordValida) {
                    // Guardamos el hash de la matrícula como password actual
                    // y marcamos que debe cambiarla antes de continuar.
                    $hash = password_hash($al['matricula'], PASSWORD_DEFAULT);
                    $upd = $pdo->prepare("UPDATE alumnos SET password=?, debe_cambiar_password=1 WHERE id=?");
                    $upd->execute([$hash, $al['id']]);
                }
            }

            if ($passwordValida) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $al['id'];
                $_SESSION['user_tipo'] = 'alumno';
                $_SESSION['user_matricula'] = $al['matricula'];
                $_SESSION['user_nombre'] = $al['nombre'] ?? $al['matricula'];
                redirect('encuesta.php');
            } else {
                $error = 'Matrícula o contraseña incorrecta.';
            }
        } else {
            $error = 'Matrícula o contraseña incorrecta.';
        }
    }

    if (isset($_POST['es_admin'])) {
        $usr = trim($_POST['usuario']);
        $pass = $_POST['password_admin'];
        $stmt = $pdo->prepare("SELECT * FROM usuarios_admin WHERE usuario=? AND activo=1");
        $stmt->execute([$usr]);
        $ad = $stmt->fetch();
        if ($ad && password_verify($pass, $ad['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $ad['id'];
            $_SESSION['user_tipo'] = 'admin';
            $_SESSION['user_nombre'] = $ad['nombre'];
            redirect('admin/dashboard.php');
        } else { $error = 'Credenciales de administrador incorrectas.'; }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Iniciar Sesión – Evaluación Docente</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
<div class="login-card">
    <div class="logo">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#f97316" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        <h2>Evaluación Docente</h2>
    </div>
    <?php if ($error): ?><div class="error-msg"><?= limpiar($error) ?></div><?php endif; ?>

    <div class="tabs">
        <button class="tab active" type="button" onclick="switchTab('alumno')">Alumno</button>
        <button class="tab" type="button" onclick="switchTab('admin')">Administrador</button>
    </div>

    <div id="tab-alumno" class="tab-content active">
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>Matrícula</label>
                <input type="text" name="matricula" placeholder="Ingresa tu matrícula" required>
            </div>
            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" name="password" placeholder="Tu contraseña" required>
                <div class="form-hint">¿Primera vez? Tu contraseña inicial es tu propia matrícula.</div>
            </div>
            <button type="submit" class="btn btn-primary">Ingresar al sistema</button>
        </form>
    </div>

    <div id="tab-admin" class="tab-content">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="es_admin" value="1">
            <div class="form-group">
                <label>Usuario</label>
                <input type="text" name="usuario" placeholder="Nombre de administrador" required>
            </div>
            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" name="password_admin" required>
            </div>
            <button type="submit" class="btn btn-primary">Ingresar al panel</button>
        </form>
    </div>

    <div class="back-link">
        <a href="index.php">← Volver al inicio</a>
    </div>
</div>
<script>
document.querySelectorAll('.tab').forEach((btn, idx) => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.getElementById('tab-' + (idx === 0 ? 'alumno' : 'admin')).classList.add('active');
    });
});
function switchTab(type) {
    const idx = type === 'alumno' ? 0 : 1;
    document.querySelectorAll('.tab')[idx].click();
}
</script>
</body>
</html>

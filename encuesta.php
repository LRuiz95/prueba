<?php
require_once 'config/app.php';
requiere_login();
if (!es_alumno()) redirect('admin/dashboard.php');
requiere_password_actualizada($pdo);

$periodos_abiertos = $pdo->query("
    SELECT pe.id AS periodo_eval_id, pe.periodo_academico_id, pe.encuesta_id,
           pa.nombre AS periodo_nombre, pa.inicial, pa.numero_periodo,
           pe.fecha_inicio, pe.fecha_fin, e.nombre AS encuesta_nombre
    FROM periodos_evaluacion pe
    JOIN periodos_academicos pa ON pe.periodo_academico_id = pa.id
    LEFT JOIN encuestas e ON pe.encuesta_id = e.id
    WHERE pe.abierto = 1 AND NOW() BETWEEN pe.fecha_inicio AND pe.fecha_fin
    ORDER BY pe.fecha_inicio DESC
")->fetchAll();

if (empty($periodos_abiertos)) {
    mensaje_flash('error', 'No hay ningún periodo de evaluación abierto actualmente.');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['periodo_eval_id'])) {
    $periodo_seleccionado = (int)$_POST['periodo_eval_id'];
} elseif (isset($_GET['periodo_eval_id'])) {
    $periodo_seleccionado = (int)$_GET['periodo_eval_id'];
} else {
    $periodo_seleccionado = $periodos_abiertos[0]['periodo_eval_id'];
}

$periodo_actual = null;
foreach ($periodos_abiertos as $p) {
    if ($p['periodo_eval_id'] == $periodo_seleccionado) { $periodo_actual = $p; break; }
}
if (!$periodo_actual) {
    mensaje_flash('error', 'Periodo no válido.');
    redirect('encuesta.php');
}
if (!$periodo_actual['encuesta_id']) {
    mensaje_flash('error', 'Este periodo no tiene una encuesta configurada. Contacta al administrador.');
    redirect('index.php');
}

$estructura_encuesta = obtener_estructura_encuesta($pdo, $periodo_actual['encuesta_id']);
if (!$estructura_encuesta || empty($estructura_encuesta['categorias'])) {
    mensaje_flash('error', 'La encuesta de este periodo aún no tiene preguntas configuradas.');
    redirect('index.php');
}

$matricula = $_SESSION['user_matricula'];
$materias = materias_alumno($pdo, $matricula, $periodo_actual['periodo_academico_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profesor_id'])) {
    csrf_verificar();
    $prof_id = (int)$_POST['profesor_id'];
    $asig_id = (int)$_POST['asignatura_id'];
    $comentarios = trim($_POST['comentarios'] ?? '');

    if (ya_evaluo_profesor($pdo, $_SESSION['user_id'], $prof_id, $periodo_actual['periodo_academico_id'])) {
        mensaje_flash('error', 'Ya has evaluado a este profesor en este periodo.');
        redirect("encuesta.php?periodo_eval_id=$periodo_seleccionado");
    }

    $validacion = validar_respuestas_post($estructura_encuesta, $_POST);
    if (!$validacion['ok']) {
        mensaje_flash('error', $validacion['error']);
        redirect("encuesta.php?periodo_eval_id=$periodo_seleccionado");
    }

    $calculo = calcular_calificacion_encuesta($estructura_encuesta, $validacion['respuestas']);
    $calificacionFinal = convertir_a_escala($calculo['global_bruto'], $calculo['valor_maximo_posible'], (float)$estructura_encuesta['escala_maxima']);

    $stmt = $pdo->prepare("SELECT grupo_id FROM alumno_asignatura_profesor WHERE alumno_id=? AND profesor_id=? AND asignatura_id=? AND periodo_academico_id=? LIMIT 1");
    $stmt->execute([$_SESSION['user_id'], $prof_id, $asig_id, $periodo_actual['periodo_academico_id']]);
    $grupo_id = $stmt->fetchColumn();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO evaluaciones
                (alumno_id, profesor_id, asignatura_id, grupo_id, periodo_academico_id, encuesta_id, comentarios, promedio, detalle_categorias)
            VALUES (?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $_SESSION['user_id'], $prof_id, $asig_id, $grupo_id, $periodo_actual['periodo_academico_id'],
            $periodo_actual['encuesta_id'], $comentarios, $calculo['global_bruto'],
            json_encode($calculo['detalle_categorias'], JSON_UNESCAPED_UNICODE)
        ]);
        $eval_id = $pdo->lastInsertId();

        $stmtR = $pdo->prepare("INSERT INTO respuestas (evaluacion_id, pregunta_id, encuesta_pregunta_id, encuesta_opcion_id, respuesta, valor) VALUES (?, NULL, ?, ?, ?, ?)");
        foreach ($estructura_encuesta['categorias'] as $cat) {
            foreach ($cat['preguntas'] as $preg) {
                $respuesta = $validacion['respuestas'][$preg['id']] ?? null;
                $tipo = $preg['tipo_respuesta'] ?? 'opciones_personalizadas';
                if ($tipo === 'ayuda') continue;

                if (in_array($tipo, ['opciones_personalizadas', 'binaria'])) {
                    $opcionId = $respuesta;
                    foreach ($preg['opciones'] as $op) {
                        if ((int)$op['id'] === (int)$opcionId) {
                            // (evaluacion_id, encuesta_pregunta_id, encuesta_opcion_id, respuesta, valor)
                            $stmtR->execute([$eval_id, $preg['id'], $opcionId, $op['texto_opcion'], (float)$op['valor']]);
                            break;
                        }
                    }
                } elseif ($tipo === 'texto_libre') {
                    $texto = $respuesta['valor'] ?? '';
                    // BUGFIX: encuesta_opcion_id debe quedar NULL (no hay opción elegida);
                    // antes se guardaba el propio texto ahí, rompiendo la FK/joins.
                    $stmtR->execute([$eval_id, $preg['id'], null, $texto, null]);
                } elseif ($tipo === 'numerica') {
                    $num = $respuesta['valor'] ?? 0;
                    // BUGFIX: idem, encuesta_opcion_id debe ser NULL, no el número como string.
                    $stmtR->execute([$eval_id, $preg['id'], null, (string)$num, (float)$num]);
                }
            }
        }

        $pdo->commit();
        mensaje_flash('success', '✅ Evaluación enviada. Calificación: ' . number_format($calificacionFinal, 2) . ' / ' . number_format((float)$estructura_encuesta['escala_maxima'], 0));
        redirect("encuesta.php?periodo_eval_id=$periodo_seleccionado");
    } catch (Exception $e) {
        $pdo->rollBack();
        mensaje_flash('error', 'Error al guardar: ' . $e->getMessage());
        redirect("encuesta.php?periodo_eval_id=$periodo_seleccionado");
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mis evaluaciones – <?= APP_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>
<nav class="student-nav">
    <div class="brand">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f97316" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        Evaluación Docente
    </div>
    <a href="admin/logout.php" class="logout-btn">Cerrar sesión</a>
</nav>

<div class="container">
    <?= mostrar_flash() ?>

    <div class="card">
        <h2>Periodo de evaluación</h2>
        <form method="get" id="formPeriodo">
            <select name="periodo_eval_id" class="form-select" onchange="document.getElementById('formPeriodo').submit()">
                <?php foreach ($periodos_abiertos as $p): ?>
                    <option value="<?= $p['periodo_eval_id'] ?>" <?= $p['periodo_eval_id'] == $periodo_seleccionado ? 'selected' : '' ?>>
                        <?= limpiar($p['periodo_nombre']) ?> (<?= date('d/m/Y', strtotime($p['fecha_inicio'])) ?> - <?= date('d/m/Y', strtotime($p['fecha_fin'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <p class="info-note">
            Encuesta de este periodo: <span class="badge badge-info"><?= limpiar($periodo_actual['encuesta_nombre'] ?? 'N/D') ?></span>
        </p>
    </div>

    <div class="card">
        <h2>📋 Docentes a evaluar</h2>
        <?php if (empty($materias)): ?>
            <div class="alert alert-error">No tienes docentes asignados en este periodo.</div>
        <?php else: ?>
            <div class="tabla-desktop">
                <table>
                    <thead><tr><th>Materia</th><th>Profesor</th><th>Grupo</th><th>Estado</th><th>Acción</th></tr></thead>
                    <tbody>
                    <?php foreach ($materias as $m): $evaluado = ya_evaluo_profesor($pdo, $_SESSION['user_id'], $m['profesor_id'], $periodo_actual['periodo_academico_id']); ?>
                        <tr>
                            <td><strong><?= limpiar($m['nombre_asignatura']) ?></strong><br><small class="small-muted"><?= limpiar($m['clave']) ?></small></td>
                            <td><?= limpiar($m['nombre_completo']) ?><br><small class="small-muted"><?= limpiar($m['clave_profesor']) ?></small></td>
                            <td><?= limpiar($m['codigo_grupo']) ?></td>
                            <td><?= $evaluado ? '<span class="badge badge-success">Evaluado</span>' : '<span class="badge badge-warning">Pendiente</span>' ?></td>
                            <td><?php if (!$evaluado): ?><button type="button" class="btn-evaluar" data-profesor-id="<?= (int)$m['profesor_id'] ?>" data-asignatura-id="<?= (int)$m['asignatura_id'] ?>" data-profesor-nombre="<?= htmlspecialchars($m['nombre_completo'], ENT_QUOTES, 'UTF-8') ?>" data-materia-nombre="<?= htmlspecialchars($m['nombre_asignatura'], ENT_QUOTES, 'UTF-8') ?>">Evaluar</button><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="tarjetas-container">
                <?php foreach ($materias as $m): $evaluado = ya_evaluo_profesor($pdo, $_SESSION['user_id'], $m['profesor_id'], $periodo_actual['periodo_academico_id']); ?>
                <div class="tarjeta-docente">
                    <div class="materia-nombre"><?= limpiar($m['nombre_asignatura']) ?></div>
                    <div class="materia-clave"><?= limpiar($m['clave']) ?></div>
                    <div class="profesor"><?= limpiar($m['nombre_completo']) ?></div>
                    <div class="profesor-clave"><?= limpiar($m['clave_profesor']) ?></div>
                    <div class="grupo">Grupo: <?= limpiar($m['codigo_grupo']) ?></div>
                    <div class="estado"><?= $evaluado ? '<span class="badge badge-success">Evaluado</span>' : '<span class="badge badge-warning">Pendiente</span>' ?></div>
                    <?php if (!$evaluado): ?>
                        <button type="button" class="btn-evaluar" data-profesor-id="<?= (int)$m['profesor_id'] ?>" data-asignatura-id="<?= (int)$m['asignatura_id'] ?>" data-profesor-nombre="<?= htmlspecialchars($m['nombre_completo'], ENT_QUOTES, 'UTF-8') ?>" data-materia-nombre="<?= htmlspecialchars($m['nombre_asignatura'], ENT_QUOTES, 'UTF-8') ?>">Evaluar</button>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="modalEvaluacion">
    <div class="modal-content">
        <button class="modal-close" onclick="cerrarModal()">&times;</button>
        <h2 class="modal-title">Evaluando a <span id="modalProfesor"></span></h2>
        <p class="modal-subtitle">Materia: <span id="modalMateria"></span></p>
        <form method="post" id="formEvaluacion">
            <?= csrf_field() ?>
            <input type="hidden" name="periodo_eval_id" value="<?= (int)$periodo_seleccionado ?>">
            <input type="hidden" name="profesor_id" id="modalProfId">
            <input type="hidden" name="asignatura_id" id="modalAsigId">

            <div class="survey-guidance">
                <span class="badge badge-info">Las preguntas marcadas como obligatorias deben responderse.</span>
                <span class="badge badge-warning">El comentario es obligatorio.</span>
            </div>

            <?php foreach ($estructura_encuesta['categorias'] as $cat): $n = 0; ?>
                <div class="categoria-titulo"><?= limpiar($cat['nombre']) ?></div>
                <?php foreach ($cat['preguntas'] as $preg): $n++; ?>
                    <?php $tipo = $preg['tipo_respuesta'] ?? 'opciones_personalizadas'; ?>
                    <?php if ($tipo === 'ayuda') continue; ?>
                    <div class="pregunta-bloque">
                        <div class="pregunta-header">
                            <p class="pregunta-texto"><?= $n ?>. <?= limpiar($preg['texto']) ?></p>
                            <?php if (!empty($preg['obligatoria'])): ?>
                                <span class="badge badge-required">Obligatoria</span>
                            <?php else: ?>
                                <span class="badge badge-optional">Opcional</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($preg['ayuda'])): ?>
                            <p class="helper-text">💡 <?= limpiar($preg['ayuda']) ?></p>
                        <?php endif; ?>

                        <?php if (in_array($tipo, ['opciones_personalizadas', 'binaria'])): ?>
                            <div class="evaluacion-chip-group">
                                <?php foreach ($preg['opciones'] as $op): ?>
                                    <label class="chip-label">
                                        <input type="radio" name="preg_<?= (int)$preg['id'] ?>" value="<?= (int)$op['id'] ?>" <?= !empty($preg['obligatoria']) ? 'required' : '' ?>>
                                        <span><?= limpiar($op['texto_opcion']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($tipo === 'texto_libre'): ?>
                            <textarea name="preg_<?= (int)$preg['id'] ?>" rows="3" style="width:100%; padding:10px; border:1px solid #E2E8F0; border-radius:6px;" placeholder="Escribe tu respuesta..." <?= !empty($preg['obligatoria']) ? 'required' : '' ?>></textarea>
                        <?php elseif ($tipo === 'numerica'): ?>
                            <input type="number" name="preg_<?= (int)$preg['id'] ?>" step="any" style="width:200px; padding:10px; border:1px solid #E2E8F0; border-radius:6px;" placeholder="Ingresa un número" <?= !empty($preg['obligatoria']) ? 'required' : '' ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <div class="comentario-bloque">
                <label class="comentario-label">Comentario (obligatorio)</label>
                <textarea name="comentarios" rows="4" required placeholder="Comparte una opinión breve y constructiva..."></textarea>
                <p class="helper-text">Tus comentarios ayudan a mejorar la evaluación y hacen más útil la retroalimentación.</p>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Enviar evaluación</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModal(profId, asigId, nombreProf, nombreMat) {
    const modal = document.getElementById('modalEvaluacion');
    if (!modal) return;
    document.getElementById('modalProfId').value = profId;
    document.getElementById('modalAsigId').value = asigId;
    document.getElementById('modalProfesor').textContent = nombreProf;
    document.getElementById('modalMateria').textContent = nombreMat;
    document.querySelectorAll('#formEvaluacion input[type="radio"]').forEach(r => r.checked = false);
    modal.classList.add('active');
}
function cerrarModal() {
    const modal = document.getElementById('modalEvaluacion');
    if (modal) modal.classList.remove('active');
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-evaluar').forEach(function(button) {
        button.addEventListener('click', function() {
            abrirModal(
                this.getAttribute('data-profesor-id'),
                this.getAttribute('data-asignatura-id'),
                this.getAttribute('data-profesor-nombre'),
                this.getAttribute('data-materia-nombre')
            );
        });
    });
});
window.addEventListener('click', function(event) {
    const modal = document.getElementById('modalEvaluacion');
    if (modal && event.target === modal) cerrarModal();
});
</script>
</body>
</html>
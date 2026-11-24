<?php
require_once 'config.php';

$mensaje = "";
$tipo_alerta = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'registrar_vendedor') {
    $nombre_completo = trim($_POST['nombre_completo'] ?? '');
    $usuario         = trim($_POST['usuario'] ?? '');
    $clave           = $_POST['clave'] ?? '';
    $clave_conf      = $_POST['clave_confirmar'] ?? '';
    $id_sucursal     = (int)($_POST['id_sucursal'] ?? 0);

    try {
        if (strlen($usuario) < 4) {
            throw new Exception("El nombre de usuario debe tener al menos 4 caracteres.");
        }
        if (strlen($clave) < 6) {
            throw new Exception("La contraseña debe tener al menos 6 caracteres.");
        }
        if ($clave !== $clave_conf) {
            throw new Exception("Las contraseñas no coinciden.");
        }
        if ($id_sucursal <= 0) {
            throw new Exception("Debes seleccionar una sucursal válida.");
        }

        // Verificar que no exista en `administradores` ni en `vendedores`
        $stmtAdm = $pdo->prepare("SELECT id_admin FROM administradores WHERE usuario = ?");
        $stmtAdm->execute([$usuario]);
        if ($stmtAdm->fetch()) {
            throw new Exception("Ese nombre de usuario no está disponible.");
        }

        $stmtCheck = $pdo->prepare("SELECT id_vendedor FROM vendedores WHERE usuario = ?");
        $stmtCheck->execute([$usuario]);
        if ($stmtCheck->fetch()) {
            throw new Exception("El usuario '$usuario' ya está registrado.");
        }

        $hash = password_hash($clave, PASSWORD_DEFAULT);
        $stmtIns = $pdo->prepare("
            INSERT INTO vendedores (nombre_completo, usuario, password_hash, id_sucursal, activo)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmtIns->execute([$nombre_completo, $usuario, $hash, $id_sucursal]);

        $mensaje = "¡Vendedor '$nombre_completo' registrado con éxito! Ya puede iniciar sesión.";
    } catch (Exception $e) {
        $mensaje = $e->getMessage();
        $tipo_alerta = "error";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'toggle_vendedor' && esAdmin()) {
    $id_v = (int)$_POST['id_vendedor'];
    $pdo->prepare("UPDATE vendedores SET activo = IF(activo=1, 0, 1) WHERE id_vendedor = ?")->execute([$id_v]);
    $mensaje = "Estado del vendedor actualizado.";
}

$sucursales = $pdo->query("SELECT * FROM sucursales ORDER BY departamento, nombre")->fetchAll();
$vendedores = $pdo->query("
    SELECT v.*, s.nombre AS sucursal_nombre, s.departamento
    FROM vendedores v
    LEFT JOIN sucursales s ON v.id_sucursal = s.id_sucursal
    ORDER BY v.id_vendedor DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Vendedores - ToysWa Bolivia</title>
    <link rel="stylesheet" href="estilos.css">
    <link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0d6efd">
<meta name="apple-mobile-web-app-capable" content="yes">

</head>
<body>
    <header class="navbar">
        <div class="container navbar-content">
            <a href="index.php" class="logo">🧸 ToysWa Bolivia</a>
            <div class="nav-acciones">
                <a href="index.php" class="btn-nav">🏪 Tienda</a>
                <?php if (estaLogueado()): ?>
                    <a href="registro_juguetes.php" class="btn-nav">📦 Juguetes</a>
                    <a href="login.php?logout=1" class="btn-nav">🚪 Salir</a>
                <?php else: ?>
                    <a href="login.php" class="btn-nav">🔐 Iniciar Sesión</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="container">
        <?php if ($mensaje): ?>
            <div class="alerta <?= $tipo_alerta === 'error' ? 'error' : '' ?>">
                <span><?= htmlspecialchars($mensaje) ?></span>
                <button type="button" class="btn-cerrar-alerta" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <div class="panel-box">
            <h2 style="margin-bottom: 18px;">👤 Registro de Vendedores</h2>
            <div class="admin-grid-2">
                <div class="card-admin">
                    <h3>➕ Crear Cuenta de Vendedor</h3>
                    <form method="POST" action="registro_vendedor.php">
                        <input type="hidden" name="accion" value="registrar_vendedor">
                        <div class="campo">
                            <label>Nombre Completo *</label>
                            <input type="text" name="nombre_completo" required placeholder="Ej: María Gómez">
                        </div>
                        <div class="campo">
                            <label>Usuario de Acceso *</label>
                            <input type="text" name="usuario" required placeholder="Ej: maria.gomez" minlength="4">
                        </div>
                        <div class="campo">
                            <label>Sucursal Asignada *</label>
                            <select name="id_sucursal" required>
                                <option value="">Seleccione sucursal...</option>
                                <?php foreach ($sucursales as $suc): ?>
                                    <option value="<?= $suc['id_sucursal'] ?>">
                                        <?= htmlspecialchars($suc['departamento'] . ' - ' . $suc['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fila-dos-col">
                            <div class="campo">
                                <label>Contraseña *</label>
                                <input type="password" name="clave" required minlength="6" placeholder="Mín. 6 caracteres">
                            </div>
                            <div class="campo">
                                <label>Confirmar *</label>
                                <input type="password" name="clave_confirmar" required minlength="6" placeholder="Repetir clave">
                            </div>
                        </div>
                        <button type="submit" class="btn-confirmar" style="width:100%;">Registrar Vendedor</button>
                    </form>
                </div>

                <div class="card-admin">
                    <h3>📋 Vendedores Registrados</h3>
                    <div class="tabla-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Usuario</th>
                                    <th>Sucursal</th>
                                    <th>Estado</th>
                                    <?php if (esAdmin()): ?><th>Acción</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($vendedores)): ?>
                                    <tr><td colspan="5" style="text-align:center; color:#6c757d;">No hay vendedores registrados aún.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($vendedores as $v): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($v['nombre_completo']) ?></strong></td>
                                            <td><?= htmlspecialchars($v['usuario']) ?></td>
                                            <td><?= htmlspecialchars(($v['departamento'] ?? 'Sin asignar') . ' - ' . ($v['sucursal_nombre'] ?? '')) ?></td>
                                            <td>
                                                <span class="badge-estado <?= $v['activo'] ? 'estado-Completada' : 'estado-Anulada' ?>">
                                                    <?= $v['activo'] ? 'Activo' : 'Inactivo' ?>
                                                </span>
                                            </td>
                                            <?php if (esAdmin()): ?>
                                                <td>
                                                    <form method="POST">
                                                        <input type="hidden" name="accion" value="toggle_vendedor">
                                                        <input type="hidden" name="id_vendedor" value="<?= $v['id_vendedor'] ?>">
                                                        <button type="submit" class="btn-sm <?= $v['activo'] ? 'btn-anular' : 'btn-pagar' ?>">
                                                            <?= $v['activo'] ? 'Desactivar' : 'Activar' ?>
                                                        </button>
                                                    </form>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>

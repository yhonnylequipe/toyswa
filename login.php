<?php
require_once 'config.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if (estaLogueado()) {
    header("Location: registro_juguetes.php");
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $clave   = $_POST['clave'] ?? '';

    // 1. Buscar en la tabla `administradores` de tu BD
    $stmtAdmin = $pdo->prepare("SELECT * FROM administradores WHERE usuario = ? AND activo = 1 LIMIT 1");
    $stmtAdmin->execute([$usuario]);
    $adminDb = $stmtAdmin->fetch();

    if ($adminDb && password_verify($clave, $adminDb['password_hash'])) {
        $_SESSION['usuario_id']     = $adminDb['id_admin'];
        $_SESSION['usuario_nombre'] = $adminDb['nombre'] ?: $adminDb['usuario'];
        $_SESSION['usuario_rol']    = 'admin';
        $_SESSION['usuario_suc']    = null;
        header("Location: registro_juguetes.php");
        exit;
    }

    // 2. Buscar en la tabla `vendedores`
    $stmtVend = $pdo->prepare("
        SELECT v.*, s.nombre AS sucursal_nombre, s.departamento
        FROM vendedores v
        LEFT JOIN sucursales s ON v.id_sucursal = s.id_sucursal
        WHERE v.usuario = ? AND v.activo = 1
        LIMIT 1
    ");
    $stmtVend->execute([$usuario]);
    $vendDb = $stmtVend->fetch();

    if ($vendDb && password_verify($clave, $vendDb['password_hash'])) {
        $_SESSION['usuario_id']       = $vendDb['id_vendedor'];
        $_SESSION['usuario_nombre']   = $vendDb['nombre_completo'];
        $_SESSION['usuario_rol']      = 'vendedor';
        $_SESSION['usuario_suc']      = $vendDb['id_sucursal'];
        $_SESSION['usuario_suc_name'] = ($vendDb['departamento'] ?? '') . ' - ' . ($vendDb['sucursal_nombre'] ?? '');
        header("Location: registro_juguetes.php");
        exit;
    }

    $error = "Usuario o contraseña incorrectos, o cuenta desactivada.";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - ToysWa Bolivia</title>
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
                <a href="index.php" class="btn-nav">🏪 Volver a la Tienda</a>
                <a href="registro_vendedor.php" class="btn-nav">👤 Registrar Vendedor</a>
            </div>
        </div>
    </header>

    <main class="container">
        <div class="auth-card">
            <h2>🔐 Iniciar Sesión</h2>
            <p>Ingresa con tu cuenta de Administrador o Vendedor de ToysWa.</p>

            <?php if ($error): ?>
                <div class="alerta error">
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="campo">
                    <label>Usuario *</label>
                    <input type="text" name="usuario" required placeholder="Ej: admin o vendedor1" autofocus>
                </div>
                <div class="campo">
                    <label>Contraseña *</label>
                    <input type="password" name="clave" required placeholder="••••••••">
                </div>
                <button type="submit" class="btn-confirmar" style="width:100%; margin-top: 8px;">Ingresar al Sistema</button>
            </form>

            <hr class="separador">
            <p style="text-align:center; margin-bottom:0;">
                ¿Eres vendedor nuevo? <a href="registro_vendedor.php" style="color:var(--primary); font-weight:600;">Regístrate aquí</a>
            </p>
        </div>
    </main>
</body>
    <nav class="app-bottom-nav">
    <a href="index.php" class="app-tab <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'activo' : '' ?>">
        <span class="tab-icon">🏪</span>
        <span>Catálogo</span>
    </a>
    <?php if (estaLogueado()): ?>
        <a href="registro_juguetes.php" class="app-tab <?= basename($_SERVER['PHP_SELF']) === 'registro_juguetes.php' ? 'activo' : '' ?>">
            <span class="tab-icon">📦</span>
            <span>Juguetes</span>
        </a>
        <a href="registro_vendedor.php" class="app-tab <?= basename($_SERVER['PHP_SELF']) === 'registro_vendedor.php' ? 'activo' : '' ?>">
            <span class="tab-icon">👥</span>
            <span>Vendedores</span>
        </a>
        <a href="login.php?logout=1" class="app-tab">
            <span class="tab-icon">🚪</span>
            <span>Salir</span>
        </a>
    <?php else: ?>
        <a href="registro_vendedor.php" class="app-tab <?= basename($_SERVER['PHP_SELF']) === 'registro_vendedor.php' ? 'activo' : '' ?>">
            <span class="tab-icon">👤</span>
            <span>Registro</span>
        </a>
        <a href="login.php" class="app-tab <?= basename($_SERVER['PHP_SELF']) === 'login.php' ? 'activo' : '' ?>">
            <span class="tab-icon">🔐</span>
            <span>Ingresar</span>
        </a>
    <?php endif; ?>
</nav>

<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js');
}
</script>

</html>

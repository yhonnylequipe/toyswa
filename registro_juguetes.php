<?php
require_once 'config.php';
exigirAcceso();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mensaje = "";
$tipo_alerta = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Error de validación de seguridad (CSRF). Recarga la página.");
    }

    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar_juguete') {
        $ruta_imagen = null;
        try {
            $nombre = trim($_POST['nombre']);
            $edad_minima = (int)$_POST['edad_minima'];
            $edad_maxima = (int)$_POST['edad_maxima'];
            $precio_compra = (float)$_POST['precio_compra'];
            $precio_venta = (float)$_POST['precio_venta'];

            if ($nombre === '') throw new Exception("El nombre es obligatorio.");
            if ($edad_maxima < $edad_minima) throw new Exception("La edad máxima no puede ser menor a la mínima.");
            if ($precio_venta <= 0) throw new Exception("El precio de venta debe ser mayor a 0.");

            $ruta_imagen = procesarSubidaFoto('foto');
            $pdo->beginTransaction();

            $codigo_barras = trim($_POST['codigo_barras']) ?: null;
            $icono = $_POST['icono'] ?? '🧸';
            $marca = trim($_POST['marca']);
            $id_categoria = (int)$_POST['id_categoria'];
            $id_sucursal = esVendedor() ? (int)$_SESSION['usuario_suc'] : (int)$_POST['id_sucursal_inicial'];
            $stock_inicial = max(0, (int)$_POST['stock_inicial']);

            $stmtIns = $pdo->prepare("
                INSERT INTO productos (codigo_barras, nombre, icono, imagen, marca, id_categoria, edad_minima, edad_maxima, precio_compra, precio_venta, activo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmtIns->execute([$codigo_barras, $nombre, $icono, $ruta_imagen, $marca, $id_categoria, $edad_minima, $edad_maxima, $precio_compra, $precio_venta]);
            $nuevo_id = $pdo->lastInsertId();

            if ($id_sucursal > 0) {
                $pdo->prepare("INSERT INTO inventario (id_sucursal, id_producto, stock_actual) VALUES (?, ?, ?)")
                    ->execute([$id_sucursal, $nuevo_id, $stock_inicial]);
            }

            $pdo->commit();
            $mensaje = "Juguete '$nombre' registrado correctamente en el catálogo.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($ruta_imagen && file_exists($ruta_imagen)) @unlink($ruta_imagen);
            $mensaje = "Error al agregar juguete: " . $e->getMessage();
            $tipo_alerta = "error";
        }
    }

    if ($accion === 'actualizar_producto') {
        $id_producto = (int)$_POST['id_producto'];
        $precio_venta = (float)$_POST['precio_venta'];
        $id_sucursal = esVendedor() ? (int)$_SESSION['usuario_suc'] : (int)$_POST['id_sucursal'];
        $nuevo_stock = max(0, (int)$_POST['stock_actual']);
        $nueva_imagen = null;

        try {
            if ($precio_venta <= 0) throw new Exception("El precio de venta debe ser mayor a 0.");

            $nueva_imagen = procesarSubidaFoto('foto');
            $pdo->beginTransaction();

            if ($nueva_imagen !== null) {
                $stmtOld = $pdo->prepare("SELECT imagen FROM productos WHERE id_producto = ?");
                $stmtOld->execute([$id_producto]);
                $imgAnterior = $stmtOld->fetchColumn();

                $pdo->prepare("UPDATE productos SET precio_venta = ?, imagen = ? WHERE id_producto = ?")
                    ->execute([$precio_venta, $nueva_imagen, $id_producto]);

                if ($imgAnterior && file_exists($imgAnterior)) {
                    @unlink($imgAnterior);
                }
            } else {
                $pdo->prepare("UPDATE productos SET precio_venta = ? WHERE id_producto = ?")
                    ->execute([$precio_venta, $id_producto]);
            }

            if ($id_sucursal > 0) {
                $pdo->prepare("
                    INSERT INTO inventario (id_sucursal, id_producto, stock_actual)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE stock_actual = VALUES(stock_actual)
                ")->execute([$id_sucursal, $id_producto, $nuevo_stock]);
            }

            $pdo->commit();
            $mensaje = "Producto, precio y stock actualizados correctamente.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($nueva_imagen && file_exists($nueva_imagen)) @unlink($nueva_imagen);
            $mensaje = "Error al actualizar: " . $e->getMessage();
            $tipo_alerta = "error";
        }
    }
}

$categorias = $pdo->query("SELECT * FROM categorias ORDER BY nombre")->fetchAll();

if (esVendedor()) {
    $stmtSuc = $pdo->prepare("SELECT * FROM sucursales WHERE id_sucursal = ?");
    $stmtSuc->execute([$_SESSION['usuario_suc']]);
    $sucursales = $stmtSuc->fetchAll();
} else {
    $sucursales = $pdo->query("SELECT * FROM sucursales ORDER BY departamento, nombre")->fetchAll();
}

$productos = $pdo->query("
    SELECT p.*, COALESCE(SUM(i.stock_actual), 0) AS stock_total
    FROM productos p
    LEFT JOIN inventario i ON p.id_producto = i.id_producto
    WHERE p.activo = 1
    GROUP BY p.id_producto
    ORDER BY p.id_producto DESC
")->fetchAll();

$invRows = $pdo->query("SELECT id_producto, id_sucursal, stock_actual FROM inventario")->fetchAll();
$stockMapa = [];
foreach ($invRows as $r) {
    $stockMapa[$r['id_producto']][$r['id_sucursal']] = (int)$r['stock_actual'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Juguetes - ToysWa Bolivia</title>
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
                <span style="font-size:0.85rem;">Hola, <strong><?= htmlspecialchars($_SESSION['usuario_nombre']) ?></strong> (<?= strtoupper($_SESSION['usuario_rol']) ?>)</span>
                <a href="index.php" class="btn-nav">🏪 Ver Tienda / Reservas</a>
                <a href="registro_juguetes.php" class="btn-nav activo">📦 Registro Juguetes</a>
                <?php if (esAdmin()): ?>
                    <a href="registro_vendedor.php" class="btn-nav">👤 Vendedores</a>
                <?php endif; ?>
                <a href="login.php?logout=1" class="btn-nav">🚪 Cerrar Sesión</a>
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
            <h2 style="margin-bottom: 18px;">📦 Gestión y Registro de Juguetes</h2>
            <div class="admin-grid-2">
                <div class="card-admin">
                    <h3>➕ Agregar Nuevo Juguete</h3>
                    <form method="POST" action="registro_juguetes.php" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="accion" value="agregar_juguete">
                        <div class="fila-dos-col">
                            <div class="campo" style="flex:2;">
                                <label>Nombre del Juguete *</label>
                                <input type="text" name="nombre" required placeholder="Ej: Robot Solar">
                            </div>
                            <div class="campo" style="flex:1;">
                                <label>Icono</label>
                                <select name="icono">
                                    <option value="🧸">🧸</option>
                                    <option value="🚀">🚀</option>
                                    <option value="🏎️">🏎️</option>
                                    <option value="🩺">🩺</option>
                                    <option value="🎨">🎨</option>
                                    <option value="🤖">🤖</option>
                                    <option value="⚽">⚽</option>
                                </select>
                            </div>
                        </div>
                        <div class="campo">
                            <label>📷 Foto del Juguete (JPG, PNG, WEBP - Máx. 5MB)</label>
                            <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" onchange="previsualizarFoto(this, 'preview-nuevo')">
                            <div id="preview-nuevo" class="preview-box">
                                <img src="" alt="Vista previa">
                                <small style="color: var(--primary); font-weight: 600;">Vista previa lista para subir</small>
                            </div>
                        </div>
                        <div class="fila-dos-col">
                            <div class="campo">
                                <label>Código Barras</label>
                                <input type="text" name="codigo_barras" placeholder="Opcional">
                            </div>
                            <div class="campo">
                                <label>Marca *</label>
                                <input type="text" name="marca" required placeholder="Ej: LEGO">
                            </div>
                        </div>
                        <div class="campo">
                            <label>Categoría *</label>
                            <select name="id_categoria" required>
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['id_categoria'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fila-dos-col">
                            <div class="campo">
                                <label>Edad Mín.</label>
                                <input type="number" name="edad_minima" value="3" min="0" required>
                            </div>
                            <div class="campo">
                                <label>Edad Máx.</label>
                                <input type="number" name="edad_maxima" value="12" min="1" required>
                            </div>
                        </div>
                        <div class="fila-dos-col">
                            <div class="campo">
                                <label>Precio Compra (Bs.) *</label>
                                <input type="number" step="0.01" min="0" name="precio_compra" required placeholder="100.00">
                            </div>
                            <div class="campo">
                                <label>Precio Venta (Bs.) *</label>
                                <input type="number" step="0.01" min="1" name="precio_venta" required placeholder="150.00">
                            </div>
                        </div>
                        <div class="fila-dos-col">
                            <div class="campo" style="flex:2;">
                                <label>Sucursal Stock Inicial *</label>
                                <select name="id_sucursal_inicial" required>
                                    <?php foreach ($sucursales as $suc): ?>
                                        <option value="<?= $suc['id_sucursal'] ?>">
                                            <?= htmlspecialchars($suc['departamento'] . ' - ' . $suc['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="campo" style="flex:1;">
                                <label>Cantidad *</label>
                                <input type="number" name="stock_inicial" value="10" min="0" required>
                            </div>
                        </div>
                        <button type="submit" class="btn-confirmar" style="width:100%;">Guardar Juguete</button>
                    </form>
                </div>

                <div class="card-admin">
                    <h3>✏️ Editar Fotos, Precios y Stock por Sucursal</h3>
                    <div class="tabla-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Foto</th>
                                    <th>Juguete</th>
                                    <th>Cambiar Foto</th>
                                    <th>Precio (Bs.)</th>
                                    <th>Sucursal</th>
                                    <th>Stock</th>
                                    <th>Guardar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productos as $prod): 
                                    $fId = "form-prod-" . $prod['id_producto'];
                                ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($prod['imagen']) && file_exists($prod['imagen'])): ?>
                                                <img src="<?= htmlspecialchars($prod['imagen']) ?>" alt="" class="mini-thumb">
                                            <?php else: ?>
                                                <div class="mini-emoji"><?= htmlspecialchars($prod['icono'] ?? '🧸') ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($prod['nombre']) ?></strong><br>
                                            <small style="color:#6c757d;">Total: <?= $prod['stock_total'] ?> unid.</small>
                                        </td>
                                        <td>
                                            <input type="file" form="<?= $fId ?>" name="foto" accept="image/jpeg,image/png,image/webp" class="input-file-tabla">
                                        </td>
                                        <td>
                                            <input type="number" form="<?= $fId ?>" step="0.01" min="1" name="precio_venta" class="input-tabla" value="<?= number_format($prod['precio_venta'], 2, '.', '') ?>" required>
                                        </td>
                                        <td>
                                            <select form="<?= $fId ?>" name="id_sucursal" class="select-tabla" onchange="cambiarStockInput(this, <?= $prod['id_producto'] ?>)">
                                                <?php foreach ($sucursales as $suc): ?>
                                                    <option value="<?= $suc['id_sucursal'] ?>">
                                                        <?= htmlspecialchars($suc['departamento'] . ' - ' . $suc['nombre']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <?php $primerSucId = $sucursales['id_sucursal'] ?? 0; ?>
                                            <input type="number" form="<?= $fId ?>" min="0" name="stock_actual" id="admin_stock_<?= $prod['id_producto'] ?>" class="input-tabla" style="width:60px;" value="<?= $stockMapa[$prod['id_producto']][$primerSucId] ?? 0 ?>" required>
                                        </td>
                                        <td>
                                            <button type="submit" form="<?= $fId ?>" class="btn-sm btn-guardar">Guardar</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php foreach ($productos as $prod): ?>
                            <form id="form-prod-<?= $prod['id_producto'] ?>" method="POST" action="registro_juguetes.php" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="accion" value="actualizar_producto">
                                <input type="hidden" name="id_producto" value="<?= $prod['id_producto'] ?>">
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        const inventarioSucursales = <?= json_encode($stockMapa) ?>;
        function cambiarStockInput(selectEl, prodId) {
            const sucId = selectEl.value;
            const stock = (inventarioSucursales[prodId] && inventarioSucursales[prodId][sucId]) || 0;
            document.getElementById('admin_stock_' + prodId).value = stock;
        }
        function previsualizarFoto(input, previewId) {
            const box = document.getElementById(previewId);
            const img = box.querySelector('img');
            if (input.files && input.files) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    img.src = e.target.result;
                    box.style.display = 'flex';
                };
                reader.readAsDataURL(input.files);
            } else {
                box.style.display = 'none';
            }
        }
    </script>

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
            <?php if (esAdmin()): ?>
                <a href="registro_vendedor.php" class="app-tab <?= basename($_SERVER['PHP_SELF']) === 'registro_vendedor.php' ? 'activo' : '' ?>">
                    <span class="tab-icon">👥</span>
                    <span>Vendedores</span>
                </a>
            <?php endif; ?>
            <a href="login.php?logout=1" class="app-tab">
                <span class="tab-icon">🚪</span>
                <span>Salir</span>
            </a>
        <?php else: ?>
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
</body>
</html>

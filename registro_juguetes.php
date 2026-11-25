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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Registro de Juguetes - ToysWa Bolivia</title>
    <link rel="stylesheet" href="estilos.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0d6efd">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <style>
        .nav-saludo { font-size: 0.85rem; }
        .sub-info-movil { display: none; font-size: 0.78rem; color: var(--text-muted); margin-top: 2px; }

        @media (max-width: 600px) {
            .nav-acciones .btn-nav:not(.btn-salir-movil) { display: none; }
            .nav-saludo { font-size: 0.78rem; max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .sub-info-movil { display: block; }
            .tabla-inventario th:nth-child(1),
            .tabla-inventario td:nth-child(1) { padding: 8px 6px; }
            .btn-guardar { width: 100%; padding: 8px; }
        }
    </style>
</head>
<body>
    <header class="navbar">
        <div class="container navbar-content">
            <a href="index.php" class="logo">🧸 ToysWa Bolivia</a>
            <div class="nav-acciones">
                <span class="nav-saludo">Hola, <strong><?= htmlspecialchars($_SESSION['usuario_nombre']) ?></strong> (<?= strtoupper($_SESSION['usuario_rol']) ?>)</span>
                <a href="index.php" class="btn-nav">🏪 Ver Tienda / Reservas</a>
                <a href="registro_juguetes.php" class="btn-nav activo">📦 Registro Juguetes</a>
                <?php if (esAdmin()): ?>
                    <a href="registro_vendedor.php" class="btn-nav">👤 Vendedores</a>
                <?php endif; ?>
                <a href="login.php?logout=1" class="btn-nav btn-salir-movil">🚪 Salir</a>
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
            <h2 style="margin-bottom: 18px; font-size: 1.25rem;">📦 Gestión y Registro de Juguetes</h2>
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
                                <label>Ícono</label>
                                <select name="icono">
                                    <option value="🧸">🧸 Oso</option>
                                    <option value="🚗">🚗 Auto</option>
                                    <option value="🤖">🤖 Robot</option>
                                    <option value="🪆">🪆 Muñeca</option>
                                    <option value="🧱">🧱 Bloques</option>
                                    <option value="🎲">🎲 Mesa</option>
                                    <option value="⚽">⚽ Pelota</option>
                                    <option value="🎨">🎨 Arte</option>
                                </select>
                            </div>
                        </div>

                        <div class="fila-dos-col">
                            <div class="campo">
                                <label>Marca</label>
                                <input type="text" name="marca" placeholder="Ej: Lego, Mattel">
                            </div>
                            <div class="campo">
                                <label>Código de Barras</label>
                                <input type="text" name="codigo_barras" inputmode="numeric" placeholder="Opcional">
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
                                <label>Edad Mín. (años)</label>
                                <input type="number" name="edad_minima" inputmode="numeric" value="3" min="0" max="99" required>
                            </div>
                            <div class="campo">
                                <label>Edad Máx. (años)</label>
                                <input type="number" name="edad_maxima" inputmode="numeric" value="12" min="0" max="99" required>
                            </div>
                        </div>

                        <div class="fila-dos-col">
                            <div class="campo">
                                <label>Precio Compra (Bs)</label>
                                <input type="number" step="0.01" inputmode="decimal" name="precio_compra" value="0.00" min="0">
                            </div>
                            <div class="campo">
                                <label>Precio Venta (Bs) *</label>
                                <input type="number" step="0.01" inputmode="decimal" name="precio_venta" required min="0.1" placeholder="0.00">
                            </div>
                        </div>

                        <div class="fila-dos-col">
                            <div class="campo">
                                <label>Sucursal Inicial *</label>
                                <select name="id_sucursal_inicial" required>
                                    <?php foreach ($sucursales as $suc): ?>
                                        <option value="<?= $suc['id_sucursal'] ?>">
                                            <?= htmlspecialchars($suc['nombre'] . ' (' . $suc['departamento'] . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="campo">
                                <label>Stock Inicial *</label>
                                <input type="number" name="stock_inicial" inputmode="numeric" value="1" min="0" required>
                            </div>
                        </div>

                        <div class="campo">
                            <label>Foto del Juguete (Cámara o Galería)</label>
                            <input type="file" name="foto" accept="image/*" onchange="previsualizarFoto(this)">
                            <div id="previewNuevaFoto" class="preview-box">
                                <img id="imgPreviewNueva" src="" alt="Vista previa">
                                <span style="font-size: 0.8rem; color: var(--primary);">Vista previa lista para subir</span>
                            </div>
                        </div>

                        <button type="submit" class="btn-confirmar" style="width: 100%; margin-top: 6px;">💾 Guardar Juguete</button>
                    </form>
                </div>

                <div class="card-admin">
                    <h3>📋 Catálogo e Inventario por Sucursal</h3>
                    <div class="tabla-responsive">
                        <table class="tabla-inventario">
                            <thead>
                                <tr>
                                    <th>Foto</th>
                                    <th>Juguete</th>
                                    <th>Sucursal</th>
                                    <th>Stock</th>
                                    <th>Precio (Bs)</th>
                                    <th>Nueva Foto</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productos as $prod): 
                                    $idProd = (int)$prod['id_producto'];
                                    $sucDefault = $sucursales['id_sucursal'] ?? 0;
                                    $stockInicialFila = $stockMapa[$idProd][$sucDefault] ?? 0;
                                ?>
                                <tr>
                                    <form method="POST" action="registro_juguetes.php" enctype="multipart/form-data">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="accion" value="actualizar_producto">
                                        <input type="hidden" name="id_producto" value="<?= $idProd ?>">

                                        <td>
                                            <?php if (!empty($prod['imagen']) && file_exists($prod['imagen'])): ?>
                                                <img src="<?= htmlspecialchars($prod['imagen']) ?>" class="mini-thumb" alt="Foto" onclick="abrirLightbox(this.src, '<?= htmlspecialchars(addslashes($prod['nombre'])) ?>')">
                                            <?php else: ?>
                                                <div class="mini-emoji"><?= htmlspecialchars($prod['icono'] ?: '🧸') ?></div>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <strong><?= htmlspecialchars($prod['nombre']) ?></strong>
                                            <div style="font-size: 0.76rem; color: var(--text-muted);">
                                                <?= htmlspecialchars($prod['marca'] ?: 'Sin marca') ?> • Total: <strong><?= (int)$prod['stock_total'] ?></strong>
                                            </div>
                                        </td>

                                        <td>
                                            <select name="id_sucursal" class="select-tabla" onchange="cambiarStockSucursal(this, <?= $idProd ?>)">
                                                <?php foreach ($sucursales as $suc): ?>
                                                    <option value="<?= $suc['id_sucursal'] ?>"><?= htmlspecialchars($suc['nombre']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>

                                        <td>
                                            <input type="number" name="stock_actual" id="stock_<?= $idProd ?>" class="input-tabla" inputmode="numeric" min="0" value="<?= $stockInicialFila ?>">
                                        </td>

                                        <td>
                                            <input type="number" step="0.01" name="precio_venta" class="input-tabla" inputmode="decimal" min="0.1" value="<?= number_format((float)$prod['precio_venta'], 2, '.', '') ?>">
                                        </td>

                                        <td>
                                            <input type="file" name="foto" accept="image/*" class="input-file-tabla">
                                        </td>

                                        <td>
                                            <button type="submit" class="btn-sm btn-guardar">Actualizar</button>
                                        </td>
                                    </form>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <nav class="app-bottom-nav">
        <a href="index.php" class="app-tab">
            <span class="tab-icon">🏪</span>
            <span>Tienda</span>
        </a>
        <a href="registro_juguetes.php" class="app-tab activo">
            <span class="tab-icon">📦</span>
            <span>Inventario</span>
        </a>
        <?php if (esAdmin()): ?>
        <a href="registro_vendedor.php" class="app-tab">
            <span class="tab-icon">👤</span>
            <span>Vendedores</span>
        </a>
        <?php endif; ?>
    </nav>

    <div id="lightbox" class="lightbox-overlay oculto" onclick="cerrarLightbox()">
        <button type="button" class="lightbox-cerrar" onclick="cerrarLightbox()">&times;</button>
        <div class="lightbox-contenido" onclick="event.stopPropagation()">
            <img id="lightboxImg" src="" alt="Juguete">
            <div id="lightboxTitulo" class="lightbox-titulo"></div>
        </div>
    </div>

    <script>
        const stockMapa = <?= json_encode($stockMapa) ?>;

        function cambiarStockSucursal(selectEl, idProducto) {
            const idSuc = selectEl.value;
            const inputStock = document.getElementById('stock_' + idProducto);
            if (inputStock) {
                inputStock.value = (stockMapa[idProducto] && stockMapa[idProducto][idSuc] !== undefined)
                    ? stockMapa[idProducto][idSuc]
                    : 0;
            }
        }

        function previsualizarFoto(input) {
            const box = document.getElementById('previewNuevaFoto');
            const img = document.getElementById('imgPreviewNueva');
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

        function abrirLightbox(src, titulo) {
            document.getElementById('lightboxImg').src = src;
            document.getElementById('lightboxTitulo').textContent = titulo;
            document.getElementById('lightbox').classList.remove('oculto');
        }

        function cerrarLightbox() {
            document.getElementById('lightbox').classList.add('oculto');
        }
    </script>
</body>
</html>

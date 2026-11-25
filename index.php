<?php
require_once 'config.php';

$mensaje = "";
$tipo_alerta = "success";

if (($_GET['error'] ?? '') === 'acceso_denegado') {
    $mensaje = "Acceso denegado: Se requieren permisos de Administrador.";
    $tipo_alerta = "error";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'reservar') {
        $id_producto      = (int)$_POST['id_producto'];
        $id_sucursal      = (int)$_POST['id_sucursal'];
        $cantidad         = (int)$_POST['cantidad'];
        $razon_social     = trim($_POST['razon_social']);
        $tipo_documento   = $_POST['tipo_documento'];
        $numero_documento = trim($_POST['numero_documento']);
        $complemento      = trim($_POST['complemento'] ?? '');
        $telefono         = trim($_POST['telefono']);
        $correo           = trim($_POST['correo']);
        $metodo_pago      = $_POST['metodo_pago'];

        try {
            $pdo->beginTransaction();

            $stmtStock = $pdo->prepare("SELECT stock_actual FROM inventario WHERE id_producto = ? AND id_sucursal = ? FOR UPDATE");
            $stmtStock->execute([$id_producto, $id_sucursal]);
            $inv = $stmtStock->fetch();

            if (!$inv || $inv['stock_actual'] < $cantidad || $cantidad <= 0) {
                throw new Exception("Stock insuficiente en la sucursal seleccionada.");
            }

            $stmtCli = $pdo->prepare("SELECT id_cliente FROM clientes WHERE numero_documento = ? AND tipo_documento = ?");
            $stmtCli->execute([$numero_documento, $tipo_documento]);
            $cli = $stmtCli->fetch();

            if ($cli) {
                $id_cliente = $cli['id_cliente'];
                $pdo->prepare("UPDATE clientes SET razon_social = ?, complemento = ?, telefono = ?, correo = ? WHERE id_cliente = ?")
                    ->execute([$razon_social, $complemento ?: null, $telefono, $correo ?: null, $id_cliente]);
            } else {
                $stmtInsCli = $pdo->prepare("INSERT INTO clientes (razon_social, tipo_documento, numero_documento, complemento, correo, telefono) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtInsCli->execute([$razon_social, $tipo_documento, $numero_documento, $complemento ?: null, $correo ?: null, $telefono]);
                $id_cliente = $pdo->lastInsertId();
            }

            $stmtProd = $pdo->prepare("SELECT nombre, precio_venta FROM productos WHERE id_producto = ?");
            $stmtProd->execute([$id_producto]);
            $prod = $stmtProd->fetch();
            $subtotal = $prod['precio_venta'] * $cantidad;

            $stmtVenta = $pdo->prepare("INSERT INTO ventas (id_sucursal, id_cliente, metodo_pago, total_bs, estado) VALUES (?, ?, ?, ?, 'Reservada')");
            $stmtVenta->execute([$id_sucursal, $id_cliente, $metodo_pago, $subtotal]);
            $id_venta = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_unitario_bs, subtotal_bs) VALUES (?, ?, ?, ?, ?)")
                ->execute([$id_venta, $id_producto, $cantidad, $prod['precio_venta'], $subtotal]);

            $pdo->prepare("UPDATE inventario SET stock_actual = stock_actual - ? WHERE id_producto = ? AND id_sucursal = ?")
                ->execute([$cantidad, $id_producto, $id_sucursal]);

            $pdo->commit();
            $mensaje = "¡Reserva #$id_venta confirmada! Apartaste {$cantidad}x '{$prod['nombre']}' por Bs. " . number_format($subtotal, 2) . ". Te contactaremos al $telefono.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensaje = "No se pudo completar la reserva: " . $e->getMessage();
            $tipo_alerta = "error";
        }
    }

    if ($accion === 'cambiar_estado_venta' && esVendedorOAdmin()) {
        $id_venta     = (int)$_POST['id_venta'];
        $nuevo_estado = $_POST['nuevo_estado'];

        try {
            $pdo->beginTransaction();
            $stmtV = $pdo->prepare("SELECT id_sucursal, estado FROM ventas WHERE id_venta = ? FOR UPDATE");
            $stmtV->execute([$id_venta]);
            $venta = $stmtV->fetch();

            if (!$venta || $venta['estado'] !== 'Reservada') {
                throw new Exception("La reserva ya fue procesada anteriormente.");
            }

            if ($nuevo_estado === 'Anulada') {
                $stmtDet = $pdo->prepare("SELECT id_producto, cantidad FROM detalle_ventas WHERE id_venta = ?");
                $stmtDet->execute([$id_venta]);
                foreach ($stmtDet->fetchAll() as $item) {
                    $pdo->prepare("UPDATE inventario SET stock_actual = stock_actual + ? WHERE id_producto = ? AND id_sucursal = ?")
                        ->execute([$item['cantidad'], $item['id_producto'], $venta['id_sucursal']]);
                }
            }

            $num_factura = ($nuevo_estado === 'Completada') ? 'FAC-' . str_pad((string)$id_venta, 6, '0', STR_PAD_LEFT) : null;
            $pdo->prepare("UPDATE ventas SET estado = ?, numero_factura = COALESCE(?, numero_factura) WHERE id_venta = ?")
                ->execute([$nuevo_estado, $num_factura, $id_venta]);

            $pdo->commit();
            $mensaje = ($nuevo_estado === 'Completada')
                ? "Pago confirmado para la Reserva #$id_venta (Factura $num_factura)."
                : "Reserva #$id_venta anulada y stock restaurado.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensaje = "Error al procesar la reserva: " . $e->getMessage();
            $tipo_alerta = "error";
        }
    }
}

$categorias = $pdo->query("SELECT * FROM categorias ORDER BY nombre")->fetchAll();
$sucursales = $pdo->query("SELECT * FROM sucursales ORDER BY departamento, nombre")->fetchAll();
$cat_filtro = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;

$sqlProductos = "
    SELECT p.*, c.nombre AS categoria_nombre, COALESCE(SUM(i.stock_actual), 0) AS stock_total
    FROM productos p
    LEFT JOIN categorias c ON p.id_categoria = c.id_categoria
    LEFT JOIN inventario i ON p.id_producto = i.id_producto
    WHERE p.activo = 1
";
if ($cat_filtro > 0) {
    $sqlProductos .= " AND p.id_categoria = :cat ";
}
$sqlProductos .= " GROUP BY p.id_producto ORDER BY p.id_producto DESC";

$stmtProd = $pdo->prepare($sqlProductos);
if ($cat_filtro > 0) {
    $stmtProd->bindValue(':cat', $cat_filtro, PDO::PARAM_INT);
}
$stmtProd->execute();
$productos = $stmtProd->fetchAll();

$invRows = $pdo->query("SELECT id_producto, id_sucursal, stock_actual FROM inventario")->fetchAll();
$stockMapa = [];
foreach ($invRows as $r) {
    $stockMapa[$r['id_producto']][$r['id_sucursal']] = (int)$r['stock_actual'];
}

$reservasAdmin = [];
if (esVendedorOAdmin()) {
    $sqlReservas = "
        SELECT v.*, c.razon_social, c.tipo_documento, c.numero_documento, c.telefono,
               s.nombre AS sucursal_nombre, s.departamento,
               GROUP_CONCAT(CONCAT(dv.cantidad, 'x ', p.nombre) SEPARATOR ', ') AS detalle_productos
        FROM ventas v
        JOIN clientes c ON v.id_cliente = c.id_cliente
        JOIN sucursales s ON v.id_sucursal = s.id_sucursal
        LEFT JOIN detalle_ventas dv ON v.id_venta = dv.id_venta
        LEFT JOIN productos p ON dv.id_producto = p.id_producto
    ";
    if (esVendedor() && !empty($_SESSION['usuario_suc'])) {
        $sqlReservas .= " WHERE v.id_sucursal = " . (int)$_SESSION['usuario_suc'];
    }
    $sqlReservas .= " GROUP BY v.id_venta ORDER BY FIELD(v.estado, 'Reservada', 'Completada', 'Anulada'), v.fecha_venta DESC";
    $reservasAdmin = $pdo->query($sqlReservas)->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ToysWa Bolivia - Catálogo y Reservas</title>
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
                <?php if (estaLogueado()): ?>
                    <span style="font-size:0.85rem;">👤 <?= htmlspecialchars($_SESSION['usuario_nombre']) ?></span>
                    <a href="registro_juguetes.php" class="btn-nav activo">📦 Registrar Juguetes</a>
                    <a href="registro_vendedor.php" class="btn-nav">👥 Vendedores</a>
                    <a href="login.php?logout=1" class="btn-nav">🚪 Salir</a>
                <?php else: ?>
                    <a href="registro_vendedor.php" class="btn-nav">👤 Registro Vendedor</a>
                    <a href="login.php" class="btn-nav activo">🔐 Iniciar Sesión</a>
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

        <?php if (esVendedorOAdmin()): ?>
            <section class="panel-box">
                <h3 style="margin-bottom:14px; color:var(--primary-dark);">
                    💳 Control de Reservas y Pagos <?= esVendedor() ? '(' . htmlspecialchars($_SESSION['usuario_suc_name'] ?? 'Mi Sucursal') . ')' : '(Todas las Sucursales)' ?>
                </h3>
                <div class="tabla-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#Venta</th>
                                <th>Cliente</th>
                                <th>Teléfono</th>
                                <th>Detalle</th>
                                <th>Sucursal</th>
                                <th>Total (Bs.)</th>
                                <th>Pago</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reservasAdmin)): ?>
                                <tr><td colspan="9" style="text-align:center; color:#6c757d;">No hay reservas registradas.</td></tr>
                            <?php else: ?>
                                <?php foreach ($reservasAdmin as $res): ?>
                                    <tr>
                                        <td><strong>#<?= $res['id_venta'] ?></strong></td>
                                        <td><?= htmlspecialchars($res['razon_social']) ?></td>
                                        <td><?= htmlspecialchars($res['telefono'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($res['detalle_productos']) ?></td>
                                        <td><?= htmlspecialchars($res['departamento'] . ' - ' . $res['sucursal_nombre']) ?></td>
                                        <td><strong>Bs. <?= number_format($res['total_bs'], 2) ?></strong></td>
                                        <td><?= htmlspecialchars($res['metodo_pago']) ?></td>
                                        <td>
                                            <span class="badge-estado estado-<?= $res['estado'] ?>">
                                                <?= $res['estado'] === 'Completada' ? 'Pagada (' . $res['numero_factura'] . ')' : $res['estado'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($res['estado'] === 'Reservada'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="accion" value="cambiar_estado_venta">
                                                    <input type="hidden" name="id_venta" value="<?= $res['id_venta'] ?>">
                                                    <input type="hidden" name="nuevo_estado" value="Completada">
                                                    <button type="submit" class="btn-sm btn-pagar">✓ Confirmar Pago</button>
                                                </form>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="accion" value="cambiar_estado_venta">
                                                    <input type="hidden" name="id_venta" value="<?= $res['id_venta'] ?>">
                                                    <input type="hidden" name="nuevo_estado" value="Anulada">
                                                    <button type="submit" class="btn-sm btn-anular" onclick="return confirm('¿Anular reserva y devolver stock?')">✕ Anular</button>
                                                </form>
                                            <?php else: ?>
                                                <small style="color:#6c757d;">Procesada</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="filtros">
            <span class="filtros-titulo">Categorías:</span>
            <a href="index.php" class="btn-filtro <?= $cat_filtro === 0 ? 'activo' : '' ?>">Todos</a>
            <?php foreach ($categorias as $cat): ?>
                <a href="index.php?categoria=<?= $cat['id_categoria'] ?>" 
                   class="btn-filtro <?= $cat_filtro === (int)$cat['id_categoria'] ? 'activo' : '' ?>">
                    <?= htmlspecialchars($cat['nombre']) ?>
                </a>
            <?php endforeach; ?>
        </section>

        <section class="galeria">
            <?php if (empty($productos)): ?>
                <p style="grid-column: 1/-1; text-align: center; color: #6c757d; padding: 40px 0;">
                    No hay juguetes disponibles en esta categoría.
                </p>
            <?php else: ?>
                <?php foreach ($productos as $prod): ?>
                    <?php
                        $disp = $prod['stock_total'] > 0;
                        $tieneFoto = !empty($prod['imagen']));
                    ?>
                    <article class="card-juguete">
                        <div class="img-juguete <?= $tieneFoto ? 'con-foto' : '' ?>"
                             <?php if ($tieneFoto): ?>
                                 onclick='agrandarImagen(<?= json_encode($prod['imagen']) ?>, <?= json_encode($prod['nombre'] . " — Bs. " . number_format($prod['precio_venta'], 2)) ?>)'
                                 title="Haz clic para agrandar"
                             <?php endif; ?>>
                            <?php if ($tieneFoto): ?>
                                <img src="<?= htmlspecialchars($prod['imagen']) ?>" alt="<?= htmlspecialchars($prod['nombre']) ?>">
                                <span class="badge-zoom">🔍 Ampliar</span>
                            <?php else: ?>
                                <div class="fallback-box">
                                    <span class="icono-juguete"><?= htmlspecialchars($prod['icono'] ?? '🧸') ?></span>
                                    <span><?= htmlspecialchars($prod['nombre']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="card-meta">
                                <span class="marca"><?= htmlspecialchars($prod['marca'] ?? 'Genérico') ?></span>
                                <span class="badge-edad"><?= $prod['edad_minima'] ?> - <?= $prod['edad_maxima'] ?> años</span>
                            </div>
                            <h3 class="titulo-juguete"><?= htmlspecialchars($prod['nombre']) ?></h3>
                            <p class="categoria-texto"><?= htmlspecialchars($prod['categoria_nombre'] ?? 'General') ?></p>
                            
                            <div class="card-footer">
                                <div class="precio-stock">
                                    <span class="precio">Bs. <?= number_format($prod['precio_venta'], 2) ?></span>
                                    <span class="badge-stock <?= $disp ? 'disponible' : 'agotado' ?>">
                                        <?= $disp ? 'Stock: ' . $prod['stock_total'] : 'Agotado' ?>
                                    </span>
                                </div>
                                <button class="btn-reservar" <?= !$disp ? 'disabled' : '' ?>
                                        onclick='abrirModalReserva(<?= json_encode($prod) ?>)'>
                                    <?= $disp ? 'Reservar Juguete' : 'No disponible' ?>
                                </button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

    <div id="lightbox-overlay" class="lightbox-overlay oculto" onclick="cerrarLightbox()">
        <button type="button" class="lightbox-cerrar" onclick="cerrarLightbox()">&times;</button>
        <div class="lightbox-contenido" onclick="event.stopPropagation()">
            <img id="lightbox-img" src="" alt="Vista ampliada">
            <div id="lightbox-titulo" class="lightbox-titulo"></div>
        </div>
    </div>

    <div id="modal-overlay" class="modal-overlay oculto">
        <div class="modal">
            <div class="modal-header">
                <h3>Reservar: <span id="modal-nombre"></span></h3>
                <button type="button" class="btn-cerrar-modal" onclick="cerrarModal()">&times;</button>
            </div>
            <form method="POST" action="index.php">
                <input type="hidden" name="accion" value="reservar">
                <input type="hidden" name="id_producto" id="modal_producto_id">
                <div class="modal-body">
                    <div class="resumen-precio">
                        <span>Precio Unitario:</span>
                        <strong id="modal-precio">Bs. 0.00</strong>
                    </div>
                    <div class="fila-dos-col">
                        <div class="campo" style="flex: 2;">
                            <label>Sucursal de recojo *</label>
                            <select name="id_sucursal" id="modal_id_sucursal" required onchange="actualizarMaxSucursal()">
                                <option value="">Seleccione sucursal...</option>
                                <?php foreach ($sucursales as $suc): ?>
                                    <option value="<?= $suc['id_sucursal'] ?>">
                                        <?= htmlspecialchars($suc['departamento'] . ' - ' . $suc['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small id="info_stock_sucursal" style="color: var(--primary); font-weight: 600;"></small>
                        </div>
                        <div class="campo" style="flex: 1;">
                            <label>Cantidad *</label>
                            <input type="number" name="cantidad" id="modal_cantidad" value="1" min="1" required>
                        </div>
                    </div>

                    <hr class="separador">
                    <h4 class="subtitulo-form">Datos del Cliente (Facturación Bolivia)</h4>

                    <div class="campo">
                        <label>Nombre Completo / Razón Social *</label>
                        <input type="text" name="razon_social" placeholder="Ej: Juan Pérez" required>
                    </div>
                    <div class="fila-dos-col">
                        <div class="campo" style="flex: 1;">
                            <label>Tipo Doc. *</label>
                            <select name="tipo_documento" required>
                                <option value="CI">CI</option>
                                <option value="NIT">NIT</option>
                                <option value="CEX">CEX</option>
                                <option value="PAS">PAS</option>
                            </select>
                        </div>
                        <div class="campo" style="flex: 2;">
                            <label>Número Documento *</label>
                            <input type="text" name="numero_documento" placeholder="Ej: 6789012" required>
                        </div>
                        <div class="campo" style="flex: 1;">
                            <label>Compl.</label>
                            <input type="text" name="complemento" placeholder="1A" maxlength="5">
                        </div>
                    </div>
                    <div class="fila-dos-col">
                        <div class="campo">
                            <label>Teléfono / WhatsApp *</label>
                            <input type="text" name="telefono" placeholder="Ej: 71234567" required>
                        </div>
                        <div class="campo">
                            <label>Correo Electrónico</label>
                            <input type="email" name="correo" placeholder="opcional@correo.com">
                        </div>
                    </div>
                    <div class="campo">
                        <label>Método de Pago al recoger *</label>
                        <select name="metodo_pago" required>
                            <option value="QR Simple">QR Simple</option>
                            <option value="Efectivo">Efectivo</option>
                            <option value="Tarjeta Débito/Crédito">Tarjeta Débito/Crédito</option>
                            <option value="Transferencia">Transferencia</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secundario" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn-confirmar">Confirmar Reserva</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const inventarioSucursales = <?= json_encode($stockMapa) ?>;
        let productoActualId = 0;

        function agrandarImagen(ruta, titulo) {
            document.getElementById('lightbox-img').src = ruta;
            document.getElementById('lightbox-titulo').textContent = titulo || '';
            document.getElementById('lightbox-overlay').classList.remove('oculto');
        }
        function cerrarLightbox() {
            document.getElementById('lightbox-overlay').classList.add('oculto');
            document.getElementById('lightbox-img').src = '';
        }
        function abrirModalReserva(prod) {
            productoActualId = prod.id_producto;
            document.getElementById('modal_producto_id').value = prod.id_producto;
            document.getElementById('modal-nombre').textContent = prod.nombre;
            document.getElementById('modal-precio').textContent = 'Bs. ' + parseFloat(prod.precio_venta).toFixed(2);
            document.getElementById('info_stock_sucursal').textContent = '';
            document.getElementById('modal_cantidad').value = 1;

            const sel = document.getElementById('modal_id_sucursal');
            sel.value = "";
            Array.from(sel.options).forEach(opt => {
                if (!opt.value) return;
                const stock = (inventarioSucursales[productoActualId] && inventarioSucursales[productoActualId][opt.value]) || 0;
                const baseText = opt.textContent.split(' (')[0];
                opt.disabled = stock <= 0;
                opt.textContent = baseText + (stock > 0 ? ` (${stock} disp.)` : ' (Agotado)');
            });
            document.getElementById('modal-overlay').classList.remove('oculto');
        }
        function cerrarModal() {
            document.getElementById('modal-overlay').classList.add('oculto');
        }
        function actualizarMaxSucursal() {
            const sucId = document.getElementById('modal_id_sucursal').value;
            const inputCant = document.getElementById('modal_cantidad');
            const info = document.getElementById('info_stock_sucursal');
            if (sucId && inventarioSucursales[productoActualId]) {
                const max = inventarioSucursales[productoActualId][sucId] || 0;
                inputCant.max = max;
                if (parseInt(inputCant.value) > max) inputCant.value = max;
                info.textContent = `Disponibles: ${max} unidades`;
            } else {
                info.textContent = '';
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
</body>
</html>

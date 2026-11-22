<?php
require_once 'config.php';
requerirLogin();

$paginaActual = 'ventas.php';

$error = '';
$exito = '';

// Qué pantalla mostrar: el listado/historial (por defecto) o el formulario de nueva venta
$vista = (($_GET['vista'] ?? '') === 'nueva') ? 'nueva' : 'lista';

// Mensaje tras registrar una venta (patrón Post/Redirect/Get)
if (isset($_GET['ok']) && (int)$_GET['ok'] > 0) {
    $exito = 'Venta V' . str_pad((string)(int)$_GET['ok'], 4, '0', STR_PAD_LEFT)
           . ' registrada correctamente. El stock fue actualizado.';
}

// Catálogo de productos mediante prepared statement
$stmtProd = $pdo->prepare(
    "SELECT ID_Producto, codigoProducto, nombreProducto, precioVenta, stockActual
     FROM productos
     ORDER BY nombreProducto"
);
$stmtProd->execute();
$productos = $stmtProd->fetchAll();
$productosPorId = array_column($productos, null, 'ID_Producto');

/**
 * Devuelve el HTML de una fila de producto del formulario de venta.
 */
function filaVenta(array $productos, int $prodSeleccionado = 0, string $cantSeleccionada = ''): string
{
    $stockActualFila  = '-';
    $precioActualFila = '$0';
    $subActualFila    = '$0';
    $maxAttr          = '';

    $html  = '<tr>';
    $html .= '<td><select name="producto[]" class="in-prod"><option value="">-- Seleccionar --</option>';

    foreach ($productos as $p) {
        $idP      = (int)$p['ID_Producto'];
        $stock    = (int)$p['stockActual'];
        $precio   = (float)$p['precioVenta'];
        $selected = ($idP === $prodSeleccionado) ? ' selected' : '';
        $disabled = ($stock <= 0 && $idP !== $prodSeleccionado) ? ' disabled' : '';

        if ($idP === $prodSeleccionado) {
            $stockActualFila  = (string)$stock;
            $precioActualFila = formatoPesos($precio);
            $maxAttr          = ' max="' . $stock . '"';
            if (is_numeric($cantSeleccionada) && (int)$cantSeleccionada > 0) {
                $subActualFila = formatoPesos($precio * (int)$cantSeleccionada);
            }
        }

        $etiqueta = $p['codigoProducto'] . ' - ' . $p['nombreProducto'] . ' (Stock: ' . $stock . ')';
        $html .= '<option value="' . $idP . '"'
               . ' data-precio="' . h((string)$precio) . '"'
               . ' data-stock="' . $stock . '"'
               . $selected . $disabled . '>'
               . h($etiqueta) . '</option>';
    }

    $html .= '</select></td>';
    $html .= '<td class="stock-disp" style="text-align:center;">' . h($stockActualFila) . '</td>';
    $html .= '<td><input type="number" name="cantidad[]" class="in-cant" min="1" step="1"' . $maxAttr . ' value="' . h($cantSeleccionada) . '"></td>';
    $html .= '<td class="precio-unit">' . h($precioActualFila) . '</td>';
    $html .= '<td class="sub">' . h($subActualFila) . '</td>';
    $html .= '<td><button type="button" class="btn quitar" title="Quitar fila">✕</button></td>';
    $html .= '</tr>';

    return $html;
}

$filasFormulario = [];

// Procesamiento del formulario de nueva venta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_nueva'])) {
    $vista = 'nueva';

    $txt = function ($v): string {
        return is_scalar($v) ? trim((string)$v) : '';
    };

    $fecha      = $txt($_POST['fecha'] ?? '');
    $ids        = is_array($_POST['producto'] ?? null) ? $_POST['producto'] : [];
    $cantidades = is_array($_POST['cantidad'] ?? null) ? $_POST['cantidad'] : [];

    // Arma la lista de líneas ignorando filas completamente vacías
    $lineas = [];
    foreach ($ids as $i => $idProd) {
        $idProdInt = (int)$txt($idProd);
        $cantTxt   = $txt($cantidades[$i] ?? '');
        if ($idProdInt === 0 && $cantTxt === '') {
            continue;
        }
        $lineas[] = ['producto' => $idProdInt, 'cantidad' => $cantTxt];
        $filasFormulario[] = ['producto' => $idProdInt, 'cantidad' => $cantTxt];
    }

    if (!csrfValido()) {
        $error = 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.';
    } else {
        $fechaObj    = DateTime::createFromFormat('Y-m-d', $fecha);
        $fechaValida = $fechaObj && $fechaObj->format('Y-m-d') === $fecha;

        // Agrupa cantidades por producto por si se repite el mismo producto en distintas líneas
        $cantidadesPorProducto = [];

        if (!$fechaValida) {
            $error = 'La fecha de la venta no es válida.';
        } elseif ($fecha > date('Y-m-d')) {
            $error = 'La fecha de la venta no puede ser futura.';
        } elseif (empty($lineas)) {
            $error = 'Agrega al menos un producto a la venta.';
        } else {
            foreach ($lineas as $n => $l) {
                $num = $n + 1;
                if (!isset($productosPorId[$l['producto']])) {
                    $error = "Línea $num: selecciona un producto válido.";
                    break;
                }
                $cant = filter_var($l['cantidad'], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => 1000000]
                ]);
                if ($cant === false) {
                    $error = "Línea $num: la cantidad debe ser un número entero mayor a 0.";
                    break;
                }
                $idP = $l['producto'];
                $cantidadesPorProducto[$idP] = ($cantidadesPorProducto[$idP] ?? 0) + $cant;
            }
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();

                // Bloquea filas involucradas para validar stock real y evitar condiciones de carrera
                $stmtBloqueo = $pdo->prepare(
                    "SELECT ID_Producto, nombreProducto, precioVenta, stockActual
                     FROM productos
                     WHERE ID_Producto = ?
                     FOR UPDATE"
                );

                $lineasValidadas = [];
                $totalVenta = 0.0;

                foreach ($cantidadesPorProducto as $idProd => $cantSolicitada) {
                    $stmtBloqueo->execute([$idProd]);
                    $prodDb = $stmtBloqueo->fetch();

                    if (!$prodDb) {
                        throw new RuntimeException('Uno de los productos seleccionados ya no existe.');
                    }

                    $stockDisponible = (int)$prodDb['stockActual'];
                    if ($stockDisponible < $cantSolicitada) {
                        throw new RuntimeException(
                            'Stock insuficiente para "' . $prodDb['nombreProducto'] . '". '
                            . 'Disponible: ' . $stockDisponible . ', solicitado: ' . $cantSolicitada . '.'
                        );
                    }

                    // Cálculo estricto en el servidor
                    $precioUnitario = round((float)$prodDb['precioVenta'], 2);
                    $subtotal       = round($cantSolicitada * $precioUnitario, 2);

                    $lineasValidadas[] = [
                        'ID_Producto'    => $idProd,
                        'cantidad'       => $cantSolicitada,
                        'precioUnitario' => $precioUnitario,
                        'subtotal'       => $subtotal,
                    ];
                    $totalVenta = round($totalVenta + $subtotal, 2);
                }

                // 1. Guardar encabezado en ventas
                $stmtVenta = $pdo->prepare(
                    "INSERT INTO ventas (fechaVenta, totalVenta, ID_Administrador)
                     VALUES (?, ?, ?)"
                );
                $stmtVenta->execute([$fecha, $totalVenta, (int)$_SESSION['admin_id']]);
                $idVenta = (int)$pdo->lastInsertId();

                // 2. Guardar líneas en detalle_ventas y descontar stockActual
                $stmtDetalle = $pdo->prepare(
                    "INSERT INTO detalle_ventas (ID_Venta, ID_Producto, cantidad, precioUnitario, subtotal)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmtDescuento = $pdo->prepare(
                    "UPDATE productos
                     SET stockActual = stockActual - ?
                     WHERE ID_Producto = ? AND stockActual >= ?"
                );

                foreach ($lineasValidadas as $item) {
                    $stmtDetalle->execute([
                        $idVenta,
                        $item['ID_Producto'],
                        $item['cantidad'],
                        $item['precioUnitario'],
                        $item['subtotal']
                    ]);

                    $stmtDescuento->execute([
                        $item['cantidad'],
                        $item['ID_Producto'],
                        $item['cantidad']
                    ]);

                    if ($stmtDescuento->rowCount() === 0) {
                        throw new RuntimeException('No se pudo descontar el stock por falta de disponibilidad.');
                    }
                }

                $pdo->commit();
                header('Location: ventas.php?ok=' . $idVenta . '&fecha=' . urlencode($fecha));
                exit;
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('9Eleven ventas: ' . $e->getMessage());
                $error = 'Ocurrió un error al registrar la venta. No se guardaron cambios.';
            }
        }
    }
}

// Fecha consultada para el historial: hoy por defecto, o la enviada por GET
$fechaConsulta = $_GET['fecha'] ?? date('Y-m-d');
$fechaObjCons  = DateTime::createFromFormat('Y-m-d', $fechaConsulta);
if (!$fechaObjCons || $fechaObjCons->format('Y-m-d') !== $fechaConsulta) {
    $fechaConsulta = date('Y-m-d');
}

// Encabezados de venta del día + cantidad de líneas por venta
$stmt = $pdo->prepare(
    "SELECT v.ID_Venta, v.fechaVenta, v.totalVenta,
            COUNT(dv.ID_Detalle) AS totalItems
     FROM ventas v
     LEFT JOIN detalle_ventas dv ON dv.ID_Venta = v.ID_Venta
     WHERE v.fechaVenta = ?
     GROUP BY v.ID_Venta, v.fechaVenta, v.totalVenta
     ORDER BY v.ID_Venta DESC"
);
$stmt->execute([$fechaConsulta]);
$ventas = $stmt->fetchAll();

// Total vendido en el día
$totalDia = array_sum(array_column($ventas, 'totalVenta'));

// Detalle de productos vendidos en el día
$stmtDetalle = $pdo->prepare(
    "SELECT p.nombreProducto, dv.cantidad, dv.precioUnitario, dv.subtotal, v.ID_Venta
     FROM detalle_ventas dv
     JOIN ventas v ON v.ID_Venta = dv.ID_Venta
     JOIN productos p ON p.ID_Producto = dv.ID_Producto
     WHERE v.fechaVenta = ?
     ORDER BY v.ID_Venta DESC"
);
$stmtDetalle->execute([$fechaConsulta]);
$detalles = $stmtDetalle->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>9Eleven - Ventas</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php require 'includes/header.php'; ?>

<div class="contenedor-principal">
    <?php require 'includes/sidebar.php'; ?>

    <main class="contenido">
        <h1>Ventas</h1>

        <?php if ($error): ?>
            <div class="mensaje-error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($exito): ?>
            <div class="mensaje-ok"><?= h($exito) ?></div>
        <?php endif; ?>

        <div class="barra-acciones">
            <a href="ventas.php" class="btn">Ver ventas</a>
            <a href="ventas.php?vista=nueva" class="btn btn-rojo">+ Nueva venta</a>
        </div>

        <?php if ($vista === 'nueva'): ?>
            <div class="form-box" style="max-width:860px;">
                <h2>Registrar nueva venta</h2>
                <form method="POST" action="ventas.php?vista=nueva">
                    <?= csrfCampo() ?>

                    <div class="form-grupo">
                        <label for="fecha">Fecha de venta</label>
                        <input type="date" id="fecha" name="fecha"
                               value="<?= h($_POST['fecha'] ?? date('Y-m-d')) ?>"
                               max="<?= h(date('Y-m-d')) ?>" required>
                    </div>

                    <h2 style="margin-top:18px;">Productos a vender</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th style="width:90px; text-align:center;">Stock</th>
                                <th style="width:110px;">Cantidad</th>
                                <th style="width:130px;">Precio Unit.</th>
                                <th style="width:130px;">Subtotal</th>
                                <th style="width:50px;"></th>
                            </tr>
                        </thead>
                        <tbody id="lineas">
                            <?php if (!empty($filasFormulario)): ?>
                                <?php foreach ($filasFormulario as $f): ?>
                                    <?= filaVenta($productos, (int)$f['producto'], (string)$f['cantidad']) ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?= filaVenta($productos) ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" style="text-align:right; font-weight:bold;">Total de la venta:</td>
                                <td colspan="2" style="font-weight:bold;" id="total-venta">$0</td>
                            </tr>
                        </tfoot>
                    </table>

                    <template id="plantilla-fila"><?= filaVenta($productos) ?></template>

                    <div style="margin-top:12px;">
                        <button type="button" class="btn" id="agregar-fila">+ Agregar producto</button>
                    </div>

                    <div class="acciones-form">
                        <a href="ventas.php" class="btn">Cancelar</a>
                        <button type="submit" name="accion_nueva" class="btn btn-rojo">REGISTRAR VENTA</button>
                    </div>
                </form>
            </div>

            <style>
                #lineas select, #lineas input {
                    width: 100%; padding: 6px 8px; border: none; border-radius: 4px;
                    background-color: var(--azul-hielo); color: #1a1a1a; font-size: 0.85rem;
                }
            </style>
            <script>
            (function () {
                var cuerpo = document.getElementById('lineas');
                var plantilla = document.getElementById('plantilla-fila');
                var totalEl = document.getElementById('total-venta');

                function pesos(n) {
                    return '$' + Math.round(n).toLocaleString('es-CO');
                }

                function recalcular() {
                    var total = 0;
                    cuerpo.querySelectorAll('tr').forEach(function (tr) {
                        var select = tr.querySelector('.in-prod');
                        var opcion = select.options[select.selectedIndex];
                        var precio = (opcion && opcion.dataset.precio) ? parseFloat(opcion.dataset.precio) : 0;
                        var stock  = (opcion && opcion.dataset.stock)  ? parseInt(opcion.dataset.stock, 10) : null;
                        var cantInput = tr.querySelector('.in-cant');
                        var c = parseFloat(cantInput.value) || 0;

                        tr.querySelector('.stock-disp').textContent = (stock !== null) ? stock : '-';
                        tr.querySelector('.precio-unit').textContent = pesos(precio);

                        if (stock !== null) {
                            cantInput.max = stock;
                        } else {
                            cantInput.removeAttribute('max');
                        }

                        var sub = c * precio;
                        tr.querySelector('.sub').textContent = pesos(sub);
                        total += sub;
                    });
                    totalEl.textContent = pesos(total);
                }

                document.getElementById('agregar-fila').addEventListener('click', function () {
                    cuerpo.appendChild(plantilla.content.querySelector('tr').cloneNode(true));
                    recalcular();
                });

                cuerpo.addEventListener('input', recalcular);
                cuerpo.addEventListener('change', recalcular);

                cuerpo.addEventListener('click', function (e) {
                    if (e.target.classList.contains('quitar')) {
                        var filas = cuerpo.querySelectorAll('tr');
                        var tr = e.target.closest('tr');
                        if (filas.length > 1) {
                            tr.remove();
                        } else {
                            tr.querySelector('.in-prod').value = '';
                            tr.querySelector('.in-cant').value = '';
                        }
                        recalcular();
                    }
                });

                recalcular();
            })();
            </script>

        <?php else: ?>

            <div class="panel">
                <div class="panel-header">
                    <h2>Ventas del día — <?= h($fechaConsulta) ?></h2>
                    <form method="GET" action="ventas.php" style="display:flex; gap:8px; align-items:center;">
                        <input type="date" name="fecha" value="<?= h($fechaConsulta) ?>"
                               style="padding:6px 10px; border-radius:4px; border:none; background-color:var(--azul-hielo); color:#1a1a1a;">
                        <button type="submit" class="btn">Consultar</button>
                    </form>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>ID Venta</th>
                            <th>Fecha</th>
                            <th>Items</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ventas)): ?>
                            <tr><td colspan="4" class="sin-datos">No hay ventas registradas para esta fecha.</td></tr>
                        <?php else: ?>
                            <?php foreach ($ventas as $v): ?>
                                <tr>
                                    <td>V<?= str_pad((string)$v['ID_Venta'], 4, '0', STR_PAD_LEFT) ?></td>
                                    <td><?= h($v['fechaVenta']) ?></td>
                                    <td><?= (int)$v['totalItems'] ?></td>
                                    <td><?= formatoPesos($v['totalVenta']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($ventas)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="text-align:right; font-weight:bold;">Total del día:</td>
                            <td style="font-weight:bold;"><?= formatoPesos($totalDia) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <?php if (!empty($detalles)): ?>
            <div class="panel" style="margin-top:20px;">
                <div class="panel-header">
                    <h2>Detalle de productos vendidos</h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>ID Venta</th>
                            <th>Producto</th>
                            <th>Cantidad</th>
                            <th>Precio Unit.</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detalles as $d): ?>
                            <tr>
                                <td>V<?= str_pad((string)$d['ID_Venta'], 4, '0', STR_PAD_LEFT) ?></td>
                                <td><?= h($d['nombreProducto']) ?></td>
                                <td><?= (int)$d['cantidad'] ?></td>
                                <td><?= formatoPesos($d['precioUnitario']) ?></td>
                                <td><?= formatoPesos($d['subtotal']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        <?php endif; ?>
    </main>
</div>

<?php require 'includes/footer.php'; ?>
</body>
</html>
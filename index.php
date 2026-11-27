<?php
require_once 'config.php';

// Si ya hay sesión activa, ir directo al dashboard
if (estaLogueado()) {
    header('Location: dashboard.php');
    exit;
}

const MAX_INTENTOS_LOGIN = 5;
const TIEMPO_BLOQUEO_SEGUNDOS = 300; // 5 minutos

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_login'])) {
    $email = trim($_POST['email'] ?? '');
    $clave =$_POST['contrasena'] ?? '';
    $ip    =$_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($email === '' || $clave === '') {$error = 'Debes ingresar email y contraseña.';
    } else {
        $claveControl = 'login_' . hash('sha256', strtolower($email) . '\vert{}' .$ip);

        if (!isset($_SESSION['control_intentos'][$claveControl])) {
            $_SESSION['control_intentos'][$claveControl] = [
                'intentos'        => 0,
                'bloqueado_hasta' => 0
            ];
        }

        $registro = &$_SESSION['control_intentos'][$claveControl];$ahora    = time();

        // Si el tiempo de bloqueo ya expiró, reiniciar contador
        if ($registro['bloqueado_hasta'] > 0 &&$ahora >= $registro['bloqueado_hasta']) {$registro['intentos']        = 0;
            $registro['bloqueado_hasta'] = 0;
        }

        // Verificar si sigue bloqueado
        if ($registro['bloqueado_hasta'] >$ahora) {
            $minutosRestantes = (int) ceil(($registro['bloqueado_hasta'] - $ahora) / 60);$error = "Demasiados intentos fallidos. Intenta nuevamente en {$minutosRestantes} minuto(s).";
        } else {
            $stmt =$pdo->prepare("SELECT * FROM administrador WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $admin =$stmt->fetch();

            $claveValida = false;
            if ($admin) {
                if (password_get_info($admin['contrasena'])['algo'] !== null) {$claveValida = password_verify($clave,$admin['contrasena']);
                } else {
                    $claveValida = hash_equals($admin['contrasena'],$clave);
                }
            }

            if ($admin &&$claveValida) {
                // Reiniciar contador tras acceso exitoso
                unset($_SESSION['control_intentos'][$claveControl]);

                $_SESSION['admin_id']    =$admin['ID_Administrador'];
                $_SESSION['admin_email'] =$admin['email'];
                header('Location: dashboard.php');
                exit;
            } else {
                $registro['intentos']++;

                if ($registro['intentos'] >= MAX_INTENTOS_LOGIN) {
                    $registro['bloqueado_hasta'] =$ahora + TIEMPO_BLOQUEO_SEGUNDOS;
                    $minutos = (int) ceil(TIEMPO_BLOQUEO_SEGUNDOS / 60);$error = "Credenciales inválidas. Acceso bloqueado temporalmente por {$minutos} minutos.";
                } else {
                    $error = 'Credenciales inválidas.';
                }
            }
        }
        unset($registro);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>9Eleven - Inventario</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="header">
    <div class="logo">
        <span style="font-weight:bold; font-size:1.4rem; letter-spacing:1px;">9ELEVEN</span>
    </div>
    <div class="usuario">
        <a href="#login">Log In</a>
        <div class="avatar"></div>
    </div>
</header>

<div class="contenedor-principal" style="flex-direction:column; align-items:center;">

    <div class="home-hero">
        <h1>Tu inventario, siempre bajo control.</h1>
        <p>Plataforma web para la gestión eficiente del inventario.<br>
           Controla productos, proveedores y movimientos en un solo lugar.</p>
    </div>

    <div id="login" class="login-caja">
        <div class="avatar-grande"></div>
        <h1>Log In</h1>

        <?php if ($error): ?>
            <div class="mensaje-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php#login">
            <div class="form-grupo">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autofocus>
            </div>
            <div class="form-grupo">
                <label for="contrasena">Contraseña</label>
                <input type="password" id="contrasena" name="contrasena" required>
            </div>
            <button type="submit" name="accion_login" class="btn btn-rojo btn-ancho">Log In</button>
        </form>
    </div>

</div>

<?php require 'includes/footer.php'; ?>
</body>
</html>
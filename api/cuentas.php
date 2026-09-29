<?php
/**
 * API de cuentas de los compañeros del Observatorio Estelar (UNAD | CIP Turbo).
 *
 * Registro abierto con usuario y contraseña. Las contraseñas se guardan cifradas
 * con password_hash (nunca en texto plano) y la sesión usa una cookie HttpOnly.
 *
 *   GET  api/cuentas.php                    -> sesión actual y, si hay sesión, lista de compañeros
 *   POST api/cuentas.php  accion=registrar  -> nombre, usuario, clave
 *   POST api/cuentas.php  accion=iniciar    -> usuario, clave
 *   POST api/cuentas.php  accion=cerrar
 */

declare(strict_types=1);

require __DIR__ . '/comun.php';
prepararRespuestaJson();

const ARCHIVO_USUARIOS = DIR_PRIVADO . '/usuarios.json';
const MAX_USUARIOS = 300;
const MIN_LARGO_CLAVE = 8;
const MAX_LARGO_CLAVE = 72; // Límite de bcrypt
const MAX_REGISTROS_POR_HORA = 5;
const MAX_INTENTOS_INGRESO = 10;
const VENTANA_INTENTOS = 15 * 60;

function datosPublicos(array $usuario): array
{
    return ['usuario' => $usuario['usuario'], 'nombre' => $usuario['nombre']];
}

// Inicia la sesión con un identificador nuevo para evitar fijación de sesión
function abrirSesion(array $usuario): void
{
    iniciarSesion();
    session_regenerate_id(true);
    $_SESSION['usuario'] = datosPublicos($usuario);
}

function estado(): void
{
    $actual = usuarioActual();
    $respuesta = ['ok' => true, 'usuario' => $actual];
    // La lista de compañeros solo la ven quienes tienen cuenta
    if ($actual) {
        $respuesta['miembros'] = array_map(
            fn($u) => ['nombre' => $u['nombre'], 'usuario' => $u['usuario'], 'desde' => $u['creado']],
            leerJson(ARCHIVO_USUARIOS)
        );
    }
    responder(200, $respuesta);
}

function registrar(): void
{
    $nombre = limpiarTexto((string) ($_POST['nombre'] ?? ''), 60);
    $usuario = strtolower(trim((string) ($_POST['usuario'] ?? '')));
    $clave = (string) ($_POST['clave'] ?? '');

    if (strlen($nombre) < 2) {
        error(400, 'Escribe tu nombre completo.');
    }
    if (!preg_match('/^[a-z0-9._]{3,20}$/', $usuario)) {
        error(400, 'El usuario debe tener de 3 a 20 caracteres: letras sin tilde, números, punto o guion bajo.');
    }
    if (strlen($clave) < MIN_LARGO_CLAVE) {
        error(400, 'La contraseña debe tener al menos ' . MIN_LARGO_CLAVE . ' caracteres.');
    }
    if (strlen($clave) > MAX_LARGO_CLAVE) {
        error(400, 'La contraseña es demasiado larga (máximo ' . MAX_LARGO_CLAVE . ' caracteres).');
    }
    verificarLimite('registrar', MAX_REGISTROS_POR_HORA, 3600, 'Se crearon muchas cuentas desde esta conexión. Espera una hora e inténtalo de nuevo.');

    $nuevo = [
        'usuario' => $usuario,
        'nombre' => $nombre,
        'clave' => password_hash($clave, PASSWORD_DEFAULT),
        'creado' => date('c'),
    ];
    $resultado = modificarJson(ARCHIVO_USUARIOS, function (array $usuarios) use ($nuevo) {
        foreach ($usuarios as $existente) {
            if ($existente['usuario'] === $nuevo['usuario']) {
                return [$usuarios, 'repetido'];
            }
        }
        if (count($usuarios) >= MAX_USUARIOS) {
            return [$usuarios, 'lleno'];
        }
        $usuarios[] = $nuevo;
        return [$usuarios, 'creado'];
    });
    if ($resultado === 'repetido') {
        error(409, 'Ese usuario ya existe. Elige otro o inicia sesión.');
    }
    if ($resultado === 'lleno') {
        error(507, 'Se alcanzó el máximo de cuentas del proyecto.');
    }
    abrirSesion($nuevo);
    responder(201, ['ok' => true, 'usuario' => datosPublicos($nuevo)]);
}

function ingresar(): void
{
    $usuario = strtolower(trim((string) ($_POST['usuario'] ?? '')));
    $clave = (string) ($_POST['clave'] ?? '');
    if ($usuario === '' || $clave === '') {
        error(400, 'Escribe tu usuario y tu contraseña.');
    }
    verificarLimite('ingresar', MAX_INTENTOS_INGRESO, VENTANA_INTENTOS, 'Demasiados intentos de ingreso. Espera 15 minutos e inténtalo de nuevo.');

    $encontrado = null;
    foreach (leerJson(ARCHIVO_USUARIOS) as $registro) {
        if ($registro['usuario'] === $usuario) {
            $encontrado = $registro;
            break;
        }
    }
    // Se verifica siempre una contraseña para no revelar por el tiempo de respuesta si el usuario existe
    $hash = $encontrado['clave'] ?? password_hash('usuario-inexistente', PASSWORD_DEFAULT);
    if (!password_verify($clave, $hash) || $encontrado === null) {
        error(401, 'Usuario o contraseña incorrectos.');
    }

    // Actualiza el cifrado si PHP recomienda uno más fuerte
    if (password_needs_rehash($encontrado['clave'], PASSWORD_DEFAULT)) {
        $nuevoHash = password_hash($clave, PASSWORD_DEFAULT);
        modificarJson(ARCHIVO_USUARIOS, function (array $usuarios) use ($usuario, $nuevoHash) {
            foreach ($usuarios as &$u) {
                if ($u['usuario'] === $usuario) {
                    $u['clave'] = $nuevoHash;
                }
            }
            return [$usuarios, null];
        });
    }
    abrirSesion($encontrado);
    responder(200, ['ok' => true, 'usuario' => datosPublicos($encontrado)]);
}

function cerrar(): void
{
    iniciarSesion();
    $_SESSION = [];
    $parametros = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $parametros['path'],
        'secure' => $parametros['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_destroy();
    responder(200, ['ok' => true]);
}

// ===================== ENRUTADOR =====================
asegurarEstructura();

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo === 'GET') {
    estado();
}
if ($metodo !== 'POST') {
    error(405, 'Método no permitido.');
}
$accion = (string) ($_POST['accion'] ?? '');
if ($accion === 'registrar') {
    registrar();
}
if ($accion === 'iniciar') {
    ingresar();
}
if ($accion === 'cerrar') {
    cerrar();
}
error(400, 'Acción no válida.');

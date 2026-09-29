<?php
/**
 * Funciones compartidas por las APIs del Observatorio Estelar (bitácora y cuentas).
 * Este archivo no se abre directamente: lo incluyen bitacora.php y cuentas.php.
 */

declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

// ===================== RUTAS =====================
const DIR_BITACORA = __DIR__ . '/../bitacora';
const DIR_ARCHIVOS = DIR_BITACORA . '/archivos';
const DIR_PAPELERA = DIR_BITACORA . '/papelera';
const DIR_PRIVADO  = DIR_BITACORA . '/privado';
const ARCHIVO_LIMITES = DIR_PRIVADO . '/limites.json';

// ===================== RESPUESTAS JSON =====================
// Las APIs llaman a esta función; index.php no, porque entrega HTML
function prepararRespuestaJson(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error(int $codigo, string $mensaje): void
{
    responder($codigo, ['ok' => false, 'error' => $mensaje]);
}

function textoCorto(string $texto, int $largo): string
{
    return function_exists('mb_substr') ? mb_substr($texto, 0, $largo) : substr($texto, 0, $largo);
}

// Quita etiquetas y caracteres de control; el navegador además lo muestra como texto plano
function limpiarTexto(string $texto, int $largo): string
{
    // Algunos clientes antiguos envían texto en Windows-1252; se convierte para no perder tildes ni eñes
    if (function_exists('mb_check_encoding') && !mb_check_encoding($texto, 'UTF-8')) {
        $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }
    $texto = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($texto)) ?? '');
    return textoCorto($texto, $largo);
}

// ===================== ESTRUCTURA DE CARPETAS =====================
// Crea las carpetas y sus reglas de seguridad si no se subieron por FTP
function asegurarEstructura(): void
{
    foreach ([DIR_BITACORA, DIR_ARCHIVOS, DIR_PAPELERA, DIR_PRIVADO] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            error(500, 'No se pudo crear la carpeta de datos en el servidor. Revisa los permisos de la carpeta del sitio.');
        }
    }
    $denegarTodo = "# Carpeta privada: no accesible desde el navegador\n"
        . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    $reglas = [
        DIR_BITACORA . '/.htaccess' => "Options -Indexes\n",
        DIR_PRIVADO . '/.htaccess'  => $denegarTodo,
        DIR_PAPELERA . '/.htaccess' => $denegarTodo,
        DIR_ARCHIVOS . '/.htaccess' => "# Solo se sirven imágenes y videos; nada se ejecuta aquí\n"
            . "Options -Indexes -ExecCGI\n"
            . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pl .py .cgi\n"
            . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar\n"
            . "<FilesMatch \"(?i)\\.(php\\d?|phtml|phar|pl|py|cgi|sh|shtml|html?|svg|js)$\">\n"
            . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
            . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n"
            . "</FilesMatch>\n"
            . "<IfModule mod_headers.c>\n  Header set X-Content-Type-Options \"nosniff\"\n</IfModule>\n",
    ];
    foreach ($reglas as $ruta => $contenido) {
        if (!is_file($ruta)) {
            file_put_contents($ruta, $contenido);
        }
    }
}

// ===================== LECTURA/ESCRITURA SEGURA DE JSON =====================
// Abre un JSON con bloqueo exclusivo para que dos peticiones simultáneas no se pisen
function modificarJson(string $ruta, callable $cambio)
{
    $manejador = fopen($ruta, 'c+');
    if ($manejador === false) {
        error(500, 'No se pudo abrir un archivo de datos en el servidor.');
    }
    flock($manejador, LOCK_EX);
    $texto = stream_get_contents($manejador);
    $datos = $texto ? json_decode($texto, true) : [];
    if (!is_array($datos)) {
        $datos = [];
    }
    [$datos, $resultado] = $cambio($datos);
    ftruncate($manejador, 0);
    rewind($manejador);
    fwrite($manejador, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($manejador);
    flock($manejador, LOCK_UN);
    fclose($manejador);
    return $resultado;
}

function leerJson(string $ruta): array
{
    if (!is_file($ruta)) {
        return [];
    }
    $manejador = fopen($ruta, 'r');
    if ($manejador === false) {
        return [];
    }
    flock($manejador, LOCK_SH);
    $datos = json_decode((string) stream_get_contents($manejador), true);
    flock($manejador, LOCK_UN);
    fclose($manejador);
    return is_array($datos) ? $datos : [];
}

// ===================== LÍMITE ANTI-SPAM POR VISITANTE =====================
// Se guarda un hash de la IP (no la IP real) con las acciones recientes
function verificarLimite(string $accion, int $maximo, int $ventanaSegundos = 3600, ?string $mensaje = null): void
{
    // Detrás de un túnel o proxy local todas las visitas llegan como 127.0.0.1: solo en ese caso
    // se confía en la IP real que envía el proxy, para no mezclar a todos los compañeros en un solo límite
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocido';
    if (in_array($ip, ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    $visitante = hash('sha256', $ip . '|observatorio-estelar');
    $ahora = time();
    $permitido = modificarJson(ARCHIVO_LIMITES, function (array $limites) use ($visitante, $accion, $maximo, $ahora, $ventanaSegundos) {
        // Limpia marcas de más de un día para que el archivo no crezca sin control
        foreach ($limites as $clave => $marcas) {
            $limites[$clave] = array_values(array_filter($marcas, fn($t) => $t > $ahora - 86400));
            if (!$limites[$clave]) {
                unset($limites[$clave]);
            }
        }
        $clave = $accion . ':' . $visitante;
        $recientes = array_filter($limites[$clave] ?? [], fn($t) => $t > $ahora - $ventanaSegundos);
        if (count($recientes) >= $maximo) {
            return [$limites, false];
        }
        $limites[$clave][] = $ahora;
        return [$limites, true];
    });
    if (!$permitido) {
        error(429, $mensaje ?? 'Hiciste muchas acciones seguidas. Espera un rato y vuelve a intentarlo.');
    }
}

// ===================== SESIÓN DE USUARIO =====================
// Cookie de sesión solo accesible por el servidor (HttpOnly) y que no viaja en peticiones de otros sitios
function iniciarSesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $duracion = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', (string) $duracion);
    ini_set('session.use_strict_mode', '1');
    session_name('observatorio_sesion');
    session_set_cookie_params([
        'lifetime' => $duracion,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function usuarioActual(): ?array
{
    // Sin cookie no hay sesión: no se crea una sesión vacía por cada visitante
    if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE['observatorio_sesion'])) {
        return null;
    }
    iniciarSesion();
    $usuario = $_SESSION['usuario'] ?? null;
    return is_array($usuario) ? $usuario : null;
}

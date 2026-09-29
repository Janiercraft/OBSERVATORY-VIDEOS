<?php
/**
 * Guardián para el servidor de prueba de PHP (php -S) cuando se comparte por túnel.
 * El servidor de prueba no lee los archivos .htaccess, así que aquí se aplican las
 * mismas reglas que en Hostinger: carpetas privadas bloqueadas e ingreso obligatorio.
 *
 * Uso:  php -S 127.0.0.1:80 router-local.php
 */

declare(strict_types=1);

$ruta = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$rutaMinuscula = strtolower($ruta);

// Carpetas y archivos que nunca se entregan al navegador
$bloqueadas = ['/app', '/bitacora/privado', '/bitacora/papelera', '/api/comun.php', '/router-local.php'];
foreach ($bloqueadas as $prefijo) {
    if ($rutaMinuscula === $prefijo || strpos($rutaMinuscula, $prefijo . '/') === 0 || ($prefijo[-4] === '.' && strpos($rutaMinuscula, $prefijo) === 0)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Acceso prohibido.';
        return true;
    }
}
// Archivos ocultos (.htaccess, etc.) y cualquier intento de subir de carpeta
if (strpos($ruta, '/.') !== false || strpos($ruta, '..') !== false) {
    http_response_code(403);
    echo 'Acceso prohibido.';
    return true;
}
// En la carpeta de archivos subidos solo se sirven imágenes y videos, nunca código
if (strpos($rutaMinuscula, '/bitacora/archivos/') === 0 && !preg_match('/\.(jpg|png|webp|gif|mp4|webm)$/', $rutaMinuscula)) {
    http_response_code(403);
    echo 'Acceso prohibido.';
    return true;
}
// La raíz siempre pasa por index.php (ingreso obligatorio)
if ($ruta === '/' || $rutaMinuscula === '/index.html') {
    require __DIR__ . '/index.php';
    return true;
}
// El resto (imágenes, historia, APIs) lo entrega el servidor normalmente
return false;

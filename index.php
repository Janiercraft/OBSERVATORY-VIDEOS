<?php
/**
 * Puerta de entrada del Observatorio Estelar (UNAD | CIP Turbo).
 *
 * El ingreso es obligatorio: sin sesión solo se entrega la pantalla de
 * Ingresar / Crear cuenta. Con sesión se entrega el sitio completo.
 * Las dos páginas viven en app/, carpeta bloqueada al navegador, así que no se
 * pueden abrir directamente saltándose este control.
 */

declare(strict_types=1);

require __DIR__ . '/api/comun.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
// La página privada no se guarda en caché: al cerrar sesión no queda visible con "Atrás"
header('Cache-Control: no-store, private');

$pagina = usuarioActual() !== null ? 'sitio.html' : 'ingreso.html';
$ruta = __DIR__ . '/app/' . $pagina;

if (!is_file($ruta)) {
    http_response_code(500);
    echo 'Falta el archivo app/' . $pagina . ' en el servidor. Vuelve a extraer el paquete del sitio.';
    exit;
}
readfile($ruta);

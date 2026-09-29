<?php
/**
 * API de la Bitácora Multimedia del Observatorio Estelar (UNAD | CIP Turbo).
 *
 * Los compañeros con sesión iniciada ven, suben y borran fotos/videos desde
 * cualquier lugar. Cada publicación indica la actividad del proyecto a la que
 * pertenece y el nombre de la cuenta que la subió.
 *
 *   GET  api/bitacora.php                       -> lista de publicaciones
 *   POST api/bitacora.php  accion=subir         -> titulo, tipo (imagen|video|youtube), actividad, archivo | url
 *   POST api/bitacora.php  accion=borrar        -> id
 *
 * Protecciones:
 *   - Solo se aceptan imágenes y videos reales (se revisa el contenido, no solo la extensión).
 *   - Nombres aleatorios: nadie puede elegir el nombre ni la ruta del archivo.
 *   - Límite de subidas y borrados por visitante y por hora contra spam.
 *   - Lo borrado se mueve a una papelera privada para poder recuperarlo.
 */

declare(strict_types=1);

require __DIR__ . '/comun.php';
prepararRespuestaJson();

// ===================== CONFIGURACIÓN =====================
const ARCHIVO_INDICE   = DIR_PRIVADO . '/indice.json';
const ARCHIVO_BORRADOS = DIR_PRIVADO . '/borrados.json';
const URL_ARCHIVOS     = 'bitacora/archivos/'; // Relativa a la página HTML (raíz del sitio)

const MAX_BYTES_IMAGEN = 10 * 1024 * 1024;  // 10 MB
const MAX_BYTES_VIDEO  = 50 * 1024 * 1024;  // 50 MB (el plan de Hostinger debe permitirlo)
const MAX_PUBLICACIONES = 500;
const MAX_SUBIDAS_POR_HORA  = 15;
const MAX_BORRADOS_POR_HORA = 30;
const MAX_LARGO_TITULO = 100;

// Actividades del proyecto a las que se puede asociar cada publicación (misma lista que la página)
const ACTIVIDADES = [
    'observacion' => 'Observación nocturna',
    'taller'      => 'Taller o clase',
    'montaje'     => 'Montaje y mantenimiento',
    'salida'      => 'Salida de campo',
    'divulgacion' => 'Charla o divulgación',
    'reunion'     => 'Reunión del equipo',
    'otra'        => 'Otra actividad',
];

// Tipos permitidos según el contenido real del archivo
const TIPOS_PERMITIDOS = [
    'image/jpeg' => ['imagen', 'jpg'],
    'image/png'  => ['imagen', 'png'],
    'image/webp' => ['imagen', 'webp'],
    'image/gif'  => ['imagen', 'gif'],
    'video/mp4'  => ['video', 'mp4'],
    'video/webm' => ['video', 'webm'],
];

// ===================== VALIDACIONES =====================
function idDeYoutube(string $url): ?string
{
    $patron = '~^(?:https?://)?(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~';
    return preg_match($patron, trim($url), $m) ? $m[1] : null;
}

function mensajeErrorSubida(int $codigo): string
{
    switch ($codigo) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo es más grande de lo que permite el servidor.';
        case UPLOAD_ERR_PARTIAL:
            return 'El archivo llegó incompleto. Revisa tu conexión y vuelve a intentarlo.';
        case UPLOAD_ERR_NO_FILE:
            return 'Elige un archivo para subir.';
        default:
            return 'El servidor no pudo recibir el archivo (código ' . $codigo . ').';
    }
}

// ===================== ACCIONES =====================
// Convierte valores de php.ini como "10M" o "512K" a bytes
function bytesDeIni(string $valor): int
{
    $valor = trim($valor);
    if ($valor === '') {
        return PHP_INT_MAX;
    }
    $numero = (int) $valor;
    switch (strtolower(substr($valor, -1))) {
        case 'g': return $numero * 1024 * 1024 * 1024;
        case 'm': return $numero * 1024 * 1024;
        case 'k': return $numero * 1024;
        default:  return $numero;
    }
}

// Límite real por tipo: el menor entre lo configurado aquí y lo que permite el hosting
// (ej. InfinityFree acepta 10 MB por archivo aunque aquí se configuren 50 MB para videos)
function limiteBytes(string $categoria): int
{
    $servidor = min(bytesDeIni((string) ini_get('upload_max_filesize')), bytesDeIni((string) ini_get('post_max_size')) - 1024 * 1024);
    return min($categoria === 'imagen' ? MAX_BYTES_IMAGEN : MAX_BYTES_VIDEO, $servidor);
}

function listar(): void
{
    responder(200, [
        'ok' => true,
        'items' => leerJson(ARCHIVO_INDICE),
        'limites' => ['imagen' => limiteBytes('imagen'), 'video' => limiteBytes('video')],
    ]);
}

function subir(): void
{
    $titulo = limpiarTexto((string) ($_POST['titulo'] ?? ''), MAX_LARGO_TITULO);
    $tipo = (string) ($_POST['tipo'] ?? '');
    $actividad = (string) ($_POST['actividad'] ?? 'otra');
    if ($titulo === '') {
        error(400, 'Escribe un título para la publicación.');
    }
    if (!in_array($tipo, ['imagen', 'video', 'youtube'], true)) {
        error(400, 'Tipo de publicación no válido.');
    }
    if (!isset(ACTIVIDADES[$actividad])) {
        error(400, 'Elige una actividad de la lista.');
    }

    // Autor: siempre la cuenta con sesión iniciada (el enrutador ya exige sesión)
    $cuenta = usuarioActual();
    $autor = $cuenta['nombre'];

    $publicacion = [
        'id' => bin2hex(random_bytes(8)),
        'titulo' => $titulo,
        'tipo' => $tipo,
        'actividad' => $actividad,
        'autor' => $autor !== '' ? $autor : 'Visitante',
        'verificado' => $cuenta !== null,
        'fecha' => date('c'),
    ];

    if ($tipo === 'youtube') {
        $idVideo = idDeYoutube((string) ($_POST['url'] ?? ''));
        if ($idVideo === null) {
            error(400, 'El enlace no es de un video de YouTube. Copia el enlace desde el botón "Compartir" de YouTube.');
        }
        verificarLimite('subir', MAX_SUBIDAS_POR_HORA, 3600, 'Hiciste muchas subidas seguidas. Espera un rato (máximo ' . MAX_SUBIDAS_POR_HORA . ' por hora).');
        $publicacion['url'] = 'https://www.youtube-nocookie.com/embed/' . $idVideo;
    } else {
        $archivo = $_FILES['archivo'] ?? null;
        if (!$archivo || !is_array($archivo) || is_array($archivo['error'])) {
            error(400, 'Elige un archivo para subir.');
        }
        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            error(400, mensajeErrorSubida((int) $archivo['error']));
        }
        if (!is_uploaded_file($archivo['tmp_name'])) {
            error(400, 'El archivo no llegó correctamente al servidor.');
        }
        if (!class_exists('finfo')) {
            error(500, 'El servidor no tiene activada la extensión "fileinfo" de PHP. Actívala en hPanel > Avanzado > Configuración de PHP.');
        }

        // Se revisa el contenido real del archivo, no el nombre que trae
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']) ?: '';
        if (!isset(TIPOS_PERMITIDOS[$mime])) {
            error(415, 'Formato no permitido. Sube imágenes JPG, PNG, WEBP o GIF, o videos MP4 o WEBM.');
        }
        [$categoria, $extension] = TIPOS_PERMITIDOS[$mime];
        if ($categoria !== $tipo) {
            error(400, $tipo === 'imagen' ? 'El archivo elegido es un video. Cambia el tipo a "Video".' : 'El archivo elegido es una imagen. Cambia el tipo a "Imagen".');
        }
        $maximo = limiteBytes($categoria);
        if ($archivo['size'] > $maximo) {
            error(413, 'El archivo pesa más de ' . round($maximo / 1024 / 1024, 1) . ' MB, el máximo que permite el servidor. Redúcelo o súbelo a YouTube y comparte el enlace.');
        }
        if ($categoria === 'imagen' && @getimagesize($archivo['tmp_name']) === false) {
            error(415, 'La imagen está dañada o no es una imagen válida.');
        }

        verificarLimite('subir', MAX_SUBIDAS_POR_HORA, 3600, 'Hiciste muchas subidas seguidas. Espera un rato (máximo ' . MAX_SUBIDAS_POR_HORA . ' por hora).');
        $nombre = $publicacion['id'] . '.' . $extension;
        if (!move_uploaded_file($archivo['tmp_name'], DIR_ARCHIVOS . '/' . $nombre)) {
            error(500, 'No se pudo guardar el archivo en el servidor.');
        }
        chmod(DIR_ARCHIVOS . '/' . $nombre, 0644);
        $publicacion['archivo'] = $nombre;
        $publicacion['url'] = URL_ARCHIVOS . $nombre;
    }

    $aceptada = modificarJson(ARCHIVO_INDICE, function (array $indice) use ($publicacion) {
        if (count($indice) >= MAX_PUBLICACIONES) {
            return [$indice, false];
        }
        array_unshift($indice, $publicacion);
        return [$indice, true];
    });
    if (!$aceptada) {
        if (isset($publicacion['archivo'])) {
            @unlink(DIR_ARCHIVOS . '/' . $publicacion['archivo']);
        }
        error(507, 'La bitácora está llena (' . MAX_PUBLICACIONES . ' publicaciones). Borra algunas antes de subir más.');
    }
    responder(201, ['ok' => true, 'item' => $publicacion]);
}

function borrar(): void
{
    $id = (string) ($_POST['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
        error(400, 'Identificador no válido.');
    }
    verificarLimite('borrar', MAX_BORRADOS_POR_HORA, 3600, 'Hiciste muchos borrados seguidos. Espera un rato (máximo ' . MAX_BORRADOS_POR_HORA . ' por hora).');

    $borrada = modificarJson(ARCHIVO_INDICE, function (array $indice) use ($id) {
        foreach ($indice as $posicion => $publicacion) {
            if (($publicacion['id'] ?? '') === $id) {
                array_splice($indice, $posicion, 1);
                return [$indice, $publicacion];
            }
        }
        return [$indice, null];
    });
    if ($borrada === null) {
        error(404, 'Esa publicación ya no existe. Recarga la página.');
    }

    // El archivo pasa a la papelera privada para poder recuperarlo si alguien borra por error
    if (!empty($borrada['archivo']) && is_file(DIR_ARCHIVOS . '/' . $borrada['archivo'])) {
        rename(DIR_ARCHIVOS . '/' . $borrada['archivo'], DIR_PAPELERA . '/' . $borrada['archivo']);
    }
    $cuenta = usuarioActual();
    $borrada['borrado'] = date('c');
    $borrada['borradoPor'] = $cuenta ? $cuenta['usuario'] : 'visitante';
    modificarJson(ARCHIVO_BORRADOS, function (array $registro) use ($borrada) {
        $registro[] = $borrada;
        return [$registro, null];
    });
    responder(200, ['ok' => true]);
}

// ===================== ENRUTADOR =====================
asegurarEstructura();

// El sitio es solo para compañeros con cuenta: sin sesión no se ve ni se modifica la bitácora
if (usuarioActual() === null) {
    error(401, 'Tu sesión terminó. Vuelve a iniciar sesión para usar la bitácora.');
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo === 'GET') {
    listar();
}
if ($metodo !== 'POST') {
    error(405, 'Método no permitido.');
}
// Si el envío supera post_max_size, PHP descarta todo el formulario sin avisar
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    error(413, 'El archivo es más grande de lo que permite el servidor.');
}

$accion = (string) ($_POST['accion'] ?? '');
if ($accion === 'subir') {
    subir();
}
if ($accion === 'borrar') {
    borrar();
}
error(400, 'Acción no válida.');

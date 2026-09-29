/**
 * Bitácora Multimedia del Observatorio Estelar (versión Vercel).
 *
 *   GET  /api/bitacora                         -> publicaciones + límites (requiere sesión)
 *   POST /api/bitacora  accion=subir           -> YouTube: titulo, tipo=youtube, actividad, url
 *   POST /api/bitacora  accion=publicar        -> foto/video ya subido a Vercel Blob: titulo, tipo, actividad, url
 *   POST /api/bitacora  accion=borrar          -> id
 *
 * Los archivos van directo del navegador a Vercel Blob (ver api/subida.js); aquí solo se
 * registra la publicación después de comprobar que el archivo existe en nuestro Blob.
 */

import { head } from '@vercel/blob';
import { randomBytes } from 'node:crypto';
import { ErrorApi, json, manejar, redis, limpiarTexto, verificarLimite, usuarioActual, leerFormulario } from '../lib/comun.js';
import { ACTIVIDADES, LIMITES_BYTES, TIPOS_PERMITIDOS } from '../lib/reglas.js';

const CLAVE_ITEMS = 'bitacora:items';
const CLAVE_BORRADOS = 'bitacora:borrados';
const MAX_PUBLICACIONES = 500;

async function exigirSesion(request) {
    const cuenta = await usuarioActual(request);
    if (!cuenta) throw new ErrorApi(401, 'Tu sesión terminó. Vuelve a iniciar sesión para usar la bitácora.');
    return cuenta;
}

function idDeYoutube(url) {
    const coincidencia = String(url || '').trim().match(/^(?:https?:\/\/)?(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/);
    return coincidencia ? coincidencia[1] : null;
}

async function listar() {
    const textos = await redis().lrange(CLAVE_ITEMS, 0, -1);
    return textos.map(t => JSON.parse(t));
}

export const GET = manejar(async (request) => {
    await exigirSesion(request);
    return json(200, {
        ok: true,
        items: await listar(),
        limites: LIMITES_BYTES,
        // Indica a la página que los archivos se suben directo a Vercel Blob
        subidaDirecta: true,
    });
});

function datosComunes(datos, cuenta) {
    const titulo = limpiarTexto(datos.titulo, 100);
    const actividad = String(datos.actividad || 'otra');
    if (!titulo) throw new ErrorApi(400, 'Escribe un título para la publicación.');
    if (!ACTIVIDADES[actividad]) throw new ErrorApi(400, 'Elige una actividad de la lista.');
    return {
        id: randomBytes(8).toString('hex'),
        titulo,
        actividad,
        autor: cuenta.nombre,
        verificado: true,
        fecha: new Date().toISOString(),
    };
}

async function guardar(publicacion) {
    if (await redis().llen(CLAVE_ITEMS) >= MAX_PUBLICACIONES) {
        throw new ErrorApi(507, `La bitácora está llena (${MAX_PUBLICACIONES} publicaciones). Borra algunas antes de subir más.`);
    }
    await redis().lpush(CLAVE_ITEMS, JSON.stringify(publicacion));
    return json(201, { ok: true, item: publicacion });
}

async function subirYoutube(request, datos, cuenta) {
    if (datos.tipo !== 'youtube') throw new ErrorApi(400, 'Las fotos y videos se suben como archivo.');
    const publicacion = datosComunes(datos, cuenta);
    const idVideo = idDeYoutube(datos.url);
    if (!idVideo) throw new ErrorApi(400, 'El enlace no es de un video de YouTube. Copia el enlace desde el botón "Compartir" de YouTube.');
    await verificarLimite(request, 'subir', 15, 3600, 'Hiciste muchas subidas seguidas. Espera un rato (máximo 15 por hora).');
    return guardar({ ...publicacion, tipo: 'youtube', url: `https://www.youtube-nocookie.com/embed/${idVideo}` });
}

// Registra un archivo que el navegador ya subió a Vercel Blob, verificando que sea nuestro y válido
async function publicarArchivo(request, datos, cuenta) {
    const tipo = String(datos.tipo || '');
    if (tipo !== 'imagen' && tipo !== 'video') throw new ErrorApi(400, 'Tipo de publicación no válido.');
    const publicacion = datosComunes(datos, cuenta);
    let direccion;
    try { direccion = new URL(String(datos.url || '')); } catch { throw new ErrorApi(400, 'Dirección de archivo no válida.'); }
    if (direccion.protocol !== 'https:' || !direccion.hostname.endsWith('.blob.vercel-storage.com') || !direccion.pathname.startsWith('/bitacora/')) {
        throw new ErrorApi(400, 'El archivo no pertenece a la bitácora del observatorio.');
    }
    let info;
    try { info = await head(direccion.toString()); } catch { throw new ErrorApi(400, 'El archivo no se encontró. Vuelve a subirlo.'); }
    const categoria = TIPOS_PERMITIDOS[info.contentType];
    if (!categoria) throw new ErrorApi(415, 'Formato no permitido. Sube imágenes JPG, PNG, WEBP o GIF, o videos MP4 o WEBM.');
    if (categoria !== tipo) throw new ErrorApi(400, tipo === 'imagen' ? 'El archivo elegido es un video. Cambia el tipo a "Video".' : 'El archivo elegido es una imagen. Cambia el tipo a "Imagen".');
    if (info.size > LIMITES_BYTES[tipo]) throw new ErrorApi(413, 'El archivo supera el tamaño permitido.');
    return guardar({ ...publicacion, tipo, url: info.url });
}

async function borrar(request, datos, cuenta) {
    const id = String(datos.id || '');
    if (!/^[a-f0-9]{16}$/.test(id)) throw new ErrorApi(400, 'Identificador no válido.');
    await verificarLimite(request, 'borrar', 30, 3600, 'Hiciste muchos borrados seguidos. Espera un rato (máximo 30 por hora).');
    const textos = await redis().lrange(CLAVE_ITEMS, 0, -1);
    const texto = textos.find(t => JSON.parse(t).id === id);
    if (!texto) throw new ErrorApi(404, 'Esa publicación ya no existe. Recarga la página.');
    await redis().lrem(CLAVE_ITEMS, 1, texto);
    // El archivo se conserva en Blob y queda registrado para poder recuperarlo si se borró por error
    await redis().lpush(CLAVE_BORRADOS, JSON.stringify({ ...JSON.parse(texto), borrado: new Date().toISOString(), borradoPor: cuenta.usuario }));
    return json(200, { ok: true });
}

export const POST = manejar(async (request) => {
    const cuenta = await exigirSesion(request);
    const datos = await leerFormulario(request);
    switch (datos.accion) {
        case 'subir': return subirYoutube(request, datos, cuenta);
        case 'publicar': return publicarArchivo(request, datos, cuenta);
        case 'borrar': return borrar(request, datos, cuenta);
        default: throw new ErrorApi(400, 'Acción no válida.');
    }
});

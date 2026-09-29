/**
 * Puerta de entrada del Observatorio Estelar (versión Vercel), equivalente a index.php.
 *
 * El ingreso es obligatorio: sin sesión se entrega la pantalla de Ingresar / Crear cuenta;
 * con sesión, el sitio completo. Las páginas viven en privado/, fuera de la carpeta pública,
 * así que no se pueden abrir directamente saltándose este control.
 */

import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { usuarioActual } from '../lib/comun.js';

const cache = {};

async function leerPagina(nombre) {
    if (!cache[nombre]) cache[nombre] = await readFile(path.join(process.cwd(), 'privado', nombre), 'utf8');
    return cache[nombre];
}

export async function GET(request) {
    let conSesion = false;
    try {
        conSesion = (await usuarioActual(request)) !== null;
    } catch (error) {
        // Si la base de datos no responde, se muestra el ingreso (que informará el error al intentar entrar)
        console.error(error);
    }
    const html = await leerPagina(conSesion ? 'sitio.html' : 'ingreso.html');
    return new Response(html, {
        status: 200,
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            // La página privada no se guarda en caché: al cerrar sesión no queda visible con "Atrás"
            'Cache-Control': 'no-store, private',
            'X-Content-Type-Options': 'nosniff',
            'X-Frame-Options': 'SAMEORIGIN',
        },
    });
}

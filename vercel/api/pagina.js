/**
 * Puerta de entrada del Observatorio Estelar para Vercel.
 *
 * Los HTML privados se importan como cadenas JS en vez de leerse con fs.
 * Esto evita errores ENOENT en el runtime de Vercel y mantiene las páginas
 * fuera de /public, por lo que no pueden abrirse saltándose el control de sesión.
 */

import { usuarioActual } from '../lib/comun.js';
import { HTML_INGRESO, HTML_SITIO } from '../lib/paginas.js';

export async function GET(request) {
    let conSesion = false;

    try {
        conSesion = (await usuarioActual(request)) !== null;
    } catch (error) {
        // La portada debe seguir cargando incluso si hay un problema temporal de sesión.
        console.error('Error comprobando sesión:', error);
    }

    const html = conSesion ? HTML_SITIO : HTML_INGRESO;

    return new Response(html, {
        status: 200,
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'Cache-Control': 'no-store, private',
            'X-Content-Type-Options': 'nosniff',
            'X-Frame-Options': 'SAMEORIGIN',
        },
    });
}

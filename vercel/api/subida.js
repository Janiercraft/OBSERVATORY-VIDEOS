/**
 * Autoriza subidas directas del navegador a Vercel Blob (versión Vercel).
 *
 * El navegador pide aquí un permiso temporal; solo se entrega a compañeros con sesión,
 * para la carpeta bitacora/, con los formatos y tamaños permitidos según el tipo.
 * Así los archivos grandes no pasan por la función (que tiene límite de 4,5 MB).
 */

import { handleUpload } from '@vercel/blob';
import { ErrorApi, json, manejar, usuarioActual, verificarLimite } from '../lib/comun.js';
import { LIMITES_BYTES, TIPOS_PERMITIDOS } from '../lib/reglas.js';

export const POST = manejar(async (request) => {
    let cuerpo;
    try { cuerpo = await request.json(); } catch { throw new ErrorApi(400, 'Solicitud de subida no válida.'); }

    // Al pedir el permiso se exige sesión; el aviso de "subida completada" lo envía Vercel y no trae cookie
    if (cuerpo?.type === 'blob.generate-client-token') {
        if (!await usuarioActual(request)) throw new ErrorApi(401, 'Tu sesión terminó. Vuelve a iniciar sesión para subir archivos.');
        await verificarLimite(request, 'permiso-subida', 20, 3600, 'Hiciste muchas subidas seguidas. Espera un rato (máximo 20 por hora).');
    }

    const respuesta = await handleUpload({
        request,
        body: cuerpo,
        onBeforeGenerateToken: async (ruta, datosCliente) => {
            const tipo = datosCliente === 'video' ? 'video' : 'imagen';
            if (!String(ruta).startsWith('bitacora/')) throw new ErrorApi(400, 'Ruta de subida no permitida.');
            return {
                allowedContentTypes: Object.keys(TIPOS_PERMITIDOS).filter(mime => TIPOS_PERMITIDOS[mime] === tipo),
                maximumSizeInBytes: LIMITES_BYTES[tipo],
                addRandomSuffix: true,
            };
        },
        // La publicación se registra aparte (accion=publicar), así no depende de este aviso
        onUploadCompleted: async () => {},
    });
    return json(200, respuesta);
});

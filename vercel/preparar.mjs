/**
 * Prepara la versión Vercel a partir de los archivos originales del proyecto (carpeta superior).
 * Ejecutar antes de cada despliegue:  node preparar.mjs
 *
 *   public/   -> lo que cualquiera puede descargar: imágenes, historia del telescopio, scripts
 *   privado/  -> páginas que solo entrega api/pagina.js (ingreso obligatorio)
 */

import { cp, mkdir, rm, copyFile, readdir, access } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const aqui = path.dirname(fileURLToPath(import.meta.url));
const origen = path.resolve(aqui, '..');
const publico = path.join(aqui, 'public');
const privado = path.join(aqui, 'privado');

await rm(publico, { recursive: true, force: true });
await rm(privado, { recursive: true, force: true });
await mkdir(path.join(publico, 'js'), { recursive: true });
await mkdir(privado, { recursive: true });

// Páginas protegidas: el sitio completo y la pantalla de ingreso
await copyFile(path.join(origen, 'vista_previa_observatorio_estelar.html'), path.join(privado, 'sitio.html'));
// La pantalla de ingreso puede llamarse ingreso.html o "OBSERVATORIO ESTELAR.html" (nombre elegido por el usuario)
const existe = (ruta) => access(ruta).then(() => true, () => false);
const fuenteIngreso = await existe(path.join(origen, 'ingreso.html')) ? 'ingreso.html' : 'OBSERVATORIO ESTELAR.html';
await copyFile(path.join(origen, fuenteIngreso), path.join(privado, 'ingreso.html'));

// Archivos públicos
const imagenes = [
    'logo.jpg', 'equipo-observatorio.jpg', 'cv.jpg', 'Gemin.jpg', 'sistem.jpg', 'xd.jpg',
    'Gemini_Generated_Image_hmxjazhmxjazhmxj (1).jpg', 'Gemini_Generated_Image_hmxjazhmxjazhmxj.jpg',
    'ChatGPT Image 24 sept 2026, 02_57_11 p.m.png',
];
for (const archivo of imagenes) await copyFile(path.join(origen, archivo), path.join(publico, archivo));
await copyFile(path.join(origen, 'historia-telescopio.html'), path.join(publico, 'historia-telescopio.html'));
await cp(path.join(origen, 'img-cielo'), path.join(publico, 'img-cielo'), { recursive: true });
await copyFile(path.join(origen, 'js', 'qrcode.min.js'), path.join(publico, 'js', 'qrcode.min.js'));

// Cliente de subida de Vercel Blob empaquetado para el navegador (sin depender de un CDN)
await build({
    stdin: { contents: "export { upload } from '@vercel/blob/client';", resolveDir: aqui, loader: 'js' },
    bundle: true,
    format: 'esm',
    platform: 'browser',
    minify: true,
    outfile: path.join(publico, 'js', 'blob-cliente.js'),
    logLevel: 'warning',
});

const contar = async (dir) => (await readdir(dir, { recursive: true })).length;
console.log(`Listo: public/ (${await contar(publico)} archivos) y privado/ (${await contar(privado)} archivos).`);

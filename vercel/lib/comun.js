/**
 * Funciones compartidas por las funciones de Vercel del Observatorio Estelar.
 * Equivalente a api/comun.php de la versión Hostinger:
 *   - Redis (Upstash) guarda usuarios, bitácora y límites anti-spam.
 *   - La sesión es una cookie firmada con HMAC (no se puede falsificar sin el secreto).
 *   - Las contraseñas se cifran con scrypt (incluido en Node.js).
 */

import { Redis } from '@upstash/redis';
import { createHmac, randomBytes, scrypt as scryptCb, timingSafeEqual, createHash } from 'node:crypto';
import { promisify } from 'node:util';

const scrypt = promisify(scryptCb);

export const NOMBRE_COOKIE = 'observatorio_sesion';
const DURACION_SESION = 60 * 60 * 24 * 30; // 30 días

// ===================== REDIS =====================
// La integración de Upstash en Vercel crea KV_REST_API_URL/TOKEN; también se aceptan los nombres UPSTASH_*
let clienteRedis = null;
export function redis() {
    if (clienteRedis) return clienteRedis;
    const url = process.env.KV_REST_API_URL || process.env.UPSTASH_REDIS_REST_URL;
    const token = process.env.KV_REST_API_TOKEN || process.env.UPSTASH_REDIS_REST_TOKEN;
    if (!url || !token) {
        throw new ErrorApi(500, 'El sitio está en línea, pero falta conectar Upstash Redis. Configura UPSTASH_REDIS_REST_URL y UPSTASH_REDIS_REST_TOKEN (o KV_REST_API_URL y KV_REST_API_TOKEN) en Vercel y vuelve a desplegar.');
    }
    // Sin deserialización automática: se guardan textos JSON exactos (necesario para borrar de listas)
    clienteRedis = new Redis({ url, token, automaticDeserialization: false });
    return clienteRedis;
}

// ===================== RESPUESTAS =====================
export class ErrorApi extends Error {
    constructor(codigo, mensaje) {
        super(mensaje);
        this.codigo = codigo;
    }
}

export function json(codigo, datos, cabeceras = {}) {
    return new Response(JSON.stringify(datos), {
        status: codigo,
        headers: {
            'Content-Type': 'application/json; charset=utf-8',
            'Cache-Control': 'no-store',
            'X-Content-Type-Options': 'nosniff',
            ...cabeceras,
        },
    });
}

// Envuelve cada función para que cualquier error llegue al navegador como JSON en español
export function manejar(funcion) {
    return async (request) => {
        try {
            return await funcion(request);
        } catch (error) {
            if (error instanceof ErrorApi) {
                return json(error.codigo, { ok: false, error: error.message });
            }
            console.error(error);
            return json(500, { ok: false, error: 'Ocurrió un error en el servidor. Inténtalo de nuevo en un momento.' });
        }
    };
}

// ===================== TEXTOS =====================
// Quita etiquetas y caracteres de control; el navegador además lo muestra como texto plano
export function limpiarTexto(texto, largo) {
    return String(texto ?? '')
        .replace(/<[^>]*>/g, '')
        .replace(/[\u0000-\u001F\u007F]/g, ' ')
        .trim()
        .slice(0, largo);
}

// ===================== LÍMITE ANTI-SPAM =====================
// Cuenta acciones por visitante (hash de la IP, nunca la IP real) en una ventana de tiempo
export async function verificarLimite(request, accion, maximo, ventanaSegundos, mensaje) {
    const ip = (request.headers.get('x-forwarded-for') || request.headers.get('x-real-ip') || 'desconocido').split(',')[0].trim();
    const visitante = createHash('sha256').update(`${ip}|observatorio-estelar`).digest('hex').slice(0, 32);
    const clave = `limite:${accion}:${visitante}`;
    const total = await redis().incr(clave);
    if (total === 1) await redis().expire(clave, ventanaSegundos);
    if (total > maximo) {
        throw new ErrorApi(429, mensaje);
    }
}

// ===================== CONTRASEÑAS =====================
export async function cifrarClave(clave) {
    const sal = randomBytes(16);
    const derivada = await scrypt(clave, sal, 64);
    return `scrypt$${sal.toString('hex')}$${derivada.toString('hex')}`;
}

export async function verificarClave(clave, guardada) {
    const [tipo, salHex, hashHex] = String(guardada || '').split('$');
    if (tipo !== 'scrypt' || !salHex || !hashHex) return false;
    const derivada = await scrypt(clave, Buffer.from(salHex, 'hex'), 64);
    const esperado = Buffer.from(hashHex, 'hex');
    return esperado.length === derivada.length && timingSafeEqual(esperado, derivada);
}

// ===================== SESIÓN =====================
// El secreto de firma se crea una sola vez y se guarda en Redis (o se toma de SESSION_SECRET si existe)
let secretoCache = null;
async function secreto() {
    if (process.env.SESSION_SECRET) return process.env.SESSION_SECRET;
    if (secretoCache) return secretoCache;
    await redis().set('config:secreto-sesion', randomBytes(32).toString('hex'), { nx: true });
    secretoCache = await redis().get('config:secreto-sesion');
    return secretoCache;
}

function firmar(texto, clave) {
    return createHmac('sha256', clave).update(texto).digest('base64url');
}

export async function crearCookieSesion(usuario) {
    const datos = Buffer.from(JSON.stringify({
        u: usuario.usuario,
        n: usuario.nombre,
        exp: Math.floor(Date.now() / 1000) + DURACION_SESION,
    })).toString('base64url');
    const valor = `${datos}.${firmar(datos, await secreto())}`;
    return `${NOMBRE_COOKIE}=${valor}; Path=/; Max-Age=${DURACION_SESION}; HttpOnly; Secure; SameSite=Lax`;
}

export function cookieCerrarSesion() {
    return `${NOMBRE_COOKIE}=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=Lax`;
}

// Devuelve { usuario, nombre } si la cookie es válida y no ha vencido; si no, null
export async function usuarioActual(request) {
    const cookies = request.headers.get('cookie') || '';
    const encontrada = cookies.split(';').map(c => c.trim()).find(c => c.startsWith(`${NOMBRE_COOKIE}=`));
    if (!encontrada) return null;
    const [datos, firma] = encontrada.slice(NOMBRE_COOKIE.length + 1).split('.');
    if (!datos || !firma) return null;
    const esperada = firmar(datos, await secreto());
    const a = Buffer.from(firma);
    const b = Buffer.from(esperada);
    if (a.length !== b.length || !timingSafeEqual(a, b)) return null;
    try {
        const contenido = JSON.parse(Buffer.from(datos, 'base64url').toString('utf8'));
        if (!contenido.exp || contenido.exp < Math.floor(Date.now() / 1000)) return null;
        return { usuario: contenido.u, nombre: contenido.n };
    } catch {
        return null;
    }
}

// Lee el formulario enviado por la página (FormData) como objeto simple
export async function leerFormulario(request) {
    try {
        const formulario = await request.formData();
        return Object.fromEntries(Array.from(formulario.entries()).filter(([, v]) => typeof v === 'string'));
    } catch {
        throw new ErrorApi(400, 'No se pudieron leer los datos enviados.');
    }
}

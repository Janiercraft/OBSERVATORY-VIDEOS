/**
 * Cuentas de los compañeros del Observatorio Estelar (versión Vercel).
 *
 *   GET  /api/cuentas                    -> sesión actual y, si hay sesión, lista de compañeros
 *   POST /api/cuentas  accion=registrar  -> nombre, usuario, clave
 *   POST /api/cuentas  accion=iniciar    -> usuario, clave
 *   POST /api/cuentas  accion=cerrar
 *
 * Los usuarios se guardan en el hash de Redis "usuarios" (campo = nombre de usuario).
 */

import {
    ErrorApi, json, manejar, redis, limpiarTexto, verificarLimite,
    cifrarClave, verificarClave, crearCookieSesion, cookieCerrarSesion, usuarioActual, leerFormulario,
} from '../lib/comun.js';

const MAX_USUARIOS = 300;
const MIN_LARGO_CLAVE = 8;
const MAX_LARGO_CLAVE = 72;
// Hash de relleno: se verifica igual cuando el usuario no existe, para no revelar por el tiempo si existe
const CLAVE_RELLENO = 'scrypt$00000000000000000000000000000000$' + '0'.repeat(128);

async function listarMiembros() {
    const todos = await redis().hvals('usuarios');
    return todos
        .map(texto => JSON.parse(texto))
        .sort((a, b) => a.creado.localeCompare(b.creado))
        .map(u => ({ nombre: u.nombre, usuario: u.usuario, desde: u.creado }));
}

export const GET = manejar(async (request) => {
    const actual = await usuarioActual(request);
    const respuesta = { ok: true, usuario: actual };
    // La lista de compañeros solo la ven quienes tienen cuenta
    if (actual) respuesta.miembros = await listarMiembros();
    return json(200, respuesta);
});

async function registrar(request, datos) {
    const nombre = limpiarTexto(datos.nombre, 60);
    const usuario = String(datos.usuario || '').trim().toLowerCase();
    const clave = String(datos.clave || '');
    if (nombre.length < 2) throw new ErrorApi(400, 'Escribe tu nombre completo.');
    if (!/^[a-z0-9._]{3,20}$/.test(usuario)) throw new ErrorApi(400, 'El usuario debe tener de 3 a 20 caracteres: letras sin tilde, números, punto o guion bajo.');
    if (clave.length < MIN_LARGO_CLAVE) throw new ErrorApi(400, `La contraseña debe tener al menos ${MIN_LARGO_CLAVE} caracteres.`);
    if (clave.length > MAX_LARGO_CLAVE) throw new ErrorApi(400, `La contraseña es demasiado larga (máximo ${MAX_LARGO_CLAVE} caracteres).`);
    await verificarLimite(request, 'registrar', 5, 3600, 'Se crearon muchas cuentas desde esta conexión. Espera una hora e inténtalo de nuevo.');

    if (await redis().hlen('usuarios') >= MAX_USUARIOS) throw new ErrorApi(507, 'Se alcanzó el máximo de cuentas del proyecto.');
    const nuevo = { usuario, nombre, clave: await cifrarClave(clave), creado: new Date().toISOString() };
    // HSETNX es atómico: si dos personas eligen el mismo usuario a la vez, solo una lo obtiene
    const creado = await redis().hsetnx('usuarios', usuario, JSON.stringify(nuevo));
    if (!creado) throw new ErrorApi(409, 'Ese usuario ya existe. Elige otro o inicia sesión.');
    const publico = { usuario, nombre };
    return json(201, { ok: true, usuario: publico }, { 'Set-Cookie': await crearCookieSesion(publico) });
}

async function iniciar(request, datos) {
    const usuario = String(datos.usuario || '').trim().toLowerCase();
    const clave = String(datos.clave || '');
    if (!usuario || !clave) throw new ErrorApi(400, 'Escribe tu usuario y tu contraseña.');
    await verificarLimite(request, 'ingresar', 10, 900, 'Demasiados intentos de ingreso. Espera 15 minutos e inténtalo de nuevo.');

    const guardado = await redis().hget('usuarios', usuario);
    const registro = guardado ? JSON.parse(guardado) : null;
    const valida = await verificarClave(clave, registro ? registro.clave : CLAVE_RELLENO);
    if (!registro || !valida) throw new ErrorApi(401, 'Usuario o contraseña incorrectos.');
    const publico = { usuario: registro.usuario, nombre: registro.nombre };
    return json(200, { ok: true, usuario: publico }, { 'Set-Cookie': await crearCookieSesion(publico) });
}

export const POST = manejar(async (request) => {
    const datos = await leerFormulario(request);
    switch (datos.accion) {
        case 'registrar': return registrar(request, datos);
        case 'iniciar': return iniciar(request, datos);
        case 'cerrar': return json(200, { ok: true }, { 'Set-Cookie': cookieCerrarSesion() });
        default: throw new ErrorApi(400, 'Acción no válida.');
    }
});

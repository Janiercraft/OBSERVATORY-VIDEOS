/** Diagnóstico del backend de Vercel. No expone secretos. */
import { redis } from '../lib/comun.js';
import { list } from '@vercel/blob';

export async function GET() {
  const configuracion = {
    redis: Boolean(
      (process.env.UPSTASH_REDIS_REST_URL && process.env.UPSTASH_REDIS_REST_TOKEN) ||
      (process.env.KV_REST_API_URL && process.env.KV_REST_API_TOKEN)
    ),
    // En proyectos nuevos Vercel Blob también puede autenticarse mediante OIDC.
    blob: Boolean(process.env.BLOB_READ_WRITE_TOKEN || process.env.VERCEL_OIDC_TOKEN),
  };

  let redisDisponible = false;
  let detalleRedis = configuracion.redis ? 'Configurado, pero no fue posible comprobar la conexión.' : 'Faltan variables de entorno de Redis.';
  if (configuracion.redis) {
    try {
      const respuesta = await redis().ping();
      redisDisponible = String(respuesta).toUpperCase() === 'PONG';
      detalleRedis = redisDisponible ? 'Conectado correctamente.' : `Respuesta inesperada: ${String(respuesta)}`;
    } catch (error) {
      detalleRedis = `No conecta: ${error?.message || 'error desconocido'}`;
    }
  }

  let blobDisponible = false;
  let detalleBlob = configuracion.blob ? 'Configurado, pero no fue posible comprobar la conexión.' : 'No se detectó autenticación de Vercel Blob.';
  if (configuracion.blob) {
    try {
      await list({ limit: 1 });
      blobDisponible = true;
      detalleBlob = 'Conectado correctamente.';
    } catch (error) {
      detalleBlob = `No conecta: ${error?.message || 'error desconocido'}`;
    }
  }

  const ok = redisDisponible && blobDisponible;
  return Response.json({
    ok,
    servicio: 'Observatorio Estelar',
    redis: { configurado: configuracion.redis, disponible: redisDisponible, detalle: detalleRedis },
    blob: { configurado: configuracion.blob, disponible: blobDisponible, detalle: detalleBlob },
    consejo: ok
      ? 'Backend y almacenamiento listos.'
      : 'Revisa Storage/Environment Variables en Vercel y vuelve a desplegar después de conectar Redis y Blob.'
  }, { status: ok ? 200 : 503, headers: { 'Cache-Control': 'no-store' } });
}

/** Diagnóstico mínimo del backend de Vercel. No expone secretos. */
import { redis } from '../lib/comun.js';

export async function GET() {
  const configuracion = {
    redis: Boolean(
      (process.env.UPSTASH_REDIS_REST_URL && process.env.UPSTASH_REDIS_REST_TOKEN) ||
      (process.env.KV_REST_API_URL && process.env.KV_REST_API_TOKEN)
    ),
    blob: Boolean(process.env.BLOB_READ_WRITE_TOKEN),
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

  const ok = redisDisponible && configuracion.blob;
  return Response.json({
    ok,
    servicio: 'Observatorio Estelar',
    redis: { configurado: configuracion.redis, disponible: redisDisponible, detalle: detalleRedis },
    blob: { configurado: configuracion.blob },
    consejo: ok
      ? 'Backend listo.'
      : 'Revisa Storage/Environment Variables en Vercel y vuelve a desplegar después de conectar Upstash Redis y Vercel Blob.'
  }, { status: ok ? 200 : 503, headers: { 'Cache-Control': 'no-store' } });
}

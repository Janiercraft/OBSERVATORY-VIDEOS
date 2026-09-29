# Configurar la conexión en Vercel

La web puede cargar aunque el backend no tenga su almacenamiento conectado. Esta versión necesita dos servicios:

1. **Upstash Redis** para usuarios, sesiones, publicaciones y límites anti-spam.
2. **Vercel Blob** para fotos y videos.

## Variables necesarias

En Vercel > Project > Settings > Environment Variables, deben existir:

- `UPSTASH_REDIS_REST_URL`
- `UPSTASH_REDIS_REST_TOKEN`
- `BLOB_READ_WRITE_TOKEN`

También se aceptan las variables antiguas de la integración Redis:

- `KV_REST_API_URL`
- `KV_REST_API_TOKEN`

Después de añadir o conectar Redis, **haz un nuevo deploy**; un despliegue anterior no recibe automáticamente las variables nuevas.

## Diagnóstico

Una vez desplegado abre:

`https://TU-DOMINIO.vercel.app/api/estado`

Debe responder con `"ok": true` y `redis.disponible: true`.

Si `redis.configurado` es `false`, faltan las variables de Upstash.
Si `blob.configurado` es `false`, falta conectar Vercel Blob.

## Importante

No subas `.env.local` a GitHub ni lo compartas. Las credenciales deben permanecer en Vercel/Upstash.

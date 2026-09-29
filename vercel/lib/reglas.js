// Reglas de la bitácora compartidas por api/bitacora.js y api/subida.js (misma lista que la página)

export const ACTIVIDADES = {
    observacion: 'Observación nocturna',
    taller: 'Taller o clase',
    montaje: 'Montaje y mantenimiento',
    salida: 'Salida de campo',
    divulgacion: 'Charla o divulgación',
    reunion: 'Reunión del equipo',
    otra: 'Otra actividad',
};

// Tamaños máximos por tipo de archivo
export const LIMITES_BYTES = {
    imagen: 10 * 1024 * 1024, // 10 MB
    video: 50 * 1024 * 1024,  // 50 MB
};

// Formatos aceptados según el tipo real del archivo
export const TIPOS_PERMITIDOS = {
    'image/jpeg': 'imagen',
    'image/png': 'imagen',
    'image/webp': 'imagen',
    'image/gif': 'imagen',
    'video/mp4': 'video',
    'video/webm': 'video',
};

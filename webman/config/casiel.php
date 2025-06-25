<?php
// --- ARCHIVO: webman\config\casiel.php ---

/**
 * Configuraciones específicas para el servicio Casiel.
 */
return [
    'audio_util' => [
        // Rutas a ejecutables (leídas desde .env para flexibilidad)
        'python_path' => $_ENV['PYTHON_PATH'] ?? 'python',
        'ffmpeg_path' => $_ENV['FFMPEG_PATH'] ?? 'ffmpeg',

        // Ruta al script de análisis de audio.
        'script_path' => base_path() . '/app/utils/audio.py',

        // Directorio temporal para procesar archivos.
        'temp_dir' => runtime_path() . '/tmp/audio',
        
        // Timeout en segundos para el script de Python.
        'python_timeout' => 300, // 5 minutos

        // (NUEVO) Directorios de almacenamiento permanente
        'storage_originals' => base_path() . '/storage/samples_originals',
        'storage_publicos' => public_path() . '/samples',

        // (NUEVO) URL base pública para los audios de stream.
        // Se asume que la carpeta 'public' de webman es la raíz del sitio.
        'public_url_base' => '/samples'
    ]
];
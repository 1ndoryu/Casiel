<?php

/**
 * Configuraciones específicas para el servicio Casiel.
 */
return [
    'audio_util' => [
        // Ruta al ejecutable de Python. Puede ser solo 'python' si está en el PATH.
        'python_path' => 'python',

        // Ruta al script de análisis de audio.
        'script_path' => base_path() . '/app/utils/audio.py',

        // Ruta al ejecutable de FFMPEG.
        'ffmpeg_path' => '/usr/bin/ffmpeg',

        // Directorio temporal para descargar y procesar los archivos de audio.
        'temp_dir' => runtime_path() . '/tmp/audio',
    ]
];

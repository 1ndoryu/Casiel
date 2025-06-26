<?php

return [
    'audio_util' => [
        'python_path' => $_ENV['PYTHON_PATH'] ?? 'python',
        'ffmpeg_path' => $_ENV['FFMPEG_PATH'] ?? 'ffmpeg',
        'script_path' => base_path() . '/app/utils/audio.py',
        'temp_dir' => runtime_path() . '/tmp/audio',
        'python_timeout' => 300,
        'storage_originals' => base_path() . '/storage/samples_originals',
        'storage_publicos' => public_path() . '/samples',
        'public_url_base' => '/samples'
    ],

    'naming' => [
        'brand_name' => 'kamples',
    ],

    // (NUEVO) Configuración para el cliente HTTP que se conecta a Sword
    'sword_client' => [
        // Cambia a 'false' para tests locales si experimentas errores de SSL.
        // ¡IMPORTANTE! Debe ser 'true' en producción por seguridad.
        'verify_ssl' => true,
    ],

    'rabbitmq' => [
        'host' => $_ENV['RABBITMQ_HOST'] ?? 'localhost',
        'port' => $_ENV['RABBITMQ_PORT'] ?? 5672,
        'user' => $_ENV['RABBITMQ_USER'] ?? 'guest',
        'password' => $_ENV['RABBITMQ_PASS'] ?? 'guest',
        'vhost' => $_ENV['RABBITMQ_VHOST'] ?? '/',
        'queue' => 'casiel_processing_queue',
        'dlx_exchange' => 'casiel_dlx', 
        'dlq_queue' => 'casiel_dead_letter_queue',
    ],

    'security' => [
        'internal_api_key' => $_ENV['CASIEL_INTERNAL_API_KEY'] ?? null,
    ]
];
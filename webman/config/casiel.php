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

    // (NUEVO) Configuración para el consumidor de RabbitMQ
    'rabbitmq' => [
        'host' => $_ENV['RABBITMQ_HOST'] ?? 'localhost',
        'port' => $_ENV['RABBITMQ_PORT'] ?? 5672,
        'user' => $_ENV['RABBITMQ_USER'] ?? 'guest',
        'password' => $_ENV['RABBITMQ_PASS'] ?? 'guest',
        'vhost' => $_ENV['RABBITMQ_VHOST'] ?? '/',
        'queue' => 'casiel_processing_queue',
    ]
];

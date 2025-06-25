<?php

/**
 * Configuración para APIs externas.
 * Se utiliza la superglobal $_ENV para máxima fiabilidad en Workerman.
 */
return [
    'sword' => [
        'base_url' => $_ENV['SWORD_BASE_URL'] ?? null,
        'api_url'  => $_ENV['SWORD_API_URL'] ?? null,
        'api_key'  => $_ENV['SWORD_API_KEY'] ?? null,
        // (NUEVO) Lista de campos que la API espera en el nivel raíz del JSON
        'root_fields' => [
            'titulo', 'subtitulo', 'slug', 'estado', 'tipocontenido',
            'descripcion', 'descripcion_corta', 'post_tags'
        ],
    ],
    'gemini' => [
        'api_key' => $_ENV['API_GEMINI'] ?? null,
        'model_id' => 'gemini-1.5-flash-latest',
    ]
];
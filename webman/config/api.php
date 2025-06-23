<?php

/**
 * Configuración para APIs externas.
 */
return [
    'sword' => [
        'base_url' => getenv('SWORD_BASE_URL'),
        'api_url'  => getenv('SWORD_API_URL'),
        'api_key'  => getenv('SWORD_API_KEY'),
    ],

    'gemini' => [
        'api_key' => getenv('API_GEMINI'),
        'model_id' => 'gemini-1.5-flash-latest', // Usamos un modelo más reciente
    ]
];

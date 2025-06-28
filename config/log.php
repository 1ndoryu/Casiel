<?php

use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Logger;

$logLevel = getenv('LOG_LEVEL') ? constant(Logger::class . '::' . strtoupper(getenv('LOG_LEVEL'))) : Logger::DEBUG;
$maxFiles = getenv('LOG_MAX_FILES') ?: 15;
$formatter = [
    'class' => LineFormatter::class,
    'constructor' => [
        'format' => "%datetime% %channel%.%level_name%: %message% %context% %extra%\n",
        'dateFormat' => 'Y-m-d H:i:s',
        'allow_inline_line_breaks' => true,
        'include_stacktraces' => true,
    ],
];

return [
    // Log principal que captura todo
    'default' => [
        'handlers' => [
            [
                'class' => RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/master.log',
                    $maxFiles,
                    $logLevel,
                ],
                'formatter' => $formatter,
            ]
        ],
    ],

    // Canal para el cliente HTTP asíncrono compartido
    'async_http' => [
        'handlers' => [
            [
                'class' => RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/async_http.log',
                    $maxFiles,
                    $logLevel,
                ],
                'formatter' => $formatter,
            ],
            [
                'class' => RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/master.log',
                    $maxFiles,
                    $logLevel,
                ],
                'formatter' => $formatter,
            ]
        ],
    ],

    // Canal específico para el procesamiento de audio
    'audio_processor' => [
        'handlers' => [
            [
                'class' => RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/audio_processor.log',
                    $maxFiles,
                    $logLevel,
                ],
                'formatter' => $formatter,
            ],
            // También enviamos al log maestro
            [
                'class' => RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/master.log',
                    $maxFiles,
                    $logLevel,
                ],
                'formatter' => $formatter,
            ]
        ],
    ],

    // Canal específico para las interacciones con la API de Sword
    'sword_api' => [
        'handlers' => [
            [
                'class' => RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/sword_api.log',
                    $maxFiles,
                    $logLevel,
                ],
                'formatter' => $formatter,
            ],
            [
                'class' => RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/master.log',
                    $maxFiles,
                    $logLevel,
                ],
                'formatter' => $formatter,
            ]
        ],
    ],

    // Canal específico para las interacciones con la API de Gemini
    'gemini_api' => [
        'handlers' => [
            [
                'class' => RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/gemini_api.log',
                    $maxFiles,
                    $logLevel,
                ],
                'formatter' => $formatter,
            ],
            [
                'class' => RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/master.log',
                    $maxFiles,
                    $logLevel,
                ],
                'formatter' => $formatter,
            ]
        ],
    ],

    'quota_service' => [
        'handlers' => [
            [
                'class' => RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/quota_service.log',
                    $maxFiles,
                    $logLevel,
                ],
                'formatter' => $formatter,
            ],
            [
                'class' => RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/master.log',
                    $maxFiles,
                    $logLevel,
                ],
                'formatter' => $formatter,
            ]
        ],
    ],
];

<?php

// config/dependence.php
use app\services\AudioAnalysisService;
use app\services\AudioProcessingService;
use app\services\AudioQueueConsumer;
use app\services\GeminiService;
use app\services\RabbitMqService;
use app\services\SwordApiService;
use Workerman\Http\Client as HttpClient;

return [
    // Definición para el proceso consumidor principal
    AudioQueueConsumer::class => function ($container) {
        return new AudioQueueConsumer(
            $container->get(RabbitMqService::class),
            $container->get(AudioProcessingService::class)
        );
    },

    // Definición para el servicio de procesamiento
    AudioProcessingService::class => function ($container) {
        return new AudioProcessingService(
            $container->get(SwordApiService::class),
            $container->get(AudioAnalysisService::class),
            $container->get(GeminiService::class)
        );
    },

    // Definición para el servicio de RabbitMQ
    RabbitMqService::class => function () {
        return new RabbitMqService();
    },

    // Definición para el servicio de la API de Sword
    SwordApiService::class => function () {
        return new SwordApiService(new HttpClient());
    },

    // Definición para el servicio de análisis de audio
    AudioAnalysisService::class => function () {
        return new AudioAnalysisService(
            getenv('PYTHON_COMMAND') ?: 'python3',
            base_path('audio.py'),
            getenv('FFMPEG_PATH') ?: 'ffmpeg'
        );
    },

    // Definición para el servicio de Gemini
    GeminiService::class => function () {
        return new GeminiService(new HttpClient());
    }
];

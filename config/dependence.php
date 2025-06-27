<?php

use app\services\AudioAnalysisService;
use app\services\GeminiService;
use app\services\SwordApiService;
use Workerman\Http\Client as HttpClient;

/**
 * Service container definitions.
 */
return [
    SwordApiService::class => function () {
        // Inject the http client to make the service testable
        return new SwordApiService(new HttpClient());
    },
    // SOLUCIÓN: Actualizar la definición en el contenedor de dependencias
    // para que coincida con el nuevo constructor que requiere una ruta.
    AudioAnalysisService::class => function () {
        return new AudioAnalysisService(base_path('audio.py'));
    },
    GeminiService::class => function () {
        // This service now requires an HttpClient for testability
        return new GeminiService(new HttpClient());
    }
    // Se eliminó la definición de AudioQueueConsumer::class de aquí,
    // ya que ahora se gestiona en config/process.php
];
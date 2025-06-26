<?php

use app\services\AudioAnalysisService;
use app\services\GeminiService;
use app\services\SwordApiService;
use Workerman\Http\Client as HttpClient;

/**
 * Service container definitions.
 */
return [
    SwordApiService::class => function() {
        // Inject the http client to make the service testable
        return new SwordApiService(new HttpClient());
    },
    AudioAnalysisService::class => function() {
        return new AudioAnalysisService();
    },
    GeminiService::class => function() {
        // In the future, this service could also benefit from http client injection
        return new GeminiService();
    }
    // Se eliminó la definición de AudioQueueConsumer::class de aquí,
    // ya que ahora se gestiona en config/process.php
];
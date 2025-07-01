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

    // SOLUTION: Update the service definition to inject the python and ffmpeg commands from .env
    AudioAnalysisService::class => function () {
        $pythonCommand = getenv('PYTHON_COMMAND') ?: 'python3'; // Fallback to 'python3' for Linux/Mac
        $ffmpegPath = getenv('FFMPEG_PATH') ?: 'ffmpeg'; // Fallback to 'ffmpeg'
        return new AudioAnalysisService($pythonCommand, base_path('audio.py'), $ffmpegPath);
    },

    GeminiService::class => function () {
        // This service now requires an HttpClient for testability
        return new GeminiService(new HttpClient());
    }
    // Se eliminó la definición de AudioQueueConsumer::class de aquí,
    // ya que ahora se gestiona en config/process.php
];

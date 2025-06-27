<?php

use support\Log;
use support\Request;
use app\process\Http;
use app\process\AudioQueueConsumer;
use app\services\SwordApiService;
use app\services\AudioAnalysisService;
use app\services\GeminiService;
use Workerman\Http\Client as HttpClient;

global $argv;

return [
  'webman' => [
    'handler' => Http::class,
    'listen' => 'http://0.0.0.0:8787',
    'count' => cpu_count() * 4,
    'user' => '',
    'group' => '',
    'reusePort' => false,
    'eventLoop' => '',
    'context' => [],
    'constructor' => [
      'requestClass' => Request::class,
      'logger' => Log::channel('default'),
      'appPath' => app_path(),
      'publicPath' => public_path()
    ]
  ],
  // File update detection and automatic reload
  'monitor' => [
    'handler' => app\process\Monitor::class,
    'reloadable' => false,
    'constructor' => [
      // Monitor these directories
      'monitorDir' => array_merge([
        app_path(),
        config_path(),
        base_path() . '/process',
        base_path() . '/support',
        base_path() . '/resource',
        base_path() . '/.env',
      ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
      // Files with these suffixes will be monitored
      'monitorExtensions' => [
        'php',
        'html',
        'htm',
        'env'
      ],
      'options' => [
        'enable_file_monitor' => !in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/',
        'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
      ]
    ]
  ],
  // RabbitMQ consumer process
  'audio_queue_consumer' => [
    'handler' => AudioQueueConsumer::class,
    'constructor' => [
      // Instantiate dependencies manually for the process
      'swordApiService' => new SwordApiService(new HttpClient()),
      'audioAnalysisService' => new AudioAnalysisService(base_path('audio.py')),
      'geminiService' => new GeminiService(new HttpClient()),
      'httpClient' => new HttpClient() // Inject the http client for downloads
    ]
  ]
];
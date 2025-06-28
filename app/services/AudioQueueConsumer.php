<?php

namespace app\services; // <-- CORRECCIÓN: El namespace ahora es 'app\services'

use app\services\AudioProcessingService;
use app\services\RabbitMqService;
use Workerman\Worker;

/**
 * Connects the RabbitMQ listener with the audio processing logic.
 */
class AudioQueueConsumer
{
    public function __construct(
        private RabbitMqService $rabbitMqService,
        private AudioProcessingService $audioProcessingService
    ) {}

    public function onWorkerStart(Worker $worker)
    {
        // Tell the RabbitMQ service to start listening, and when a message arrives,
        // pass it to the 'process' method of our AudioProcessingService.
        $this->rabbitMqService->startListening([$this->audioProcessingService, 'process']);
    }
}
<?php

namespace app\services;

use PhpAmqpLib\Message\AMQPMessage;
use Throwable;
use PhpAmqpLib\Channel\AMQPChannel;

class AudioProcessingService
{
    public function __construct(
        private SwordApiService $swordApiService,
        private AudioAnalysisService $audioAnalysisService,
        private GeminiService $geminiService
    ) {}

    public function process(AMQPMessage $msg, AMQPChannel $channel): void
    {
        $payload = json_decode($msg->body, true);
        $contentId = $payload['data']['content_id'] ?? null;
        $mediaId = $payload['data']['media_id'] ?? null;

        // This is a stateful service, create a new one for each job.
        $fileHandler = new FileHandlerService();

        // The failure handler needs the Sword API to mark content as failed.
        $failureHandler = new JobFailureHandler($this->swordApiService);

        if (!$contentId || !$mediaId) {
            casiel_log('audio_processor', 'Mensaje inválido, faltan content_id o media_id. Descartando permanentemente.', ['body' => $msg->body], 'error');
            // This is a malformed message, send straight to final DLQ and ack.
            $channel->basic_publish($msg, 'casiel_dlx', 'casiel.dlq.final');
            $channel->basic_ack($msg->getDeliveryTag());
            return;
        }

        // The workflow orchestrator that contains the main logic.
        $workflow = new AudioWorkflowService(
            $this->swordApiService,
            $this->audioAnalysisService,
            $this->geminiService,
            $fileHandler
        );

        $onSuccess = function () use ($msg, $channel, $fileHandler, $contentId) {
            casiel_log('audio_processor', "Flujo de trabajo para content_id: {$contentId} completado con éxito.", [], 'info');
            $fileHandler->cleanupFiles();
            $channel->basic_ack($msg->getDeliveryTag());
        };

        $onError = function (string $errorMessage) use ($msg, $channel, $contentId, $failureHandler, $fileHandler) {
            $failureHandler->handle($msg, $channel, $errorMessage, $contentId, $fileHandler);
        };

        try {
            casiel_log('audio_processor', "Iniciando flujo de trabajo. content_id: {$contentId}, media_id: {$mediaId}");
            $workflow->run($contentId, $mediaId, $onSuccess, $onError);
        } catch (Throwable $e) {
            // Catch any synchronous exception during workflow setup.
            $failureHandler->handle($msg, $channel, "Excepción síncrona fatal: " . $e->getMessage(), $contentId, $fileHandler, true);
        }
    }
}

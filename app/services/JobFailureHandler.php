<?php

namespace app\services;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;

class JobFailureHandler
{
    private const MAX_RETRIES = 3;

    public function __construct(private SwordApiService $swordApiService) {}

    /**
     * Handles a failed audio processing job.
     *
     * @param AMQPMessage $msg The original message.
     * @param AMQPChannel $channel The channel the message came from.
     * @param string $errorMessage The reason for failure.
     * @param int $contentId The ID of the content being processed.
     * @param FileHandlerService $fileHandler The file handler for this specific job to perform cleanup.
     * @param bool $isFinal Determines if failure is final, bypassing retry logic.
     */
    public function handle(
        AMQPMessage $msg,
        AMQPChannel $channel,
        string $errorMessage,
        int $contentId,
        FileHandlerService $fileHandler,
        bool $isFinal = false
    ): void {
        casiel_log('audio_processor', "Error procesando content_id: {$contentId}. Razón: {$errorMessage}", [], 'error');

        $fileHandler->cleanupFiles();

        $retryCount = $this->getRetryCount($msg);

        if ($retryCount < self::MAX_RETRIES && !$isFinal) {
            casiel_log('audio_processor', "Proceso fallido. Rechazando para reintento v." . ($retryCount + 1) . ".", ['content_id' => $contentId]);
            // Nack the message to send it to the retry queue via the dead-letter exchange.
            $channel->basic_nack($msg->getDeliveryTag(), false, false);
        } else {
            casiel_log('audio_processor', "Fallo final después de " . ($retryCount + 1) . " intentos. Marcando como fallido.", ['content_id' => $contentId], 'error');

            $failureData = ['content_data' => ['casiel_status' => 'failed', 'casiel_error' => $errorMessage]];

            $this->swordApiService->updateContent(
                $contentId,
                $failureData,
                function () use ($msg, $channel, $contentId) {
                    casiel_log('audio_processor', "Contenido {$contentId} marcado como fallido en Sword. Publicando en DLQ final y confirmando mensaje.", [], 'info');
                    $channel->basic_publish($msg, 'casiel_dlx', 'casiel.dlq.final');
                    $channel->basic_ack($msg->getDeliveryTag());
                },
                function ($updateError) use ($msg, $channel, $contentId) {
                    casiel_log('audio_processor', "¡CRÍTICO! No se pudo actualizar estado de fallo del contenido {$contentId}. Publicando en DLQ de todas formas.", ['error' => $updateError], 'critical');
                    $channel->basic_publish($msg, 'casiel_dlx', 'casiel.dlq.final');
                    $channel->basic_ack($msg->getDeliveryTag());
                }
            );
        }
    }

    private function getRetryCount(AMQPMessage $msg): int
    {
        if (!$msg->has('application_headers')) {
            return 0;
        }

        $headers = $msg->get('application_headers')->getNativeData();
        if (isset($headers['x-death'])) {
            foreach ($headers['x-death'] as $death) {
                if ($death['queue'] === getenv('RABBITMQ_WORK_QUEUE')) {
                    // The 'count' is the number of times it has been dead-lettered
                    return (int) $death['count'];
                }
            }
        }
        return 0;
    }
}

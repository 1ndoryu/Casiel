<?php

namespace app\services;

use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class AudioProcessingService
{
    private const MAX_RETRIES = 3;

    public function __construct(
        private SwordApiService $swordApiService,
        private AudioAnalysisService $audioAnalysisService,
        private GeminiService $geminiService,
        private FileHandlerService $fileHandler // NUEVO: Inyectar el manejador de archivos
    ) {
    }

    public function process(AMQPMessage $msg, \PhpAmqpLib\Channel\AMQPChannel $channel): void
    {
        $payload = json_decode($msg->body, true);
        $contentId = $payload['data']['content_id'] ?? null;
        $mediaId = $payload['data']['media_id'] ?? null;

        $handleFailure = function (string $errorMessage, bool $isFinal = false) use ($msg, $contentId, $channel) {
            casiel_log('audio_processor', "Error procesando content_id: {$contentId}. Razón: {$errorMessage}", [], 'error');

            $this->fileHandler->cleanupFiles(); // Limpiar usando el servicio

            $retryCount = 0;
            if ($headers = $this->getMessageHeaders($msg)) {
                if (isset($headers['x-death'])) {
                    foreach ($headers['x-death'] as $death) {
                        if (isset($death['queue']) && $death['queue'] === getenv('RABBITMQ_WORK_QUEUE')) {
                            $retryCount = $death['count'];
                            break;
                        }
                    }
                }
            }

            if ($retryCount < self::MAX_RETRIES && !$isFinal) {
                casiel_log('audio_processor', "Proceso fallido. Reintentando (" . ($retryCount + 1) . "/" . (self::MAX_RETRIES) . ").", ['content_id' => $contentId]);
                $msg->nack(false);
            } else {
                casiel_log('audio_processor', "Fallo final después de " . ($retryCount + 1) . " intentos. Marcando como fallido y enviando a DLQ.", ['content_id' => $contentId], 'error');
                $failureData = ['content_data' => ['casiel_status' => 'failed', 'casiel_error' => $errorMessage]];
                $this->swordApiService->updateContent(
                    $contentId,
                    $failureData,
                    function () use ($msg, $channel, $contentId) {
                        casiel_log('audio_processor', "Contenido {$contentId} marcado como fallido en Sword. Enviando a DLQ final.", [], 'info');
                        $channel->basic_publish($msg, 'casiel_dlx', 'casiel.dlq.final');
                        $msg->ack();
                    },
                    function ($updateError) use ($msg, $channel, $contentId) {
                        casiel_log('audio_processor', "¡CRÍTICO! No se pudo actualizar el contenido {$contentId} con el estado de fallo. Enviando a DLQ de todas formas.", ['error' => $updateError], 'critical');
                        $channel->basic_publish($msg, 'casiel_dlx', 'casiel.dlq.final');
                        $msg->ack();
                    }
                );
            }
        };

        if (!$contentId || !$mediaId) {
            casiel_log('audio_processor', 'Mensaje inválido, faltan content_id o media_id. Descartando permanentemente.', ['body' => $msg->body], 'error');
            $channel->basic_publish($msg, 'casiel_dlx', 'casiel.dlq.final');
            $msg->ack();
            return;
        }

        try {
            casiel_log('audio_processor', "Iniciando flujo. content_id: {$contentId}, media_id: {$mediaId}");
            $this->runWorkflow($contentId, $mediaId, $msg, $handleFailure);
        } catch (Throwable $e) {
            $handleFailure("Excepción síncrona fatal: " . $e->getMessage(), true);
        }
    }

    protected function runWorkflow(int $contentId, int $mediaId, AMQPMessage $msg, callable $handleFailure): void
    {
        $this->swordApiService->getContent(
            $contentId,
            function ($existingContent) use ($contentId, $mediaId, $msg, $handleFailure) {
                casiel_log('audio_processor', "Step 1/8: Contenido existente obtenido.", ['content_id' => $contentId]);
                $existingContentData = $existingContent['content_data'] ?? [];

                $this->swordApiService->getMediaDetails(
                    $mediaId,
                    function ($mediaDetails) use ($contentId, $msg, $handleFailure, $existingContentData) {
                        $audioUrl = $mediaDetails['path'] ?? null;
                        $originalFilename = $mediaDetails['metadata']['original_name'] ?? 'audio.tmp';
                        if (!$audioUrl) {
                            $handleFailure("No se pudo encontrar 'path' en los detalles del medio.");
                            return;
                        }
                        casiel_log('audio_processor', "Step 2/8: Detalles del medio obtenidos.", ['content_id' => $contentId]);

                        $fullAudioUrl = rtrim(getenv('SWORD_API_URL'), '/') . '/' . ltrim($audioUrl, '/');
                        $localPath = $this->fileHandler->createOriginalFilePath($mediaDetails['id'], $originalFilename);

                        $this->swordApiService->downloadFile(
                            $fullAudioUrl,
                            $localPath,
                            function () use ($localPath, $contentId, $originalFilename, $msg, $handleFailure, $existingContentData) {
                                casiel_log('audio_processor', "Step 3/8: Audio descargado.", ['content_id' => $contentId]);

                                $techData = $this->audioAnalysisService->analyze($localPath);
                                if ($techData === null) {
                                    $handleFailure("El análisis técnico del audio falló.");
                                    return;
                                }
                                casiel_log('audio_processor', "Step 4/8: Metadatos técnicos obtenidos.", ['content_id' => $contentId]);

                                $geminiContext = [
                                    'title' => pathinfo($originalFilename, PATHINFO_FILENAME),
                                    'technical_metadata' => $techData,
                                    'existing_metadata' => $existingContentData
                                ];
                                $this->geminiService->analyzeAudio(
                                    $localPath,
                                    $geminiContext,
                                    function ($creativeData) use ($techData, $contentId, $localPath, $originalFilename, $msg, $handleFailure, $existingContentData) {
                                        if ($creativeData === null) {
                                            $handleFailure("El análisis creativo con Gemini falló.");
                                            return;
                                        }
                                        casiel_log('audio_processor', "Step 5/8: Metadatos creativos obtenidos.", ['content_id' => $contentId]);
                                        
                                        $baseNameForLight = $creativeData['nombre_archivo_base'] ?? "{$contentId}_light";
                                        $lightweightPath = $this->fileHandler->createLightweightFilePath($baseNameForLight);

                                        if (!$this->audioAnalysisService->generateLightweightVersion($localPath, $lightweightPath)) {
                                            $handleFailure("Falló la generación de la versión ligera.");
                                            return;
                                        }
                                        casiel_log('audio_processor', "Step 6/8: Versión ligera generada.", ['content_id' => $contentId]);

                                        $this->swordApiService->uploadMedia(
                                            $lightweightPath,
                                            function ($lightweightMediaData) use ($techData, $creativeData, $contentId, $originalFilename, $msg, $handleFailure, $existingContentData) {
                                                $lightweightMediaId = $lightweightMediaData['id'] ?? null;
                                                if (!$lightweightMediaId) {
                                                    $handleFailure("La subida de la versión ligera no devolvió 'id'.");
                                                    return;
                                                }
                                                casiel_log('audio_processor', "Step 7/8: Versión ligera subida.", ['content_id' => $contentId, 'media_id' => $lightweightMediaId]);

                                                $casielGeneratedData = array_merge(
                                                    $techData, $creativeData, [
                                                        'casiel_status' => 'success',
                                                        'original_media_id' => $msg->body['data']['media_id'] ?? null,
                                                        'light_media_id' => $lightweightMediaId,
                                                        'original_filename' => $originalFilename,
                                                    ]
                                                );
                                                $finalContentData = $casielGeneratedData + $existingContentData;
                                                
                                                $newFileNameBase = $creativeData['nombre_archivo_base'] ?? "audio_sample_{$contentId}";
                                                $finalPayload = [
                                                    'content_data' => $finalContentData,
                                                    'slug' => str_replace(' ', '_', $newFileNameBase) . "_" . substr(bin2hex(random_bytes(4)), 0, 4)
                                                ];

                                                $this->swordApiService->updateContent(
                                                    $contentId,
                                                    $finalPayload,
                                                    function () use ($contentId, $msg) {
                                                        casiel_log('audio_processor', "Step 8/8: Contenido {$contentId} actualizado. ¡Éxito!", [], 'info');
                                                        $msg->ack();
                                                        $this->fileHandler->cleanupFiles();
                                                    },
                                                    fn($err) => $handleFailure($err)
                                                );
                                            },
                                            fn($err) => $handleFailure($err)
                                        );
                                    },
                                    fn($err) => $handleFailure($err)
                                );
                            },
                            fn($err) => $handleFailure("Error en descarga: " . $err)
                        );
                    },
                    fn($err) => $handleFailure($err)
                );
            },
            fn($err) => $handleFailure($err)
        );
    }

    protected function getMessageHeaders(AMQPMessage $msg): ?array
    {
        return $msg->has('application_headers') ? $msg->get('application_headers')->getNativeData() : null;
    }
}
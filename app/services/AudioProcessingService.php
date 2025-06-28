<?php

namespace app\services;

use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class AudioProcessingService
{
    private const MAX_RETRIES = 3;
    private string $tempDir;

    public function __construct(
        private SwordApiService $swordApiService,
        private AudioAnalysisService $audioAnalysisService,
        private GeminiService $geminiService
    ) {
        $this->tempDir = runtime_path() . '/tmp/audio_processing';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    /**
     * SOLUCIÓN: Nuevo método para eliminar archivos temporales de forma centralizada.
     * @param array $filesToDelete
     */
    private function cleanupFiles(array $filesToDelete): void
    {
        foreach ($filesToDelete as $file) {
            if (file_exists($file)) {
                @unlink($file);
                casiel_log('audio_processor', "Cleanup: Archivo temporal eliminado: " . basename($file), [], 'debug');
            }
        }
    }

    public function process(AMQPMessage $msg, \PhpAmqpLib\Channel\AMQPChannel $channel): void
    {
        $payload = json_decode($msg->body, true);
        $contentId = $payload['data']['content_id'] ?? null;
        $mediaId = $payload['data']['media_id'] ?? null;
        $filesToDelete = [];

        // SOLUCIÓN: El manejador de fallos ahora captura $filesToDelete por referencia para poder limpiarlos.
        $handleFailure = function (string $errorMessage) use ($msg, $contentId, $mediaId, $channel, &$filesToDelete) {
            casiel_log('audio_processor', "Error procesando. content_id: {$contentId}, media_id: {$mediaId}. Razón: {$errorMessage}", [], 'error');

            // Realizar la limpieza también en caso de fallo.
            $this->cleanupFiles($filesToDelete);

            $retryCount = 0;
            if ($headers = $this->getMessageHeaders($msg)) {
                if (isset($headers['x-death'][0]['count'])) {
                    $retryCount = $headers['x-death'][0]['count'];
                }
            }
            if ($retryCount < self::MAX_RETRIES) {
                casiel_log('audio_processor', "Proceso fallido. Reintentando (" . ($retryCount + 1) . "/" . (self::MAX_RETRIES + 1) . ").", ['content_id' => $contentId]);
                // Se usa nack y se deja que la DLX de reintento haga su trabajo.
                $msg->nack(false);
            } else {
                casiel_log('audio_processor', "Fallo final después de " . ($retryCount + 1) . " intentos. Enviando a DLQ.", ['content_id' => $contentId], 'error');
                $channel->basic_publish($msg, 'casiel_dlx', 'casiel.dlq.final');
                $msg->ack();
            }
        };

        if (!$contentId || !$mediaId) {
            casiel_log('audio_processor', 'Mensaje inválido o faltan IDs requeridos. Descartando permanentemente.', ['body' => $msg->body], 'error');
            $channel->basic_publish($msg, 'casiel_dlx', 'casiel.dlq.final');
            $msg->ack();
            return;
        }

        // SOLUCIÓN: Se elimina el bloque finally. La limpieza ahora es parte del flujo de éxito/error.
        try {
            casiel_log('audio_processor', "Iniciando flujo. content_id: {$contentId}, media_id: {$mediaId}");
            $this->runWorkflow($contentId, $mediaId, $msg, $handleFailure, $filesToDelete);
        } catch (Throwable $e) {
            $handleFailure("Excepción síncrona fatal: " . $e->getMessage());
        }
    }

    protected function runWorkflow(int $contentId, int $mediaId, AMQPMessage $msg, callable $handleFailure, array &$filesToDelete): void
    {
        $this->swordApiService->getMediaDetails(
            $mediaId,
            function ($mediaDetails) use ($contentId, $mediaId, $msg, $handleFailure, &$filesToDelete) {
                $audioUrl = $mediaDetails['path'] ?? null;
                $originalFilename = $mediaDetails['metadata']['original_name'] ?? 'audio.tmp';
                if (!$audioUrl) {
                    $handleFailure("No se pudo encontrar 'path' en los detalles del medio de Sword.");
                    return;
                }

                $fullAudioUrl = rtrim(getenv('SWORD_API_URL'), '/') . '/' . ltrim($audioUrl, '/');
                $extension = pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'tmp';
                $localPath = "{$this->tempDir}/{$mediaId}_original.{$extension}";
                $filesToDelete[] = $localPath;
                casiel_log('audio_processor', "Step 1/7: URL de audio obtenida.");

                $this->swordApiService->downloadFile(
                    $fullAudioUrl,
                    $localPath,
                    function ($downloadedFilePath) use ($localPath, $contentId, $originalFilename, $msg, $handleFailure, &$filesToDelete) {
                        casiel_log('audio_processor', "Step 2/7: Audio descargado.");

                        $techData = $this->audioAnalysisService->analyze($localPath);
                        if ($techData === null) {
                            $handleFailure("El análisis técnico del audio falló.");
                            return;
                        }
                        casiel_log('audio_processor', "Step 3/7: Metadatos técnicos obtenidos.");

                        $geminiContext = ['title' => pathinfo($originalFilename, PATHINFO_FILENAME), 'technical_metadata' => $techData];
                        $this->geminiService->analyzeAudio(
                            $localPath,
                            $geminiContext,
                            function ($creativeData) use ($techData, $contentId, $localPath, $msg, $handleFailure, &$filesToDelete) {
                                if ($creativeData === null) {
                                    $handleFailure("El análisis creativo con Gemini falló.");
                                    return;
                                }
                                casiel_log('audio_processor', "Step 4/7: Metadatos creativos obtenidos.");

                                $lightweightPath = "{$this->tempDir}/{$contentId}_light.mp3";
                                $filesToDelete[] = $lightweightPath;
                                if (!$this->audioAnalysisService->generateLightweightVersion($localPath, $lightweightPath)) {
                                    $handleFailure("Falló la generación de la versión ligera.");
                                    return;
                                }
                                casiel_log('audio_processor', "Step 5/7: Versión ligera generada.");

                                $this->swordApiService->uploadMedia(
                                    $lightweightPath,
                                    function ($lightweightMediaData) use ($techData, $creativeData, $contentId, $msg, $handleFailure, &$filesToDelete) {
                                        $lightweightUrl = $lightweightMediaData['path'] ?? null;
                                        if (!$lightweightUrl) {
                                            $handleFailure("La subida de la versión ligera no devolvió un 'path'.");
                                            return;
                                        }
                                        casiel_log('audio_processor', "Step 6/7: Versión ligera subida.");

                                        $newFileNameBase = $creativeData['nombre_archivo_base'] ?? "audio_sample_{$contentId}";
                                        $finalData = [
                                            'content_data' => array_merge($creativeData, $techData, ['light_version_url' => $lightweightUrl]),
                                            'slug' => str_replace(' ', '_', $newFileNameBase) . "_" . substr(bin2hex(random_bytes(4)), 0, 4)
                                        ];

                                        $this->swordApiService->updateContent(
                                            $contentId,
                                            $finalData,
                                            // SOLUCIÓN: El callback final ahora también captura $filesToDelete para limpiarlos.
                                            function () use ($contentId, $msg, &$filesToDelete) {
                                                casiel_log('audio_processor', "Step 7/7: Contenido {$contentId} actualizado. ¡Éxito!");
                                                $msg->ack();
                                                $this->cleanupFiles($filesToDelete);
                                            },
                                            $handleFailure
                                        );
                                    },
                                    $handleFailure
                                );
                            },
                            $handleFailure
                        );
                    },
                    fn($error) => $handleFailure("Excepción durante la descarga: " . $error)
                );
            },
            $handleFailure
        );
    }

    protected function getMessageHeaders(AMQPMessage $msg): ?array
    {
        return $msg->has('application_headers') ? $msg->get('application_headers')->getNativeData() : null;
    }

    // SOLUCIÓN: El método scheduleCleanup() se ha eliminado. Su funcionalidad ahora está en cleanupFiles() y se llama directamente.
}

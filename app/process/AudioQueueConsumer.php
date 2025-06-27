<?php

namespace app\process;

use app\services\AudioAnalysisService;
use app\services\GeminiService;
use app\services\SwordApiService;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;
use Workerman\Worker;
use Workerman\Http\Client;

/**
 * Class AudioQueueConsumer
 * Consumes audio processing jobs from a RabbitMQ queue with retry and dead-lettering logic.
 */
class AudioQueueConsumer
{
    private SwordApiService $swordApiService;
    private AudioAnalysisService $audioAnalysisService;
    private GeminiService $geminiService;
    private Client $httpClient;
    protected string $tempDir;
    private const MAX_RETRIES = 3;

    public function __construct(
        SwordApiService $swordApiService,
        AudioAnalysisService $audioAnalysisService,
        GeminiService $geminiService,
        Client $httpClient // Injected dependency
    ) {
        $this->swordApiService = $swordApiService;
        $this->audioAnalysisService = $audioAnalysisService;
        $this->geminiService = $geminiService;
        $this->httpClient = $httpClient; // Store client
    }

    public function onWorkerStart(Worker $worker)
    {
        $this->tempDir = runtime_path() . '/tmp/audio_processing';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }

        //casiel_log('audio_processor', 'Iniciando consumidor de cola de audio...');
        $this->connectAndConsume();
    }

    private function setupQueues($channel): void
    {
        $mainExchange = 'casiel_main_exchange';
        $dlx = 'casiel_dlx'; // Dead-Letter Exchange: Un "router" para mensajes fallidos.
        $retryDelay = 60000; // 1 minuto en milisegundos

        $channel->exchange_declare($mainExchange, 'direct', false, true, false);
        $channel->exchange_declare($dlx, 'direct', false, true, false);

        $finalDlq = 'casiel_audio_dlq';
        $channel->queue_declare($finalDlq, false, true, false, false);
        $channel->queue_bind($finalDlq, $dlx, 'casiel.dlq.final');

        $retryQueue = 'casiel_audio_retry_queue';
        $channel->queue_declare($retryQueue, false, true, false, false, new AMQPTable([
            'x-message-ttl' => $retryDelay,
            'x-dead-letter-exchange' => $mainExchange,
            'x-dead-letter-routing-key' => 'casiel.process'
        ]));
        $channel->queue_bind($retryQueue, $dlx, 'casiel.dlq.retry');

        $workQueue = getenv('RABBITMQ_WORK_QUEUE');
        $channel->queue_declare($workQueue, false, true, false, false, new AMQPTable([
            'x-dead-letter-exchange' => $dlx,
            'x-dead-letter-routing-key' => 'casiel.dlq.retry'
        ]));
        $channel->queue_bind($workQueue, $mainExchange, 'casiel.process');
    }

    private function connectAndConsume(): void
    {
        try {
            $connection = new AMQPStreamConnection(
                getenv('RABBITMQ_HOST'),
                getenv('RABBITMQ_PORT'),
                getenv('RABBITMQ_USER'),
                getenv('RABBITMQ_PASS'),
                getenv('RABBITMQ_VHOST')
            );
            $channel = $connection->channel();
            $this->setupQueues($channel);

            $queueName = getenv('RABBITMQ_WORK_QUEUE');

            $callback = function (AMQPMessage $msg) use ($channel) {
                casiel_log('audio_processor', "Mensaje recibido de la cola.", ['body' => $msg->body]);
                $this->processMessage($msg, $channel);
            };

            $channel->basic_consume($queueName, '', false, false, false, false, $callback);

            while ($channel->is_consuming()) {
                $channel->wait(null, false, 5);
            }

            $channel->close();
            $connection->close();
        } catch (AMQPProtocolChannelException $e) {
            sleep(30);
            $this->connectAndConsume();
        } catch (Throwable $e) {
            sleep(10);
            $this->connectAndConsume();
        }
    }

    /**
     * Extracts the native array of application headers from the message.
     * Protected to allow mocking in tests by overriding it.
     *
     * @param AMQPMessage $msg
     * @return array|null
     */
    protected function getMessageHeaders(AMQPMessage $msg): ?array
    {
        if (!$msg->has('application_headers')) {
            return null;
        }
        return $msg->get('application_headers')->getNativeData();
    }

    public function processMessage(AMQPMessage $msg, $channel): void
    {
        $payload = json_decode($msg->body, true);
        $contentId = $payload['data']['id'] ?? null;
        $filesToDelete = [];

        $handleFailure = function (string $errorMessage) use ($msg, $contentId, $channel) {
            casiel_log('audio_processor', "Error procesando ID {$contentId}: {$errorMessage}", [], 'error');

            $retryCount = 0;
            $headers = $this->getMessageHeaders($msg); // Use the new helper method

            if (isset($headers['x-death'])) {
                foreach ($headers['x-death'] as $death) {
                    if ($death['queue'] === getenv('RABBITMQ_WORK_QUEUE')) {
                        $retryCount = $death['count'];
                        break;
                    }
                }
            }

            if ($retryCount < self::MAX_RETRIES) {
                casiel_log('audio_processor', "Fallo en procesamiento. Reintentando (" . ($retryCount + 1) . "/" . (self::MAX_RETRIES + 1) . ").", ['content_id' => $contentId]);
                $msg->nack(false);
            } else {
                casiel_log('audio_processor', "Fallo final después de " . ($retryCount + 1) . " intentos. Enviando a la cola de letras muertas.", ['content_id' => $contentId], 'error');
                $channel->basic_publish($msg, 'casiel_dlx', 'casiel.dlq.final');
                $msg->ack();
            }
        };

        if (json_last_error() !== JSON_ERROR_NONE || !$contentId) {
            casiel_log('audio_processor', 'Mensaje inválido o sin ID de contenido. Descartando permanentemente.', ['body' => $msg->body], 'error');
            // This path is for fundamentally broken messages, send straight to final DLQ
            $channel->basic_publish($msg, 'casiel_dlx', 'casiel.dlq.final');
            $msg->ack();
            return;
        }

        try {
            casiel_log('audio_processor', "Iniciando flujo asíncrono para contenido ID: {$contentId}");

            // STEP 1: Get media details from Sword
            $this->swordApiService->getMediaDetails(
                $contentId,
                function ($mediaDetails) use ($contentId, $handleFailure, &$filesToDelete, $msg) {
                    $audioUrl = $mediaDetails['path'] ?? null;
                    $originalFilename = $mediaDetails['metadata']['original_name'] ?? 'audio.tmp';
                    if (!$audioUrl) {
                        $handleFailure("No se encontró la ruta del audio (path) en los detalles del medio de Sword.");
                        return;
                    }

                    $fullAudioUrl = rtrim(getenv('SWORD_API_URL'), '/') . '/' . ltrim($audioUrl, '/');
                    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'tmp';
                    $localPath = "{$this->tempDir}/{$contentId}_original.{$extension}";
                    $filesToDelete[] = $localPath;
                    casiel_log('audio_processor', "Paso 1 completado. URL de audio: {$fullAudioUrl}");

                    // STEP 2: Download the audio file
                    $this->httpClient->get($fullAudioUrl, [], function ($response) use ($localPath, $contentId, $handleFailure, $originalFilename, &$filesToDelete, $msg) {
                        if ($response->getStatusCode() !== 200) {
                            $handleFailure("No se pudo descargar el archivo de audio. Status: " . $response->getStatusCode());
                            return;
                        }
                        file_put_contents($localPath, (string)$response->getBody());
                        casiel_log('audio_processor', "Paso 2 completado. Audio descargado en: {$localPath}");

                        // STEP 3: Get technical metadata
                        $techData = $this->audioAnalysisService->analyze($localPath);
                        if ($techData === null) {
                            $handleFailure("Falló el análisis técnico del audio.");
                            return;
                        }
                        casiel_log('audio_processor', "Paso 3 completado. Metadatos técnicos obtenidos.");

                        // STEP 4: Get creative metadata from Gemini
                        $geminiContext = ['title' => pathinfo($originalFilename, PATHINFO_FILENAME), 'technical_metadata' => $techData];
                        $this->geminiService->analyzeAudio(
                            $localPath,
                            $geminiContext,
                            function ($creativeData) use ($techData, $contentId, $handleFailure, $localPath, &$filesToDelete, $msg) {
                                if ($creativeData === null) {
                                    $handleFailure("Falló el análisis creativo con Gemini.");
                                    return;
                                }
                                casiel_log('audio_processor', "Paso 4 completado. Metadatos creativos obtenidos.");

                                // STEP 5: Generate lightweight version
                                $lightweightPath = "{$this->tempDir}/{$contentId}_light.mp3";
                                $filesToDelete[] = $lightweightPath;
                                if (!$this->audioAnalysisService->generateLightweightVersion($localPath, $lightweightPath)) {
                                    $handleFailure("Falló la generación de la versión ligera del audio.");
                                    return;
                                }
                                casiel_log('audio_processor', "Paso 5 completado. Versión ligera generada.");

                                // STEP 6: Upload lightweight version
                                $this->swordApiService->uploadMedia(
                                    $lightweightPath,
                                    function ($lightweightMediaData) use ($techData, $creativeData, $contentId, $handleFailure, $msg) {
                                        $lightweightUrl = $lightweightMediaData['path'] ?? null;
                                        if (!$lightweightUrl) {
                                            $handleFailure("La subida de la versión ligera no devolvió una ruta (path).");
                                            return;
                                        }
                                        casiel_log('audio_processor', "Paso 6 completado. Versión ligera subida a: {$lightweightUrl}");

                                        // STEP 7: Merge data and update Sword
                                        $newFileNameBase = $creativeData['nombre_archivo_base'] ?? "audio_sample_{$contentId}";
                                        $randomSuffix = substr(bin2hex(random_bytes(4)), 0, 4);
                                        $finalData = [
                                            'content_data' => array_merge($creativeData, $techData, ['light_version_url' => $lightweightUrl]),
                                            'slug' => str_replace(' ', '_', $newFileNameBase) . "_{$randomSuffix}"
                                        ];

                                        $this->swordApiService->updateContent(
                                            $contentId,
                                            $finalData,
                                            function () use ($contentId, $msg) {
                                                casiel_log('audio_processor', "Paso 7 completado. Contenido {$contentId} actualizado en Sword.");
                                                casiel_log('audio_processor', "Procesamiento del ID {$contentId} completado exitosamente.");
                                                $msg->ack(); // Acknowledge the message on full success
                                            },
                                            $handleFailure
                                        );
                                    },
                                    $handleFailure
                                );
                            },
                            $handleFailure
                        );
                    }, function ($exception) use ($handleFailure) {
                        $handleFailure("Excepción al descargar el archivo: " . $exception->getMessage());
                    });
                },
                $handleFailure
            );
        } catch (Throwable $e) {
            $handleFailure("Excepción síncrona fatal en el flujo de procesamiento: " . $e->getMessage());
        } finally {
            $this->scheduleCleanup($filesToDelete);
        }
    }

    protected function scheduleCleanup(array $filesToDelete): void
    {
        \Workerman\Timer::add(1, function () use ($filesToDelete) {
            foreach ($filesToDelete as $file) {
                if (file_exists($file)) {
                    unlink($file);
                    casiel_log('audio_processor', "Archivo temporal de limpieza eliminado: " . basename($file));
                }
            }
        }, null, false);
    }
}
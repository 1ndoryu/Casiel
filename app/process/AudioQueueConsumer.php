<?php

namespace app\process;

use app\services\AudioAnalysisService;
use app\services\GeminiService;
use app\services\SwordApiService;
use Workerman\Worker;
use Workerman\Http\Client;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Class AudioQueueConsumer
 * Consumes audio processing jobs from a RabbitMQ queue.
 */
class AudioQueueConsumer
{
    private SwordApiService $swordApiService;
    private AudioAnalysisService $audioAnalysisService;
    private GeminiService $geminiService;
    private string $tempDir;

    /**
     * Constructor with dependency injection.
     * Webman will automatically inject the services defined in config/dependence.php.
     * @param SwordApiService $swordApiService
     * @param AudioAnalysisService $audioAnalysisService
     * @param GeminiService $geminiService
     */
    public function __construct(
        SwordApiService $swordApiService,
        AudioAnalysisService $audioAnalysisService,
        GeminiService $geminiService
    ) {
        $this->swordApiService = $swordApiService;
        $this->audioAnalysisService = $audioAnalysisService;
        $this->geminiService = $geminiService;
    }

    /**
     * This method is called when the process starts.
     * @param Worker $worker
     */
    public function onWorkerStart(Worker $worker)
    {
        // Dependencies are already injected via the constructor.

        // Setup temporary directory for audio files
        $this->tempDir = runtime_path() . '/tmp/audio_processing';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
        
        casiel_log('audio_processor', 'Iniciando consumidor de cola de audio...');
        $this->connectAndConsume();
    }

    /**
     * Connects to RabbitMQ and starts consuming messages.
     */
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
            $queueName = getenv('RABBITMQ_QUEUE_CASIEL');
            $channel->queue_declare($queueName, false, true, false, false);
            casiel_log('audio_processor', "Escuchando en la cola: '{$queueName}'");

            $callback = function (AMQPMessage $msg) {
                casiel_log('audio_processor', "Mensaje recibido de la cola.", ['body' => $msg->body]);
                // This starts the async workflow.
                $this->processMessage($msg);
            };

            $channel->basic_consume($queueName, '', false, false, false, false, $callback);

            while ($channel->is_consuming()) {
                $channel->wait(null, false, 5); // Use a timeout to prevent hard blocking
            }

            $channel->close();
            $connection->close();
        } catch (Throwable $e) {
            casiel_log('audio_processor', 'No se pudo conectar o consumir de RabbitMQ. Reintentando en 10s.', [
                'error' => $e->getMessage(),
            ], 'error');
            sleep(10);
            $this->connectAndConsume();
        }
    }
    
    /**
     * Initiates the asynchronous processing of a single message from the queue.
     * @param AMQPMessage $msg
     */
    private function processMessage(AMQPMessage $msg): void
    {
        $payload = json_decode($msg->body, true);
        $contentId = $payload['data']['id'] ?? null;

        if (json_last_error() !== JSON_ERROR_NONE || !$contentId) {
            casiel_log('audio_processor', 'Mensaje inválido o sin ID de contenido. Descartando.', ['body' => $msg->body], 'warning');
            $msg->ack();
            return;
        }

        casiel_log('audio_processor', "Iniciando flujo asíncrono para contenido ID: {$contentId}");

        $filesToDelete = [];

        $onError = function (string $errorMessage) use ($msg, $contentId, &$filesToDelete) {
            casiel_log('audio_processor', "Error procesando ID {$contentId}: {$errorMessage}", [], 'error');
            // Here you could implement a dead-letter queue or retry logic
            // For now, we acknowledge to prevent reprocessing a failed job.
            foreach($filesToDelete as $file) {
                if (file_exists($file)) unlink($file);
            }
            $msg->ack();
        };

        // STEP 1: Get media details from Sword to find the audio URL
        $this->swordApiService->getMediaDetails($contentId, 
            function ($mediaDetails) use ($contentId, $onError, &$filesToDelete, $msg) {
                $audioUrl = $mediaDetails['path'] ?? null;
                if (!$audioUrl || !isset($mediaDetails['metadata']['original_name'])) {
                    $onError("No se encontró la ruta del audio (path) en los detalles del medio de Sword.");
                    return;
                }
                
                $fullAudioUrl = getenv('SWORD_API_URL') . '/' . ltrim($audioUrl, '/');
                $originalFilename = pathinfo($mediaDetails['metadata']['original_name'], PATHINFO_FILENAME);
                $originalExtension = pathinfo($mediaDetails['metadata']['original_name'], PATHINFO_EXTENSION);
                
                $localPath = "{$this->tempDir}/{$contentId}_original.{$originalExtension}";
                $filesToDelete[] = $localPath;
                casiel_log('audio_processor', "Paso 1 completado. URL de audio: {$fullAudioUrl}");

                // STEP 2: Download the audio file asynchronously
                $httpClient = new Client();
                $httpClient->get($fullAudioUrl, [], function ($response) use ($localPath, $contentId, $onError, $originalFilename, &$filesToDelete, $msg) {
                    if ($response->getStatusCode() !== 200) {
                        $onError("No se pudo descargar el archivo de audio. Status: " . $response->getStatusCode());
                        return;
                    }
                    file_put_contents($localPath, (string)$response->getBody());
                    casiel_log('audio_processor', "Paso 2 completado. Audio descargado en: {$localPath}");

                    // STEP 3: Get technical metadata (this is currently synchronous)
                    $techData = $this->audioAnalysisService->analyze($localPath);
                    if ($techData === null) {
                        $onError("Falló el análisis técnico del audio.");
                        return;
                    }
                    casiel_log('audio_processor', "Paso 3 completado. Metadatos técnicos obtenidos.");

                    // STEP 4: Get creative metadata from Gemini
                    $geminiContext = ['title' => $originalFilename, 'technical_metadata' => $techData];
                    $this->geminiService->analyzeAudio($localPath, $geminiContext,
                        function ($creativeData) use ($techData, $contentId, $onError, $localPath, &$filesToDelete, $msg) {
                            if ($creativeData === null) {
                                $onError("Falló el análisis creativo con Gemini.");
                                return;
                            }
                            casiel_log('audio_processor', "Paso 4 completado. Metadatos creativos obtenidos.");

                            // STEP 5: Generate lightweight version
                            $lightweightPath = "{$this->tempDir}/{$contentId}_light.mp3";
                            $filesToDelete[] = $lightweightPath;
                            if (!$this->audioAnalysisService->generateLightweightVersion($localPath, $lightweightPath)) {
                                $onError("Falló la generación de la versión ligera del audio.");
                                return;
                            }
                            casiel_log('audio_processor', "Paso 5 completado. Versión ligera generada.");

                            // STEP 6: Upload lightweight version to Sword
                            $this->swordApiService->uploadMedia($lightweightPath, 
                                function ($lightweightMediaData) use ($techData, $creativeData, $contentId, $onError, &$filesToDelete, $msg) {
                                    $lightweightUrl = $lightweightMediaData['path'] ?? null;
                                    if (!$lightweightUrl) {
                                        $onError("La subida de la versión ligera no devolvió una ruta (path).");
                                        return;
                                    }
                                    casiel_log('audio_processor', "Paso 6 completado. Versión ligera subida a: {$lightweightUrl}");
                                    
                                    // STEP 7: Merge all data and update content in Sword
                                    $newFileNameBase = $creativeData['nombre_archivo_base'] ?? "audio_sample_{$contentId}";
                                    $randomSuffix = substr(bin2hex(random_bytes(4)), 0, 4);
                                    $finalData = [
                                        'content_data' => array_merge($creativeData, $techData, ['light_version_url' => $lightweightUrl]),
                                        'slug' => str_replace(' ', '_', $newFileNameBase) . "_{$randomSuffix}" // new filename
                                    ];

                                    $this->swordApiService->updateContent($contentId, $finalData, 
                                        function () use ($contentId, &$filesToDelete, $msg) {
                                            casiel_log('audio_processor', "Paso 7 completado. Contenido {$contentId} actualizado en Sword.");
                                            
                                            // STEP 8: Cleanup and acknowledge
                                            foreach ($filesToDelete as $file) {
                                                if (file_exists($file)) unlink($file);
                                            }
                                            casiel_log('audio_processor', "Procesamiento del ID {$contentId} completado exitosamente. Archivos temporales eliminados.");
                                            $msg->ack();
                                        },
                                        $onError // On final update failure
                                    );
                                }, 
                                $onError // On lightweight upload failure
                            );
                        }, 
                        $onError // On Gemini failure
                    );
                }, function ($exception) use ($onError) {
                    $onError("Excepción al descargar el archivo: " . $exception->getMessage());
                });
            }, 
            $onError // On getMediaDetails failure
        );
    }
}
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

    public function process(AMQPMessage $msg, \PhpAmqpLib\Channel\AMQPChannel $channel): void
    {
        $payload = json_decode($msg->body, true);
        $contentId = $payload['data']['content_id'] ?? null;
        $mediaId = $payload['data']['media_id'] ?? null;
        $filesToDelete = [];

        $handleFailure = function (string $errorMessage) use ($msg, $contentId, $mediaId, $channel) {
            casiel_log('audio_processor', "Error processing. content_id: {$contentId}, media_id: {$mediaId}. Reason: {$errorMessage}", [], 'error');
            $retryCount = 0;
            if ($headers = $this->getMessageHeaders($msg)) {
                if (isset($headers['x-death'][0]['count'])) {
                    $retryCount = $headers['x-death'][0]['count'];
                }
            }
            if ($retryCount < self::MAX_RETRIES) {
                casiel_log('audio_processor', "Processing failed. Retrying (" . ($retryCount + 1) . "/" . (self::MAX_RETRIES + 1) . ").", ['content_id' => $contentId]);
                $msg->nack(false);
            } else {
                casiel_log('audio_processor', "Final failure after " . ($retryCount + 1) . " attempts. Sending to DLQ.", ['content_id' => $contentId], 'error');
                $channel->basic_publish($msg, 'casiel_dlx', 'casiel.dlq.final');
                $msg->ack();
            }
        };

        if (!$contentId || !$mediaId) {
            casiel_log('audio_processor', 'Invalid message or missing required IDs. Discarding permanently.', ['body' => $msg->body], 'error');
            $channel->basic_publish($msg, 'casiel_dlx', 'casiel.dlq.final');
            $msg->ack();
            return;
        }

        try {
            casiel_log('audio_processor', "Starting flow. content_id: {$contentId}, media_id: {$mediaId}");
            $this->runWorkflow($contentId, $mediaId, $msg, $handleFailure, $filesToDelete);
        } catch (Throwable $e) {
            $handleFailure("Fatal synchronous exception: " . $e->getMessage());
        } finally {
            // This method uses the Timer, which fails in tests.
            $this->scheduleCleanup($filesToDelete);
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
                    $handleFailure("Could not find 'path' in Sword's media details.");
                    return;
                }

                $fullAudioUrl = rtrim(getenv('SWORD_API_URL'), '/') . '/' . ltrim($audioUrl, '/');
                $extension = pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'tmp';
                $localPath = "{$this->tempDir}/{$mediaId}_original.{$extension}";
                $filesToDelete[] = $localPath;
                casiel_log('audio_processor', "Step 1/7: Audio URL obtained.");

                $this->swordApiService->downloadFile(
                    $fullAudioUrl,
                    $localPath,
                    function ($downloadedFilePath) use ($localPath, $contentId, $originalFilename, $msg, $handleFailure, &$filesToDelete) {
                        casiel_log('audio_processor', "Step 2/7: Audio downloaded.");

                        $techData = $this->audioAnalysisService->analyze($localPath);
                        if ($techData === null) {
                            $handleFailure("Technical audio analysis failed.");
                            return;
                        }
                        casiel_log('audio_processor', "Step 3/7: Technical metadata obtained.");

                        $geminiContext = ['title' => pathinfo($originalFilename, PATHINFO_FILENAME), 'technical_metadata' => $techData];
                        $this->geminiService->analyzeAudio(
                            $localPath,
                            $geminiContext,
                            function ($creativeData) use ($techData, $contentId, $localPath, $msg, $handleFailure, &$filesToDelete) {
                                if ($creativeData === null) {
                                    $handleFailure("Creative analysis with Gemini failed.");
                                    return;
                                }
                                casiel_log('audio_processor', "Step 4/7: Creative metadata obtained.");

                                $lightweightPath = "{$this->tempDir}/{$contentId}_light.mp3";
                                $filesToDelete[] = $lightweightPath;
                                if (!$this->audioAnalysisService->generateLightweightVersion($localPath, $lightweightPath)) {
                                    $handleFailure("Failed to generate lightweight version.");
                                    return;
                                }
                                casiel_log('audio_processor', "Step 5/7: Lightweight version generated.");


                                $this->swordApiService->uploadMedia(
                                    $lightweightPath,
                                    function ($lightweightMediaData) use ($techData, $creativeData, $contentId, $msg, $handleFailure) {
                                        $lightweightUrl = $lightweightMediaData['path'] ?? null;
                                        if (!$lightweightUrl) {
                                            $handleFailure("Lightweight version upload did not return 'path'.");
                                            return;
                                        }
                                        casiel_log('audio_processor', "Step 6/7: Lightweight version uploaded.");

                                        $newFileNameBase = $creativeData['nombre_archivo_base'] ?? "audio_sample_{$contentId}";
                                        $finalData = [
                                            'content_data' => array_merge($creativeData, $techData, ['light_version_url' => $lightweightUrl]),
                                            'slug' => str_replace(' ', '_', $newFileNameBase) . "_" . substr(bin2hex(random_bytes(4)), 0, 4)
                                        ];

                                        $this->swordApiService->updateContent(
                                            $contentId,
                                            $finalData,
                                            function () use ($contentId, $msg) {
                                                casiel_log('audio_processor', "Step 7/7: Content {$contentId} updated. Success!");
                                                $msg->ack();
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
                    fn($error) => $handleFailure("Exception during download: " . $error)
                );
            },
            $handleFailure
        );
    }

    protected function getMessageHeaders(AMQPMessage $msg): ?array
    {
        return $msg->has('application_headers') ? $msg->get('application_headers')->getNativeData() : null;
    }

    protected function scheduleCleanup(array $filesToDelete): void
    {
        \Workerman\Timer::add(1, function () use ($filesToDelete) {
            foreach ($filesToDelete as $file) {
                if (file_exists($file)) {
                    unlink($file);
                    casiel_log('audio_processor', "Cleanup: Temporary file deleted: " . basename($file));
                }
            }
        }, null, false);
    }
}

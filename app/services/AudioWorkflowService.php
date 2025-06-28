<?php

namespace app\services;

use Throwable;

class AudioWorkflowService
{
    public function __construct(
        private SwordApiService $swordApiService,
        private AudioAnalysisService $audioAnalysisService,
        private GeminiService $geminiService,
        private FileHandlerService $fileHandler
    ) {}

    /**
     * Executes the entire audio processing workflow.
     *
     * @param int $contentId
     * @param int $mediaId
     * @param callable $onSuccess Callback on successful completion.
     * @param callable $onError Callback on any failure: function(string $errorMessage).
     */
    public function run(int $contentId, int $mediaId, callable $onSuccess, callable $onError): void
    {
        try {
            $this->step1_getContent($contentId, $mediaId, $onSuccess, $onError);
        } catch (Throwable $e) {
            $onError("Excepción síncrona fatal iniciando el flujo: " . $e->getMessage());
        }
    }

    private function step1_getContent(int $contentId, int $mediaId, callable $onSuccess, callable $onError): void
    {
        $this->swordApiService->getContent(
            $contentId,
            function ($existingContent) use ($contentId, $mediaId, $onSuccess, $onError) {
                casiel_log('audio_processor', "Step 1/8: Contenido existente obtenido.", ['content_id' => $contentId]);
                $this->step2_getMediaDetails($contentId, $mediaId, $existingContent, $onSuccess, $onError);
            },
            $onError
        );
    }

    private function step2_getMediaDetails(int $contentId, int $mediaId, array $existingContent, callable $onSuccess, callable $onError): void
    {
        $this->swordApiService->getMediaDetails(
            $mediaId,
            function ($mediaDetails) use ($contentId, $existingContent, $onSuccess, $onError) {
                casiel_log('audio_processor', "Step 2/8: Detalles del medio obtenidos.", ['content_id' => $contentId]);
                $this->step3_downloadFile($contentId, $mediaDetails, $existingContent, $onSuccess, $onError);
            },
            $onError
        );
    }

    private function step3_downloadFile(int $contentId, array $mediaDetails, array $existingContent, callable $onSuccess, callable $onError): void
    {
        $audioUrl = $mediaDetails['path'] ?? null;
        if (!$audioUrl) {
            $onError("No se pudo encontrar 'path' en los detalles del medio.");
            return;
        }

        $originalFilename = $mediaDetails['metadata']['original_name'] ?? 'audio.tmp';
        $fullAudioUrl = rtrim(getenv('SWORD_API_URL'), '/') . '/' . ltrim($audioUrl, '/');
        $localPath = $this->fileHandler->createOriginalFilePath($mediaDetails['id'], $originalFilename);

        $this->swordApiService->downloadFile(
            $fullAudioUrl,
            $localPath,
            function ($downloadedPath) use ($contentId, $mediaDetails, $existingContent, $onSuccess, $onError) {
                casiel_log('audio_processor', "Step 3/8: Audio descargado.", ['content_id' => $contentId]);
                $this->step4_analyzeTechnical($contentId, $downloadedPath, $mediaDetails, $existingContent, $onSuccess, $onError);
            },
            fn($err) => $onError("Error en descarga: " . $err)
        );
    }

    private function step4_analyzeTechnical(int $contentId, string $localPath, array $mediaDetails, array $existingContent, callable $onSuccess, callable $onError): void
    {
        $techData = $this->audioAnalysisService->analyze($localPath);
        if ($techData === null) {
            $onError("El análisis técnico del audio falló.");
            return;
        }
        casiel_log('audio_processor', "Step 4/8: Metadatos técnicos obtenidos.", ['content_id' => $contentId]);
        $this->step5_analyzeCreative($contentId, $localPath, $mediaDetails, $existingContent, $techData, $onSuccess, $onError);
    }

    private function step5_analyzeCreative(int $contentId, string $localPath, array $mediaDetails, array $existingContent, array $techData, callable $onSuccess, callable $onError): void
    {
        $geminiContext = [
            'title' => pathinfo($mediaDetails['metadata']['original_name'] ?? '', PATHINFO_FILENAME),
            'technical_metadata' => $techData,
            'existing_metadata' => $existingContent['content_data'] ?? []
        ];

        $this->geminiService->analyzeAudio(
            $localPath,
            $geminiContext,
            function ($creativeData) use ($contentId, $localPath, $mediaDetails, $existingContent, $techData, $onSuccess, $onError) {
                if ($creativeData === null) {
                    $onError("El análisis creativo con Gemini falló.");
                    return;
                }
                casiel_log('audio_processor', "Step 5/8: Metadatos creativos obtenidos.", ['content_id' => $contentId]);
                $this->step6_generateLightweight($contentId, $localPath, $mediaDetails, $existingContent, $techData, $creativeData, $onSuccess, $onError);
            },
            $onError
        );
    }

    private function step6_generateLightweight(int $contentId, string $localPath, array $mediaDetails, array $existingContent, array $techData, array $creativeData, callable $onSuccess, callable $onError): void
    {
        $baseNameForLight = $creativeData['nombre_archivo_base'] ?? "{$contentId}_light";
        $lightweightPath = $this->fileHandler->createLightweightFilePath($baseNameForLight);

        if (!$this->audioAnalysisService->generateLightweightVersion($localPath, $lightweightPath)) {
            $onError("Falló la generación de la versión ligera.");
            return;
        }
        casiel_log('audio_processor', "Step 6/8: Versión ligera generada.", ['content_id' => $contentId]);
        $this->step7_uploadLightweight($contentId, $lightweightPath, $mediaDetails, $existingContent, $techData, $creativeData, $onSuccess, $onError);
    }

    private function step7_uploadLightweight(int $contentId, string $lightweightPath, array $mediaDetails, array $existingContent, array $techData, array $creativeData, callable $onSuccess, callable $onError): void
    {
        $this->swordApiService->uploadMedia(
            $lightweightPath,
            function ($lightweightMediaData) use ($contentId, $mediaDetails, $existingContent, $techData, $creativeData, $onSuccess, $onError) {
                $lightweightMediaId = $lightweightMediaData['id'] ?? null;
                if (!$lightweightMediaId) {
                    $onError("La subida de la versión ligera no devolvió 'id'.");
                    return;
                }
                casiel_log('audio_processor', "Step 7/8: Versión ligera subida.", ['content_id' => $contentId, 'media_id' => $lightweightMediaId]);
                $this->step8_updateContent($contentId, $mediaDetails, $existingContent, $techData, $creativeData, $lightweightMediaId, $onSuccess, $onError);
            },
            $onError
        );
    }

    private function step8_updateContent(int $contentId, array $mediaDetails, array $existingContent, array $techData, array $creativeData, int $lightweightMediaId, callable $onSuccess, callable $onError): void
    {
        $casielGeneratedData = array_merge(
            $techData,
            $creativeData,
            [
                'casiel_status' => 'success',
                'original_media_id' => $mediaDetails['id'],
                'light_media_id' => $lightweightMediaId,
                'original_filename' => $mediaDetails['metadata']['original_name'] ?? null,
            ]
        );

        $existingContentData = $existingContent['content_data'] ?? [];
        // La data de Casiel toma precedencia sobre la existente.
        $finalContentData = $casielGeneratedData + $existingContentData;

        $newFileNameBase = $creativeData['nombre_archivo_base'] ?? "audio_sample_{$contentId}";
        $finalPayload = [
            'content_data' => $finalContentData,
            'slug' => str_replace(' ', '_', $newFileNameBase) . "_" . substr(bin2hex(random_bytes(4)), 0, 4)
        ];

        $this->swordApiService->updateContent(
            $contentId,
            $finalPayload,
            function () use ($contentId, $onSuccess) {
                casiel_log('audio_processor', "Step 8/8: Contenido {$contentId} actualizado. ¡Éxito!", [], 'info');
                $onSuccess();
            },
            $onError
        );
    }
}

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
                casiel_log('audio_processor', "Step 1/X: Contenido existente obtenido.", ['content_id' => $contentId]);
                
                // DEBUG: Log para ver qué se está recibiendo exactamente de la API de Sword.
                casiel_log(
                    'audio_processor',
                    "DEBUG: Contenido completo obtenido de Sword API.",
                    [
                        'content_id' => $contentId,
                        'fetched_content' => $existingContent
                    ],
                    'debug'
                );

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
                casiel_log('audio_processor', "Step 2/X: Detalles del medio obtenidos.", ['content_id' => $contentId]);
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
                casiel_log('audio_processor', "Step 3/X: Audio descargado.", ['content_id' => $contentId]);
                $this->step4_generateHash($contentId, $downloadedPath, $mediaDetails, $existingContent, $onSuccess, $onError);
            },
            fn($err) => $onError("Error en descarga: " . $err)
        );
    }

    private function step4_generateHash(int $contentId, string $localPath, array $mediaDetails, array $existingContent, callable $onSuccess, callable $onError): void
    {
        $audioHash = $this->audioAnalysisService->generatePerceptualHash($localPath);
        if ($audioHash === null) {
            $onError("La generación del hash perceptual falló. No se puede continuar con la detección de duplicados.");
            return;
        }
        casiel_log('audio_processor', "Step 4/X: Hash perceptual generado.", ['content_id' => $contentId, 'hash' => $audioHash]);
        $this->step5_findDuplicateByHash($contentId, $localPath, $mediaDetails, $existingContent, $audioHash, $onSuccess, $onError);
    }

    /**
     * ==========================================================
     * INICIO DE LA CORRECCIÓN DEL BUG
     * ==========================================================
     * Este método ahora valida la respuesta de la API de forma segura para prevenir el error 'Undefined array key "id"'.
     */
    private function step5_findDuplicateByHash(int $contentId, string $localPath, array $mediaDetails, array $existingContent, string $audioHash, callable $onSuccess, callable $onError): void
    {
        $this->swordApiService->findContentByHash(
            $audioHash,
            function ($duplicateData) use ($contentId, $localPath, $mediaDetails, $existingContent, $audioHash, $onSuccess, $onError) {
                // Comprueba si la respuesta no está vacía (puede ser null o un array vacío).
                if (!empty($duplicateData)) {
                    // La API puede devolver un objeto o una colección. Nos aseguramos de manejar ambos casos.
                    $duplicateContent = (isset($duplicateData[0]) && is_array($duplicateData[0]))
                                      ? $duplicateData[0]
                                      : $duplicateData;

                    // Valida que el resultado sea un contenido válido con un 'id'.
                    if (isset($duplicateContent['id']) && is_array($duplicateContent)) {
                        casiel_log('audio_processor', "Step 5/X: DUPLICADO ENCONTRADO. ID del original: {$duplicateContent['id']}", ['content_id' => $contentId]);
                        $this->handleDuplicate($contentId, $mediaDetails, $existingContent, $duplicateContent, $onSuccess, $onError);
                    } else {
                        // Si la respuesta no es el formato esperado, lo registramos y continuamos como si no fuera un duplicado.
                        casiel_log('audio_processor', "Respuesta de búsqueda de hash inesperada. Tratando como no-duplicado.", [
                            'content_id' => $contentId,
                            'received_data' => $duplicateData
                        ], 'warning');
                        $this->step6_analyzeTechnical($contentId, $localPath, $mediaDetails, $existingContent, $audioHash, $onSuccess, $onError);
                    }
                } else {
                    casiel_log('audio_processor', "Step 5/X: No se encontraron duplicados. Continuando con el flujo normal.", ['content_id' => $contentId]);
                    $this->step6_analyzeTechnical($contentId, $localPath, $mediaDetails, $existingContent, $audioHash, $onSuccess, $onError);
                }
            },
            $onError
        );
    }

    /**
     * Maneja la lógica de actualización cuando se encuentra un contenido duplicado.
     */
    private function handleDuplicate(int $newContentId, array $newMediaDetails, array $existingContent, array $duplicateContent, callable $onSuccess, callable $onError): void
    {
        $newContentData = $existingContent['content_data'] ?? [];
        $duplicateMetadata = $duplicateContent['content_data'] ?? [];

        $casielStatusData = [
            'casiel_status' => 'duplicate',
            'casiel_error' => null, // Limpiar cualquier error previo
            'duplicate_of_content_id' => $duplicateContent['id'], // Referencia al original
            'original_media_id' => $newMediaDetails['id'],
            'original_filename' => $newMediaDetails['metadata']['original_name'] ?? null,
        ];

        // El orden de fusión es importante: la metadata rica del duplicado es la base,
        // se sobrescribe con cualquier dato que el usuario haya puesto en el nuevo ítem,
        // y finalmente se impone el estado de Casiel.
        $finalData = array_merge($duplicateMetadata, $newContentData, $casielStatusData);

        $payload = [
            'content_data' => $finalData
        ];

        $this->swordApiService->updateContent(
            $newContentId,
            $payload,
            function () use ($newContentId, $onSuccess) {
                casiel_log('audio_processor', "Contenido {$newContentId} actualizado como duplicado. ¡Éxito!", [], 'info');
                $onSuccess(); // El flujo de trabajo se completa aquí.
            },
            $onError
        );
    }
    // ==========================================================
    // FIN DE LA CORRECCIÓN DEL BUG
    // ==========================================================

    private function step6_analyzeTechnical(int $contentId, string $localPath, array $mediaDetails, array $existingContent, string $audioHash, callable $onSuccess, callable $onError): void
    {
        $techData = $this->audioAnalysisService->analyze($localPath);
        if ($techData === null) {
            $onError("El análisis técnico del audio falló.");
            return;
        }
        casiel_log('audio_processor', "Step 6/10: Metadatos técnicos obtenidos.", ['content_id' => $contentId]);
        $this->step7_analyzeCreative($contentId, $localPath, $mediaDetails, $existingContent, $audioHash, $techData, $onSuccess, $onError);
    }

    private function step7_analyzeCreative(int $contentId, string $localPath, array $mediaDetails, array $existingContent, string $audioHash, array $techData, callable $onSuccess, callable $onError): void
    {
        $geminiContext = [
            'title' => pathinfo($mediaDetails['metadata']['original_name'] ?? '', PATHINFO_FILENAME),
            'technical_metadata' => $techData,
            'existing_metadata' => $existingContent['content_data'] ?? []
        ];

        $this->geminiService->analyzeAudio(
            $localPath,
            $geminiContext,
            function ($creativeData) use ($contentId, $localPath, $mediaDetails, $existingContent, $audioHash, $techData, $onSuccess, $onError) {
                if ($creativeData === null) {
                    $onError("El análisis creativo con Gemini falló.");
                    return;
                }
                casiel_log('audio_processor', "Step 7/10: Metadatos creativos obtenidos.", ['content_id' => $contentId]);
                $this->step8_generateLightweight($contentId, $localPath, $mediaDetails, $existingContent, $audioHash, $techData, $creativeData, $onSuccess, $onError);
            },
            $onError
        );
    }

    private function step8_generateLightweight(int $contentId, string $localPath, array $mediaDetails, array $existingContent, string $audioHash, array $techData, array $creativeData, callable $onSuccess, callable $onError): void
    {
        $baseNameForLight = $creativeData['nombre_archivo_base'] ?? "{$contentId}_light";
        $lightweightPath = $this->fileHandler->createLightweightFilePath($baseNameForLight);

        if (!$this->audioAnalysisService->generateLightweightVersion($localPath, $lightweightPath)) {
            $onError("Falló la generación de la versión ligera.");
            return;
        }
        casiel_log('audio_processor', "Step 8/10: Versión ligera generada.", ['content_id' => $contentId]);
        $this->step9_uploadLightweight($contentId, $lightweightPath, $mediaDetails, $existingContent, $audioHash, $techData, $creativeData, $onSuccess, $onError);
    }

    private function step9_uploadLightweight(int $contentId, string $lightweightPath, array $mediaDetails, array $existingContent, string $audioHash, array $techData, array $creativeData, callable $onSuccess, callable $onError): void
    {
        $this->swordApiService->uploadMedia(
            $lightweightPath,
            function ($lightweightMediaData) use ($contentId, $mediaDetails, $existingContent, $audioHash, $techData, $creativeData, $onSuccess, $onError) {
                $lightweightMediaId = $lightweightMediaData['id'] ?? null;
                if (!$lightweightMediaId) {
                    $onError("La subida de la versión ligera no devolvió 'id'.");
                    return;
                }
                casiel_log('audio_processor', "Step 9/10: Versión ligera subida.", ['content_id' => $contentId, 'media_id' => $lightweightMediaId]);
                $this->step10_updateContent($contentId, $mediaDetails, $existingContent, $audioHash, $techData, $creativeData, $lightweightMediaId, $onSuccess, $onError);
            },
            $onError
        );
    }


    private function step10_updateContent(int $contentId, array $mediaDetails, array $existingContent, string $audioHash, array $techData, array $creativeData, int $lightweightMediaId, callable $onSuccess, callable $onError): void
    {
        $casielGeneratedData = array_merge(
            $techData,
            $creativeData,
            [
                'casiel_status' => 'success',
                'audio_hash' => $audioHash,
                'original_media_id' => $mediaDetails['id'],
                'light_media_id' => $lightweightMediaId,
                'original_filename' => $mediaDetails['metadata']['original_name'] ?? null,
            ]
        );

        $existingContentData = $existingContent['content_data'] ?? [];

        // DEBUG: Log para verificar los datos justo antes de la fusión.
        casiel_log(
            'audio_processor',
            "DEBUG: Verificando datos justo antes de la fusión final.",
            [
                'content_id' => $contentId,
                '1_datos_existentes' => $existingContentData,
                '2_datos_generados_casiel' => $casielGeneratedData
            ],
            'debug'
        );
        
        // Usar array_merge para fusionar la data de forma segura.
        $finalContentData = array_merge($existingContentData, $casielGeneratedData);

        $newFileNameBase = $creativeData['nombre_archivo_base'] ?? "audio_sample_{$contentId}";
        $finalPayload = [
            'content_data' => $finalContentData,
            'slug' => str_replace(' ', '_', $newFileNameBase) . "_" . substr(bin2hex(random_bytes(4)), 0, 4)
        ];

        $this->swordApiService->updateContent(
            $contentId,
            $finalPayload,
            function () use ($contentId, $onSuccess) {
                casiel_log('audio_processor', "Step 10/10: Contenido {$contentId} actualizado. ¡Éxito!", [], 'info');
                $onSuccess();
            },
            $onError
        );
    }
}
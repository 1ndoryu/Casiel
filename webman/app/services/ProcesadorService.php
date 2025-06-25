<?php

namespace app\services;

use app\utils\AudioUtil;
use Throwable;

/**
 * Servicio para orquestar el procesamiento completo de un sample.
 */
class ProcesadorService
{
    private SwordService $swordService;
    private GeminiService $geminiService;
    private AudioUtil $audioUtil;
    private string $swordBaseUrl;

    public function __construct(SwordService $swordService, GeminiService $geminiService, AudioUtil $audioUtil, string $swordBaseUrl)
    {
        $this->swordService = $swordService;
        $this->geminiService = $geminiService;
        $this->audioUtil = $audioUtil;
        $this->swordBaseUrl = $swordBaseUrl;
    }

    /**
     * Procesa un único sample de principio a fin.
     * @param array $sample Los datos del sample a procesar.
     * @param bool $esForzado Indica si la ejecución es forzada (ignora reintentos).
     * @return array Log de la ejecución.
     * @throws Throwable
     */
    public function procesarSample(array $sample, bool $esForzado = false): array
    {
        $log = [];
        $idSample = $sample['id'];
        $metadataActual = $sample['metadata'] ?? [];
        $urlAudioOriginal = $metadataActual['url_archivo'] ?? null;
        $statusSuffix = $esForzado ? '_forzado' : '';
        $rutaTemporalOriginal = null;
        $rutaTemporalLigero = null;

        casielLog("Iniciando procesamiento para sample ID: $idSample", ['esForzado' => $esForzado]);

        if (!$urlAudioOriginal) {
            throw new \Exception("El sample ID: $idSample no tiene 'url_archivo' en su metadata.");
        }

        $this->swordService->actualizarMetadataSample($idSample, ['ia_status' => 'procesando' . $statusSuffix]);
        $log['paso1_marcar_procesando'] = "Sample ID: $idSample marcado como 'procesando{$statusSuffix}'.";

        try {
            // FASE 1: Descargar y crear versión ligera temporal
            $urlCompletaAudio = rtrim($this->swordBaseUrl, '/') . $urlAudioOriginal;
            $rutaTemporalOriginal = $this->audioUtil->descargarAudio($urlCompletaAudio);
            $rutaTemporalLigero = $this->audioUtil->crearVersionLigeraTemporal($rutaTemporalOriginal);
            $log['paso2_descarga_y_conversion_temporal'] = 'Audio original descargado y versión ligera temporal creada.';

            // FASE 2: Análisis con IA y Python
            $contextoIA = ['titulo' => $sample['titulo']];
            $metadataGeneradaIA = $this->geminiService->analizarAudio($rutaTemporalLigero, $contextoIA);
            if (!$metadataGeneradaIA || empty($metadataGeneradaIA['nombre_archivo_base'])) {
                throw new \Exception("El análisis de Gemini falló o no devolvió un 'nombre_archivo_base'.");
            }

            $metadataTecnica = $this->audioUtil->ejecutarAnalisisPython($rutaTemporalOriginal);
            $log['paso3_analisis_completado'] = ['ia' => $metadataGeneradaIA, 'tecnica' => $metadataTecnica];

            // FASE 3: Nomenclatura y almacenamiento final (usando rename para eficiencia)
            $nombreBaseIA = preg_replace('/[^a-z0-9_]+/', '', strtolower($metadataGeneradaIA['nombre_archivo_base']));
            $nombreArchivoBaseFinal = "kamples_{$idSample}_{$nombreBaseIA}";
            $extensionOriginal = pathinfo($urlAudioOriginal, PATHINFO_EXTENSION);

            $resultadoAlmacenamiento = $this->audioUtil->guardarArchivosPermanentes(
                $rutaTemporalOriginal,
                $rutaTemporalLigero,
                $nombreArchivoBaseFinal,
                $extensionOriginal
            );
            $log['paso4_almacenamiento_final'] = $resultadoAlmacenamiento;

            // FASE 4: Actualización final en Sword
            $metadataFinal = array_merge(
                $metadataTecnica,
                $metadataGeneradaIA,
                [
                    'ia_status' => 'completado' . $statusSuffix,
                    'url_stream' => $resultadoAlmacenamiento['url_stream'],
                    'nombre_archivo_ligero' => $resultadoAlmacenamiento['nombre_ligero'],
                    'nombre_archivo_original' => $resultadoAlmacenamiento['nombre_original'],
                    'ia_retry_count' => 0
                ]
            );

            // No necesitamos enviar la metadata antigua de vuelta, solo los campos a actualizar/añadir.
            $payloadFinal = [
                // Campos raíz que podrían actualizarse (ej. si la IA los mejora)
                'descripcion' => $metadataFinal['descripcion'],
                'descripcion_corta' => $metadataFinal['descripcion_corta'],
                'metadata' => $metadataFinal // El resto va a metadata
            ];

            $exito = $this->swordService->actualizarSample($idSample, $payloadFinal);

            if (!$exito) {
                throw new \Exception("La actualización final en Sword API falló.");
            }

            $log['paso5_actualizacion_final'] = ['status' => 'ok', 'payload_enviado' => $payloadFinal];
            $log['fin'] = 'Proceso finalizado con éxito.';

            // Limpieza final exitosa
            $this->audioUtil->limpiarTemporal([$rutaTemporalOriginal, $rutaTemporalLigero]);
        } catch (Throwable $e) {
            // En caso de error, limpiar archivos temporales si existen
            $this->audioUtil->limpiarTemporal([$rutaTemporalOriginal, $rutaTemporalLigero]);

            $retryCount = ($metadataActual['ia_retry_count'] ?? 0) + 1;
            $metadataError = [
                'ia_status' => 'fallido' . $statusSuffix,
                'ia_retry_count' => $retryCount,
                'ia_last_error' => substr($e->getMessage(), 0, 500)
            ];
            $this->swordService->actualizarMetadataSample($idSample, $metadataError);

            // Re-lanzar la excepción para que el llamador (controller/consumer) la maneje
            throw $e;
        }

        return $log;
    }
}

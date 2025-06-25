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
     * (NUEVO) Valida y sanea la metadata devuelta por la IA para asegurar que los tipos de datos son correctos.
     * @param array $metadata La data cruda de Gemini.
     * @return array La data saneada.
     */
    private function sanitizarMetadataIA(array $metadata): array
    {
        $sanitizada = $metadata;
        $camposArray = ['tags', 'genero', 'emocion', 'instrumentos', 'artista_vibes'];

        foreach ($camposArray as $campo) {
            if (!isset($sanitizada[$campo])) {
                $sanitizada[$campo] = []; // Si no existe, crea un array vacío.
                continue;
            }
            if (is_string($sanitizada[$campo])) {
                // Si es un string, lo convierte en un array limpio (sin espacios vacíos).
                $sanitizada[$campo] = array_filter(array_map('trim', explode(',', $sanitizada[$campo])));
            } elseif (!is_array($sanitizada[$campo])) {
                // Si es cualquier otra cosa que no sea un array, lo resetea a un array vacío.
                $sanitizada[$campo] = [];
            }
        }

        // Asegura que los campos de texto principales sean strings.
        $camposString = ['nombre_archivo_base', 'descripcion_corta', 'descripcion', 'tipo'];
        foreach ($camposString as $campo) {
            $sanitizada[$campo] = isset($sanitizada[$campo]) ? (string)$sanitizada[$campo] : '';
        }

        // Valida el campo 'tipo' para que sea uno de los valores permitidos.
        $tipoNormalizado = strtolower(trim($sanitizada['tipo']));
        if (!in_array($tipoNormalizado, ['one shot', 'loop'])) {
            $sanitizada['tipo'] = str_contains($tipoNormalizado, 'loop') ? 'loop' : 'one shot'; // Default inteligente
        } else {
            $sanitizada['tipo'] = $tipoNormalizado;
        }

        casielLog("Metadata de IA saneada.", ['original' => $metadata, 'saneada' => $sanitizada]);
        return $sanitizada;
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

            // ========== INICIO DE LA NUEVA CORRECCIÓN ==========
            // Se sanea la respuesta de la IA antes de usarla.
            $metadataGeneradaIA = $this->sanitizarMetadataIA($metadataGeneradaIA);
            // ========== FIN DE LA NUEVA CORRECCIÓN ==========

            $metadataTecnica = $this->audioUtil->ejecutarAnalisisPython($rutaTemporalOriginal);
            $log['paso3_analisis_completado'] = ['ia' => $metadataGeneradaIA, 'tecnica' => $metadataTecnica];

            // FASE 3: Nomenclatura y almacenamiento final
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
                $metadataActual,
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

            $exito = $this->swordService->actualizarSample($idSample, $metadataFinal);

            if (!$exito) {
                // Esta es la línea 103 (aproximadamente) que da el error
                throw new \Exception("La actualización final en Sword API falló.");
            }

            $log['paso5_actualizacion_final'] = ['status' => 'ok', 'payload_enviado' => $metadataFinal];
            $log['fin'] = 'Proceso finalizado con éxito.';

            $this->audioUtil->limpiarTemporal([$rutaTemporalOriginal, $rutaTemporalLigero]);
        } catch (Throwable $e) {
            $this->audioUtil->limpiarTemporal([$rutaTemporalOriginal, $rutaTemporalLigero]);

            $retryCount = ($metadataActual['ia_retry_count'] ?? 0) + 1;
            $metadataError = [
                'ia_status' => 'fallido' . $statusSuffix,
                'ia_retry_count' => $retryCount,
                'ia_last_error' => substr($e->getMessage(), 0, 500)
            ];
            $this->swordService->actualizarMetadataSample($idSample, $metadataError);

            throw $e;
        }

        return $log;
    }
}
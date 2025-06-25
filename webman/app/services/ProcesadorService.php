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
    
    private function sanitizarMetadataIA(array $metadata): array
    {
        $sanitizada = $metadata;
        $camposArray = ['tags', 'genero', 'emocion', 'instrumentos', 'artista_vibes'];

        foreach ($camposArray as $campo) {
            if (!isset($sanitizada[$campo])) {
                $sanitizada[$campo] = []; 
                continue;
            }
            if (is_string($sanitizada[$campo])) {
                $sanitizada[$campo] = array_filter(array_map('trim', explode(',', $sanitizada[$campo])));
            } elseif (!is_array($sanitizada[$campo])) {
                $sanitizada[$campo] = [];
            }
        }
        
        $camposString = ['nombre_archivo_base', 'descripcion_corta', 'descripcion', 'tipo'];
        foreach ($camposString as $campo) {
            $sanitizada[$campo] = isset($sanitizada[$campo]) ? (string)$sanitizada[$campo] : '';
        }
        
        $tipoNormalizado = strtolower(trim($sanitizada['tipo']));
        if (!in_array($tipoNormalizado, ['one shot', 'loop'])) {
            $sanitizada['tipo'] = str_contains($tipoNormalizado, 'loop') ? 'loop' : 'one shot'; // Default inteligente
        } else {
            $sanitizada['tipo'] = $tipoNormalizado;
        }

        casielLog("Metadata de IA saneada.", ['original' => $metadata, 'saneada' => $sanitizada]);
        return $sanitizada;
    }

    private function generarCodigo(int $longitud = 5): string
    {
        $caracteres = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $longitudCaracteres = strlen($caracteres);
        $codigo = '';
        for ($i = 0; $i < $longitud; $i++) {
            $codigo .= $caracteres[rand(0, $longitudCaracteres - 1)];
        }
        return $codigo;
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

        $this->swordService->actualizarMetadataSample($idSample, array_merge($metadataActual, ['ia_status' => 'procesando' . $statusSuffix]));
        $log['paso1_marcar_procesando'] = "Sample ID: $idSample marcado como 'procesando{$statusSuffix}'.";

        try {
            // FASE 1: Descargar y generar hash para detección de duplicados
            $urlCompletaAudio = rtrim($this->swordBaseUrl, '/') . $urlAudioOriginal;
            $rutaTemporalOriginal = $this->audioUtil->descargarAudio($urlCompletaAudio);
            $hash = $this->audioUtil->generarHashPerceptual($rutaTemporalOriginal);

            if ($hash) {
                $log['paso2_hash_generado'] = $hash;
                $duplicado = $this->swordService->buscarSamplePorHash($hash);
                if ($duplicado) {
                    $log['paso2.1_duplicado_detectado'] = "El sample es un duplicado del ID: " . $duplicado['id'];
                    $metadataUpdate = [
                        'ia_status' => 'duplicado',
                        'es_duplicado' => true,
                        'duplicado_de_id' => $duplicado['id'],
                        'metadata_heredada' => $duplicado['metadata'] ?? []
                    ];
                    $this->swordService->actualizarMetadataSample($idSample, array_merge($metadataActual, $metadataUpdate));
                    $this->audioUtil->limpiarTemporal([$rutaTemporalOriginal]);
                    return $log;
                }
            } else {
                 $log['paso2_hash_generado'] = 'No se pudo generar el hash, se continua sin detección de duplicados.';
            }

            // FASE 2: Crear versión ligera y Análisis con IA y Python
            $rutaTemporalLigero = $this->audioUtil->crearVersionLigeraTemporal($rutaTemporalOriginal);
            $log['paso3_conversion_temporal'] = 'Versión ligera temporal creada.';
            
            $contextoIA = ['titulo' => $sample['titulo']];
            $metadataGeneradaIA = $this->geminiService->analizarAudio($rutaTemporalLigero, $contextoIA);
            if (!$metadataGeneradaIA || empty($metadataGeneradaIA['nombre_archivo_base'])) {
                throw new \Exception("El análisis de Gemini falló o no devolvió un 'nombre_archivo_base'.");
            }
            $metadataGeneradaIA = $this->sanitizarMetadataIA($metadataGeneradaIA);
            $metadataTecnica = $this->audioUtil->ejecutarAnalisisPython($rutaTemporalOriginal);
            $log['paso4_analisis_completado'] = ['ia' => $metadataGeneradaIA, 'tecnica' => $metadataTecnica];

            // FASE 3: Nomenclatura y almacenamiento final
            $codeSample = $this->generarCodigo();
            $nombreBaseIA = $metadataGeneradaIA['nombre_archivo_base'];
            $brandName = config('casiel.naming.brand_name', 'kamples');
            $nombreArchivoBaseFinal = ucfirst($nombreBaseIA) . " {$brandName}_" . $codeSample;
            $extensionOriginal = pathinfo($urlAudioOriginal, PATHINFO_EXTENSION);
            
            $log['paso5_nuevo_nombre_base'] = $nombreArchivoBaseFinal;

            $resultadoAlmacenamiento = $this->audioUtil->guardarArchivosPermanentes(
                $rutaTemporalOriginal,
                $rutaTemporalLigero,
                $nombreArchivoBaseFinal,
                $extensionOriginal
            );
            $log['paso6_almacenamiento_final'] = $resultadoAlmacenamiento;

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
                    'ia_retry_count' => 0,
                    'code_sample' => $codeSample,
                    'audio_hash' => $hash,
                ]
            );

            $exito = $this->swordService->actualizarSample($idSample, $metadataFinal);

            if (!$exito) {
                throw new \Exception("La actualización final en Sword API falló.");
            }

            $log['paso7_actualizacion_final'] = ['status' => 'ok', 'payload_enviado' => $metadataFinal];
            $log['fin'] = 'Proceso finalizado con éxito.';

            $this->audioUtil->limpiarTemporal([$rutaTemporalOriginal, $rutaTemporalLigero]);
        } catch (Throwable $e) {
            $this->audioUtil->limpiarTemporal([$rutaTemporalOriginal, $rutaTemporalLigero]);

            $retryCount = ($metadataActual['ia_retry_count'] ?? 0) + 1;
            $metadataError = array_merge($metadataActual, [
                'ia_status' => 'fallido' . $statusSuffix,
                'ia_retry_count' => $retryCount,
                'ia_last_error' => substr($e->getMessage(), 0, 500)
            ]);

            $this->swordService->actualizarMetadataSample($idSample, $metadataError);
            throw $e; // Se relanza la excepción para que el orquestador principal la registre.
        }

        return $log;
    }
}
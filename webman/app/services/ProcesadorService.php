<?php

namespace app\services;

use app\utils\AudioUtil;
use Throwable;

class ProcesadorService
{
    private SwordService $swordService;
    private GeminiService $geminiService;
    private AudioUtil $audioUtil;

    public function __construct(SwordService $swordService, GeminiService $geminiService, AudioUtil $audioUtil)
    {
        $this->swordService = $swordService;
        $this->geminiService = $geminiService;
        $this->audioUtil = $audioUtil;
    }

    public function procesarSample(array $sample, bool $esForzado = false): array
    {
        $log = [];
        $idSample = $sample['id'];
        $metadataActual = $sample['metadata'] ?? [];
        $statusSuffix = $esForzado ? '_forzado' : '';
        $rutaTemporalOriginal = null;
        $rutaTemporalLigero = null;

        casielLog("Iniciando procesamiento para sample ID: $idSample", ['esForzado' => $esForzado]);

        $mediaId = $metadataActual['media_id'] ?? null;

        if (!$mediaId) {
            throw new \Exception("El sample ID: $idSample no tiene 'media_id' en su metadata. No se puede procesar.");
        }

        $this->swordService->actualizarMetadataSample($idSample, array_merge($metadataActual, ['ia_status' => 'procesando' . $statusSuffix]));
        $log['paso1_marcar_procesando'] = "Sample ID: $idSample marcado como 'procesando{$statusSuffix}'.";

        try {
            // FASE 1: Descargar y generar hash para detección de duplicados
            $contenidoAudio = $this->swordService->descargarAudioPorMediaId($mediaId);
            if (!$contenidoAudio) {
                throw new \Exception("No se pudo descargar el audio desde Sword para el media_id: $mediaId");
            }

            $nombreOriginal = $metadataActual['nombre_archivo_original'] ?? $sample['titulo'] ?? 'audio.tmp';
            $extensionOriginal = pathinfo($nombreOriginal, PATHINFO_EXTENSION) ?: 'tmp';
            $rutaTemporalOriginal = $this->audioUtil->guardarContenidoTemporal($contenidoAudio, $nombreOriginal);
            $log['paso1.1_descarga'] = "Audio guardado en: $rutaTemporalOriginal";

            $hash = $this->audioUtil->generarHashPerceptual($rutaTemporalOriginal);

            if ($hash) {
                $log['paso2_hash_generado'] = $hash;
                $duplicado = $this->swordService->buscarSamplePorHash($hash);

                // --- INICIO DE LA CORRECCIÓN DE ROBUSTEZ ---
                // Si no se encuentra al instante, reintentamos tras una pausa.
                // Esto soluciona delays de indexación en el backend.
                if (!$duplicado) {
                    casielLog("No se encontró duplicado en el primer intento. Reintentando en 2 segundos...", ['hash' => $hash, 'sample_id' => $idSample]);
                    sleep(2);
                    $duplicado = $this->swordService->buscarSamplePorHash($hash);
                }

                if ($duplicado && $duplicado['id'] != $idSample) {
                    $statusOriginal = $duplicado['metadata']['ia_status'] ?? 'desconocido';
                    // Solo lo consideramos un duplicado válido si el original fue procesado con éxito.
                    if (str_starts_with($statusOriginal, 'completado')) {
                        $log['paso2.1_duplicado_detectado'] = "Confirmado duplicado del ID: " . $duplicado['id'];
                        $metadataUpdate = [
                            'ia_status'       => 'duplicado',
                            'duplicado_de_id' => $duplicado['id'],
                            'audio_hash'      => $hash,
                        ];
                        $this->swordService->actualizarMetadataSample($idSample, array_merge($metadataActual, $metadataUpdate));
                        $this->audioUtil->limpiarTemporal([$rutaTemporalOriginal]);
                        return $log; // Proceso finaliza aquí para el duplicado.
                    }
                    casielLog("Sample con mismo hash (ID: {$duplicado['id']}) encontrado pero no está 'completado' (estado: '$statusOriginal'). Se procesará como original.", ['sample_id' => $idSample]);
                }
                // --- FIN DE LA CORRECCIÓN DE ROBUSTEZ ---
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
            throw $e;
        }

        return $log;
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
            $sanitizada['tipo'] = str_contains($tipoNormalizado, 'loop') ? 'loop' : 'one shot';
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
}
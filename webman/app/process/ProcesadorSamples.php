<?php

namespace app\process;

use Workerman\Timer;
use app\services\SwordService;
use app\services\GeminiService;
use app\utils\AudioUtil; // Importar

/**
 * Proceso de fondo para buscar y analizar samples de audio con IA.
 */
class ProcesadorSamples
{
    private ?SwordService $swordService = null;
    private ?GeminiService $geminiService = null;
    private ?AudioUtil $audioUtil = null; // Añadir
    private ?string $swordBaseUrl = null;

    public function onWorkerStart()
    {
        // Inyectar la configuración en los servicios.
        $this->swordService = new SwordService(config('api.sword.api_url'), config('api.sword.api_key'));
        $this->geminiService = new GeminiService(config('api.gemini.api_key'), config('api.gemini.model_id'));
        $this->audioUtil = new AudioUtil();
        $this->swordBaseUrl = config('api.sword.base_url');

        if (empty($this->swordBaseUrl) || !config('api.sword.api_key') || !config('api.gemini.api_key')) {
            casielLog("Configuración de API (.env) incompleta. El worker no se iniciará.", [], 'alert');
            return;
        }
        
        casielLog("Iniciando Proceso de Samples. Buscando cada 60 segundos.");

        $this->procesar(); // Ejecutar inmediatamente al iniciar
        Timer::add(60, function () {
            $this->procesar();
        });
    }

    /**
     * Lógica principal del procesador.
     */
    public function procesar()
    {
        if (!$this->swordService || !$this->geminiService || !$this->audioUtil) {
            casielLog("Los servicios no fueron inicializados. Saltando ciclo.", [], 'warning');
            return;
        }

        casielLog("Ejecutando ciclo de procesamiento...");
        $samples = $this->swordService->obtenerSamplesPendientes(5);

        if (empty($samples)) {
            casielLog("Ciclo finalizado. No hay samples que procesar.");
            return;
        }

        foreach ($samples as $sample) {
            $idSample = $sample['id'];
            $this->procesarSampleIndividual($sample, $idSample);
        }
    }

    private function procesarSampleIndividual(array $sample, int $idSample)
    {
        $metadataActual = $sample['metadata'] ?? [];
        $urlAudioOriginal = $metadataActual['url_archivo'] ?? null;
        
        if (!$urlAudioOriginal) {
            casielLog("El sample ID: $idSample no tiene 'url_archivo'. Saltando.", ['sample_id' => $idSample], 'warning');
            return;
        }

        $nombreOriginal = basename($urlAudioOriginal);
        $this->swordService->actualizarMetadataSample($idSample, array_merge($metadataActual, ['ia_status' => 'procesando']));

        try {
            $urlCompletaAudio = rtrim($this->swordBaseUrl, '/') . $urlAudioOriginal;
            $resultadoAudio = $this->audioUtil->procesarDesdeUrl($urlCompletaAudio, $nombreOriginal);
            if (!$resultadoAudio) throw new \Exception("Fallo en AudioUtil.");

            $archivoSubido = $this->swordService->subirArchivo($resultadoAudio['ruta_mp3'], $resultadoAudio['nombre_mp3']);
            if (!$archivoSubido) throw new \Exception("Fallo al subir MP3 a Sword.");
            
            unlink($resultadoAudio['ruta_mp3']);

            $contextoIA = ['titulo' => $sample['titulo'], 'metadata_tecnica' => $resultadoAudio['metadata_tecnica']];
            $metadataGeneradaIA = $this->geminiService->analizarAudio($resultadoAudio['ruta_mp3'], $contextoIA);
            if (!$metadataGeneradaIA) throw new \Exception("Fallo en el análisis de Gemini.");

            $metadataFinal = array_merge(
                $metadataActual,
                $resultadoAudio['metadata_tecnica'],
                $metadataGeneradaIA,
                [
                    'ia_status' => 'completado',
                    'url_archivo_ligero' => $archivoSubido['url'],
                    'nombre_archivo_original' => $resultadoAudio['nombre_original']
                ]
            );
            
            $this->swordService->actualizarMetadataSample($idSample, $metadataFinal);
            casielLog("Sample ID: $idSample procesado con éxito.", ['sample_id' => $idSample]);

        } catch (\Throwable $e) {
            $metadataError = array_merge($metadataActual, ['ia_status' => 'fallido']);
            $this->swordService->actualizarMetadataSample($idSample, $metadataError);
            casielLog("Error procesando sample ID: $idSample. Causa: " . $e->getMessage(), ['sample_id' => $idSample], 'error');
        }
    }
}
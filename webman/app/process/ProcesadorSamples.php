<?php

namespace app\process;

use Workerman\Timer;
use app\services\SwordService;
use app\services\GeminiService;

/**
 * Proceso de fondo para buscar y analizar samples de audio con IA.
 */
class ProcesadorSamples
{
    private SwordService $swordService;
    private GeminiService $geminiService;

    public function onWorkerStart()
    {
        $this->swordService = new SwordService();
        $this->geminiService = new GeminiService();

        casielLog("Iniciando Proceso de Samples. Buscando cada 60 segundos.");

        // Ejecutar la tarea cada 60 segundos.
        Timer::add(60, function () {
            $this->procesar();
        });
    }

    /**
     * Lógica principal del procesador.
     */
    public function procesar()
    {
        casielLog("Ejecutando ciclo de procesamiento de samples...");

        $samples = $this->swordService->obtenerSamplesPendientes(5);

        if (empty($samples)) {
            casielLog("Ciclo finalizado. No hay samples que procesar.");
            return;
        }

        foreach ($samples as $sample) {
            $idSample = $sample['id'];
            $metadataActual = $sample['metadata'] ?? [];
            $urlAudio = $metadataActual['url_archivo'] ?? null;

            if (!$urlAudio) {
                casielLog("El sample ID: $idSample no tiene 'url_archivo' en su metadata. Saltando.", [], 'warning');
                continue;
            }

            // TODO: La URL puede ser relativa. Construir la URL completa.
            // Por ahora, asumimos que la URL en .env es el dominio base.
            $urlCompletaAudio = rtrim($_ENV['SWORD_BASE_URL'], '/') . $urlAudio;

            $metadataGenerada = $this->geminiService->analizarAudio($urlCompletaAudio);

            if ($metadataGenerada) {
                // Fusionamos la metadata existente con la nueva y marcamos como completado
                $metadataFinal = array_merge($metadataActual, $metadataGenerada, ['ia_status' => 'completado']);
                $this->swordService->actualizarMetadataSample($idSample, $metadataFinal);
            } else {
                // Marcamos como fallido para poder reintentar o revisar manualmente
                casielLog("Falló el análisis para el sample ID: $idSample. Marcando como 'fallido'.", [], 'error');
                $metadataActual['ia_status'] = 'fallido';
                $this->swordService->actualizarMetadataSample($idSample, $metadataActual);
            }
        }
    }
}

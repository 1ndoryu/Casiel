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
    private ?SwordService $swordService = null;
    private ?GeminiService $geminiService = null;
    private ?string $swordBaseUrl = null;

    public function onWorkerStart()
    {
        // Se utiliza el sistema de configuración de Webman.
        $this->swordBaseUrl = config('api.sword.base_url');
        $swordApiUrl      = config('api.sword.api_url');
        $swordApiKey      = config('api.sword.api_key');
        $geminiApiKey     = config('api.gemini.api_key');
        $geminiModelId    = config('api.gemini.model_id', 'gemini-1.5-flash-latest');

        // Verificación robusta de la configuración
        if (empty($this->swordBaseUrl) || empty($swordApiUrl) || empty($swordApiKey) || empty($geminiApiKey)) {
            casielLog("Configuración de API (.env) incompleta o no cargada en el worker. REVISAR .env Y php.ini (variables_order debe incluir 'E')", [], 'error');
            return; // Detiene la inicialización del worker si la config es inválida
        }
        
        // Inyectar la configuración en los servicios.
        $this->swordService = new SwordService($swordApiUrl, $swordApiKey);
        $this->geminiService = new GeminiService($geminiApiKey, $geminiModelId);

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
        // Añadimos una guarda para no ejecutar si los servicios no se iniciaron.
        if (!$this->swordService || !$this->geminiService) {
            casielLog("Los servicios no fueron inicializados debido a un error de configuración. Saltando ciclo de procesamiento.", [], 'warning');
            return;
        }

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
                casielLog("El sample ID: $idSample no tiene 'url_archivo'. Saltando.", [], 'warning');
                continue;
            }

            $urlCompletaAudio = rtrim($this->swordBaseUrl, '/') . $urlAudio;
            $metadataGenerada = $this->geminiService->analizarAudio($urlCompletaAudio);

            if ($metadataGenerada) {
                $metadataFinal = array_merge($metadataActual, $metadataGenerada, ['ia_status' => 'completado']);
                $this->swordService->actualizarMetadataSample($idSample, $metadataFinal);
            } else {
                $metadataActual['ia_status'] = 'fallido';
                $this->swordService->actualizarMetadataSample($idSample, $metadataActual);
            }
        }
    }
}
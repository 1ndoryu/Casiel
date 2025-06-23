<?php

namespace app\controller;

use support\Request;
use app\services\SwordService;
use app\services\GeminiService;
use Throwable;

class TestController
{
    private SwordService $swordService;
    private GeminiService $geminiService;
    private string $swordBaseUrl;
    private array $log = [];

    public function index(Request $request)
    {
        return view('test/index', ['name' => 'Casiel Tester']);
    }

    private function inicializarServicios(): bool
    {
        $this->log['inicio'] = 'Iniciando proceso de prueba manual.';
        
        $swordApiUrl = config('api.sword.api_url');
        $swordApiKey = config('api.sword.api_key');
        $geminiApiKey = config('api.gemini.api_key');
        $this->swordBaseUrl = config('api.sword.base_url');
        $geminiModelId = config('api.gemini.model_id', 'gemini-1.5-flash-latest'); 

        if (!$swordApiUrl || !$swordApiKey || !$geminiApiKey || !$this->swordBaseUrl) {
            $this->log['error_config'] = 'Configuración de API (.env) incompleta o no cargada.';
            return false;
        }
        $this->log['configuracion'] = 'Variables de entorno cargadas correctamente.';

        $this->swordService = new SwordService($swordApiUrl, $swordApiKey);
        $this->geminiService = new GeminiService($geminiApiKey, $geminiModelId);
        return true;
    }

    private function procesarSampleUnico(array $sample, bool $esForzado = false)
    {
        $idSample = $sample['id'];
        $metadataActual = $sample['metadata'] ?? [];
        $urlAudio = $metadataActual['url_archivo'] ?? null;
        $statusSuffix = $esForzado ? '_forzado' : '_test';
        
        $this->log['paso1_resultado'] = ['mensaje' => "Sample seleccionado (ID: $idSample).", 'sample_data' => $sample];

        if (!$urlAudio) {
            $this->log['error_fatal'] = "El sample ID: $idSample no tiene 'url_archivo' en su metadata.";
            return json(['log_ejecucion' => $this->log], 422);
        }

        $this->swordService->actualizarMetadataSample($idSample, array_merge($metadataActual, ['ia_status' => 'procesando' . $statusSuffix]));
        $this->log['paso2_marcar_procesando'] = "Sample ID: $idSample marcado como 'procesando{$statusSuffix}'.";

        $urlCompletaAudio = rtrim($this->swordBaseUrl, '/') . $urlAudio;
        $this->log['paso3_analisis_gemini'] = ['mensaje' => 'Enviando audio a Gemini...', 'url_audio_completa' => $urlCompletaAudio];
        $metadataGenerada = $this->geminiService->analizarAudio($urlCompletaAudio);

        if (!$metadataGenerada) {
            $this->log['paso3_resultado'] = 'El análisis de Gemini falló o no devolvió datos.';
            $metadataFinal = array_merge($metadataActual, ['ia_status' => 'fallido' . $statusSuffix]);
            $this->swordService->actualizarMetadataSample($idSample, $metadataFinal);
            $this->log['paso4_actualizacion_sword'] = ['status' => 'fallido', 'metadata_enviada' => $metadataFinal];
            return json(['log_ejecucion' => $this->log], 500);
        }

        $this->log['paso3_resultado'] = ['mensaje' => 'Análisis de Gemini completado.', 'metadata_recibida' => $metadataGenerada];
        
        $metadataFinal = array_merge($metadataActual, $metadataGenerada, ['ia_status' => 'completado' . $statusSuffix]);
        $exito = $this->swordService->actualizarMetadataSample($idSample, $metadataFinal);
        $this->log['paso4_actualizacion_sword'] = ['status' => $exito ? 'ok' : 'fallido', 'metadata_final_enviada' => $metadataFinal];
        
        $this->log['fin'] = 'Proceso de prueba finalizado.';
        return json(['log_ejecucion' => $this->log]);
    }
    
    public function ejecutarTest(Request $request)
    {
        try {
            if (!$this->inicializarServicios()) return json(['log_ejecucion' => $this->log], 500);
            
            $this->log['paso1_buscar_samples'] = ['mensaje' => 'Buscando 1 sample pendiente o fallido...'];
            $samples = $this->swordService->obtenerSamplesPendientes(1);

            if (empty($samples)) {
                $this->log['paso1_resultado'] = 'No se encontraron samples pendientes o fallidos.';
                return json(['log_ejecucion' => $this->log]);
            }
            
            return $this->procesarSampleUnico($samples[0], false);

        } catch (Throwable $e) {
            $this->log['error_fatal'] = ['mensaje' => 'Excepción no controlada.', 'error' => $e->getMessage(), 'archivo' => $e->getFile(), 'linea' => $e->getLine()];
            return json(['log_ejecucion' => $this->log], 500);
        }
    }
    
    public function ejecutarTestForzado(Request $request)
    {
        try {
            if (!$this->inicializarServicios()) return json(['log_ejecucion' => $this->log], 500);

            $this->log['paso1_buscar_samples'] = ['mensaje' => 'Buscando el último sample (modo forzado)...'];
            $samples = $this->swordService->obtenerUltimoSample();

            if (empty($samples)) {
                $this->log['paso1_resultado'] = 'No se encontró ningún sample en la base de datos.';
                return json(['log_ejecucion' => $this->log]);
            }

            return $this->procesarSampleUnico($samples[0], true);

        } catch (Throwable $e) {
            $this->log['error_fatal'] = ['mensaje' => 'Excepción no controlada.', 'error' => $e->getMessage(), 'archivo' => $e->getFile(), 'linea' => $e->getLine()];
            return json(['log_ejecucion' => $this->log], 500);
        }
    }
}
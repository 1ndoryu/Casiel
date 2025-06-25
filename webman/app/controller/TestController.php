<?php

namespace app\controller;

use support\Request;
use app\services\SwordService;
use app\services\GeminiService;
use app\services\ProcesadorService;
use app\utils\AudioUtil;
use Throwable;

class TestController
{
    private array $log = [];

    public function index(Request $request)
    {
        return view('test/index', ['name' => 'Casiel Tester']);
    }

    private function ejecutarProceso(Request $request, bool $esForzado)
    {
        $this->log['inicio'] = 'Iniciando proceso de prueba manual.';
        
        try {
            // 1. Inicializar todos los servicios
            $swordService = new SwordService(config('api.sword.api_url'), config('api.sword.api_key'));
            $geminiService = new GeminiService(config('api.gemini.api_key'), config('api.gemini.model_id'));
            $audioUtil = new AudioUtil();
            $swordBaseUrl = config('api.sword.base_url');
            
            if (!$swordBaseUrl || !config('api.sword.api_key') || !config('api.gemini.api_key')) {
                throw new \Exception('Configuración de API (.env) incompleta o no cargada.');
            }
            $this->log['configuracion'] = 'Servicios y variables de entorno cargados.';

            // 2. Obtener el sample a procesar
            $this->log['paso_inicial'] = $esForzado ? 'Buscando el último sample...' : 'Buscando 1 sample pendiente/fallido...';
            $sample = $esForzado
                ? $swordService->obtenerUltimoSample()
                : $swordService->obtenerSamplePendiente();

            if (empty($sample)) {
                $this->log['resultado'] = 'No se encontró ningún sample para procesar.';
                return json(['log_ejecucion' => $this->log]);
            }
            $this->log["sample_seleccionado"] = ['id' => $sample['id'], 'url_original' => $sample['metadata']['url_archivo'] ?? 'N/A'];

            // 3. Orquestar el procesamiento
            $procesadorService = new ProcesadorService($swordService, $geminiService, $audioUtil, $swordBaseUrl);
            $logProceso = $procesadorService->procesarSample($sample, $esForzado);

            $this->log = array_merge($this->log, $logProceso);
            return json(['log_ejecucion' => $this->log]);

        } catch (Throwable $e) {
            $this->log['error_fatal'] = [
                'mensaje' => 'Excepción no controlada en el controlador.', 
                'error' => $e->getMessage(), 
                'archivo' => $e->getFile(), 
                'linea' => $e->getLine()
            ];
            return json(['log_ejecucion' => $this->log], 500);
        }
    }

    public function ejecutarTest(Request $request)
    {
        return $this->ejecutarProceso($request, false);
    }

    public function ejecutarTestForzado(Request $request)
    {
        return $this->ejecutarProceso($request, true);
    }
}
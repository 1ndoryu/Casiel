<?php

namespace app\controller;

use support\Request;
use app\services\SwordService;
use app\services\GeminiService;
use app\utils\AudioUtil;
use Throwable;

class TestController
{
    private SwordService $swordService;
    private GeminiService $geminiService;
    private AudioUtil $audioUtil;
    private string $swordBaseUrl;
    private array $log = [];

    public function index(Request $request)
    {
        return view('test/index', ['name' => 'Casiel Tester']);
    }

    private function inicializarServicios(): bool
    {
        $this->log['inicio'] = 'Iniciando proceso de prueba manual.';

        try {
            $this->swordService = new SwordService(config('api.sword.api_url'), config('api.sword.api_key'));
            $this->geminiService = new GeminiService(config('api.gemini.api_key'), config('api.gemini.model_id'));
            $this->audioUtil = new AudioUtil();
            $this->swordBaseUrl = config('api.sword.base_url');

            if (!$this->swordBaseUrl || !config('api.sword.api_key') || !config('api.gemini.api_key')) {
                throw new \Exception('Configuración de API (.env) incompleta o no cargada.');
            }
            $this->log['configuracion'] = 'Servicios y variables de entorno cargados.';
            return true;
        } catch (\Throwable $e) {
            $this->log['error_config'] = $e->getMessage();
            return false;
        }
    }

    private function procesarSampleUnico(array $sample, bool $esForzado = false)
    {
        $idSample = $sample['id'];
        $metadataActual = $sample['metadata'] ?? [];
        $urlAudioOriginal = $metadataActual['url_archivo'] ?? null;
        $nombreOriginal = basename($urlAudioOriginal);
        $statusSuffix = $esForzado ? '_forzado' : '_test';

        $this->log["sample_seleccionado"] = ['id' => $idSample, 'url_original' => $urlAudioOriginal];

        if (!$urlAudioOriginal) {
            $this->log['error_fatal'] = "El sample ID: $idSample no tiene 'url_archivo' en su metadata.";
            return json(['log_ejecucion' => $this->log], 422);
        }

        $this->swordService->actualizarMetadataSample($idSample, array_merge($metadataActual, ['ia_status' => 'procesando' . $statusSuffix]));
        $this->log['paso1_marcar_procesando'] = "Sample ID: $idSample marcado como 'procesando{$statusSuffix}'.";

        $rutaTemporalLigero = null; // Para limpieza en caso de fallo

        try {
            // FASE 1: OBTENER AUDIO Y ENVIAR A IA PARA ANÁLISIS Y NOMBRE
            $urlCompletaAudio = rtrim($this->swordBaseUrl, '/') . $urlAudioOriginal;
            $rutaTemporalLigero = $this->audioUtil->procesarDesdeUrl($urlCompletaAudio, "temp_for_gemini", pathinfo($nombreOriginal, PATHINFO_EXTENSION))['ruta_ligero'];

            if (!$rutaTemporalLigero || !file_exists($rutaTemporalLigero)) {
                throw new \Exception("Fallo al crear la versión ligera temporal para el análisis de IA.");
            }

            $contextoIA = ['titulo' => $sample['titulo']];
            $metadataGeneradaIA = $this->geminiService->analizarAudio($rutaTemporalLigero, $contextoIA);

            if (!$metadataGeneradaIA || empty($metadataGeneradaIA['nombre_archivo_base'])) {
                throw new \Exception("El análisis de Gemini falló o no devolvió un 'nombre_archivo_base'.");
            }
            $this->log['paso2_analisis_gemini'] = $metadataGeneradaIA;
            unlink($rutaTemporalLigero); // Limpiar el audio temporal de IA
            $rutaTemporalLigero = null;

            // FASE 2: PROCESAMIENTO FINAL CON EL NOMBRE CORRECTO
            $nombreArchivoBase = $metadataGeneradaIA['nombre_archivo_base'];
            $extensionOriginal = pathinfo($nombreOriginal, PATHINFO_EXTENSION);

            $resultadoAudio = $this->audioUtil->procesarDesdeUrl($urlCompletaAudio, $nombreArchivoBase, $extensionOriginal);

            if (!$resultadoAudio) {
                throw new \Exception("Fallo en el pipeline final de AudioUtil::procesarDesdeUrl.");
            }
            $this->log['paso3_procesamiento_final_audio'] = $resultadoAudio;

            // FASE 3: ACTUALIZACIÓN FINAL EN SWORD (SIN SUBIR ARCHIVOS)
            $metadataFinal = array_merge(
                $metadataActual,
                $resultadoAudio['metadata_tecnica'],
                $metadataGeneradaIA,
                [
                    'ia_status' => 'completado' . $statusSuffix,
                    'url_stream' => $resultadoAudio['url_stream'],
                    'nombre_archivo_ligero' => $resultadoAudio['nombre_ligero'],
                    'nombre_archivo_original' => $resultadoAudio['nombre_original'],
                    'ia_retry_count' => 0
                ]
            );

            $exito = $this->swordService->actualizarMetadataSample($idSample, $metadataFinal);
            $this->log['paso4_actualizacion_final'] = ['status' => $exito ? 'ok' : 'fallido', 'metadata_enviada' => $metadataFinal];
            $this->log['fin'] = 'Proceso de prueba finalizado con éxito.';

            return json(['log_ejecucion' => $this->log]);
        } catch (\Throwable $e) {
            if ($rutaTemporalLigero && file_exists($rutaTemporalLigero)) {
                unlink($rutaTemporalLigero);
            }

            $retryCount = ($metadataActual['ia_retry_count'] ?? 0) + 1;
            $metadataError = array_merge($metadataActual, [
                'ia_status' => 'fallido' . $statusSuffix,
                'ia_retry_count' => $retryCount,
                'ia_last_error' => substr($e->getMessage(), 0, 500)
            ]);
            $this->swordService->actualizarMetadataSample($idSample, $metadataError);
            $this->log['error_fatal'] = ['mensaje' => $e->getMessage(), 'archivo' => $e->getFile(), 'linea' => $e->getLine()];
            return json(['log_ejecucion' => $this->log], 500);
        }
    }

    public function ejecutarTest(Request $request)
    {
        try {
            if (!$this->inicializarServicios()) return json(['log_ejecucion' => $this->log], 500);

            $this->log['paso_inicial'] = 'Buscando 1 sample pendiente o fallido...';
            $samples = $this->swordService->obtenerSamplesPendientes(1);

            if (empty($samples)) {
                $this->log['resultado'] = 'No se encontraron samples pendientes o fallidos.';
                return json(['log_ejecucion' => $this->log]);
            }

            return $this->procesarSampleUnico($samples[0], false);
        } catch (Throwable $e) {
            $this->log['error_excepcion'] = ['mensaje' => 'Excepción no controlada.', 'error' => $e->getMessage(), 'archivo' => $e->getFile(), 'linea' => $e->getLine()];
            return json(['log_ejecucion' => $this->log], 500);
        }
    }

    public function ejecutarTestForzado(Request $request)
    {
        try {
            if (!$this->inicializarServicios()) return json(['log_ejecucion' => $this->log], 500);

            $this->log['paso_inicial'] = 'Buscando el último sample (modo forzado)...';
            $samples = $this->swordService->obtenerUltimoSample();

            if (empty($samples)) {
                $this->log['resultado'] = 'No se encontró ningún sample en la base de datos.';
                return json(['log_ejecucion' => $this->log]);
            }

            return $this->procesarSampleUnico($samples[0], true);
        } catch (Throwable $e) {
            $this->log['error_excepcion'] = ['mensaje' => 'Excepción no controlada.', 'error' => $e->getMessage(), 'archivo' => $e->getFile(), 'linea' => $e->getLine()];
            return json(['log_ejecucion' => $this->log], 500);
        }
    }
}

<?php

namespace app\controller;

use support\Request;
use app\services\SwordService;
use app\services\GeminiService;
use app\utils\AudioUtil; // Importar AudioUtil
use Throwable;

class TestController
{
    private SwordService $swordService;
    private GeminiService $geminiService;
    private AudioUtil $audioUtil; // Añadir AudioUtil
    private string $swordBaseUrl;
    private array $log = [];

    public function index(Request $request)
    {
        return view('test/index', ['name' => 'Casiel Tester']);
    }

    private function inicializarServicios(): bool
    {
        $this->log['inicio'] = 'Iniciando proceso de prueba manual.';

        $this->swordService = new SwordService(config('api.sword.api_url'), config('api.sword.api_key'));
        $this->geminiService = new GeminiService(config('api.gemini.api_key'), config('api.gemini.model_id'));
        $this->audioUtil = new AudioUtil(); // Instanciar AudioUtil
        $this->swordBaseUrl = config('api.sword.base_url');

        if (!$this->swordBaseUrl || !config('api.sword.api_key') || !config('api.gemini.api_key')) {
            $this->log['error_config'] = 'Configuración de API (.env) incompleta o no cargada.';
            return false;
        }
        $this->log['configuracion'] = 'Servicios y variables de entorno cargados.';
        return true;
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

        // Marcar como procesando en Sword
        $this->swordService->actualizarMetadataSample($idSample, array_merge($metadataActual, ['ia_status' => 'procesando' . $statusSuffix]));
        $this->log['paso1_marcar_procesando'] = "Sample ID: $idSample marcado como 'procesando{$statusSuffix}'.";

        try {
            // FASE DE PROCESAMIENTO DE AUDIO
            $urlCompletaAudio = rtrim($this->swordBaseUrl, '/') . $urlAudioOriginal;
            $resultadoAudio = $this->audioUtil->procesarDesdeUrl($urlCompletaAudio, $nombreOriginal);

            if (!$resultadoAudio) {
                throw new \Exception("Fallo en AudioUtil::procesarDesdeUrl.");
            }
            $this->log['paso2_procesamiento_audio'] = $resultadoAudio;

            // FASE DE SUBIDA DEL NUEVO AUDIO
            $archivoSubido = $this->swordService->subirArchivo($resultadoAudio['ruta_mp3'], $resultadoAudio['nombre_mp3']);
            if (!$archivoSubido) {
                throw new \Exception("Fallo al subir el nuevo MP3 a Sword.");
            }
            $this->log['paso3_subida_sword'] = $archivoSubido;
            unlink($resultadoAudio['ruta_mp3']); // Limpiar el archivo temporal

            // FASE DE ANÁLISIS CON IA
            $contextoIA = [
                'titulo' => $sample['titulo'],
                'metadata_tecnica' => $resultadoAudio['metadata_tecnica']
            ];
            $metadataGeneradaIA = $this->geminiService->analizarAudio($resultadoAudio['ruta_mp3'], $contextoIA);

            if (!$metadataGeneradaIA) {
                throw new \Exception("El análisis de Gemini falló o no devolvió datos.");
            }
            $this->log['paso4_analisis_gemini'] = $metadataGeneradaIA;

            // FASE DE ACTUALIZACIÓN FINAL
            $metadataFinal = array_merge(
                $metadataActual,
                $resultadoAudio['metadata_tecnica'],
                $metadataGeneradaIA,
                [
                    'ia_status' => 'completado' . $statusSuffix,
                    'url_archivo_ligero' => $archivoSubido['url'],
                    'nombre_archivo_original' => $resultadoAudio['nombre_original']
                ]
            );

            $exito = $this->swordService->actualizarMetadataSample($idSample, $metadataFinal);
            $this->log['paso5_actualizacion_final'] = ['status' => $exito ? 'ok' : 'fallido', 'metadata_enviada' => $metadataFinal];
            $this->log['fin'] = 'Proceso de prueba finalizado con éxito.';

            return json(['log_ejecucion' => $this->log]);
        } catch (\Throwable $e) {
            $metadataError = array_merge($metadataActual, ['ia_status' => 'fallido' . $statusSuffix]);
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

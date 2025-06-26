<?php

namespace app\controller;

use support\Request;
use app\services\SwordService;
use app\services\GeminiService;
use app\services\ProcesadorService;
use app\utils\AudioUtil;
use GuzzleHttp\Client;
use Throwable;

class TestController
{
    private array $log = [];
    private ?SwordService $swordService = null;
    private ?ProcesadorService $procesadorService = null;
    private array $idsParaLimpiar = [];

    public function index(Request $request)
    {
        return view('test/index', [
            'sword_url' => config('api.sword.api_url'),
            'sword_key' => config('api.sword.api_key')
        ]);
    }

    public function ejecutarTestCompleto(Request $request)
    {
        $apiUrl = $request->post('sword_url', config('api.sword.api_url'));
        $apiKey = $request->post('sword_key', config('api.sword.api_key'));
        $testFilePath = base_path() . '/tests/fixtures/test.mp3';

        try {
            $this->log = [];
            $this->log['inicio'] = 'INICIANDO TEST DE INTEGRACIÓN COMPLETO';
            $this->log['config'] = ['sword_api_url' => $apiUrl, 'test_file' => $testFilePath];

            if (!file_exists($testFilePath)) {
                throw new \Exception("El archivo de prueba '{$testFilePath}' no existe. Por favor, créalo.");
            }
            if (empty($apiUrl) || empty($apiKey)) {
                throw new \Exception("La URL y la Key de la API de Sword son obligatorias.");
            }

            // Inicializar servicios con los datos del formulario
            $this->swordService = new SwordService($apiUrl, $apiKey);
            $geminiService = new GeminiService(config('api.gemini.api_key'), config('api.gemini.model_id'));
            $audioUtil = new AudioUtil();
            $this->procesadorService = new ProcesadorService($this->swordService, $geminiService, $audioUtil);

            // --- FASE 1: PROCESAMIENTO NORMAL ---
            $this->log['fase1_inicio'] = "Iniciando FASE 1: Procesamiento de un sample nuevo.";
            $sample1 = $this->crearSampleDePrueba('Test Sample ' . uniqid());
            $this->procesadorService->procesarSample($sample1);
            $this->verificarProcesamientoExitoso($sample1['id']);

            // --- INICIO DE LA CORRECCIÓN ---
            // Se añade una pausa para dar tiempo a la indexación de la base de datos en Sword.
            // El test es muy rápido y puede que la búsqueda por hash en la fase 2 falle
            // si el índice de metadatos del sample 1 no se ha actualizado todavía.
            $this->log['pausa_tecnica'] = "Pausa de 2 segundos para permitir la indexación del backend...";
            sleep(2);
            // --- FIN DE LA CORRECCIÓN ---

            // --- FASE 2: DETECCIÓN DE DUPLICADOS ---
            $this->log['fase2_inicio'] = "Iniciando FASE 2: Detección de duplicado.";
            $sample2 = $this->crearSampleDePrueba('Test Duplicado ' . uniqid());
            $this->procesadorService->procesarSample($sample2);
            $this->verificarProcesamientoDuplicado($sample2['id'], $sample1['id']);

            // --- FASE 3: VERIFICACIÓN DE STREAMING ---
            $this->log['fase3_inicio'] = "Iniciando FASE 3: Verificación de URL de streaming.";
            $this->verificarStreaming($sample1['id']);

        } catch (Throwable $e) {
            $this->log['error_fatal'] = [
                'mensaje' => 'El test falló con una excepción.',
                'error' => $e->getMessage(),
                'archivo' => $e->getFile(),
                'linea' => $e->getLine(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 1000) . '...'
            ];
        } finally {
            // --- FASE 4: LIMPIEZA ---
            $this->log['fase4_inicio'] = "Iniciando FASE 4: Limpieza de datos de prueba.";
            $this->limpiarDatosDePrueba();
            $this->log['fin'] = 'TEST DE INTEGRACIÓN FINALIZADO.';
        }

        return json(['log_ejecucion' => $this->log]);
    }
    
    /**
     * Comprueba una condición y lanza una excepción con un mensaje detallado si falla.
     * Si tiene éxito, registra el paso como exitoso en el log.
     * @param bool $condition La condición a verificar.
     * @param string $successMessage El mensaje a registrar en caso de éxito.
     * @param string $failureMessage El mensaje de error si la condición es falsa.
     */
    private function assertTestCondition(bool $condition, string $successMessage, string $failureMessage)
    {
        if (!$condition) {
            throw new \Exception($failureMessage);
        }
        $this->log['verificacion_paso'][] = "✔️ " . $successMessage;
    }

    private function crearSampleDePrueba(string $titulo): array
    {
        $testFilePath = base_path() . '/tests/fixtures/test.mp3';
        $media = $this->swordService->subirArchivoLocal($testFilePath);
        if (!$media) {
            throw new \Exception("Falló la subida del archivo de prueba a Sword.");
        }
        $this->log['creacion']['media_creado'] = $media;

        $sample = $this->swordService->crearSample($titulo, $media['id'], basename($testFilePath));
        if (!$sample) {
            throw new \Exception("Falló la creación del sample de prueba en Sword.");
        }
        $this->log['creacion']['sample_creado'] = $sample;
        
        // Guardar ID para limpieza posterior
        $this->idsParaLimpiar[] = $sample['id'];

        return $sample;
    }

    private function verificarProcesamientoExitoso(int $id)
    {
        $this->log['verificacion_exito_inicio'] = "Verificando procesamiento exitoso para Sample ID: $id";
        $sample = $this->swordService->obtenerSamplePorId($id);
        $this->log['verificacion_exito']['sample_obtenido'] = $sample;
        
        if (!$sample) {
            throw new \Exception("No se pudo obtener el sample ID: $id desde Sword para su verificación.");
        }
        
        $metadata = $sample['metadata'] ?? [];

        $iaStatus = isset($metadata['ia_status']) ? $metadata['ia_status'] : 'n/a';
        $this->assertTestCondition(
            ($metadata['ia_status'] ?? '') === 'completado',
            "El estado es 'completado'.",
            "Fase 1 fallida: Se esperaba estado 'completado' para ID $id, pero se encontró '{$iaStatus}'."
        );
        
        // --- INICIO DE LA CORRECCIÓN ---
        // Se cambia !empty() por una comprobación que considera el 0 como un valor válido.
        $this->assertTestCondition(
            isset($metadata['bpm']) && is_numeric($metadata['bpm']),
            "El campo 'bpm' tiene un valor ({$metadata['bpm']}).",
            "Fase 1 fallida: Se esperaba un valor numérico para 'bpm' en ID $id, pero no se encontró o no es válido."
        );
        // --- FIN DE LA CORRECCIÓN ---

        $this->assertTestCondition(
            !empty($metadata['tags']),
            "El campo 'tags' tiene contenido.",
            "Fase 1 fallida: Se esperaban 'tags' de la IA para ID $id, pero está vacío."
        );
        $this->assertTestCondition(
            !empty($metadata['url_stream']),
            "El campo 'url_stream' tiene un valor.",
            "Fase 1 fallida: Se esperaba una 'url_stream' para ID $id, pero está vacía."
        );
        $this->assertTestCondition(
            !empty($metadata['audio_hash']),
            "El campo 'audio_hash' tiene un valor.",
            "Fase 1 fallida: Se esperaba un 'audio_hash' para ID $id, pero está vacío."
        );

        $this->log['verificacion_exito']['status'] = 'OK';
    }
    
    private function verificarProcesamientoDuplicado(int $idDuplicado, int $idOriginal)
    {
        $this->log['verificacion_duplicado_inicio'] = "Verificando procesamiento duplicado para Sample ID: $idDuplicado";
        $sample = $this->swordService->obtenerSamplePorId($idDuplicado);
        $this->log['verificacion_duplicado']['sample_obtenido'] = $sample;

        if (!$sample) {
            throw new \Exception("No se pudo obtener el sample duplicado ID: $idDuplicado desde Sword para su verificación.");
        }

        $metadata = $sample['metadata'];

        $iaStatus = isset($metadata['ia_status']) ? $metadata['ia_status'] : 'n/a';
        $this->assertTestCondition(
            ($metadata['ia_status'] ?? '') === 'duplicado',
            "El estado es 'duplicado'.",
            "Fase 2 fallida: Se esperaba estado 'duplicado' para ID $idDuplicado, pero se encontró '{$iaStatus}'."
        );
        $duplicadoDeId = isset($metadata['duplicado_de_id']) ? $metadata['duplicado_de_id'] : 0;
        $this->assertTestCondition(
            $duplicadoDeId == $idOriginal,
            "El ID del duplicado ($idOriginal) es correcto.",
            "Fase 2 fallida: Se esperaba 'duplicado_de_id'={$idOriginal} para ID $idDuplicado, pero se encontró '{$duplicadoDeId}'."
        );

        $this->log['verificacion_duplicado']['status'] = 'OK';
    }

    private function verificarStreaming(int $id)
    {
        $sample = $this->swordService->obtenerSamplePorId($id);
        $streamUrl = $sample['metadata']['url_stream'] ?? null;
        if (!$streamUrl) throw new \Exception("Fase 3 fallida: No se encontró la url_stream para verificar.");
        
        // La URL es relativa, necesitamos construir la URL completa del servidor de Casiel
        $casielHost = request()->host();
        $fullUrl = "http://" . $casielHost . $streamUrl;

        $this->log['verificacion_streaming']['url_a_probar'] = $fullUrl;

        $client = new Client(['http_errors' => false]);
        $response = $client->get($fullUrl);
        $statusCode = $response->getStatusCode();

        $this->assertTestCondition(
            $statusCode === 200,
            "La URL de streaming devolvió un código 200 OK.",
            "Fase 3 fallida: La URL de streaming devolvió un código de estado {$statusCode} en lugar de 200."
        );

        $this->log['verificacion_streaming']['status_code'] = $statusCode;
        $this->log['verificacion_streaming']['status'] = 'OK';
    }

    private function limpiarDatosDePrueba()
    {
        if (empty($this->idsParaLimpiar) || !$this->swordService) {
            $this->log['limpieza'] = 'No hay datos de prueba que limpiar o el servicio no está inicializado.';
            return;
        }
        foreach ($this->idsParaLimpiar as $id) {
            $resultado = $this->swordService->eliminarSample($id);
            $this->log['limpieza']['sample_id_' . $id] = $resultado ? 'Eliminado' : 'Falló la eliminación';
        }
        $this->idsParaLimpiar = [];
    }
}
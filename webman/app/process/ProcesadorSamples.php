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
    private string $swordBaseUrl;

    /**
     * Parsea manualmente un archivo .env y devuelve un array asociativo.
     * @param string $path La ruta completa al archivo .env.
     * @return array La configuración parseada.
     */
    private function parseEnvFile(string $path): array
    {
        $config = [];
        if (!file_exists($path) || !is_readable($path)) {
            casielLog("El archivo .env no existe o no se puede leer en: $path", [], 'error');
            return $config;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            $config[trim($key)] = trim($value);
        }
        return $config;
    }

    public function onWorkerStart()
    {
        // SOLUCIÓN DEFINITIVA: Parsear el .env manualmente para evitar los problemas
        // con getenv() en los workers de Windows.
        $envPath = base_path() . DIRECTORY_SEPARATOR . '.env';
        $config = $this->parseEnvFile($envPath);

        $this->swordBaseUrl = $config['SWORD_BASE_URL'] ?? '';
        $swordApiUrl = $config['SWORD_API_URL'] ?? '';
        $swordApiKey = $config['SWORD_API_KEY'] ?? '';
        $geminiApiKey = $config['API_GEMINI'] ?? '';
        $geminiModelId = 'gemini-1.5-flash-latest';

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
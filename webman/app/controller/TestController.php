<?php

namespace app\controller;

use support\Request;
use app\services\SwordService;
use app\services\GeminiService;

class TestController
{
    public function index(Request $request)
    {
        return view('test/index', ['name' => 'Casiel Tester']);
    }

    public function ejecutarTest(Request $request)
    {
        // Usamos la config global que ya ha sido cargada por el framework
        $swordApiUrl = config('api.sword.api_url');
        $swordApiKey = config('api.sword.api_key');
        $geminiApiKey = config('api.gemini.api_key');
        $swordBaseUrl = config('api.sword.base_url');
        $geminiModelId = config('api.gemini.model_id', 'gemini-1.5-flash-latest');

        if (!$swordApiUrl || !$swordApiKey || !$geminiApiKey || !$swordBaseUrl) {
            return json(['error' => 'Configuración de API (.env) incompleta o no cargada.'], 500);
        }

        $swordService = new SwordService($swordApiUrl, $swordApiKey);
        $geminiService = new GeminiService($geminiApiKey, $geminiModelId);
        $respuestaCompleta = [];

        // 1. Obtener un sample pendiente
        $samples = $swordService->obtenerSamplesPendientes(1);
        if (empty($samples)) {
            return json(['mensaje' => 'No se encontraron samples pendientes para procesar.']);
        }
        $sample = $samples[0];
        $idSample = $sample['id'];
        $metadataActual = $sample['metadata'] ?? [];
        $urlAudio = $metadataActual['url_archivo'] ?? null;

        $respuestaCompleta['paso1_sample_obtenido'] = $sample;

        if (!$urlAudio) {
            return json(['error' => "El sample ID: $idSample no tiene 'url_archivo'.", 'data' => $respuestaCompleta], 422);
        }

        // 2. Marcar como 'procesando' para evitar que otro proceso lo tome (buena práctica)
        $swordService->actualizarMetadataSample($idSample, array_merge($metadataActual, ['ia_status' => 'procesando_test']));
        $respuestaCompleta['paso2_marcado_como_procesando'] = ['status' => 'ok', 'id' => $idSample];

        // 3. Analizar con Gemini
        $urlCompletaAudio = rtrim($swordBaseUrl, '/') . $urlAudio;
        $metadataGenerada = $geminiService->analizarAudio($urlCompletaAudio);
        $respuestaCompleta['paso3_analisis_gemini'] = $metadataGenerada ?? ['error' => 'El análisis de Gemini falló o no devolvió datos. Revisar logs de Casiel.'];

        // 4. Actualizar en Sword con el resultado final
        if ($metadataGenerada) {
            $metadataFinal = array_merge($metadataActual, $metadataGenerada, ['ia_status' => 'completado_test']);
            $exito = $swordService->actualizarMetadataSample($idSample, $metadataFinal);
            $respuestaCompleta['paso4_actualizacion_sword'] = ['status' => $exito ? 'ok' : 'fallido', 'metadata_enviada' => $metadataFinal];
        } else {
            $metadataFinal = array_merge($metadataActual, ['ia_status' => 'fallido_test']);
            $swordService->actualizarMetadataSample($idSample, $metadataFinal);
            $respuestaCompleta['paso4_actualizacion_sword'] = ['status' => 'fallido', 'metadata_enviada' => $metadataFinal];
        }

        return json($respuestaCompleta);
    }
}

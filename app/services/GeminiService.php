<?php

namespace app\services;

use Workerman\Http\Client;
use Throwable;

/**
 * Service to interact with the Google Gemini API.
 */
class GeminiService
{
    protected string $apiUrl;

    public function __construct()
    {
        $apiKey = getenv('GEMINI_API_KEY');
        $modelId = getenv('GEMINI_MODEL_ID');
        $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent?key={$apiKey}";
    }

    /**
     * Asynchronously analyzes a local audio file and returns JSON metadata via callbacks.
     *
     * @param string $localAudioPath The local path to the audio file.
     * @param array $context Additional data to enrich the prompt (title, technical data).
     * @param callable $onSuccess Callback on successful analysis: `function(?array $metadata)`
     * @param callable $onError Callback on failure: `function(string $errorMessage)`
     */
    public function analyzeAudio(string $localAudioPath, array $context, callable $onSuccess, callable $onError): void
    {
        casiel_log('gemini_api', "Iniciando análisis de Gemini para: " . basename($localAudioPath));

        try {
            $audioContent = file_get_contents($localAudioPath);
            if ($audioContent === false) {
                $onError("No se pudo leer el archivo de audio local: $localAudioPath");
                return;
            }
            $audioBase64 = base64_encode($audioContent);
            $mimeType = mime_content_type($localAudioPath) ?: 'audio/mp3';

            $prompt = $this->createPrompt($context);

            $requestBody = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $audioBase64
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                ],
            ];

            $httpClient = new Client();
            $httpClient->post($this->apiUrl, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $requestBody,
                'timeout' => 90.0,
            ], function ($response) use ($onSuccess, $onError) {
                if ($response->getStatusCode() !== 200) {
                    $onError("La API de Gemini respondió con el código de estado: " . $response->getStatusCode());
                    return;
                }
                
                $responseBody = json_decode((string)$response->getBody(), true);

                if (isset($responseBody['candidates'][0]['content']['parts'][0]['text'])) {
                    $jsonText = $responseBody['candidates'][0]['content']['parts'][0]['text'];
                    casiel_log('gemini_api', "Respuesta JSON recibida de la IA.");
                    $onSuccess(json_decode($jsonText, true));
                } else {
                    casiel_log('gemini_api', "La respuesta de la IA no tiene el formato esperado.", ['response' => $responseBody], 'warning');
                    $onError("La respuesta de la IA no tiene el formato esperado.");
                }
            }, function ($exception) use ($onError) {
                casiel_log('gemini_api', "Excepción en la petición a Gemini API.", ['error' => $exception->getMessage()], 'error');
                $onError("Excepción en la petición a Gemini API: " . $exception->getMessage());
            });

        } catch (Throwable $e) {
            casiel_log('gemini_api', "Error al procesar el audio para Gemini.", ['error' => $e->getMessage()], 'error');
            $onError("Error al procesar el audio: " . $e->getMessage());
        }
    }

    /**
     * Creates the prompt to be sent to the AI, using additional context.
     */
    private function createPrompt(array $context): string
    {
        $contextualInfo = [];
        if (!empty($context['title'])) {
            $contextualInfo[] = "El título original que el usuario le dio es '{$context['title']}'. Úsalo como inspiración.";
        }
        if (!empty($context['technical_metadata'])) {
            $technicalData = json_encode($context['technical_metadata']);
            $contextualInfo[] = "Ya he analizado técnicamente el audio y obtuve estos datos: {$technicalData}. NO los generes tú, enfócate en los campos creativos.";
        }

        $promptContext = implode(" ", $contextualInfo);

        return <<<PROMPT
Analiza este audio. {$promptContext}
Tu tarea es generar únicamente un objeto JSON con la siguiente estructura. Sé creativo pero preciso.
No incluyas campos que ya te he proporcionado en los datos técnicos.

- nombre_archivo_base: Un título corto y descriptivo para el sample, en inglés, en minúsculas y usando espacios. NO uses guiones bajos. Debe ser legible por humanos. Ejemplos: "deep kick 808", "sad guitar melody", "energetic synth loop".
- tags: Array de strings con etiquetas descriptivas (ej: "melodic", "dark", "808", "lo-fi").
- tipo: String, "one shot" o "loop".
- genero: Array de strings con géneros musicales (ej: "hip hop", "trap", "electronic").
- emocion: Array de strings con emociones que evoca (ej: "energetic", "sad", "chill").
- instrumentos: Array de strings con los instrumentos principales que detectes (ej: "guitar", "piano", "synth", "drums").
- artista_vibes: Array de strings con nombres de artistas que tienen un estilo similar.
- descripcion_corta: Una descripción muy breve de 10-15 palabras.
- descripcion: Una descripción detallada de 30-50 palabras.
PROMPT;
    }
}
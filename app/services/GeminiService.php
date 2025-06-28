<?php

namespace app\services;

use app\services\concerns\MakesAsyncHttpRequests;
use Workerman\Http\Client;
use Throwable;

/**
 * Service to interact with the Google Gemini API.
 */
class GeminiService
{
    use MakesAsyncHttpRequests;

    protected string $apiUrl;

    public function __construct(Client $httpClient)
    {
        $apiKey = getenv('GEMINI_API_KEY');
        $modelId = getenv('GEMINI_MODEL_ID');
        $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent?key={$apiKey}";
        $this->httpClient = $httpClient;
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
            if (!file_exists($localAudioPath)) {
                $onError("No se encontró el archivo de audio local: $localAudioPath");
                return;
            }
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

            $options = [
                'json' => $requestBody,
                'timeout' => 90.0,
                'headers' => ['Content-Type' => 'application/json']
            ];

            $this->executeRequest(
                'POST',
                $this->apiUrl,
                $options,
                function ($responseBody) use ($onSuccess, $onError) {
                    // SOLUCIÓN: Validar la estructura de la respuesta para evitar errores fatales.
                    if (empty($responseBody['candidates'][0]['content']['parts'][0]['text'])) {
                        $errorDetail = json_encode($responseBody);
                        casiel_log('gemini_api', "La respuesta de la IA no tiene el formato esperado o está vacía.", ['response' => $errorDetail], 'warning');
                        $onError("La respuesta de la IA no tiene el formato esperado. Respuesta: " . substr($errorDetail, 0, 200));
                        return;
                    }

                    $jsonText = $responseBody['candidates'][0]['content']['parts'][0]['text'];
                    $decodedJson = json_decode($jsonText, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        casiel_log('gemini_api', "Respuesta JSON válida recibida de la IA.");
                        $onSuccess($decodedJson);
                    } else {
                        casiel_log('gemini_api', "La IA devolvió un string que no es JSON válido.", ['response_text' => $jsonText], 'error');
                        $onError("La respuesta de Gemini no es un JSON válido.");
                    }
                },
                function (string $errorMessage) use ($onError) {
                    casiel_log('gemini_api', "Error en la petición a Gemini API.", ['error' => $errorMessage], 'error');
                    $onError("Error en la petición a Gemini API: " . $errorMessage);
                }
            );
        } catch (Throwable $e) {
            casiel_log('gemini_api', "Error fatal al preparar la petición para Gemini.", ['error' => $e->getMessage()], 'error');
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

<?php

namespace app\services;

use app\services\concerns\MakesAsyncHttpRequests;
use Workerman\Http\Client;
use Throwable;

class GeminiService
{
    use MakesAsyncHttpRequests;

    protected string $apiUrl;
    private QuotaService $quotaService;

    public function __construct(Client $httpClient)
    {
        $apiKey = getenv('GEMINI_API_KEY');
        $modelId = getenv('GEMINI_MODEL_ID');
        $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent?key={$apiKey}";
        $this->httpClient = $httpClient;
        $this->quotaService = new QuotaService('gemini');
    }

    public function analyzeAudio(string $localAudioPath, array $context, callable $onSuccess, callable $onError): void
    {
        if (!$this->quotaService->isAllowed()) {
            $onError("Límite de peticiones diarias a Gemini ha sido alcanzado.");
            return;
        }

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
                            ['inline_data' => ['mime_type' => $mimeType, 'data' => $audioBase64]]
                        ]
                    ]
                ],
                'generationConfig' => ['responseMimeType' => 'application/json'],
            ];

            $options = [
                'json' => $requestBody,
                'timeout' => 90.0,
                'headers' => ['Content-Type' => 'application/json']
            ];

            // Record usage right before making the request
            $this->quotaService->recordUsage();

            $this->executeRequest(
                'POST',
                $this->apiUrl,
                $options,
                function ($responseBody) use ($onSuccess, $onError) {
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

    private function createPrompt(array $context): string
    {
        $contextualInfo = [];
        if (!empty($context['title'])) {
            $contextualInfo[] = "El nombre original del archivo es '{$context['title']}'. Úsalo como inspiración.";
        }
        if (!empty($context['existing_metadata'])) {
            $existingData = json_encode($context['existing_metadata'], JSON_UNESCAPED_UNICODE);
            $contextualInfo[] = "El contenido ya tiene la siguiente metadata guardada: {$existingData}. Analiza esta información junto con el audio para mejorarla, enriquecerla o corregirla. Tu respuesta debe fusionar inteligentemente la información existente con tu nuevo análisis. Si un campo ya existe y es bueno, puedes conservarlo.";
        }
        if (!empty($context['technical_metadata'])) {
            $contextualInfo[] = "Los datos técnicos ya calculados son: " . json_encode($context['technical_metadata']) . ". No necesitas calcularlos.";
        }

        $promptContext = implode(" ", $contextualInfo);

        return <<<PROMPT
Analiza este audio. {$promptContext}
Tu tarea es generar ÚNICAMENTE un objeto JSON válido con la siguiente estructura. Sé creativo y preciso.
NO incluyas en tu respuesta los campos puramente técnicos (bpm, tonalidad, escala), ya que esos se añadirán después. Tu respuesta DEBE ser solo el JSON.

- "nombre_archivo_base": Un título corto y descriptivo para el sample, en inglés, en minúsculas y usando espacios. Ej: "deep kick 808", "sad guitar melody".
- "tags": Array de strings con etiquetas descriptivas en INGLÉS (ej: "melodic", "dark", "808", "lo-fi").
- "tags_es": Array de strings con las mismas etiquetas que 'tags' pero traducidas al ESPAÑOL.
- "tipo": String, debe ser "one shot" o "loop".
- "genero": Array de strings con géneros musicales en INGLÉS (ej: "hip hop", "trap", "electronic").
- "emocion": Array de strings con emociones que evoca en INGLÉS (ej: "energetic", "sad", "chill").
- "emocion_es": Array de strings con las mismas emociones que 'emocion' pero traducidas al ESPAÑOL.
- "instrumentos": Array de strings con los instrumentos principales que detectes en INGLÉS (ej: "guitar", "piano", "synth", "drums").
- "artista_vibes": Array de strings con nombres de artistas que tienen un estilo similar.
- "descripcion_corta": Una descripción muy breve (10-15 palabras) en INGLÉS.
- "descripcion_corta_es": La misma 'descripcion_corta' traducida al ESPAÑOL.
- "descripcion": Una descripción detallada (30-50 palabras) en INGLÉS.
- "descripcion_es": La misma 'descripcion' traducida al ESPAÑOL.
PROMPT;
    }
}

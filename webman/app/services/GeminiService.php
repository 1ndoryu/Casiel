<?php

namespace app\services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Servicio para interactuar con la API de Google Gemini.
 */
class GeminiService
{
    protected Client $cliente;
    protected string $apiUrl;

    public function __construct(string $apiKey, string $modelId)
    {
        $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent?key={$apiKey}";

        $this->cliente = new Client([
            'timeout' => 90.0, // Damos más tiempo para el análisis de IA
            'verify' => base_path() . '/config/certs/cacert.pem',
        ]);
    }

    /**
     * Analiza un archivo de audio local y devuelve un JSON con la metadata.
     * @param string $rutaAudioLocal La ruta local del archivo de audio.
     * @param array $contexto Datos adicionales para enriquecer el prompt (título, datos técnicos).
     * @return array|null La metadata generada en formato de array o null si hay error.
     */
    public function analizarAudio(string $rutaAudioLocal, array $contexto = []): ?array
    {
        casielLog("Iniciando análisis de Gemini para: " . basename($rutaAudioLocal));

        try {
            // 1. Leer el contenido del audio local
            $contenidoAudio = file_get_contents($rutaAudioLocal);
            if ($contenidoAudio === false) {
                casielLog("No se pudo leer el archivo de audio local: $rutaAudioLocal", [], 'error');
                return null;
            }
            $audioBase64 = base64_encode($contenidoAudio);
            $mimeType = mime_content_type($rutaAudioLocal) ?: 'audio/mp3';
            casielLog("Audio local leído y codificado en base64.");

            // 2. Definir el prompt para la IA, ahora enriquecido con el contexto
            $prompt = $this->crearPrompt($contexto);

            // 3. Construir la petición
            $cuerpoPeticion = [
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

            // 4. Enviar la petición a Gemini
            $respuesta = $this->cliente->post($this->apiUrl, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $cuerpoPeticion,
            ]);

            $cuerpoRespuesta = json_decode($respuesta->getBody()->getContents(), true);

            // 5. Extraer y devolver el JSON de la respuesta
            if (isset($cuerpoRespuesta['candidates'][0]['content']['parts'][0]['text'])) {
                $textoJson = $cuerpoRespuesta['candidates'][0]['content']['parts'][0]['text'];
                casielLog("Respuesta JSON recibida de la IA.");
                return json_decode($textoJson, true);
            }

            casielLog("La respuesta de la IA no tiene el formato esperado.", ['response' => $cuerpoRespuesta], 'warning');
            return null;
        } catch (GuzzleException $e) {
            casielLog("Error en la petición a Gemini API: " . $e->getMessage(), [], 'error');
            return null;
        } catch (\Exception $e) {
            casielLog("Error al procesar el audio: " . $e->getMessage(), [], 'error');
            return null;
        }
    }

    /**
     * Crea el prompt que se enviará a la IA, usando contexto adicional.
     */
    private function crearPrompt(array $contexto): string
    {
        $infoContextual = [];
        if (!empty($contexto['titulo'])) {
            $infoContextual[] = "El título original que el usuario le dio es '{$contexto['titulo']}'. Úsalo como inspiración.";
        }
        if (!empty($contexto['metadata_tecnica'])) {
            $dataTecnica = json_encode($contexto['metadata_tecnica']);
            $infoContextual[] = "Ya he analizado técnicamente el audio y obtuve estos datos: {$dataTecnica}. NO los generes tú, enfócate en los campos creativos.";
        }
        
        $promptContextual = implode(" ", $infoContextual);

        return <<<PROMPT
Analiza este audio. {$promptContextual}
Tu tarea es generar únicamente un objeto JSON con la siguiente estructura. Sé creativo pero preciso con los campos que te corresponden.
No incluyas campos que ya te he proporcionado en los datos técnicos.

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
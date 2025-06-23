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
            'timeout'  => 60.0, // Damos más tiempo para el análisis de IA
        ]);
    }

    /**
     * Analiza un archivo de audio a partir de su URL y devuelve un JSON con la metadata.
     * @param string $urlAudio La URL pública del archivo de audio.
     * @return array|null La metadata generada en formato de array o null si hay error.
     */
    public function analizarAudio(string $urlAudio): ?array
    {
        casielLog("Iniciando análisis de audio para: $urlAudio");

        try {
            // 1. Descargar el contenido del audio
            $contenidoAudio = file_get_contents($urlAudio);
            if ($contenidoAudio === false) {
                casielLog("No se pudo descargar el archivo de audio: $urlAudio", [], 'error');
                return null;
            }
            $audioBase64 = base64_encode($contenidoAudio);
            casielLog("Audio descargado y codificado en base64.");

            // 2. Definir el prompt para la IA
            $prompt = $this->crearPrompt();

            // 3. Construir la petición
            $cuerpoPeticion = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inline_data' => [
                                    'mime_type' => 'audio/mp3', // Asumimos MP3 por ahora
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

            casielLog("La respuesta de la IA no tiene el formato esperado.", $cuerpoRespuesta, 'warning');
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
     * Crea el prompt que se enviará a la IA.
     */
    private function crearPrompt(): string
    {
        return <<<PROMPT
Analiza este audio y genera únicamente un objeto JSON con la siguiente estructura y campos. Sé creativo pero preciso.
- bpm: Beats por minuto como string.
- tags: Array de strings con etiquetas descriptivas (ej: "melodic", "dark", "808", "lo-fi").
- tipo: String, "one shot" o "loop".
- genero: Array de strings con géneros musicales (ej: "hip hop", "trap", "electronic").
- emocion: Array de strings con emociones que evoca (ej: "energetic", "sad", "chill").
- instrumentos: Array de strings con los instrumentos principales (ej: "guitar", "piano", "synth", "drums").
- artista_vibes: Array de strings con nombres de artistas que tienen un estilo similar.
- descripcion_corta: Una descripción muy breve de 10-15 palabras.
- descripcion: Una descripción detallada de 30-50 palabras.
PROMPT;
    }
}
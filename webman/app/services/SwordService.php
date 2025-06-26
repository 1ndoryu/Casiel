<?php

namespace app\services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;

class SwordService
{
    protected Client $cliente;
    protected string $apiKey;
    protected string $baseUrl;
    private const MAX_REINTENTOS_IA = 3;

    public function __construct(string $apiUrl, string $apiKey, ?string $baseUrl = null)
    {
        if (empty($apiUrl) || empty($apiKey)) {
            throw new InvalidArgumentException("La URL y la Key de la API de Sword no pueden estar vacías.");
        }

        $this->baseUrl = rtrim($baseUrl ?: $apiUrl, '/');

        $clientConfig = [
            'base_uri' => rtrim($apiUrl, '/') . '/',
            'timeout'  => 30.0,
        ];

        $clientConfig['verify'] = config('casiel.sword_client.verify_ssl', true);
        if (!$clientConfig['verify']) {
            casielLog("ADVERTENCIA DE SEGURIDAD: La verificación SSL para la API de Sword está DESHABILITADA.", [], 'warning');
        }

        $this->cliente = new Client($clientConfig);
        $this->apiKey = $apiKey;
    }

    private function logError(GuzzleException $e, string $mensaje)
    {
        $contextoError = ['exception_message' => $e->getMessage()];
        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            $contextoError['status_code'] = $response->getStatusCode();
            $contextoError['response_body'] = substr((string) $response->getBody(), 0, 1000);
        }
        casielLog("$mensaje", $contextoError, 'error');
    }

    public function getMediaInfo(int $mediaId): ?array
    {
        try {
            // NOTA: Este endpoint GET /media/{id} DEBE existir en la API de Sword v2.
            $respuesta = $this->cliente->get("media/{$mediaId}", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
            ]);
            $datos = json_decode($respuesta->getBody()->getContents(), true);
            return $datos['data'] ?? null;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al obtener información para media ID: {$mediaId}. Asegúrate de que el endpoint GET /media/{id} existe en Sword API.");
            return null;
        }
    }

    public function downloadAudioByMediaId(int $mediaId): ?string
    {
        casielLog("Iniciando descarga de audio para media ID: $mediaId");

        $mediaInfo = $this->getMediaInfo($mediaId);
        if (!isset($mediaInfo['path'])) {
            casielLog("No se pudo obtener la ruta del archivo para media ID: $mediaId.", [], 'error');
            return null;
        }

        $fullUrl = $this->baseUrl . '/' . ltrim($mediaInfo['path'], '/');
        casielLog("Construida URL de descarga: {$fullUrl}");

        try {
            // Usar un nuevo cliente de Guzzle sin base_uri para descargar desde una URL absoluta
            $downloadClient = new Client(['verify' => config('casiel.sword_client.verify_ssl', true)]);
            $respuesta = $downloadClient->get($fullUrl);

            if ($respuesta->getStatusCode() === 200) {
                casielLog("Audio descargado con éxito desde {$fullUrl}.");
                return $respuesta->getBody()->getContents();
            }

            casielLog("La descarga desde {$fullUrl} devolvió un código de estado inesperado: " . $respuesta->getStatusCode(), [], 'error');
            return null;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error de Guzzle al descargar audio desde {$fullUrl}.");
            return null;
        }
    }

    public function uploadFile(string $filePath): ?array
    {
        if (!file_exists($filePath)) {
            casielLog("El archivo a subir no existe en: $filePath", [], 'error');
            return null;
        }

        casielLog("Subiendo archivo a Sword: " . basename($filePath));
        try {
            // Endpoint cambiado de 'media/upload' a 'media'
            $respuesta = $this->cliente->post("media", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'multipart' => [
                    [
                        'name'     => 'file',
                        'contents' => fopen($filePath, 'r'),
                        'filename' => basename($filePath)
                    ]
                ]
            ]);
            $datos = json_decode($respuesta->getBody()->getContents(), true);
            return $datos['data'] ?? null;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al subir archivo a Sword.");
            return null;
        }
    }

    public function createContent(string $title, int $mediaId, string $originalFilename): ?array
    {
        casielLog("Creando contenido en Sword para media ID: $mediaId");
        try {
            // Payload adaptado a la nueva API Sword v2
            $payload = [
                'type'   => 'sample',
                'status' => 'published',
                'content_data' => [
                    'title' => $title,
                    'media_id' => $mediaId,
                    'nombre_archivo_original' => $originalFilename
                ]
            ];

            // Endpoint cambiado a 'contents' (plural)
            $respuesta = $this->cliente->post("contents", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'json' => $payload
            ]);
            $datos = json_decode($respuesta->getBody()->getContents(), true);
            return $datos['data'] ?? null;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al crear el contenido en Sword.");
            return null;
        }
    }

    public function updateContent(int $id, array $data): bool
    {
        casielLog("Actualizando contenido ID: $id.");
        try {
            // La nueva API usa POST para actualizar
            $respuesta = $this->cliente->post("contents/{$id}", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'json' => $data
            ]);
            casielLog("Contenido ID: $id actualizado correctamente.");
            return $respuesta->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al actualizar el contenido ID: $id.");
            return false;
        }
    }

    public function updateContentData(int $id, array $contentData): bool
    {
        casielLog("Actualizando solo content_data para el ID: $id.");
        return $this->updateContent($id, ['content_data' => $contentData]);
    }

    public function getContentById(int $id): ?array
    {
        casielLog("Buscando contenido por ID: $id.");
        try {
            $respuesta = $this->cliente->get("contents/{$id}", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json']
            ]);
            $datos = json_decode($respuesta->getBody()->getContents(), true);
            return $datos['data'] ?? null;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al obtener el contenido ID: $id.");
            return null;
        }
    }

    public function findContentByHash(string $hash): ?array
    {
        casielLog("Buscando contenido por hash: $hash");
        try {
            // Asumimos que la nueva API permite filtrar por campos anidados en content_data
            $query = [
                'type' => 'sample',
                'per_page' => 1,
                'content_data' => ['audio_hash' => $hash] // Cambiado de 'metadata' a 'content_data'
            ];

            $respuesta = $this->cliente->get('contents', [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'query' => $query
            ]);

            $datos = json_decode($respuesta->getBody()->getContents(), true);
            // La nueva estructura de paginación es 'data.data'
            $item = $datos['data']['data'][0] ?? null;

            if ($item) {
                casielLog("Éxito: Se encontró contenido por hash. ID: " . $item['id']);
            }
            return $item;
        } catch (GuzzleException $e) {
            $this->logError($e, "Excepción de Guzzle al buscar contenido por hash.");
            return null;
        }
    }

    public function deleteContent(int $id): bool
    {
        casielLog("Eliminando contenido de prueba de Sword. ID: $id");
        try {
            $respuesta = $this->cliente->delete("contents/{$id}", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey]
            ]);
            // La API v2 puede devolver 200 OK con un mensaje en lugar de 204 No Content
            return in_array($respuesta->getStatusCode(), [200, 204]);
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al eliminar el contenido ID: $id.");
            return false;
        }
    }
}

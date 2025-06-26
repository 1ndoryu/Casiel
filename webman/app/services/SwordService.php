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
    private const MAX_REINTENTOS_IA = 3;

    private array $knownRootFields = [];

    public function __construct(string $apiUrl, string $apiKey)
    {
        if (empty($apiUrl)) {
            throw new InvalidArgumentException("La URL de la API de Sword no puede estar vacía. Revisa tu .env o la configuración del test.");
        }

        $clientConfig = [
            'base_uri' => rtrim($apiUrl, '/') . '/',
            'timeout' => 30.0,
        ];

        $shouldVerifySsl = config('casiel.sword_client.verify_ssl', true);
        if ($shouldVerifySsl) {
            $caCertPath = base_path() . '/config/certs/cacert.pem';
            if (file_exists($caCertPath)) {
                $clientConfig['verify'] = $caCertPath;
            } else {
                casielLog("Advertencia: El archivo cacert.pem no se encontró en '{$caCertPath}'. Guzzle usará el gestor de certificados por defecto del sistema.", [], 'warning');
            }
        } else {
            $clientConfig['verify'] = false;
            casielLog("ADVERTENCIA DE SEGURIDAD: La verificación SSL para la API de Sword está DESHABILITADA. No usar en producción.", [], 'warning');
        }

        $this->cliente = new Client($clientConfig);
        $this->apiKey = $apiKey;
        $this->knownRootFields = config('api.sword.root_fields', []);
    }

    /**
     * Descarga el contenido de un archivo de audio usando su media_id.
     * -- VERSIÓN DE DEPURACIÓN --
     *
     * @param integer $mediaId
     * @return string|null El contenido binario del archivo o null si falla.
     */
    public function descargarAudioPorMediaId(int $mediaId): ?string
    {
        casielLog("Iniciando descarga de audio para media ID: $mediaId. [MODO DEBUG ACTIVADO]");
        try {
            // --- PASO 1: Hacemos la petición inicial SIN seguir redirecciones ---
            $respuestaInicial = $this->cliente->get("media/$mediaId/download", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'allow_redirects' => false // ¡IMPORTANTE! Desactivado para depuración
            ]);

            $statusCode = $respuestaInicial->getStatusCode();
            $headers = $respuestaInicial->getHeaders();

            // Logueamos toda la información posible de esta primera respuesta
            casielLog("[DEBUG] Respuesta inicial de Sword API recibida.", [
                'media_id' => $mediaId,
                'status_code' => $statusCode,
                'headers' => $headers,
            ]);

            // --- PASO 2: Analizamos la respuesta ---

            // Si es una redirección (código 3xx), que es lo que esperamos...
            if ($statusCode >= 300 && $statusCode < 400) {
                $location = $respuestaInicial->getHeaderLine('Location');
                if (empty($location)) {
                    casielLog("[DEBUG] La API devolvió un código de redirección ($statusCode) pero sin cabecera 'Location'.", [], 'error');
                    return null;
                }
                
                casielLog("[DEBUG] Sword API devolvió una redirección. URL de destino: " . $location, [], 'info');
                
                // --- PASO 3: Intentamos seguir la redirección manualmente ---
                casielLog("[DEBUG] Intentando petición GET a la URL de redirección...", ['url' => $location], 'info');
                
                // Usamos un nuevo cliente para esta petición por si es un dominio diferente, sin cabeceras extra.
                $clienteExterno = new Client(['verify' => config('casiel.sword_client.verify_ssl', true) === false ? false : true]);
                $respuestaFinal = $clienteExterno->get($location);

                if ($respuestaFinal->getStatusCode() === 200) {
                    casielLog("[DEBUG] Descarga desde la URL de redirección finalizada con éxito.", ['url' => $location]);
                    return $respuestaFinal->getBody()->getContents();
                } else {
                    casielLog("[DEBUG] La URL de redirección devolvió un código de error inesperado.", [
                        'url' => $location,
                        'status_code' => $respuestaFinal->getStatusCode()
                    ], 'error');
                    return null;
                }
            }

            // Si la respuesta es 200 directamente (sin redirección)
            if ($statusCode === 200) {
                casielLog("[DEBUG] Audio para media ID: $mediaId descargado directamente con éxito (sin redirección).");
                return $respuestaInicial->getBody()->getContents();
            }

            // Si es cualquier otro código, lo registramos y fallamos
            casielLog("[DEBUG] La API de Sword devolvió un código de estado no esperado (ni 200, ni 3xx): " . $statusCode, [], 'error');
            return null;

        } catch (GuzzleException $e) {
            $this->logError($e, "[DEBUG] Error de Guzzle al intentar descargar audio para media ID: $mediaId.");
            return null;
        }
    }

    private function logError(GuzzleException $e, string $mensaje)
    {
        $contextoError = [];
        if ($e instanceof RequestException) {
             $request = $e->getRequest();
             $contextoError['request_method'] = $request->getMethod();
             $contextoError['request_uri'] = (string) $request->getUri();
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $contextoError['status_code'] = $response->getStatusCode();
                $contextoError['response_body'] = substr((string) $response->getBody(), 0, 1000);
            }
        }
        casielLog("$mensaje " . $e->getMessage(), $contextoError, 'error');
    }

    // El resto de los métodos de la clase no necesitan cambios, puedes dejarlos como estaban
    // o simplemente pegar este código en la parte superior del archivo y mantener el resto.
    // Para facilitar, aquí está el resto de la clase para que el archivo quede completo.

    public function obtenerSamplePendiente(): ?array
    {
        casielLog("Buscando un sample pendiente o fallido.");
        $samples = $this->buscarSamplesPorStatus(null, 1);
        if (!empty($samples)) {
            return $samples[0];
        }

        $samplesFallidos = $this->buscarSamplesPorStatus(['fallido', 'fallido_test', 'fallido_forzado'], 50);
        if (empty($samplesFallidos)) {
            return null;
        }

        foreach ($samplesFallidos as $sample) {
            $retryCount = $sample['metadata']['ia_retry_count'] ?? 0;
            if ($retryCount < self::MAX_REINTENTOS_IA) {
                casielLog("Reintentando sample fallido ID: " . $sample['id']);
                return $sample;
            }
        }

        return null;
    }

    private function buscarSamplesPorStatus($status, int $limite): ?array
    {
        try {
            $query = ['type' => 'sample', 'per_page' => $limite, 'sort_by' => 'created_at', 'order' => 'asc'];

            $respuesta = $this->cliente->get('content', [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'query' => $query
            ]);

            $items = (json_decode($respuesta->getBody()->getContents(), true))['data']['items'] ?? [];

            if ($status === null) {
                $filtrados = array_filter($items, fn($s) => !isset($s['metadata']['ia_status']));
            } else {
                $filtrados = array_filter($items, fn($s) => isset($s['metadata']['ia_status']) && in_array($s['metadata']['ia_status'], (array)$status));
            }

            return array_values($filtrados);
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al buscar samples por status.");
            return null;
        }
    }

    public function buscarSamplePorHash(string $hash): ?array
    {
        casielLog("Buscando sample por hash: $hash");
        try {
            $respuesta = $this->cliente->get('content', [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'query' => [
                    'type' => 'sample',
                    'per_page' => 1,
                    'metadata' => ['audio_hash' => $hash]
                ]
            ]);
            $datos = json_decode($respuesta->getBody()->getContents(), true);
            $sample = $datos['data']['items'][0] ?? null;

            if ($sample) {
                casielLog("Se encontró un duplicado por hash. ID: " . $sample['id']);
            }
            return $sample;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al buscar sample por hash.");
            return null;
        }
    }

    public function obtenerUltimoSample(): ?array
    {
        casielLog("Buscando el último sample subido (modo forzado).");
        try {
            $respuesta = $this->cliente->get('content', [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'query' => ['type' => 'sample', 'per_page' => 1, 'sort_by' => 'created_at', 'order' => 'desc']
            ]);
            $datos = json_decode($respuesta->getBody()->getContents(), true);
            $sample = $datos['data']['items'][0] ?? null;
            if ($sample) {
                casielLog("Último sample encontrado con ID: " . $sample['id']);
                return $sample;
            }
            casielLog("No se encontró ningún sample.");
            return null;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al obtener el último sample.");
            return null;
        }
    }

    public function actualizarMetadataSample(int $id, array $metadataNuevos): bool
    {
        casielLog("Actualizando solo metadata para el sample ID: $id.");
        return $this->actualizarSample($id, ['metadata' => $metadataNuevos]);
    }

    public function actualizarSample(int $id, array $payload): bool
    {
        $body = [];
        $metadata = $payload['metadata'] ?? [];

        foreach ($payload as $key => $value) {
            if (in_array($key, $this->knownRootFields)) {
                $body[$key] = $value;
            } elseif ($key !== 'metadata') {
                $metadata[$key] = $value;
            }
        }

        if (!empty($metadata)) {
            $body['metadata'] = $metadata;
        }

        casielLog("Actualizando sample ID: $id con payload distribuido.", ['body' => $body]);

        try {
            $this->cliente->put("content/$id", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'json' => $body
            ]);
            casielLog("Sample ID: $id actualizado correctamente.");
            return true;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al actualizar el sample ID: $id.");
            return false;
        }
    }

    public function obtenerSamplePorId(int $id): ?array
    {
        casielLog("Buscando sample por ID: $id.");
        try {
            $respuesta = $this->cliente->get("content/$id", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json']
            ]);
            $datos = json_decode($respuesta->getBody()->getContents(), true);
            return $datos['data'] ?? null;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al obtener el sample ID: $id.");
            return null;
        }
    }

    public function subirArchivoLocal(string $rutaArchivo): ?array
    {
        if (!file_exists($rutaArchivo)) {
            casielLog("El archivo de prueba no existe en: $rutaArchivo", [], 'error');
            return null;
        }

        casielLog("Subiendo archivo a Sword: " . basename($rutaArchivo));
        try {
            $respuesta = $this->cliente->post("media/upload", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'multipart' => [
                    [
                        'name'     => 'file',
                        'contents' => fopen($rutaArchivo, 'r'),
                        'filename' => basename($rutaArchivo)
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

    public function crearSample(string $titulo, int $mediaId, string $nombreOriginal): ?array
    {
        casielLog("Creando entrada de sample en Sword para media ID: $mediaId");
        try {
            $payload = [
                'titulo' => $titulo,
                'tipocontenido' => 'sample',
                'estado' => 'publicado',
                'metadata' => [
                    'media_id' => $mediaId,
                    'nombre_archivo_original' => $nombreOriginal
                ]
            ];

            $respuesta = $this->cliente->post("content", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'json' => $payload
            ]);
            $datos = json_decode($respuesta->getBody()->getContents(), true);
            return $datos['data'] ?? null;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al crear el sample en Sword.");
            return null;
        }
    }

    public function eliminarSample(int $id): bool
    {
        casielLog("Eliminando sample de prueba de Sword. ID: $id");
        try {
            $respuesta = $this->cliente->delete("content/$id", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey]
            ]);
            return $respuesta->getStatusCode() === 204;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al eliminar el sample ID: $id.");
            return false;
        }
    }
}
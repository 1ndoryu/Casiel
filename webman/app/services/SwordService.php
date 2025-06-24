<?php

namespace app\services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;

/**
 * Servicio para interactuar con la API de Sword (Kamples).
 */
class SwordService
{
    protected Client $cliente;
    protected string $apiKey;
    // Constante para el máximo de reintentos
    private const MAX_REINTENTOS_IA = 3;

    public function __construct(string $apiUrl, string $apiKey)
    {
        if (empty($apiUrl)) {
            throw new InvalidArgumentException("La URL de la API de Sword no puede estar vacía. Revisa tu .env.");
        }

        $this->cliente = new Client([
            'base_uri' => rtrim($apiUrl, '/') . '/',
            'timeout' => 30.0, // Aumentado por si la subida tarda
            'verify' => base_path() . '/config/certs/cacert.pem',
        ]);
        $this->apiKey = $apiKey;
    }

    /**
     * Obtiene samples pendientes (sin ia_status), que han fallado o no han excedido el límite de reintentos.
     */
    public function obtenerSamplesPendientes(int $limite = 1): ?array
    {
        casielLog("Buscando samples pendientes o fallidos en Sword API.");
        try {
            $respuesta = $this->cliente->get('content', [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'query' => ['type' => 'sample', 'per_page' => 50, 'sort_by' => 'created_at', 'order' => 'desc']
            ]);

            $itemsRecibidos = (json_decode($respuesta->getBody()->getContents(), true))['data']['items'] ?? [];

            if (empty($itemsRecibidos)) {
                casielLog("La API no devolvió ningún sample.");
                return null;
            }

            $samplesFiltrados = [];
            foreach ($itemsRecibidos as $sample) {
                $metadata = $sample['metadata'] ?? [];
                $status = $metadata['ia_status'] ?? null;
                $retryCount = $metadata['ia_retry_count'] ?? 0;

                // Es pendiente si: no tiene status, O el status es de fallo Y no ha superado los reintentos.
                if ($status === null || (in_array($status, ['fallido', 'fallido_test']) && $retryCount < self::MAX_REINTENTOS_IA)) {
                    $samplesFiltrados[] = $sample;
                    if (count($samplesFiltrados) >= $limite) break;
                }
            }

            casielLog("Se encontraron " . count($samplesFiltrados) . " samples para procesar.");
            return !empty($samplesFiltrados) ? $samplesFiltrados : null;
        } catch (GuzzleException $e) {
            $contextoError = [];
            if ($e instanceof RequestException && $e->hasResponse()) {
                $contextoError['response_body'] = (string) $e->getResponse()->getBody();
            }
            casielLog("Error al conectar con Sword API: " . $e->getMessage(), $contextoError, 'error');
            return null;
        }
    }

    /**
     * Obtiene el último sample subido, sin importar su estado.
     */
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
                return [$sample]; // Devolvemos como array para mantener consistencia
            }
            casielLog("No se encontró ningún sample.");
            return null;
        } catch (GuzzleException $e) {
            casielLog("Error al obtener el último sample: " . $e->getMessage(), [], 'error');
            return null;
        }
    }

    /**
     * Actualiza la metadata de un sample específico.
     */
    public function actualizarMetadataSample(int $id, array $metadata): bool
    {
        casielLog("Actualizando metadata para el sample ID: $id.");
        try {
            $this->cliente->put("content/$id", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'json' => ['metadata' => $metadata]
            ]);
            casielLog("Metadata del sample ID: $id actualizada correctamente.");
            return true;
        } catch (GuzzleException $e) {
            $contextoError = [];
            if ($e instanceof RequestException && $e->hasResponse()) {
                $contextoError['response_body'] = (string) $e->getResponse()->getBody();
            }
            casielLog("Error al actualizar el sample ID: $id. " . $e->getMessage(), $contextoError, 'error');
            return false;
        }
    }

    /**
     * Sube un archivo al endpoint /media de Sword.
     * @param string $rutaArchivo Path local del archivo a subir.
     * @param string $nombreArchivo Nombre que tendrá el archivo en el servidor.
     * @return array|null Los datos del archivo subido o null en caso de error.
     */
    public function subirArchivo(string $rutaArchivo, string $nombreArchivo): ?array
    {
        casielLog("Subiendo archivo a Sword: $nombreArchivo");
        try {
            $respuesta = $this->cliente->post('media', [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'multipart' => [
                    [
                        'name'     => 'file',
                        'contents' => fopen($rutaArchivo, 'r'),
                        'filename' => $nombreArchivo
                    ]
                ]
            ]);
            $cuerpoRespuesta = json_decode($respuesta->getBody()->getContents(), true);
            casielLog("Archivo subido con éxito a Sword.", $cuerpoRespuesta);
            return $cuerpoRespuesta['data'] ?? null;
        } catch (GuzzleException $e) {
            casielLog("Error al subir el archivo a Sword: " . $e->getMessage(), [], 'error');
            return null;
        }
    }
}
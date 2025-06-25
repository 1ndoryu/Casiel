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

    // Lista de campos que se sabe que están en el nivel raíz del contenido en la API
    private array $knownRootFields = [
        'titulo',
        'subtitulo',
        'slug',
        'estado',
        'tipocontenido',
        'descripcion',
        'descripcion_corta',
        'post_tags'
    ];

    public function __construct(string $apiUrl, string $apiKey)
    {
        if (empty($apiUrl)) {
            throw new InvalidArgumentException("La URL de la API de Sword no puede estar vacía. Revisa tu .env.");
        }

        $this->cliente = new Client([
            'base_uri' => rtrim($apiUrl, '/') . '/',
            'timeout' => 30.0,
            'verify' => base_path() . '/config/certs/cacert.pem',
        ]);
        $this->apiKey = $apiKey;
    }

    public function obtenerSamplePendiente(): ?array
    {
        casielLog("Buscando un sample pendiente o fallido.");
        // Primero, busca los que nunca se han procesado
        $samples = $this->buscarSamplesPorStatus(null, 1);
        if (!empty($samples)) {
            return $samples[0];
        }

        // Si no hay, busca los que fallaron y aún tienen reintentos
        $samplesFallidos = $this->buscarSamplesPorStatus(['fallido', 'fallido_test', 'fallido_forzado'], 50); // Trae más para filtrar
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
            if ($status !== null) {
                // La API de SwordPHP no soporta buscar por metadata[ia_status], así que filtramos después.
            }

            $respuesta = $this->cliente->get('content', [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'query' => $query
            ]);

            $items = (json_decode($respuesta->getBody()->getContents(), true))['data']['items'] ?? [];

            if ($status === null) { // Buscando los que NO tienen status
                $filtrados = array_filter($items, fn($s) => !isset($s['metadata']['ia_status']));
            } else { // Buscando los que SÍ tienen un status de fallo
                $filtrados = array_filter($items, fn($s) => isset($s['metadata']['ia_status']) && in_array($s['metadata']['ia_status'], (array)$status));
            }

            return array_values($filtrados);
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al buscar samples por status.");
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

    /**
     * Actualiza únicamente el campo metadata de un sample.
     * Útil para operaciones simples como marcar estado.
     */
    public function actualizarMetadataSample(int $id, array $metadataNuevos): bool
    {
        casielLog("Actualizando solo metadata para el sample ID: $id.");
        return $this->actualizarSample($id, ['metadata' => $metadataNuevos]);
    }

    /**
     * Actualiza un sample con un payload que puede contener campos raíz y metadata.
     * La función distribuye los campos automáticamente.
     */
    public function actualizarSample(int $id, array $payload): bool
    {
        $body = [];
        $metadata = $payload['metadata'] ?? [];

        // Distribuir campos del payload raíz
        foreach ($payload as $key => $value) {
            if (in_array($key, $this->knownRootFields)) {
                $body[$key] = $value;
            } elseif ($key !== 'metadata') { // Si no es campo raíz ni la key 'metadata', va para adentro
                $metadata[$key] = $value;
            }
        }

        // Asignar el objeto de metadata final al body si no está vacío
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

    private function logError(GuzzleException $e, string $mensaje)
    {
        $contextoError = [];
        if ($e instanceof RequestException && $e->hasResponse()) {
            $contextoError['status_code'] = $e->getResponse()->getStatusCode();
            $contextoError['response_body'] = (string) $e->getResponse()->getBody();
        }
        casielLog("$mensaje " . $e->getMessage(), $contextoError, 'error');
    }

    public function obtenerSamplePorId(int $id): ?array
    {
        casielLog("Buscando sample por ID: $id.");
        try {
            $respuesta = $this->cliente->get("content/$id", [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json']
            ]);
            $datos = json_decode($respuesta->getBody()->getContents(), true);
            // La API devuelve el recurso dentro de una clave "data"
            return $datos['data'] ?? null;
        } catch (GuzzleException $e) {
            $this->logError($e, "Error al obtener el sample ID: $id.");
            return null;
        }
    }
}

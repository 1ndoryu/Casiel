<?php

namespace app\services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;

/**
 * Servicio para interactuar con la API de Sword (Kamples).
 */
class SwordService
{
    protected Client $cliente;
    protected string $apiKey;

    public function __construct(string $apiUrl, string $apiKey)
    {
        if (empty($apiUrl)) {
            throw new InvalidArgumentException("La URL de la API de Sword no puede estar vacía. Revisa tu .env y config/api.php.");
        }

        $this->cliente = new Client([
            'base_uri' => $apiUrl,
            'timeout'  => 10.0,
        ]);
        $this->apiKey = $apiKey;
    }

    /**
     * Obtiene los samples pendientes de procesar por la IA.
     * Se asumirá que los samples pendientes tienen `metadata->ia_status = 'pendiente'`.
     */
    public function obtenerSamplesPendientes(int $limite = 5): ?array
    {
        casielLog("Buscando samples pendientes en Sword API.");
        try {
            $respuesta = $this->cliente->get('content', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept'        => 'application/json',
                ],
                'query' => [
                    'type' => 'sample',
                    'metadata[ia_status]' => 'pendiente',
                    'per_page' => $limite,
                    'sort_by' => 'created_at',
                    'order' => 'asc'
                ]
            ]);

            $datos = json_decode($respuesta->getBody()->getContents(), true);

            if (!empty($datos['data']['items'])) {
                casielLog("Se encontraron " . count($datos['data']['items']) . " samples pendientes.");
                return $datos['data']['items'];
            }

            casielLog("No se encontraron samples pendientes.");
            return null;
        } catch (GuzzleException $e) {
            casielLog("Error al conectar con Sword API: " . $e->getMessage(), [], 'error');
            return null;
        }
    }

    /**
     * Actualiza la metadata de un sample específico.
     * @param int $id El ID del contenido (sample).
     * @param array $metadata Los nuevos datos para el campo metadata.
     */
    public function actualizarMetadataSample(int $id, array $metadata): bool
    {
        casielLog("Actualizando metadata para el sample ID: $id.");
        try {
            $this->cliente->put("content/$id", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept'        => 'application/json',
                ],
                'json' => [
                    'metadata' => $metadata
                ]
            ]);
            casielLog("Metadata del sample ID: $id actualizada correctamente.");
            return true;
        } catch (GuzzleException $e) {
            casielLog("Error al actualizar el sample ID: $id. " . $e->getMessage(), [], 'error');
            return false;
        }
    }
}
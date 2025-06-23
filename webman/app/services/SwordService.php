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
            throw new InvalidArgumentException("La URL de la API de Sword no puede estar vacía. Revisa tu .env.");
        }

        $this->cliente = new Client([
            // MODIFICACIÓN: Aseguramos que la URL base siempre termine con un slash.
            'base_uri' => rtrim($apiUrl, '/') . '/',
            'timeout' => 10.0,
            'verify' => false, // Para desarrollo local
        ]);
        $this->apiKey = $apiKey;
    }

    /**
     * Obtiene los samples que aún no han sido procesados por la IA.
     * Busca los últimos 50 samples y filtra los que no tienen 'ia_status' en su metadata.
     */
    public function obtenerSamplesPendientes(int $limite = 5): ?array
    {
        casielLog("Buscando samples sin procesar en Sword API.");
        try {
            // 1. Pedimos los últimos samples, ya que la API no permite buscar por clave de metadata inexistente.
            $respuesta = $this->cliente->get('content', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept'    => 'application/json',
                ],
                'query' => [
                    'type'   => 'sample',
                    'per_page' => 50, // Pedimos un lote más grande para tener de dónde filtrar.
                    'sort_by' => 'created_at',
                    'order'  => 'asc'
                ]
            ]);

            $datos = json_decode($respuesta->getBody()->getContents(), true);

            if (empty($datos['data']['items'])) {
                return null;
            }

            // 2. Filtramos los resultados en PHP.
            $samplesPendientes = [];
            foreach ($datos['data']['items'] as $sample) {
                // Un sample está pendiente si NO TIENE la clave 'ia_status' en su metadata.
                if (!isset($sample['metadata']['ia_status'])) {
                    $samplesPendientes[] = $sample;
                    if (count($samplesPendientes) >= $limite) {
                        break; // Salimos si ya alcanzamos el límite deseado.
                    }
                }
            }

            if (!empty($samplesPendientes)) {
                casielLog("Se encontraron " . count($samplesPendientes) . " samples para procesar.");
                return $samplesPendientes;
            }

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
                    'Accept'    => 'application/json',
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

<?php

namespace app\services;

use Throwable;
use Workerman\Http\Client;

/**
 * Service to interact with the Sword CMS API asynchronously.
 */
class SwordApiService
{
    private string $apiUrl;
    private string $apiUser;
    private string $apiPassword;
    private ?string $token = null;
    private Client $httpClient;

    /**
     * SwordApiService constructor.
     * @param Client $httpClient The HTTP client to be used for requests.
     */
    public function __construct(Client $httpClient)
    {
        $this->apiUrl = getenv('SWORD_API_URL');
        $this->apiUser = getenv('SWORD_API_USER');
        $this->apiPassword = getenv('SWORD_API_PASSWORD');
        $this->httpClient = $httpClient;
    }

    /**
     * Authenticates with the Sword API and executes a callback on success.
     *
     * @param callable $onSuccess The callback to execute after successful authentication.
     * @param callable $onError The callback to execute on failure.
     */
    private function authenticate(callable $onSuccess, callable $onError): void
    {
        if ($this->token) {
            $onSuccess();
            return;
        }

        casiel_log('sword_api', 'Autenticando con la API de Sword.');
        $this->httpClient->post("{$this->apiUrl}/auth/login", [
            'json' => [
                'identifier' => $this->apiUser,
                'password' => $this->apiPassword,
            ]
        ], function ($response) use ($onSuccess, $onError) {
            if ($response->getStatusCode() !== 200) {
                $onError("Fallo de autenticación en Sword. Status: " . $response->getStatusCode());
                return;
            }
            $body = json_decode((string)$response->getBody(), true);
            if (isset($body['data']['access_token'])) {
                $this->token = $body['data']['access_token'];
                casiel_log('sword_api', 'Autenticación exitosa.');
                $onSuccess();
            } else {
                $onError("La respuesta de autenticación no contiene un token.");
            }
        }, function ($exception) use ($onError) {
            $onError("Excepción durante la autenticación en Sword: " . $exception->getMessage());
        });
    }

    /**
     * Executes a request after ensuring authentication.
     *
     * @param string $method The HTTP method.
     * @param string $endpoint The API endpoint.
     * @param array $options The request options.
     * @param callable $onSuccess Success callback.
     * @param callable $onError Error callback.
     */
    private function executeRequest(string $method, string $endpoint, array $options, callable $onSuccess, callable $onError): void
    {
        $this->authenticate(
            function () use ($method, $endpoint, $options, $onSuccess, $onError) {
                $url = "{$this->apiUrl}/{$endpoint}";
                $defaultHeaders = [
                    'Authorization' => "Bearer {$this->token}",
                    'Accept' => 'application/json',
                ];
                $options['headers'] = array_merge($defaultHeaders, $options['headers'] ?? []);

                // The http client methods are dynamic (post, get, etc.)
                $this->httpClient->{$method}($url, $options,
                    function ($response) use ($onSuccess, $onError, $endpoint) {
                        $statusCode = $response->getStatusCode();
                        if ($statusCode >= 200 && $statusCode < 300) {
                            $responseData = json_decode((string)$response->getBody(), true);
                            $onSuccess($responseData['data'] ?? $responseData);
                        } else {
                             $errorBody = (string)$response->getBody();
                             casiel_log('sword_api', "Error en la petición a {$endpoint}", ['status' => $statusCode, 'body' => $errorBody], 'error');
                             $onError("Error en la API de Sword ({$endpoint}): Status {$statusCode}");
                        }
                    },
                    function ($exception) use ($onError, $endpoint) {
                        casiel_log('sword_api', "Excepción en la petición a {$endpoint}", ['error' => $exception->getMessage()], 'error');
                        $onError("Excepción en la petición a Sword ({$endpoint}): " . $exception->getMessage());
                    }
                );
            },
            $onError
        );
    }

    /**
     * Gets the full details of a media item from Sword.
     *
     * @param int $mediaId
     * @param callable $onSuccess
     * @param callable $onError
     */
    public function getMediaDetails(int $mediaId, callable $onSuccess, callable $onError): void
    {
        casiel_log('sword_api', "Obteniendo detalles del medio: {$mediaId}");
        $this->executeRequest('get', "media/{$mediaId}", [], $onSuccess, $onError);
    }

    /**
     * Updates content in Sword CMS.
     *
     * @param int $contentId
     * @param array $data
     * @param callable $onSuccess
     * @param callable $onError
     */
    public function updateContent(int $contentId, array $data, callable $onSuccess, callable $onError): void
    {
        casiel_log('sword_api', "Actualizando contenido: {$contentId}");
        $options = [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => $data,
        ];
        $this->executeRequest('post', "contents/{$contentId}", $options, $onSuccess, $onError);
    }

    /**
     * Uploads a media file to Sword.
     *
     * @param string $filePath The absolute path of the file to upload.
     * @param callable $onSuccess
     * @param callable $onError
     */
    public function uploadMedia(string $filePath, callable $onSuccess, callable $onError): void
    {
        casiel_log('sword_api', "Subiendo nuevo archivo: " . basename($filePath));
        if (!file_exists($filePath)) {
            $onError("El archivo a subir no existe: {$filePath}");
            return;
        }

        $options = [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => file_get_contents($filePath),
                    'filename' => basename($filePath),
                ],
            ],
        ];
        $this->executeRequest('post', 'media', $options, $onSuccess, $onError);
    }
}
<?php

namespace app\services;

use app\services\concerns\MakesAsyncHttpRequests;
use Symfony\Component\Process\Process;
use Throwable;
use Workerman\Http\Client;
use Workerman\Timer;

class SwordApiService
{
    use MakesAsyncHttpRequests;

    private string $apiUrl;
    private string $apiUser;
    private string $apiPassword;
    private ?string $token = null;

    public function __construct(Client $httpClient)
    {
        $this->apiUrl = getenv('SWORD_API_URL');
        $this->apiUser = getenv('SWORD_API_USER');
        $this->apiPassword = getenv('SWORD_API_PASSWORD');
        $this->httpClient = $httpClient;
    }

    private function authenticate(callable $onSuccess, callable $onError): void
    {
        if ($this->token) {
            $onSuccess();
            return;
        }

        casiel_log('sword_api', 'Iniciando autenticación.');
        $loginUrl = rtrim($this->apiUrl, '/') . '/auth/login';
        $payload = ['identifier' => $this->apiUser, 'password' => $this->apiPassword];
        $options = ['json' => $payload, 'headers' => ['Accept' => 'application/json']];

        $this->executeRequest('POST', $loginUrl, $options, function ($data) use ($onSuccess, $onError) {
            if (isset($data['data']['access_token'])) {
                $this->token = $data['data']['access_token'];
                casiel_log('sword_api', 'Autenticación exitosa.');
                $onSuccess();
            } else {
                $errorMessage = "La respuesta de autenticación de Sword no contiene un token.";
                casiel_log('sword_api', $errorMessage, ['response' => $data], 'error');
                $onError($errorMessage);
            }
        }, $onError);
    }

    private function authenticatedRequest(string $method, string $endpoint, array $options, callable $onSuccess, callable $onError): void
    {
        $this->authenticate(
            function () use ($method, $endpoint, $options, $onSuccess, $onError) {
                $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');
                $defaultHeaders = ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'];
                $options['headers'] = array_merge($defaultHeaders, $options['headers'] ?? []);
                $this->executeRequest($method, $url, $options, $onSuccess, $onError);
            },
            $onError
        );
    }

    public function getContent(int $contentId, callable $onSuccess, callable $onError): void
    {
        casiel_log('sword_api', "Obteniendo detalles del contenido: {$contentId}");
        // NOTA: Este endpoint debe existir en Sword y permitir acceso de administrador
        // para obtener cualquier contenido por ID, sin importar su estado.
        $this->authenticatedRequest('get', "admin/contents/{$contentId}", [], fn($res) => $onSuccess($res['data'] ?? $res), $onError);
    }
    
    /**
     * Finds content by its perceptual audio hash.
     * The Sword API is expected to return a 200 OK with `data: null` if not found.
     *
     * @param string $hash The audio hash to search for.
     * @param callable $onSuccess Callback that receives the content data or null.
     * @param callable $onError Error callback.
     */
    public function findContentByHash(string $hash, callable $onSuccess, callable $onError): void
    {
        casiel_log('sword_api', "Buscando contenido por hash: {$hash}");
        $this->authenticatedRequest(
            'get',
            "admin/contents/by-hash/{$hash}",
            [],
            fn($response) => $onSuccess($response['data'] ?? null),
            $onError
        );
    }

    public function getMediaDetails(int $mediaId, callable $onSuccess, callable $onError): void
    {
        casiel_log('sword_api', "Obteniendo detalles del medio: {$mediaId}");
        $this->authenticatedRequest('get', "media/{$mediaId}", [], fn($res) => $onSuccess($res['data'] ?? $res), $onError);
    }

    public function updateContent(int $contentId, array $data, callable $onSuccess, callable $onError): void
    {
        casiel_log('sword_api', "Actualizando contenido: {$contentId}");
        $this->authenticatedRequest('post', "contents/{$contentId}", ['json' => $data], fn($res) => $onSuccess($res['data'] ?? $res), $onError);
    }

    public function uploadMedia(string $filePath, callable $onSuccess, callable $onError): void
    {
        casiel_log('sword_api', "Subiendo nuevo archivo: " . basename($filePath));
        if (!file_exists($filePath)) {
            $onError("El archivo a subir no existe: {$filePath}");
            return;
        }

        // Para cURL, pasamos la ruta. Para HttpClient, el contenido. El trait no maneja esto.
        // Haremos una excepción para la subida de archivos para mantener la compatibilidad.
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // El trait de cURL espera una estructura específica para 'multipart'.
            $options = ['multipart' => [['name' => 'file', 'contents' => $filePath, 'filename' => basename($filePath)]]];
        } else {
             $options = ['multipart' => [['name' => 'file', 'contents' => file_get_contents($filePath), 'filename' => basename($filePath)]]];
        }

        $this->authenticatedRequest('post', 'media', $options, fn($res) => $onSuccess($res['data'] ?? $res), $onError);
    }

    public function downloadFile(string $fileUrl, string $destinationPath, callable $onSuccess, callable $onError): void
    {
        casiel_log('sword_api', "Iniciando descarga de archivo: {$fileUrl}");
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->downloadFileWithCurl($fileUrl, $destinationPath, $onSuccess, $onError);
        } else {
            $this->downloadFileWithHttpClient($fileUrl, $destinationPath, $onSuccess, $onError);
        }
    }

    private function downloadFileWithHttpClient(string $fileUrl, string $destinationPath, callable $onSuccess, callable $onError): void
    {
        casiel_log('sword_api', 'Usando HttpClient para la descarga.');
        $this->httpClient->get($fileUrl, [
            'sink' => $destinationPath, 'timeout' => 300,
        ], function ($response) use ($onSuccess, $onError, $destinationPath) {
            if (!$response) {
                $onError("Fallo de conexión en la descarga (respuesta nula).");
                return;
            }
            if ($response->getStatusCode() === 200) {
                casiel_log('sword_api', 'Archivo descargado exitosamente en: ' . $destinationPath);
                $onSuccess($destinationPath);
            } else {
                $onError("Error al descargar archivo: Status " . $response->getStatusCode());
            }
        }, $onError);
    }

    private function downloadFileWithCurl(string $fileUrl, string $destinationPath, callable $onSuccess, callable $onError): void
    {
        casiel_log('sword_api', 'Usando cURL para la descarga en Windows.');
        $command = ['curl', '-f', '-s', '-L', '-o', $destinationPath, $fileUrl];
        casiel_log('sword_api', '[CURL-DOWNLOAD] Ejecutando comando', ['command' => implode(' ', $command)]);

        try {
            $process = new Process($command);
            $process->setTimeout(300);
            $process->start();

            $timerId = Timer::add(0.1, function () use ($process, &$timerId, $onSuccess, $onError, $destinationPath) {
                if (!$process->isRunning()) {
                    Timer::del($timerId);
                    if ($process->isSuccessful()) {
                        if (file_exists($destinationPath) && filesize($destinationPath) > 0) {
                            casiel_log('sword_api', 'Archivo descargado con cURL exitosamente en: ' . $destinationPath);
                            $onSuccess($destinationPath);
                        } else {
                            $errorMessage = 'cURL finalizó sin error, pero el archivo no se creó o está vacío.';
                            if ($process->getErrorOutput()) {
                                $errorMessage .= ' cURL stderr: ' . $process->getErrorOutput();
                            }
                            $onError($errorMessage);
                        }
                    } else {
                        $onError("Proceso cURL de descarga falló: " . $process->getErrorOutput());
                    }
                }
            });
        } catch (Throwable $e) {
            $onError("Excepción al iniciar proceso cURL de descarga: " . $e->getMessage());
        }
    }
}
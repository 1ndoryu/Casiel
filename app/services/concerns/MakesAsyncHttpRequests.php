<?php

namespace app\services\concerns;

use Symfony\Component\Process\Process;
use Throwable;
use Workerman\Http\Client;
use Workerman\Timer;

/**
 * Trait MakesAsyncHttpRequests
 * Provides a platform-aware asynchronous HTTP request mechanism.
 * It uses the more reliable cURL-based process on Windows and the standard
 * Workerman HttpClient on other systems.
 */
trait MakesAsyncHttpRequests
{
    /**
     * The class using this trait must define this property.
     * @var Client
     */
    private Client $httpClient;

    /**
     * Executes an asynchronous HTTP request, automatically choosing the best method for the OS.
     *
     * @param string $method The HTTP method (GET, POST, etc.).
     * @param string $url The target URL.
     * @param array $options Request options (headers, json, multipart, timeout).
     * @param callable $onSuccess Success callback `function(array $responseData)`.
     * @param callable $onError Error callback `function(string $errorMessage)`.
     */
    private function executeRequest(string $method, string $url, array $options, callable $onSuccess, callable $onError): void
    {
        casiel_log('async_http', "Iniciando petición a {$url}. Detectando SO.");
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            casiel_log('async_http', 'Usando método cURL para Windows.');
            $this->executeRequestWithCurl($method, $url, $options, $onSuccess, $onError);
        } else {
            casiel_log('async_http', 'Usando método Workerman HttpClient (Linux/Mac).');
            $this->executeRequestWithHttpClient(strtoupper($method), $url, $options, $onSuccess, $onError);
        }
    }

    /**
     * Executes the request using Workerman's native HttpClient.
     */
    private function executeRequestWithHttpClient(string $method, string $url, array $options, callable $onSuccess, callable $onError): void
    {
        $requestOptions = [
            'timeout' => $options['timeout'] ?? 90.0, // Increased timeout
            'headers' => $options['headers'] ?? []
        ];

        $body = $options['json'] ?? ($options['body'] ?? ($options['multipart'] ?? null));

        if (isset($options['json'])) {
            $body = json_encode($options['json']);
            $requestOptions['headers']['Content-Type'] = 'application/json';
        }

        $this->httpClient->{strtolower($method)}(
            $url,
            $body,
            $requestOptions,
            function ($response) use ($onSuccess, $onError, $url) {
                if (!$response) {
                    $onError("Fallo de conexión a {$url} (respuesta nula).");
                    return;
                }
                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $onSuccess(json_decode((string)$response->getBody(), true));
                } else {
                    $errorBody = (string)$response->getBody();
                    casiel_log('async_http', 'Error en la petición HttpClient', [
                        'status' => $response->getStatusCode(),
                        'body' => $errorBody,
                        'url' => $url
                    ], 'error');
                    $onError("Error en API (HttpClient): Status " . $response->getStatusCode());
                }
            },
            $onError
        );
    }

    /**
     * Executes the request using an asynchronous cURL command-line process.
     */
    private function executeRequestWithCurl(string $method, string $url, array $options, callable $onSuccess, callable $onError): void
    {
        $command = ['curl', '-s', '-L', '-w', '%{http_code}', '-X', strtoupper($method)];
        $tempInputFile = null;
        $tempOutputFile = tempnam(sys_get_temp_dir(), 'casiel_curl_out');

        array_push($command, '--output', $tempOutputFile);

        // ==========================================================
        // INICIO DE LA CORRECCIÓN
        // ==========================================================
        if (isset($options['json'])) {
            // Automatically set Content-Type header for JSON requests if it isn't already set.
            // This ensures Windows cURL requests behave the same as Linux HttpClient requests.
            if (!isset($options['headers']['Content-Type'])) {
                $options['headers']['Content-Type'] = 'application/json';
            }
        }
        // ==========================================================
        // FIN DE LA CORRECCIÓN
        // ==========================================================

        if (!empty($options['headers'])) {
            foreach ($options['headers'] as $key => $value) {
                $command[] = '-H';
                $command[] = "$key: $value";
            }
        }

        if (isset($options['multipart'])) {
            $part = $options['multipart'][0];
            $command[] = '-F';
            $command[] = "{$part['name']}=@{$part['contents']};filename={$part['filename']}";
        } elseif (!empty($options['json']) || !empty($options['body'])) {
            // SOLUCIÓN: Escribir el cuerpo a un archivo temporal para evitar los límites de longitud de la línea de comandos.
            $body = !empty($options['json']) ? json_encode($options['json']) : $options['body'];
            $tempInputFile = tempnam(sys_get_temp_dir(), 'casiel_curl_in');
            file_put_contents($tempInputFile, $body);
            $command[] = '-d';
            $command[] = '@' . $tempInputFile; // cURL lee el cuerpo desde el archivo.
        }

        $command[] = $url;
        $tempPaths = array_filter([$tempOutputFile, $tempInputFile]);
        $commandString = implode(' ', str_replace($tempPaths, '...temp...', $command));
        $showString = substr($commandString, 0, 80) . (strlen($commandString) > 100 ? '...' . substr($commandString, -20) : '');
        casiel_log('async_http', '[CURL] Ejecutando comando', ['command' => $showString]);

        try {
            $process = new Process($command);
            $process->setTimeout($options['timeout'] ?? 90.0); // Aumentado para Gemini
            $process->start();

            $timerId = Timer::add(0.1, function () use ($process, &$timerId, $onSuccess, $onError, $tempInputFile, $tempOutputFile) {
                if (!$process->isRunning()) {
                    Timer::del($timerId);

                    try {
                        if ($process->isSuccessful()) {
                            $output = $process->getOutput();

                            if (strlen(trim($output)) < 3) {
                                throw new \RuntimeException('Respuesta de cURL inválida o vacía. Output: ' . $output . ' | Stderr: ' . $process->getErrorOutput());
                            }

                            $statusCode = (int)substr($output, -3);
                            $responseBody = file_get_contents($tempOutputFile);

                            if ($responseBody === false) {
                                throw new \RuntimeException('No se pudo leer el archivo de salida temporal de cURL.');
                            }

                            if ($statusCode >= 200 && $statusCode < 300) {
                                $responseData = json_decode($responseBody, true);
                                if (json_last_error() !== JSON_ERROR_NONE) {
                                    $onError("Fallo al decodificar JSON de cURL: " . json_last_error_msg() . ". Body: " . substr($responseBody, 0, 150));
                                } else {
                                    $onSuccess($responseData);
                                }
                            } else {
                                $onError("Proceso cURL finalizó con código de estado HTTP {$statusCode}: " . $responseBody);
                            }
                        } else {
                            $onError("Proceso cURL falló: " . $process->getErrorOutput());
                        }
                    } catch (Throwable $e) {
                        casiel_log('async_http', 'Excepción fatal atrapada en el callback del timer de cURL.', ['error' => $e->getMessage()], 'error');
                        $onError("Excepción en el callback de cURL: " . $e->getMessage());
                    } finally {
                        if ($tempInputFile && file_exists($tempInputFile)) @unlink($tempInputFile);
                        if (file_exists($tempOutputFile)) @unlink($tempOutputFile);
                    }
                }
            });
        } catch (Throwable $e) {
            if ($tempInputFile && file_exists($tempInputFile)) @unlink($tempInputFile);
            if (file_exists($tempOutputFile)) @unlink($tempOutputFile);
            $onError("Excepción al iniciar proceso cURL: " . $e->getMessage());
        }
    }
}

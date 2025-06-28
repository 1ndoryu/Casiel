<?php

namespace app\services;

use Symfony\Component\Process\Process;
use Throwable;
use Workerman\Http\Client;
use Workerman\Timer;

/**
* Service to interact with the Sword CMS API asynchronously.
* Dispatches requests to cURL on Windows and HttpClient on Linux.
*/
class SwordApiService
{
  private string $apiUrl;
  private string $apiUser;
  private string $apiPassword;
  private ?string $token = null;
  private Client $httpClient;

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
    casiel_log('sword_api', 'Iniciando autenticación. Detectando sistema operativo.');
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
      $this->authenticateWithCurl($onSuccess, $onError);
    } else {
      $this->authenticateWithHttpClient($onSuccess, $onError);
    }
  }

  private function authenticateWithCurl(callable $onSuccess, callable $onError): void
  {
    casiel_log('sword_api', 'Usando método de autenticación cURL para Windows (asíncrono).');
    $loginUrl = rtrim($this->apiUrl, '/') . '/auth/login';
    $payload = ['identifier' => $this->apiUser, 'password' => $this->apiPassword];
    $options = ['json' => $payload, 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json']];
    $this->executeRequestWithCurl('POST', $loginUrl, $options, function($data) use ($onSuccess, $onError) {
      if (isset($data['data']['access_token'])) {
        $this->token = $data['data']['access_token'];
        casiel_log('sword_api', 'Autenticación asíncrona con cURL exitosa.');
        $onSuccess();
      } else {
        $onError("La respuesta de autenticación de cURL no contiene un token.");
      }
    }, $onError);
  }

  private function authenticateWithHttpClient(callable $onSuccess, callable $onError): void
  {
    casiel_log('sword_api', 'Usando método de autenticación HttpClient (Linux/Mac).');
    $loginUrl = rtrim($this->apiUrl, '/') . '/auth/login';
    $payload = ['identifier' => $this->apiUser, 'password' => $this->apiPassword];
    $options = ['json' => $payload, 'timeout' => 30.0, 'headers' => ['Accept' => 'application/json']];
    $this->httpClient->post($loginUrl, $options, function ($response) use ($onSuccess, $onError) {
      $body = json_decode((string)$response->getBody(), true);
      if (isset($body['data']['access_token'])) {
        $this->token = $body['data']['access_token'];
        casiel_log('sword_api', 'Autenticación exitosa.');
        $onSuccess();
      } else {
        $onError("La respuesta de autenticación no contiene un token.");
      }
    }, $onError);
  }

  private function executeRequest(string $method, string $endpoint, array $options, callable $onSuccess, callable $onError): void
  {
    $this->authenticate(
      function () use ($method, $endpoint, $options, $onSuccess, $onError) {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');
        $defaultHeaders = ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'];
        $options['headers'] = array_merge($defaultHeaders, $options['headers'] ?? []);

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
          $this->executeRequestWithCurl($method, $url, $options, $onSuccess, $onError);
        } else {
          $this->executeRequestWithHttpClient($method, $url, $options, $onSuccess, $onError);
        }
      },
      $onError
    );
  }

  private function executeRequestWithHttpClient(string $method, string $url, array $options, callable $onSuccess, callable $onError): void
  {
    $this->httpClient->{$method}($url, $options, function ($response) use ($onSuccess, $onError) {
      if (!$response) {
        $onError("Fallo de conexión (respuesta nula)."); return;
      }
      if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
        $onSuccess(json_decode((string)$response->getBody(), true));
      } else {
        $onError("Error en API: Status " . $response->getStatusCode());
      }
    }, $onError);
  }

  private function executeRequestWithCurl(string $method, string $url, array $options, callable $onSuccess, callable $onError): void
  {
    $command = ['curl', '-s', '-L', '-X', strtoupper($method)];
    $tempFile = null;

    foreach ($options['headers'] as $key => $value) {
      $command[] = '-H'; $command[] = "$key: $value";
    }

    if (isset($options['multipart'])) {
      $part = $options['multipart'][0];
      $tempFile = tempnam(sys_get_temp_dir(), 'casiel_curl');
      file_put_contents($tempFile, $part['contents']);
      $command[] = '-F';
      $command[] = "{$part['name']}=@{$tempFile};filename={$part['filename']}";
    } elseif (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
      $body = !empty($options['body']) ? $options['body'] : json_encode($options['json'] ?? []);
      $command[] = '-d'; $command[] = $body;
    }
   
    $command[] = $url;

    casiel_log('sword_api', '[CURL] Ejecutando comando', ['command' => implode(' ', $command)]);

    try {
      $process = new Process($command);
      $process->setTimeout(60);
      $process->start();

      $timerId = Timer::add(0.1, function() use ($process, &$timerId, $onSuccess, $onError, $tempFile) {
        if (!$process->isRunning()) {
          Timer::del($timerId);
          if ($tempFile) @unlink($tempFile);

          if ($process->isSuccessful()) {
            $responseBody = $process->getOutput();
            $responseData = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
             $onError("Fallo al decodificar JSON de cURL: " . $responseBody); return;
            }
            $onSuccess($responseData);
          } else {
            $onError("Proceso cURL falló: " . $process->getErrorOutput());
          }
        }
      });
    } catch (Throwable $e) {
      if ($tempFile) @unlink($tempFile);
      $onError("Excepción al iniciar proceso cURL: " . $e->getMessage());
    }
  }

  public function getMediaDetails(int $mediaId, callable $onSuccess, callable $onError): void
  {
    casiel_log('sword_api', "Obteniendo detalles del medio: {$mediaId}");
    $this->executeRequest('get', "media/{$mediaId}", [], fn($res) => $onSuccess($res['data'] ?? $res), $onError);
  }

  public function updateContent(int $contentId, array $data, callable $onSuccess, callable $onError): void
  {
    casiel_log('sword_api', "Actualizando contenido: {$contentId}");
    $this->executeRequest('post', "contents/{$contentId}", ['json' => $data], fn($res) => $onSuccess($res['data'] ?? $res), $onError);
  }

  public function uploadMedia(string $filePath, callable $onSuccess, callable $onError): void
  {
    casiel_log('sword_api', "Subiendo nuevo archivo: " . basename($filePath));
    if (!file_exists($filePath)) { $onError("El archivo a subir no existe: {$filePath}"); return; }
    $options = ['multipart' => [['name' => 'file', 'contents' => file_get_contents($filePath), 'filename' => basename($filePath)]]];
    $this->executeRequest('post', 'media', $options, fn($res) => $onSuccess($res['data'] ?? $res), $onError);
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
      'sink' => $destinationPath, // Stream response directly to file
      'timeout' => 300, // 5 minutes timeout for download
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
    $command = ['curl', '-s', '-L', '-o', $destinationPath, $fileUrl];

    casiel_log('sword_api', '[CURL-DOWNLOAD] Ejecutando comando', ['command' => implode(' ', $command)]);

    try {
      $process = new Process($command);
      $process->setTimeout(300); // 5 minutes timeout
      $process->start();

      $timerId = Timer::add(0.1, function() use ($process, &$timerId, $onSuccess, $onError, $destinationPath) {
        if (!$process->isRunning()) {
          Timer::del($timerId);
          if ($process->isSuccessful()) {
            // Verify file was actually created and has size > 0
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
<?php

use app\services\SwordApiService;
use Mockery\MockInterface;
use Workerman\Http\Client;
use Workerman\Http\Response;

test('uploadMedia se autentica y sube un archivo exitosamente', function () {
    // 1. Setup
    $mockHttpClient = Mockery::mock(Client::class);
    $apiUrl = getenv('SWORD_API_URL');
    $tempFilePath = runtime_path() . '/tmp/test_upload.mp3';
    create_dummy_file($tempFilePath, 'fake-mp3-data');

    // 2. Expectations
    // Se espera primero la llamada de autenticación
    $mockHttpClient->shouldReceive('post')
        ->once()
        ->ordered() // Asegura que esta llamada ocurra primero
        ->with("{$apiUrl}/auth/login", Mockery::any(), Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($url, $options, $onSuccess) {
            $fakeLoginResponse = new Response(200, [], json_encode(['data' => ['access_token' => 'fake-jwt-token']]));
            $onSuccess($fakeLoginResponse);
        });

    // Se espera la llamada para subir el archivo
    $mockHttpClient->shouldReceive('post')
        ->once()
        ->ordered() // Y esta después
        ->with("{$apiUrl}/media", Mockery::any(), Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($url, $options, $onSuccess) {
            $fakeUploadResponse = new Response(201, [], json_encode(['data' => ['id' => 99, 'path' => 'uploads/media/new_file.mp3']]));
            $onSuccess($fakeUploadResponse);
        });

    // 3. Action & Assertions
    $swordService = new SwordApiService($mockHttpClient);
    $swordService->uploadMedia(
        $tempFilePath,
        function ($data) {
            expect($data)->toBe(['id' => 99, 'path' => 'uploads/media/new_file.mp3']);
        },
        fn($err) => test()->fail("onError fue llamado inesperadamente: $err")
    );

    // 4. Cleanup
    unlink($tempFilePath);
});


test('getMediaDetails maneja un error de API y llama a onError', function () {
    $mockHttpClient = Mockery::mock(Client::class);
    $apiUrl = getenv('SWORD_API_URL');

    // Autenticación
    $mockHttpClient->shouldReceive('post')
        ->once()
        ->with("{$apiUrl}/auth/login", Mockery::any(), Mockery::any(), Mockery::any())
        ->andReturnUsing(fn($url, $opt, $cb) => $cb(new Response(200, [], json_encode(['data' => ['access_token' => 'fake-jwt-token']]))));

    // Petición GET que falla
    $mockHttpClient->shouldReceive('get')
        ->once()
        ->with("{$apiUrl}/media/404", Mockery::any(), Mockery::any(), Mockery::any())
        ->andReturnUsing(fn($url, $opt, $cb) => $cb(new Response(404, [], 'Not Found')));

    $swordService = new SwordApiService($mockHttpClient);
    $swordService->getMediaDetails(
        404,
        fn($data) => test()->fail("onSuccess fue llamado inesperadamente."),
        function ($error) {
            expect($error)->toContain("Status 404");
        }
    );
});
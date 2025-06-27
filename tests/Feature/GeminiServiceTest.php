<?php

use app\services\GeminiService;
use Mockery\MockInterface;
use Workerman\Http\Client;
use Workerman\Http\Response;

test('analyzeAudio obtiene exitosamente los metadatos creativos', function () {
    // 1. Setup
    $mockHttpClient = Mockery::mock(Client::class);
    $tempFilePath = runtime_path() . '/tmp/test_audio_gemini.mp3';
    create_dummy_file($tempFilePath, 'fake-audio-data');
    $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/" . getenv('GEMINI_MODEL_ID') . ":generateContent?key=" . getenv('GEMINI_API_KEY');
    $expectedCreativeData = ['nombre_archivo_base' => 'cool synth melody', 'tags' => ['synth', 'melodic', '80s']];

    // 2. Expectations
    $mockHttpClient->shouldReceive('post')
        ->once()
        ->with($apiUrl, Mockery::any(), Mockery::on(function ($onSuccessCallback) use ($expectedCreativeData) {
            $fakeApiResponse = new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($expectedCreativeData)]]]]]
            ]));
            call_user_func($onSuccessCallback, $fakeApiResponse);
            return true;
        }), Mockery::any());

    // 3. Action & Assertions
    $geminiService = new GeminiService($mockHttpClient);
    $geminiService->analyzeAudio(
        $tempFilePath,
        ['title' => 'test audio'],
        function ($data) use ($expectedCreativeData) {
            expect($data)->toBe($expectedCreativeData);
        },
        fn($err) => test()->fail("onError fue llamado inesperadamente: $err")
    );

    // 4. Cleanup
    unlink($tempFilePath);
});

test('analyzeAudio maneja un error de la API y llama a onError', function () {
    $mockHttpClient = Mockery::mock(Client::class);
    $tempFilePath = runtime_path() . '/tmp/test_audio_gemini_fail.mp3';
    create_dummy_file($tempFilePath, 'fake-audio-data');
    $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/" . getenv('GEMINI_MODEL_ID') . ":generateContent?key=" . getenv('GEMINI_API_KEY');

    $mockHttpClient->shouldReceive('post')
        ->once()
        ->with($apiUrl, Mockery::any(), Mockery::on(function ($onSuccessCallback) {
            $fakeApiResponse = new Response(500, [], 'Internal Server Error');
            call_user_func($onSuccessCallback, $fakeApiResponse);
            return true;
        }), Mockery::any());

    $geminiService = new GeminiService($mockHttpClient);
    $geminiService->analyzeAudio(
        $tempFilePath,
        [],
        fn($data) => test()->fail("onSuccess fue llamado inesperadamente."),
        function ($error) {
            expect($error)->toContain("La API de Gemini respondió con el código de estado: 500");
        }
    );

    unlink($tempFilePath);
});
<?php

use app\services\GeminiService;
use Workerman\Http\Client;
use Workerman\Http\Response;

test('analyzeAudio successfully gets creative metadata', function () {
    // 1. Setup
    $mockHttpClient = Mockery::mock(Client::class);
    $tempFilePath = runtime_path() . '/tmp/test_audio_gemini.mp3';
    file_put_contents($tempFilePath, 'fake-audio-data');

    $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/" . getenv('GEMINI_MODEL_ID') . ":generateContent?key=" . getenv('GEMINI_API_KEY');

    $expectedCreativeData = [
        'nombre_archivo_base' => 'cool synth melody',
        'tags' => ['synth', 'melodic', '80s'],
    ];

    // 2. Expectations
    $mockHttpClient->shouldReceive('post')
        ->once()
        ->with($apiUrl, Mockery::on(function ($options) {
            // Check if the prompt and audio data are in the request
            expect($options['json']['contents'][0]['parts'][0]['text'])->toBeString();
            expect($options['json']['contents'][0]['parts'][1]['inline_data']['data'])->toBe(base64_encode('fake-audio-data'));
            return true;
        }), Mockery::on(function ($callback) use ($expectedCreativeData) {
            $fakeApiResponse = new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode($expectedCreativeData)]
                            ]
                        ]
                    ]
                ]
            ]));
            call_user_func($callback, $fakeApiResponse);
            return true;
        }), Mockery::any());

    // 3. Action
    $geminiService = new GeminiService($mockHttpClient);
    $geminiService->analyzeAudio(
        $tempFilePath,
        ['title' => 'test audio'],
        function ($data) use ($expectedCreativeData) {
            // 4. Assertions
            expect($data)->toBe($expectedCreativeData);
        },
        function ($error) {
            $this->fail("onError was called unexpectedly: " . $error);
        }
    );

    // 5. Cleanup
    unlink($tempFilePath);
    Mockery::close();
});

test('analyzeAudio handles API error and calls onError', function () {
    // 1. Setup
    $mockHttpClient = Mockery::mock(Client::class);
    $tempFilePath = runtime_path() . '/tmp/test_audio_gemini_fail.mp3';
    file_put_contents($tempFilePath, 'fake-audio-data');
    $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/" . getenv('GEMINI_MODEL_ID') . ":generateContent?key=" . getenv('GEMINI_API_KEY');

    // 2. Expectations
    $mockHttpClient->shouldReceive('post')
        ->once()
        ->with($apiUrl, Mockery::any(), Mockery::on(function ($callback) {
            $fakeApiResponse = new Response(500, [], 'Internal Server Error');
            call_user_func($callback, $fakeApiResponse);
            return true;
        }), Mockery::any());

    // 3. Action
    $geminiService = new GeminiService($mockHttpClient);
    $geminiService->analyzeAudio(
        $tempFilePath,
        [],
        function ($data) {
            $this->fail("onSuccess was called unexpectedly.");
        },
        function ($error) {
            // 4. Assertion
            expect($error)->toContain("La API de Gemini respondió con el código de estado: 500");
        }
    );

    // 5. Cleanup
    unlink($tempFilePath);
    Mockery::close();
});

<?php

use app\services\SwordApiService;
use Workerman\Http\Client;
use Workerman\Http\Response;

// It is recommended to rename this file to SwordApiServiceTest.php

test('uploadMedia successfully authenticates and uploads a file', function () {
    // 1. Setup: Create a mock HTTP client
    $mockHttpClient = Mockery::mock(Client::class);
    
    $apiUrl = getenv('SWORD_API_URL');
    $tempFilePath = runtime_path() . '/tmp/test_audio.mp3';
    file_put_contents($tempFilePath, 'fake-mp3-data');

    // 2. Expectations: Define the sequence of expected calls
    // Expect the authentication call first
    $mockHttpClient->shouldReceive('post')
        ->once()
        ->with("{$apiUrl}/auth/login", Mockery::any(), Mockery::on(function($callback){
            $fakeLoginResponse = new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['access_token' => 'fake-jwt-token']
            ]));
            call_user_func($callback, $fakeLoginResponse);
            return true;
        }), Mockery::any());

    // Expect the file upload call next
    $mockHttpClient->shouldReceive('post')
        ->once()
        ->with("{$apiUrl}/media", Mockery::on(function($options) use ($tempFilePath) {
            // Check if multipart data is correctly formatted
            expect($options['multipart'][0]['name'])->toBe('file');
            expect($options['multipart'][0]['filename'])->toBe(basename($tempFilePath));
            return true;
        }), Mockery::on(function($callback){
            $fakeUploadResponse = new Response(201, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'data' => ['id' => 99, 'path' => 'uploads/media/new_file.mp3']
            ]));
            call_user_func($callback, $fakeUploadResponse);
            return true;
        }), Mockery::any());
    
    // 3. Action: Instantiate the service with the mock and call the method
    $swordService = new SwordApiService($mockHttpClient);

    $swordService->uploadMedia(
        $tempFilePath,
        function ($data) {
            // 4. Assertions: Check if onSuccess is called with correct data
            expect($data)->toBeArray()
                ->and($data['id'])->toBe(99)
                ->and($data['path'])->toBe('uploads/media/new_file.mp3');
        },
        function ($error) {
            // This should not be called in a successful test
            $this->fail("onError was called unexpectedly: " . $error);
        }
    );

    // 5. Cleanup
    unlink($tempFilePath);
    Mockery::close();
});

test('getMediaDetails handles api error and calls onError', function () {
    // 1. Setup
    $mockHttpClient = Mockery::mock(Client::class);
    $apiUrl = getenv('SWORD_API_URL');
    
    // 2. Expectations
    // Expect auth call
    $mockHttpClient->shouldReceive('post')
        ->once()
        ->with("{$apiUrl}/auth/login", Mockery::any(), Mockery::on(function($callback){
            $fakeLoginResponse = new Response(200, [], json_encode(['data' => ['access_token' => 'fake-jwt-token']]));
            call_user_func($callback, $fakeLoginResponse);
            return true;
        }), Mockery::any());

    // Expect get call to fail
    $mockHttpClient->shouldReceive('get')
        ->once()
        ->with("{$apiUrl}/media/404", Mockery::any(), Mockery::on(function($callback){
            $fakeErrorResponse = new Response(404, [], json_encode(['success' => false, 'message' => 'Not Found']));
            call_user_func($callback, $fakeErrorResponse);
            return true;
        }), Mockery::any());

    // 3. Action
    $swordService = new SwordApiService($mockHttpClient);
    $swordService->getMediaDetails(
        404, // A non-existent ID
        function ($data) {
            $this->fail("onSuccess was called unexpectedly.");
        },
        function ($error) {
            // 4. Assertion
            expect($error)->toContain("Status 404");
        }
    );
    
    // 5. Cleanup
    Mockery::close();
});
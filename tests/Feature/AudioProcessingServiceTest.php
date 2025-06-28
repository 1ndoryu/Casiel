<?php

use app\services\AudioAnalysisService;
use app\services\AudioProcessingService;
use app\services\GeminiService;
use app\services\SwordApiService;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Workerman\Http\Client;
use Workerman\Http\Response as WorkermanResponse;

/**
 * Prepares the mock environment for each processing service integration test.
 */
beforeEach(function () {
    // Clean up temporary directory before each test
    $tempDir = runtime_path() . '/tmp/audio_processing_test';
    if (is_dir($tempDir)) {
        delete_directory_recursively($tempDir);
    }
    mkdir($tempDir, 0777, true);

    // Mock ALL external dependencies for AudioProcessingService
    $this->swordApiMock = Mockery::mock(SwordApiService::class);
    $this->audioAnalysisMock = Mockery::mock(AudioAnalysisService::class);
    $this->geminiApiMock = Mockery::mock(GeminiService::class);
    $this->httpClientMock = Mockery::mock(Client::class);
    $this->channelMock = Mockery::mock(AMQPChannel::class);
    $this->realAudioPath = base_path('tests/Melancholic Guitar_Eizn_2upra.mp3');

    // Create a "Test Double" using an anonymous class.
    // This allows us to override protected methods for testing purposes.
    $this->processingServiceTestDouble = new class(
        $this->swordApiMock,
        $this->audioAnalysisMock,
        $this->geminiApiMock,
        $this->httpClientMock
    ) extends AudioProcessingService {
        /** @var array|null Control the headers for retry tests. */
        public ?array $mockHeaders = null;

        /**
         * Override the method that uses the Workerman Timer.
         * In a test environment, we simply do nothing.
         */
        protected function scheduleCleanup(array $filesToDelete): void
        {
            // This override prevents the "Timer can only be used in workerman" error.
        }

        /**
         * Override this helper to easily control the retry logic during tests.
         */
        protected function getMessageHeaders(AMQPMessage $msg): ?array
        {
            return $this->mockHeaders;
        }
    };
});

afterEach(function () {
    Mockery::close();
});

test('proceso completo de un audio real de forma exitosa', function () {
    // ARRANGE
    $contentId = 123;
    $mediaId = 456;
    $amqpMessageMock = Mockery::mock(AMQPMessage::class);
    $amqpMessageMock->body = json_encode(['data' => ['content_id' => $contentId, 'media_id' => $mediaId]]);

    // Set expectations for mocks
    $this->swordApiMock->shouldReceive('getMediaDetails')->once()->with($mediaId, Mockery::any(), Mockery::any())
        ->andReturnUsing(fn ($id, $onSuccess) => $onSuccess(['path' => 'uploads/test.mp3', 'metadata' => ['original_name' => 'test_audio.mp3']]));

    $this->httpClientMock->shouldReceive('get')->once()
        ->andReturnUsing(fn ($url, $opt, $onSuccess) => $onSuccess(new WorkermanResponse(200, [], file_get_contents($this->realAudioPath))));

    $this->audioAnalysisMock->shouldReceive('analyze')->once()->andReturn(['bpm' => 120]);
    $this->audioAnalysisMock->shouldReceive('generateLightweightVersion')->once()
        ->andReturnUsing(function (string $input, string $output): bool {
            create_dummy_file($output, 'light-audio-data');
            return true;
        });

    $this->geminiApiMock->shouldReceive('analyzeAudio')->once()->andReturnUsing(fn ($p, $c, $onSuccess) => $onSuccess(['nombre_archivo_base' => 'test']));
    $this->swordApiMock->shouldReceive('uploadMedia')->once()->andReturnUsing(fn ($p, $onSuccess) => $onSuccess(['path' => 'uploads/light.mp3']));
    $this->swordApiMock->shouldReceive('updateContent')->once()->with($contentId, Mockery::any(), Mockery::any(), Mockery::any())
        ->andReturnUsing(fn ($id, $d, $onSuccess) => $onSuccess());

    $amqpMessageMock->shouldReceive('ack')->once();

    // Set headers for this specific test run (no retries)
    $this->processingServiceTestDouble->mockHeaders = null;

    // ACT
    $this->processingServiceTestDouble->process($amqpMessageMock, $this->channelMock);
});

test('mensaje es enviado a reintento (nack) en el primer fallo', function () {
    // ARRANGE
    $mockMsg = Mockery::mock(AMQPMessage::class);
    $mockMsg->body = json_encode(['data' => ['content_id' => 456, 'media_id' => 789]]);

    $this->swordApiMock->shouldReceive('getMediaDetails')->once()
        ->andReturnUsing(fn ($id, $onSuccess, $onError) => $onError("Sword API is down"));

    $mockMsg->shouldReceive('nack')->once()->with(false);

    // Simulate the first attempt (no 'x-death' headers)
    $this->processingServiceTestDouble->mockHeaders = null;

    // ACT
    $this->processingServiceTestDouble->process($mockMsg, $this->channelMock);
});

test('mensaje es enviado a la DLQ final después de maximos reintentos', function () {
    // ARRANGE
    $mockMsg = Mockery::mock(AMQPMessage::class);
    $mockMsg->body = json_encode(['data' => ['content_id' => 789, 'media_id' => 101]]);

    $this->swordApiMock->shouldReceive('getMediaDetails')->once()
        ->andReturnUsing(fn ($id, $onSuccess, $onError) => $onError("Sword API is still down"));

    $this->channelMock->shouldReceive('basic_publish')->once()->with($mockMsg, 'casiel_dlx', 'casiel.dlq.final');
    $mockMsg->shouldReceive('ack')->once();

    // Simulate a message that has already failed the maximum number of times.
    $this->processingServiceTestDouble->mockHeaders = ['x-death' => [['count' => 3]]];

    // ACT
    $this->processingServiceTestDouble->process($mockMsg, $this->channelMock);
});
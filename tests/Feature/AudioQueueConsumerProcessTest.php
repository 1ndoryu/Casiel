<?php

use app\process\AudioQueueConsumer;
use app\services\AudioAnalysisService;
use app\services\GeminiService;
use app\services\SwordApiService;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Workerman\Http\Client;
use Workerman\Http\Response as WorkermanResponse;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Prepares the environment for each consumer integration test.
 */
beforeEach(function () {
    // Temporary directory for processed audio files
    $this->tempDir = runtime_path() . '/tmp/audio_processing_test';
    // Clean up the directory before each test to prevent artifacts
    if (is_dir($this->tempDir)) {
        delete_directory_recursively($this->tempDir);
    }
    mkdir($this->tempDir, 0777, true);

    // Mock ALL main dependencies of the consumer for true unit/integration testing
    $this->swordApiMock = Mockery::mock(SwordApiService::class);
    $this->geminiApiMock = Mockery::mock(GeminiService::class);
    $this->httpClientMock = Mockery::mock(Client::class);
    $this->channelMock = Mockery::mock(AMQPChannel::class);
    // SOLUCIÓN: Mockear AudioAnalysisService para aislar el test del entorno (Python/ffmpeg)
    $this->audioAnalysisMock = Mockery::mock(AudioAnalysisService::class);

    // Path to a real audio file for testing download simulation
    $this->realAudioPath = base_path('tests/Melancholic Guitar_Eizn_2upra.mp3');

    // Instantiate the consumer with mocked dependencies
    $this->consumer = new class(
        $this->swordApiMock,
        $this->audioAnalysisMock, // Usar el mock
        $this->geminiApiMock,
        $this->httpClientMock
    ) extends AudioQueueConsumer {
        public function __construct(...$args)
        {
            parent::__construct(...$args);
            // Override tempDir to ensure test artifacts are isolated and do not conflict.
            $this->tempDir = runtime_path() . '/tmp/audio_processing_test';
        }

        /**
         * Override the cleanup scheduler to prevent Timer from being called in a non-worker environment.
         * The cleanup logic itself is simple and doesn't need to be tested here.
         */
        protected function scheduleCleanup(array $filesToDelete): void
        {
            // Do nothing in the test environment to avoid Workerman\Timer errors.
        }
    };
});

/**
 * Cleans up the environment after each test.
 */
afterEach(function () {
    if (is_dir($this->tempDir)) {
        delete_directory_recursively($this->tempDir);
    }
    Mockery::close();
});

test('proceso completo de un audio real de forma exitosa', function () {
    $contentId = 123;

    // --- ARRANGE ---

    // 1. Sword provides media details
    $this->swordApiMock->shouldReceive('getMediaDetails')
        ->once()->with($contentId, Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($id, $onSuccess) {
            $onSuccess(['path' => 'uploads/test.mp3', 'metadata' => ['original_name' => 'test_audio.mp3']]);
        });

    // 2. Simulate downloading the audio file
    $this->httpClientMock->shouldReceive('get')
        ->once()->with(Mockery::any(), [], Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($url, $options, $onSuccess) {
            $response = new WorkermanResponse(200, [], file_get_contents($this->realAudioPath));
            $onSuccess($response);
        });

    // 3. AudioAnalysisService provides technical metadata
    $this->audioAnalysisMock->shouldReceive('analyze')
        ->once()->with(Mockery::on(fn($path) => file_exists($path)))
        ->andReturn(['bpm' => 120, 'tonalidad' => 'E', 'escala' => 'minor']);

    // 4. Gemini provides creative metadata
    $this->geminiApiMock->shouldReceive('analyzeAudio')
        ->once()->with(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($path, $context, $onSuccess) {
            expect(file_exists($path))->toBeTrue();
            $onSuccess(['nombre_archivo_base' => 'melancholic test guitar', 'tags' => ['test', 'guitar']]);
        });
        
    // 5. AudioAnalysisService generates lightweight version
    $this->audioAnalysisMock->shouldReceive('generateLightweightVersion')
        ->once()->with(Mockery::any(), Mockery::on(fn($path) => str_ends_with($path, '_light.mp3')))
        ->andReturnUsing(function ($input, $output) {
            // Simulate file creation for the next step
            create_dummy_file($output, 'light-version-data');
            return true;
        });

    // 6. Sword receives the new lightweight version
    $this->swordApiMock->shouldReceive('uploadMedia')
        ->once()->with(Mockery::on(fn($path) => file_exists($path)), Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($path, $onSuccess) {
            $onSuccess(['path' => 'uploads/light_version.mp3']);
        });

    // 7. Sword receives the final content update
    $this->swordApiMock->shouldReceive('updateContent')
        ->once()->with($contentId, Mockery::on(function ($data) {
            expect($data['slug'])->toStartWith('melancholic_test_guitar_');
            expect($data['content_data']['bpm'])->toBe(120);
            expect($data['content_data']['light_version_url'])->toBe('uploads/light_version.mp3');
            return true;
        }), Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($id, $data, $onSuccess) {
            $onSuccess();
        });

    // 8. Mock the AMQP Message: robustly define expectations for success AND failure paths
    $amqpMessageMock = Mockery::mock(AMQPMessage::class);
    $amqpMessageMock->body = json_encode(['data' => ['id' => $contentId]]);
    $amqpMessageMock->shouldReceive('ack')->once();
    // SOLUCIÓN: Explicitar que los métodos de fallo NO deben llamarse.
    $amqpMessageMock->shouldReceive('has')->with('application_headers')->times(0);
    $amqpMessageMock->shouldReceive('nack')->times(0);


    // --- ACT ---
    $this->consumer->processMessage($amqpMessageMock, $this->channelMock);
});

test('mensaje es enviado a reintento (nack) en el primer fallo', function () {
    $contentId = 456;

    // --- ARRANGE ---
    // Simulate that the first call to the Sword API fails
    $this->swordApiMock->shouldReceive('getMediaDetails')
        ->once()->with($contentId, Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($id, $onSuccess, $onError) {
            $onError("Sword API is down");
        });

    $amqpMessageMock = Mockery::mock(AMQPMessage::class);
    $amqpMessageMock->body = json_encode(['data' => ['id' => $contentId]]);

    // Expect the message to be checked for retry headers (and not have them)
    $amqpMessageMock->shouldReceive('has')->with('application_headers')->once()->andReturn(false);

    // Expect the message to be rejected (nack) to be sent to the retry queue
    $amqpMessageMock->shouldReceive('nack')->once()->with(false);
    $amqpMessageMock->shouldNotReceive('ack');

    // --- ACT ---
    $this->consumer->processMessage($amqpMessageMock, $this->channelMock);
});

test('mensaje es enviado a la DLQ final después de maximos reintentos', function () {
    $contentId = 789;

    // --- ARRANGE ---
    // Simulate failure again
    $this->swordApiMock->shouldReceive('getMediaDetails')
        ->once()->with($contentId, Mockery::any(), Mockery::any())
        ->andReturnUsing(function ($id, $onSuccess, $onError) {
            $onError("Sword API is still down");
        });

    // Expect the message to be published to the final DLX
    $this->channelMock->shouldReceive('basic_publish')
        ->once()->with(Mockery::type(AMQPMessage::class), 'casiel_dlx', 'casiel.dlq.final');

    $amqpMessageMock = Mockery::mock(AMQPMessage::class);
    $amqpMessageMock->body = json_encode(['data' => ['id' => $contentId]]);

    // Simulate a message that has already failed MAX_RETRIES times
    $mockHeaders = Mockery::mock(AMQPTable::class);
    $mockHeaders->shouldReceive('getNativeData')->once()->andReturn([
        'x-death' => [
            // The 'count' from the work queue rejection is what matters.
            ['count' => 3, 'queue' => getenv('RABBITMQ_WORK_QUEUE'), 'reason' => 'rejected']
        ]
    ]);

    $amqpMessageMock->shouldReceive('has')->with('application_headers')->once()->andReturn(true);
    $amqpMessageMock->shouldReceive('get')->with('application_headers')->once()->andReturn($mockHeaders);

    // After publishing to the DLQ, the original message must be 'acked' to be removed
    $amqpMessageMock->shouldReceive('ack')->once();
    $amqpMessageMock->shouldNotReceive('nack');

    // --- ACT ---
    $this->consumer->processMessage($amqpMessageMock, $this->channelMock);
});
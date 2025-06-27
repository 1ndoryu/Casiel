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

/**
 * Prepares the mock environment for each consumer integration test.
 */
beforeEach(function () {
    // Clean up temporary directory before each test
    $tempDir = runtime_path() . '/tmp/audio_processing_test';
    if (is_dir($tempDir)) {
        delete_directory_recursively($tempDir);
    }
    mkdir($tempDir, 0777, true);

    // Mock ALL external dependencies
    $this->swordApiMock = Mockery::mock(SwordApiService::class);
    $this->geminiApiMock = Mockery::mock(GeminiService::class);
    $this->httpClientMock = Mockery::mock(Client::class);
    $this->channelMock = Mockery::mock(AMQPChannel::class);
    $this->audioAnalysisMock = Mockery::mock(AudioAnalysisService::class);
    $this->realAudioPath = base_path('tests/Melancholic Guitar_Eizn_2upra.mp3');

    // Define the helper as a closure within the test object's context.
    $this->getConsumerDependencies = function (): array {
        return [
            $this->swordApiMock,
            $this->audioAnalysisMock,
            $this->geminiApiMock,
            $this->httpClientMock
        ];
    };
});

afterEach(function () {
    Mockery::close();
});

test('proceso completo de un audio real de forma exitosa', function () {
    // ARRANGE: Set up IDs and the correct message body format
    $contentId = 123;
    $mediaId = 456;
    $amqpMessageMock = Mockery::mock(AMQPMessage::class);
    $amqpMessageMock->body = json_encode([
        'data' => [
            'content_id' => $contentId,
            'media_id' => $mediaId
        ]
    ]);

    // ARRANGE: Expectations for all service calls in the happy path
    $this->swordApiMock->shouldReceive('getMediaDetails')
        ->once()
        ->with($mediaId, Mockery::any(), Mockery::any()) // Expect the correct mediaId
        ->andReturnUsing(fn ($id, $onSuccess) => $onSuccess(['path' => 'uploads/test.mp3', 'metadata' => ['original_name' => 'test_audio.mp3']]));

    $this->httpClientMock->shouldReceive('get')
        ->once()
        ->andReturnUsing(fn ($url, $opt, $onSuccess) => $onSuccess(new WorkermanResponse(200, [], file_get_contents($this->realAudioPath))));

    $this->audioAnalysisMock->shouldReceive('analyze')->once()->andReturn(['bpm' => 120]);
    $this->geminiApiMock->shouldReceive('analyzeAudio')->once()->andReturnUsing(fn ($p, $c, $onSuccess) => $onSuccess(['nombre_archivo_base' => 'test']));
    
    // Simulate lightweight version creation
    $this->audioAnalysisMock->shouldReceive('generateLightweightVersion')
        ->once()
        ->andReturnUsing(function (string $input, string $output): bool {
            create_dummy_file($output, 'light-audio-data');
            return true;
        });

    $this->swordApiMock->shouldReceive('uploadMedia')
        ->once()
        ->andReturnUsing(fn ($p, $onSuccess) => $onSuccess(['path' => 'uploads/light.mp3']));

    $this->swordApiMock->shouldReceive('updateContent')
        ->once()
        ->with($contentId, Mockery::any(), Mockery::any(), Mockery::any()) // Expect the correct contentId
        ->andReturnUsing(fn ($id, $d, $onSuccess) => $onSuccess());

    // ARRANGE: Message should be acknowledged, never NACKed or sent to DLQ
    $amqpMessageMock->shouldReceive('ack')->once();
    $amqpMessageMock->shouldNotReceive('nack');
    $this->channelMock->shouldNotReceive('basic_publish');

    $consumer = new class(...($this->getConsumerDependencies)()) extends AudioQueueConsumer {
        public function __construct(...$args) {
            parent::__construct(...$args);
            $this->tempDir = runtime_path() . '/tmp/audio_processing_test';
        }
        protected function getMessageHeaders(AMQPMessage $msg): ?array { return null; }
        protected function scheduleCleanup(array $filesToDelete): void {}
    };

    // ACT
    $consumer->processMessage($amqpMessageMock, $this->channelMock);
});

test('mensaje es enviado a reintento (nack) en el primer fallo', function () {
    // ARRANGE: Set up IDs and the correct message body format
    $contentId = 456;
    $mediaId = 789;
    $amqpMessageMock = Mockery::mock(AMQPMessage::class);
    $amqpMessageMock->body = json_encode([
        'data' => [
            'content_id' => $contentId,
            'media_id' => $mediaId
        ]
    ]);

    // ARRANGE: The first service call fails.
    $this->swordApiMock->shouldReceive('getMediaDetails')
        ->once()
        ->with($mediaId, Mockery::any(), Mockery::any()) // Expect the call to be made
        ->andReturnUsing(fn ($id, $onSuccess, $onError) => $onError("Sword API is down"));

    // ARRANGE: Message should be NACKed to trigger a retry
    $amqpMessageMock->shouldReceive('nack')->once()->with(false);
    $amqpMessageMock->shouldNotReceive('ack');
    $this->channelMock->shouldNotReceive('basic_publish');

    $consumer = new class(...($this->getConsumerDependencies)()) extends AudioQueueConsumer {
        public function __construct(...$args) {
            parent::__construct(...$args);
            $this->tempDir = runtime_path() . '/tmp/audio_processing_test';
        }
        protected function getMessageHeaders(AMQPMessage $msg): ?array { return null; }
        protected function scheduleCleanup(array $filesToDelete): void {}
    };

    // ACT
    $consumer->processMessage($amqpMessageMock, $this->channelMock);
});

test('mensaje es enviado a la DLQ final después de maximos reintentos', function () {
    // ARRANGE: Set up IDs and the correct message body format
    $contentId = 789;
    $mediaId = 101;
    $amqpMessageMock = Mockery::mock(AMQPMessage::class);
    $amqpMessageMock->body = json_encode([
        'data' => [
            'content_id' => $contentId,
            'media_id' => $mediaId
        ]
    ]);

    // ARRANGE: The service call fails again.
    $this->swordApiMock->shouldReceive('getMediaDetails')
        ->once()
        ->with($mediaId, Mockery::any(), Mockery::any())
        ->andReturnUsing(fn ($id, $onSuccess, $onError) => $onError("Sword API is still down"));

    // ARRANGE: After max retries, message is published to the final DLQ and ACKed.
    $this->channelMock->shouldReceive('basic_publish')->once()->with($amqpMessageMock, 'casiel_dlx', 'casiel.dlq.final');
    $amqpMessageMock->shouldReceive('ack')->once();
    $amqpMessageMock->shouldNotReceive('nack');

    $consumer = new class(...($this->getConsumerDependencies)()) extends AudioQueueConsumer {
        public function __construct(...$args) {
            parent::__construct(...$args);
            $this->tempDir = runtime_path() . '/tmp/audio_processing_test';
        }
        // Simulate that this message has already failed 3 times.
        protected function getMessageHeaders(AMQPMessage $msg): ?array {
            return ['x-death' => [['count' => 3, 'queue' => getenv('RABBITMQ_WORK_QUEUE')]]];
        }
        protected function scheduleCleanup(array $filesToDelete): void {}
    };

    // ACT
    $consumer->processMessage($amqpMessageMock, $this->channelMock);
});
<?php

use app\services\AudioAnalysisService;
use app\services\AudioProcessingService;
use app\services\FileHandlerService;
use app\services\GeminiService;
use app\services\SwordApiService;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Prepares the mock environment for each processing service integration test.
 */
beforeEach(function () {
    // Mock ALL external dependencies for AudioProcessingService
    $this->swordApiMock = Mockery::mock(SwordApiService::class);
    $this->audioAnalysisMock = Mockery::mock(AudioAnalysisService::class);
    $this->geminiApiMock = Mockery::mock(GeminiService::class);
    $this->fileHandlerMock = Mockery::mock(FileHandlerService::class); // NUEVO MOCK
    $this->channelMock = Mockery::mock(AMQPChannel::class);
    $this->realAudioPath = base_path('tests/Melancholic Guitar_Eizn_2upra.mp3');

    $this->processingService = new AudioProcessingService(
        $this->swordApiMock,
        $this->audioAnalysisMock,
        $this->geminiApiMock,
        $this->fileHandlerMock // Inyectar el mock
    );
});

afterEach(function () {
    Mockery::close();
});

test('proceso completo de un audio real de forma exitosa', function () {
    // ARRANGE
    $contentId = 123;
    $mediaId = 456;
    $amqpMessageMock = new AMQPMessage(json_encode(['data' => ['content_id' => $contentId, 'media_id' => $mediaId]]));

    $tempOriginalPath = "/tmp/456_original.mp3";
    $tempLightPath = "/tmp/test.mp3";

    // Set expectations for mocks
    $this->swordApiMock->shouldReceive('getContent')->once()->with($contentId, Mockery::any(), Mockery::any())
        ->andReturnUsing(fn($id, $onSuccess) => $onSuccess(['content_data' => ['existing' => 'data']]));

    $this->swordApiMock->shouldReceive('getMediaDetails')->once()->with($mediaId, Mockery::any(), Mockery::any())
        ->andReturnUsing(fn($id, $onSuccess) => $onSuccess(['id' => $mediaId, 'path' => 'uploads/test.mp3', 'metadata' => ['original_name' => 'test_audio.mp3']]));
    
    // Simular FileHandler
    $this->fileHandlerMock->shouldReceive('createOriginalFilePath')->once()->andReturn($tempOriginalPath);
    $this->fileHandlerMock->shouldReceive('createLightweightFilePath')->once()->andReturn($tempLightPath);
    $this->fileHandlerMock->shouldReceive('cleanupFiles')->once();

    $this->swordApiMock->shouldReceive('downloadFile')->once()->with(Mockery::any(), $tempOriginalPath, Mockery::any(), Mockery::any())
        ->andReturnUsing(fn($u, $d, $onSuccess) => $onSuccess($d));

    $this->audioAnalysisMock->shouldReceive('analyze')->once()->with($tempOriginalPath)->andReturn(['bpm' => 120]);
    $this->geminiApiMock->shouldReceive('analyzeAudio')->once()->andReturnUsing(fn($p, $c, $onSuccess) => $onSuccess(['nombre_archivo_base' => 'test']));
    $this->audioAnalysisMock->shouldReceive('generateLightweightVersion')->once()->with($tempOriginalPath, $tempLightPath)->andReturn(true);

    $this->swordApiMock->shouldReceive('uploadMedia')->once()->with($tempLightPath, Mockery::any(), Mockery::any())
        ->andReturnUsing(fn($p, $onSuccess) => $onSuccess(['id' => 999]));

    $this->swordApiMock->shouldReceive('updateContent')->once()->with($contentId, Mockery::any(), Mockery::any(), Mockery::any())
        ->andReturnUsing(fn($id, $d, $onSuccess) => $onSuccess([]));

    // ACT
    $this->processingService->process($amqpMessageMock, $this->channelMock);
});

test('mensaje es enviado a la DLQ final después de maximos reintentos', function () {
    // ARRANGE
    $messageBody = json_encode(['data' => ['content_id' => 789, 'media_id' => 101]]);
    $headers = new AMQPTable(['x-death' => [['count' => 3, 'queue' => getenv('RABBITMQ_WORK_QUEUE')]]]);
    $mockMsg = new AMQPMessage($messageBody, ['application_headers' => $headers]);

    $this->swordApiMock->shouldReceive('getContent')->once()
        ->andReturnUsing(fn($id, $onSuccess, $onError) => $onError("Sword API is still down"));
    
    // Esperamos que se llame a la limpieza
    $this->fileHandlerMock->shouldReceive('cleanupFiles')->once();

    // Esperamos que se actualice el contenido a "failed" antes de ir a la DLQ final
    $this->swordApiMock->shouldReceive('updateContent')->once()->with(789, Mockery::on(function ($data) {
        return $data['content_data']['casiel_status'] === 'failed';
    }), Mockery::any(), Mockery::any())->andReturnUsing(fn($id, $d, $onSuccess) => $onSuccess());

    $this->channelMock->shouldReceive('basic_publish')->once()->with(Mockery::any(), 'casiel_dlx', 'casiel.dlq.final');

    // ACT
    $this->processingService->process($mockMsg, $this->channelMock);
});
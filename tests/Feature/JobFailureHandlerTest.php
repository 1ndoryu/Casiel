<?php

use app\services\JobFailureHandler;
use app\services\FileHandlerService;
use app\services\SwordApiService;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

beforeEach(function () {
    $this->swordApiMock = Mockery::mock(SwordApiService::class);
    $this->channelMock = Mockery::mock(AMQPChannel::class);
    $this->fileHandlerMock = Mockery::mock(FileHandlerService::class);

    $this->failureHandler = new JobFailureHandler($this->swordApiMock);
});

afterEach(function () {
    Mockery::close();
});

test('handle rechaza el mensaje para reintento si los reintentos no se han agotado', function () {
    // ARRANGE
    $messageBody = json_encode(['data' => ['content_id' => 1, 'media_id' => 1]]);
    // Simula 1 fallo previo
    $headers = new AMQPTable(['x-death' => [['count' => 1, 'queue' => getenv('RABBITMQ_WORK_QUEUE')]]]);
    $mockMsg = new AMQPMessage($messageBody, ['application_headers' => $headers, 'delivery_tag' => 'dt123']);

    $this->fileHandlerMock->shouldReceive('cleanupFiles')->once();
    $this->channelMock->shouldReceive('basic_nack')->once()->with('dt123', false, false);

    // ACT
    $this->failureHandler->handle($mockMsg, $this->channelMock, 'Error temporal', 1, $this->fileHandlerMock);
});

test('handle actualiza sword y envia a DLQ final despues de maximos reintentos', function () {
    // ARRANGE
    $messageBody = json_encode(['data' => ['content_id' => 789, 'media_id' => 101]]);
    // Simula 3 fallos previos
    $headers = new AMQPTable(['x-death' => [['count' => 3, 'queue' => getenv('RABBITMQ_WORK_QUEUE')]]]);
    $mockMsg = new AMQPMessage($messageBody, ['application_headers' => $headers, 'delivery_tag' => 'dt789']);

    $this->fileHandlerMock->shouldReceive('cleanupFiles')->once();

    $this->swordApiMock->shouldReceive('updateContent')->once()->with(789, Mockery::on(function ($data) {
        return $data['content_data']['casiel_status'] === 'failed' && $data['content_data']['casiel_error'] === 'Fallo definitivo';
    }), Mockery::any(), Mockery::any())->andReturnUsing(fn($id, $d, $onSuccess) => $onSuccess());

    $this->channelMock->shouldReceive('basic_publish')->once()->with($mockMsg, 'casiel_dlx', 'casiel.dlq.final');
    $this->channelMock->shouldReceive('basic_ack')->once()->with('dt789');

    // ACT
    $this->failureHandler->handle($mockMsg, $this->channelMock, 'Fallo definitivo', 789, $this->fileHandlerMock);
});

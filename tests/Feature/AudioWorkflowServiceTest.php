<?php

use app\services\AudioAnalysisService;
use app\services\AudioWorkflowService;
use app\services\FileHandlerService;
use app\services\GeminiService;
use app\services\SwordApiService;
use Mockery\MockInterface;

beforeEach(function () {
    $this->swordApiMock = Mockery::mock(SwordApiService::class);
    $this->audioAnalysisMock = Mockery::mock(AudioAnalysisService::class);
    $this->geminiApiMock = Mockery::mock(GeminiService::class);

    // Para el workflow, el FileHandler se pasa al constructor.
    $this->fileHandlerMock = Mockery::mock(FileHandlerService::class);

    $this->workflowService = new AudioWorkflowService(
        $this->swordApiMock,
        $this->audioAnalysisMock,
        $this->geminiApiMock,
        $this->fileHandlerMock
    );
});

afterEach(function () {
    Mockery::close();
});

test('el flujo de trabajo completo se ejecuta exitosamente', function () {
    // ARRANGE
    $contentId = 123;
    $mediaId = 456;
    $tempOriginalPath = "/tmp/456_original.mp3";
    $tempLightPath = "/tmp/melodic_synth_loop.mp3";

    // Mocking the entire successful chain of events
    $this->swordApiMock->shouldReceive('getContent')->once()->with($contentId, Mockery::any(), Mockery::any())
        ->andReturnUsing(fn($id, $onSuccess) => $onSuccess(['id' => $contentId, 'content_data' => ['existing' => 'data']]));

    $this->swordApiMock->shouldReceive('getMediaDetails')->once()->with($mediaId, Mockery::any(), Mockery::any())
        ->andReturnUsing(fn($id, $onSuccess) => $onSuccess(['id' => $mediaId, 'path' => 'uploads/test.mp3', 'metadata' => ['original_name' => 'test_audio.mp3']]));

    $this->fileHandlerMock->shouldReceive('createOriginalFilePath')->once()->andReturn($tempOriginalPath);

    $this->swordApiMock->shouldReceive('downloadFile')->once()->with(Mockery::any(), $tempOriginalPath, Mockery::any(), Mockery::any())
        ->andReturnUsing(fn($u, $d, $onSuccess) => $onSuccess($d));

    $this->audioAnalysisMock->shouldReceive('analyze')->once()->with($tempOriginalPath)->andReturn(['bpm' => 120]);

    $this->geminiApiMock->shouldReceive('analyzeAudio')->once()
        ->andReturnUsing(fn($p, $c, $onSuccess) => $onSuccess(['nombre_archivo_base' => 'melodic synth loop']));

    $this->fileHandlerMock->shouldReceive('createLightweightFilePath')->once()->andReturn($tempLightPath);

    $this->audioAnalysisMock->shouldReceive('generateLightweightVersion')->once()->with($tempOriginalPath, $tempLightPath)->andReturn(true);

    $this->swordApiMock->shouldReceive('uploadMedia')->once()->with($tempLightPath, Mockery::any(), Mockery::any())
        ->andReturnUsing(fn($p, $onSuccess) => $onSuccess(['id' => 999]));

    $this->swordApiMock->shouldReceive('updateContent')->once()->with($contentId, Mockery::any(), Mockery::any(), Mockery::any())
        ->andReturnUsing(fn($id, $d, $onSuccess) => $onSuccess([]));

    // ACT & ASSERT
    $this->workflowService->run(
        $contentId,
        $mediaId,
        function () {
            // This is the success case, so we just assert that we got here.
            expect(true)->toBeTrue();
        },
        fn($err) => test()->fail("onError fue llamado inesperadamente: $err")
    );
});

test('el flujo de trabajo llama a onError si el análisis técnico falla', function () {
    // ARRANGE
    $contentId = 123;
    $mediaId = 456;
    $tempOriginalPath = "/tmp/456_original.mp3";

    $this->swordApiMock->shouldReceive('getContent')->once()->andReturnUsing(fn($id, $onSuccess) => $onSuccess(['id' => $contentId, 'content_data' => []]));
    $this->swordApiMock->shouldReceive('getMediaDetails')->once()->andReturnUsing(fn($id, $onSuccess) => $onSuccess(['id' => $mediaId, 'path' => 'uploads/test.mp3', 'metadata' => ['original_name' => 'test.mp3']]));
    $this->fileHandlerMock->shouldReceive('createOriginalFilePath')->once()->andReturn($tempOriginalPath);
    $this->swordApiMock->shouldReceive('downloadFile')->once()->andReturnUsing(fn($u, $d, $onSuccess) => $onSuccess($d));

    // This is the step that fails
    $this->audioAnalysisMock->shouldReceive('analyze')->once()->with($tempOriginalPath)->andReturn(null);

    // ACT & ASSERT
    $this->workflowService->run(
        $contentId,
        $mediaId,
        fn() => test()->fail("onSuccess fue llamado inesperadamente."),
        function ($errorMessage) {
            expect($errorMessage)->toBe('El análisis técnico del audio falló.');
        }
    );
});

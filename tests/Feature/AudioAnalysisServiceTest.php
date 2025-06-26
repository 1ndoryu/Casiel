<?php

use app\services\AudioAnalysisService;
use Symfony\Component\Process\Process;

test('analyze successfully returns data from python script', function () {
    // 1. Setup
    $tempFilePath = runtime_path() . '/tmp/test_audio_analysis.mp3';
    file_put_contents($tempFilePath, 'fake-audio-data');

    $expectedData = ['bpm' => 120, 'tonalidad' => 'C', 'escala' => 'major'];

    // Mock the Process class
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(120);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(true);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(json_encode($expectedData));

    // Create a partial mock of our service to intercept process creation
    $serviceMock = Mockery::mock(AudioAnalysisService::class)->makePartial();
    $serviceMock->shouldReceive('createProcess')
        ->once()
        ->with(['python3', base_path('audio.py'), 'analyze', $tempFilePath])
        ->andReturn($mockProcess);

    // 2. Action
    $result = $serviceMock->analyze($tempFilePath);

    // 3. Assertions
    expect($result)->toBe($expectedData);

    // 4. Cleanup
    unlink($tempFilePath);
    Mockery::close();
});

test('analyze handles process failure', function () {
    // 1. Setup
    $tempFilePath = runtime_path() . '/tmp/test_audio_analysis_fail.mp3';
    file_put_contents($tempFilePath, 'fake-audio-data');

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once();
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(false);
    $mockProcess->shouldReceive('getErrorOutput')->once()->andReturn('Python script error');

    $serviceMock = Mockery::mock(AudioAnalysisService::class)->makePartial();
    $serviceMock->shouldReceive('createProcess')->once()->andReturn($mockProcess);

    // 2. Action
    $result = $serviceMock->analyze($tempFilePath);

    // 3. Assertions
    expect($result)->toBeNull();

    // 4. Cleanup
    unlink($tempFilePath);
    Mockery::close();
});

test('generateLightweightVersion successfully calls ffmpeg', function () {
    // 1. Setup
    $inputFile = runtime_path() . '/tmp/input.wav';
    $outputFile = runtime_path() . '/tmp/output.mp3';
    file_put_contents($inputFile, 'fake-wav-data');

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(180);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(true);

    $expectedCommand = ['ffmpeg', '-i', $inputFile, '-b:a', '96k', '-y', $outputFile];

    $serviceMock = Mockery::mock(AudioAnalysisService::class)->makePartial();
    $serviceMock->shouldReceive('createProcess')
        ->once()
        ->with($expectedCommand)
        ->andReturn($mockProcess);

    // 2. Action
    $result = $serviceMock->generateLightweightVersion($inputFile, $outputFile);

    // 3. Assertions
    expect($result)->toBeTrue();

    // 4. Cleanup
    unlink($inputFile);
    if (file_exists($outputFile)) {
        unlink($outputFile);
    }
    Mockery::close();
});

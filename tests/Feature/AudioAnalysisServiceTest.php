<?php

use app\services\AudioAnalysisService;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;

test('analyze successfully returns data from python script', function () {
    // 1. Setup
    $tempFilePath = runtime_path() . '/tmp/test_audio_analysis.mp3';
    file_put_contents($tempFilePath, 'fake-audio-data');
    $expectedData = ['bpm' => 120, 'tonalidad' => 'C', 'escala' => 'major'];

    // Mock the Process dependency
    /** @var Process&MockInterface $mockProcess */
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(120);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(true);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(json_encode($expectedData));

    // Create an anonymous class that extends our service to override the protected method
    $service = new class extends AudioAnalysisService
    {
        public Process $mockedProcess; // Property to hold our mock

        protected function createProcess(array $command): Process
        {
            // Override the method to return our controlled mock instead of a real process
            return $this->mockedProcess;
        }
    };
    $service->mockedProcess = $mockProcess; // Inject the mock into our test-specific class

    // 2. Action
    $result = $service->analyze($tempFilePath);

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

    /** @var Process&MockInterface $mockProcess */
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once();
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(false);
    $mockProcess->shouldReceive('getErrorOutput')->once()->andReturn('Python script error');

    // Use the same anonymous class strategy
    $service = new class extends AudioAnalysisService
    {
        public Process $mockedProcess;
        protected function createProcess(array $command): Process
        {
            return $this->mockedProcess;
        }
    };
    $service->mockedProcess = $mockProcess;

    // 2. Action
    $result = $service->analyze($tempFilePath);

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

    /** @var Process&MockInterface $mockProcess */
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(180);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(true);

    // Use the same anonymous class strategy, passing the variables needed for the assertion
    $service = new class extends AudioAnalysisService
    {
        public Process $mockedProcess;
        public string $expectedInput;
        public string $expectedOutput;

        protected function createProcess(array $command): Process
        {
            // Assert that the correct ffmpeg command is being built
            $expectedCommand = ['ffmpeg', '-i', $this->expectedInput, '-b:a', '96k', '-y', $this->expectedOutput];
            expect($command)->toBe($expectedCommand);
            return $this->mockedProcess;
        }
    };
    $service->mockedProcess = $mockProcess;
    // Set the public properties on our anonymous class instance
    $service->expectedInput = $inputFile;
    $service->expectedOutput = $outputFile;

    // 2. Action
    $result = $service->generateLightweightVersion($inputFile, $outputFile);

    // 3. Assertions
    expect($result)->toBeTrue();

    // 4. Cleanup
    unlink($inputFile);
    if (file_exists($outputFile)) {
        unlink($outputFile);
    }
    Mockery::close();
});

test('generateLightweightVersion handles ffmpeg failure', function () {
    // 1. Setup
    $inputFile = runtime_path() . '/tmp/input.wav';
    $outputFile = runtime_path() . '/tmp/output.mp3';
    file_put_contents($inputFile, 'fake-wav-data');

    /** @var Process&MockInterface $mockProcess */
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(180);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(false); // Simulate failure
    $mockProcess->shouldReceive('getErrorOutput')->once()->andReturn('ffmpeg error');

    // Use the same anonymous class strategy
    $service = new class extends AudioAnalysisService {
        public Process $mockedProcess;
        protected function createProcess(array $command): Process
        {
            return $this->mockedProcess;
        }
    };
    $service->mockedProcess = $mockProcess;

    // 2. Action
    $result = $service->generateLightweightVersion($inputFile, $outputFile);

    // 3. Assertions
    expect($result)->toBeFalse();

    // 4. Cleanup
    unlink($inputFile);
    if (file_exists($outputFile)) {
        unlink($outputFile);
    }
    Mockery::close();
});
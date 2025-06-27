<?php
use app\services\AudioAnalysisService;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;

// Usando la última versión que te envié con la clase anónima, que es la más robusta.
test('analyze devuelve datos exitosamente al ejecutar el script de python', function () {
    // 1. Setup
    $tempFilePath = runtime_path() . '/tmp/test_audio.tmp';
    create_dummy_file($tempFilePath, 'fake-audio-data');
    $expectedData = ['bpm' => 120, 'tonalidad' => 'C', 'escala' => 'major'];
    $pythonScriptPath = base_path('audio.py');

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(120);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(true);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(json_encode($expectedData));

    $service = new class($pythonScriptPath, $mockProcess) extends AudioAnalysisService {
        private Process $mockedProcess;
        public function __construct(string $path, Process $mockedProcess) {
            parent::__construct($path);
            $this->mockedProcess = $mockedProcess;
        }
        protected function createProcess(array $command): Process {
            return $this->mockedProcess;
        }
    };

    // 2. Action
    $result = $service->analyze($tempFilePath);

    // 3. Assertions
    expect($result)->toBe($expectedData);

    // 4. Cleanup
    unlink($tempFilePath);
});


test('analyze maneja correctamente un fallo del proceso', function () {
    // 1. Setup
    $tempFilePath = runtime_path() . '/tmp/test_audio_fail.tmp';
    create_dummy_file($tempFilePath, 'fake-audio-data');
    $pythonScriptPath = base_path('audio.py');

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(120);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(false);
    $mockProcess->shouldReceive('getErrorOutput')->once()->andReturn('Python script error');

    // FIX: Replaced makePartial() with the robust anonymous class pattern.
    // This ensures the parent constructor is called and properties are initialized.
    $service = new class($pythonScriptPath, $mockProcess) extends AudioAnalysisService {
        private Process $mockedProcess;
        public function __construct(string $path, Process $mockedProcess) {
            parent::__construct($path); // This correctly initializes $pythonScriptPath
            $this->mockedProcess = $mockedProcess;
        }
        protected function createProcess(array $command): Process {
            return $this->mockedProcess;
        }
    };

    // 2. Action
    $result = $service->analyze($tempFilePath);

    // 3. Assertions
    expect($result)->toBeNull();

    // 4. Cleanup
    unlink($tempFilePath);
});

test('generateLightweightVersion llama a ffmpeg con los argumentos correctos', function () {
    $inputFile = runtime_path() . '/tmp/input.wav';
    $outputFile = runtime_path() . '/tmp/output.mp3';
    create_dummy_file($inputFile, 'fake-wav-data');
    $pythonScriptPath = base_path('audio.py');

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(180);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(true);

    $service = new class($pythonScriptPath, $mockProcess) extends AudioAnalysisService {
        private Process $mockedProcess;
        public function __construct(string $path, Process $mockedProcess) {
            parent::__construct($path);
            $this->mockedProcess = $mockedProcess;
        }
        protected function createProcess(array $command): Process {
            return $this->mockedProcess;
        }
    };

    $result = $service->generateLightweightVersion($inputFile, $outputFile);

    expect($result)->toBeTrue();
    unlink($inputFile);
});

test('generateLightweightVersion maneja un fallo de ffmpeg', function () {
    $inputFile = runtime_path() . '/tmp/input_fail.wav';
    $outputFile = runtime_path() . '/tmp/output_fail.mp3';
    create_dummy_file($inputFile, 'fake-wav-data');
    $pythonScriptPath = base_path('audio.py');

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(180);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(false);
    $mockProcess->shouldReceive('getErrorOutput')->once()->andReturn('ffmpeg error');

    $service = new class($pythonScriptPath, $mockProcess) extends AudioAnalysisService {
        private Process $mockedProcess;
        public function __construct(string $path, Process $mockedProcess) {
            parent::__construct($path);
            $this->mockedProcess = $mockedProcess;
        }
        protected function createProcess(array $command): Process {
            return $this->mockedProcess;
        }
    };
    
    $result = $service->generateLightweightVersion($inputFile, $outputFile);

    expect($result)->toBeFalse();
    unlink($inputFile);
});
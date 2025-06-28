<?php
use app\services\AudioAnalysisService;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;

// Helper to create a dummy file for tests if it doesn't exist in Pest.php
if (!function_exists('create_dummy_file')) {
    function create_dummy_file(string $path, string $content = 'dummy_content'): void {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }
}

test('analyze devuelve datos exitosamente al ejecutar el script de python', function () {
    // 1. Setup
    $tempFilePath = runtime_path() . '/tmp/test_audio.tmp';
    create_dummy_file($tempFilePath, 'fake-audio-data');
    $expectedData = ['bpm' => 120, 'tonalidad' => 'C', 'escala' => 'major'];
    $pythonScriptPath = base_path('audio.py');
    $pythonCommand = 'python_test'; // Dummy command for testing

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(120);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(true);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(json_encode($expectedData));

    // UPDATE: The constructor signature has changed.
    $service = new class($pythonCommand, $pythonScriptPath, $mockProcess) extends AudioAnalysisService {
        private Process $mockedProcess;
        public function __construct(string $cmd, string $path, Process $mockedProcess) {
            parent::__construct($cmd, $path);
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
    $pythonCommand = 'python_test';

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(120);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(false);
    $mockProcess->shouldReceive('getCommandLine')->once()->andReturn('python_test audio.py analyze ...');
    $mockProcess->shouldReceive('getErrorOutput')->once()->andReturn('Python script error');
    
    // UPDATE: The constructor signature has changed.
    $service = new class($pythonCommand, $pythonScriptPath, $mockProcess) extends AudioAnalysisService {
        private Process $mockedProcess;
        public function __construct(string $cmd, string $path, Process $mockedProcess) {
            parent::__construct($cmd, $path);
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
    $pythonCommand = 'python_test';

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(180);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(true);

    // UPDATE: The constructor signature has changed.
    $service = new class($pythonCommand, $pythonScriptPath, $mockProcess) extends AudioAnalysisService {
        private Process $mockedProcess;
        public function __construct(string $cmd, string $path, Process $mockedProcess) {
            parent::__construct($cmd, $path);
            $this->mockedProcess = $mockedProcess;
        }
        protected function createProcess(array $command): Process {
            // This test focuses on ffmpeg, ensure the command matches
            expect($command[0])->toBe('ffmpeg');
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
    $pythonCommand = 'python_test';

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setTimeout')->once()->with(180);
    $mockProcess->shouldReceive('run')->once();
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(false);
    $mockProcess->shouldReceive('getErrorOutput')->once()->andReturn('ffmpeg error');

    // UPDATE: The constructor signature has changed.
    $service = new class($pythonCommand, $pythonScriptPath, $mockProcess) extends AudioAnalysisService {
        private Process $mockedProcess;
        public function __construct(string $cmd, string $path, Process $mockedProcess) {
            parent::__construct($cmd, $path);
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
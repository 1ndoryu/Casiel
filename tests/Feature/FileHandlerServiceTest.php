<?php

use app\services\FileHandlerService;

// Helper para limpiar el directorio de prueba
function cleanupTestDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    rmdir($dir);
}

$testTempDir = runtime_path() . '/tmp/file_handler_test';

beforeEach(function () use ($testTempDir) {
    // Asegurarse que el directorio de prueba está limpio antes de cada test
    cleanupTestDir($testTempDir);
    mkdir($testTempDir, 0777, true);
    $this->fileHandler = new FileHandlerService($testTempDir);
});

afterAll(function () use ($testTempDir) {
    // Limpiar todo al final
    cleanupTestDir($testTempDir);
});

test('constructor crea el directorio temporal si no existe', function () use ($testTempDir) {
    // 1. Arrange: delete the directory first
    rmdir($testTempDir);
    expect(is_dir($testTempDir))->toBeFalse();

    // 2. Action: create a new instance
    new FileHandlerService($testTempDir);

    // 3. Assert: the directory should now exist
    expect(is_dir($testTempDir))->toBeTrue();
});

test('createOriginalFilePath genera la ruta correcta y la registra para limpieza', function () use ($testTempDir) {
    // 1. Action
    $path = $this->fileHandler->createOriginalFilePath(123, 'my-audio.wav');

    // 2. Assert Path
    expect($path)->toBe("{$testTempDir}/123_original.wav");

    // 3. Assert Cleanup Registration
    // Create a dummy file and check if cleanup removes it
    file_put_contents($path, 'test');
    expect(file_exists($path))->toBeTrue();

    $this->fileHandler->cleanupFiles();
    expect(file_exists($path))->toBeFalse();
});

test('createLightweightFilePath genera la ruta correcta y la registra para limpieza', function () use ($testTempDir) {
    // 1. Action
    $path = $this->fileHandler->createLightweightFilePath('A Cool Song Name');

    // 2. Assert Path (spaces and slashes should be replaced)
    expect($path)->toBe("{$testTempDir}/A_Cool_Song_Name.mp3");

    // 3. Assert Cleanup Registration
    file_put_contents($path, 'test');
    expect(file_exists($path))->toBeTrue();

    $this->fileHandler->cleanupFiles();
    expect(file_exists($path))->toBeFalse();
});

test('cleanupFiles elimina múltiples archivos registrados', function () {
    // 1. Arrange
    $path1 = $this->fileHandler->createOriginalFilePath(1, 'one.mp3');
    $path2 = $this->fileHandler->createLightweightFilePath('two');
    $path3 = $this->fileHandler->createOriginalFilePath(3, 'three.ogg');

    file_put_contents($path1, '1');
    file_put_contents($path2, '2');
    file_put_contents($path3, '3');

    expect(file_exists($path1))->toBeTrue();
    expect(file_exists($path2))->toBeTrue();
    expect(file_exists($path3))->toBeTrue();

    // 2. Action
    $this->fileHandler->cleanupFiles();

    // 3. Assert
    expect(file_exists($path1))->toBeFalse();
    expect(file_exists($path2))->toBeFalse();
    expect(file_exists($path3))->toBeFalse();
});

test('cleanupFiles no lanza error si un archivo ya no existe', function () {
    // 1. Arrange
    $path = $this->fileHandler->createOriginalFilePath(99, 'nonexistent.tmp');
    expect(file_exists($path))->toBeFalse();

    // 2. Action & Assert
    // We just expect this to run without throwing any exceptions.
    $this->fileHandler->cleanupFiles();
    expect(true)->toBeTrue(); // Assertion to confirm the test ran
});

<?php

namespace app\services;

use Throwable;

/**
 * Handles all temporary file operations for audio processing jobs.
 * Manages creation, tracking, and cleanup of files.
 */
class FileHandlerService
{
    private string $baseTempDir;
    private array $filesToDelete = [];

    public function __construct(?string $baseTempDir = null)
    {
        $this->baseTempDir = $baseTempDir ?: runtime_path() . '/tmp/audio_processing';
        if (!is_dir($this->baseTempDir)) {
            mkdir($this->baseTempDir, 0777, true);
        }
    }

    /**
     * Creates a temporary file path for a new audio file.
     *
     * @param int $mediaId The unique ID of the media.
     * @param string $originalFilename The original filename to get the extension from.
     * @return string The full path to the temporary file.
     */
    public function createOriginalFilePath(int $mediaId, string $originalFilename): string
    {
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'tmp';
        $localPath = "{$this->baseTempDir}/{$mediaId}_original.{$extension}";
        $this->filesToDelete[] = $localPath;
        casiel_log('audio_processor', "Registrado archivo temporal para original: " . basename($localPath), [], 'debug');
        return $localPath;
    }

    /**
     * Creates a temporary file path for the lightweight version.
     *
     * @param string $baseName A descriptive base name for the file.
     * @return string The full path to the temporary file.
     */
    public function createLightweightFilePath(string $baseName): string
    {
        $safeBaseName = str_replace(['/', '\\', ' '], '_', $baseName);
        $localPath = "{$this->baseTempDir}/{$safeBaseName}.mp3";
        $this->filesToDelete[] = $localPath;
        casiel_log('audio_processor', "Registrado archivo temporal para versión ligera: " . basename($localPath), [], 'debug');
        return $localPath;
    }

    /**
     * Cleans up all tracked temporary files.
     */
    public function cleanupFiles(): void
    {
        casiel_log('audio_processor', "Iniciando limpieza de " . count($this->filesToDelete) . " archivos temporales.", [], 'debug');
        foreach ($this->filesToDelete as $file) {
            try {
                if (file_exists($file)) {
                    unlink($file);
                    casiel_log('audio_processor', "Cleanup: Archivo temporal eliminado: " . basename($file), [], 'debug');
                }
            } catch (Throwable $e) {
                casiel_log('audio_processor', "Cleanup: No se pudo eliminar el archivo temporal " . basename($file), ['error' => $e->getMessage()], 'warning');
            }
        }
        $this->filesToDelete = [];
    }
}

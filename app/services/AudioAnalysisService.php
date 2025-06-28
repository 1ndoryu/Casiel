<?php

namespace app\services;

use Symfony\Component\Process\Process;
use Throwable;

class AudioAnalysisService
{
    private string $pythonCommand;
    private string $pythonScriptPath;
    private string $ffmpegPath;

    public function __construct(string $pythonCommand, string $pythonScriptPath, string $ffmpegPath)
    {
        $this->pythonCommand = $pythonCommand;
        $this->pythonScriptPath = $pythonScriptPath;
        $this->ffmpegPath = $ffmpegPath;
    }

    /**
     * Creates a new Process instance.
     * This method is protected to allow mocking during tests.
     *
     * @param array $command
     * @return Process
     */
    protected function createProcess(array $command): Process
    {
        return new Process($command);
    }

    /**
     * Analyzes an audio file to get technical metadata (BPM, key, etc.).
     *
     * @param string $filePath The absolute path to the audio file.
     * @return array|null The analysis result or null on failure.
     */
    public function analyze(string $filePath): ?array
    {
        casiel_log('audio_processor', "Iniciando análisis técnico para: " . basename($filePath));

        if (!file_exists($filePath)) {
            casiel_log('audio_processor', "El archivo de audio no existe en la ruta: {$filePath}", [], 'error');
            return null;
        }

        try {
            // Use the injected python command
            $process = $this->createProcess([$this->pythonCommand, $this->pythonScriptPath, 'analyze', $filePath]);
            $process->setTimeout(120); // 2 minutes timeout for analysis
            $process->run();

            if (!$process->isSuccessful()) {
                casiel_log('audio_processor', 'Error al ejecutar el script de análisis de audio.', [
                    'command' => $process->getCommandLine(),
                    'error_output' => $process->getErrorOutput()
                ], 'error');
                return null;
            }

            $output = $process->getOutput();
            $data = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                casiel_log('audio_processor', 'No se pudo decodificar el JSON del script de análisis.', [
                    'output' => $output
                ], 'error');
                return null;
            }

            casiel_log('audio_processor', 'Análisis técnico completado.', ['metadata' => $data]);
            return $data;
        } catch (Throwable $e) {
            casiel_log('audio_processor', 'Excepción durante el análisis técnico de audio.', [
                'error' => $e->getMessage()
            ], 'error');
            return null;
        }
    }

    /**
     * Generates a perceptual hash for an audio file.
     *
     * @param string $filePath The absolute path to the audio file.
     * @return string|null The generated hash or null on failure.
     */
    public function generatePerceptualHash(string $filePath): ?string
    {
        casiel_log('audio_processor', "Generando hash perceptual para: " . basename($filePath));

        if (!file_exists($filePath)) {
            casiel_log('audio_processor', "El archivo para generar hash no existe: {$filePath}", [], 'error');
            return null;
        }

        try {
            $process = $this->createProcess([$this->pythonCommand, $this->pythonScriptPath, 'hash', $filePath]);
            $process->setTimeout(60); // 1 minute timeout for hashing
            $process->run();

            if (!$process->isSuccessful()) {
                casiel_log('audio_processor', 'Error al ejecutar el script de generación de hash.', [
                    'command' => $process->getCommandLine(),
                    'error_output' => $process->getErrorOutput()
                ], 'error');
                return null;
            }

            $output = $process->getOutput();
            $data = json_decode($output, true);

            // ==========================================================
            // INICIO DE LA CORRECCIÓN
            // ==========================================================
            // La condición lógica correcta es verificar que NO haya error y que la clave exista.
            if (json_last_error() === JSON_ERROR_NONE && !empty($data['audio_hash'])) {
                casiel_log('audio_processor', 'Hash perceptual generado exitosamente.', ['hash' => $data['audio_hash']]);
                return $data['audio_hash'];
            }
            // ==========================================================
            // FIN DE LA CORRECCIÓN
            // ==========================================================
            
            casiel_log('audio_processor', 'No se pudo decodificar el JSON o falta la clave "audio_hash" en la respuesta del script.', [
                'output' => $output
            ], 'error');
            return null;

        } catch (Throwable $e) {
            casiel_log('audio_processor', 'Excepción durante la generación del hash perceptual.', [
                'error' => $e->getMessage()
            ], 'error');
            return null;
        }
    }

    /**
     * Generates a lightweight version of an audio file using ffmpeg.
     *
     * @param string $inputPath The absolute path to the original audio file.
     * @param string $outputPath The absolute path where the lightweight version will be saved.
     * @return bool True on success, false on failure.
     */
    public function generateLightweightVersion(string $inputPath, string $outputPath): bool
    {
        casiel_log('audio_processor', "Generando versión ligera para: " . basename($inputPath));

        try {
            // Use the configured ffmpeg path
            $command = [
                $this->ffmpegPath,
                '-i',
                $inputPath,
                '-b:a',
                '96k', // Set audio bitrate to 96kbps
                '-y', // Overwrite output file if it exists
                $outputPath
            ];

            $process = $this->createProcess($command);
            $process->setTimeout(180); // 3 minutes timeout for conversion
            $process->run();

            if (!$process->isSuccessful()) {
                casiel_log('audio_processor', 'Error al generar la versión ligera del audio.', [
                    'command' => $process->getCommandLine(),
                    'error_output' => $process->getErrorOutput()
                ], 'error');
                return false;
            }

            casiel_log('audio_processor', "Versión ligera creada en: {$outputPath}");
            return true;
        } catch (Throwable $e) {
            casiel_log('audio_processor', 'Excepción durante la generación de la versión ligera.', [
                'error' => $e->getMessage()
            ], 'error');
            return false;
        }
    }
}
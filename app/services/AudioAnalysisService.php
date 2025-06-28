<?php
namespace app\services;
use Symfony\Component\Process\Process;
use Throwable;

class AudioAnalysisService
{
    private string $pythonCommand;
    private string $pythonScriptPath;

    public function __construct(string $pythonCommand, string $pythonScriptPath)
    {
        $this->pythonCommand = $pythonCommand;
        $this->pythonScriptPath = $pythonScriptPath;
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
            // Use ffmpeg to convert to a 96kbps MP3
            $command = [
                'ffmpeg',
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
<?php

namespace app\utils;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use RuntimeException;

class AudioUtil
{
    private string $pythonPath;
    private string $scriptPath;
    private string $ffmpegPath;
    private string $tempDir;

    public function __construct()
    {
        $config = config('casiel.audio_util');
        $this->pythonPath = $config['python_path'];
        $this->scriptPath = $config['script_path'];
        $this->ffmpegPath = $config['ffmpeg_path'];
        $this->tempDir = $config['temp_dir'];

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    public function procesarDesdeUrl(string $urlAudio, string $nombreOriginal): ?array
    {
        casielLog("Iniciando procesamiento de audio para: $urlAudio");
        $rutaTemporalOriginal = null;

        try {
            // 1. Descargar el archivo
            $rutaTemporalOriginal = $this->descargarArchivo($urlAudio);

            // 2. Analizar con Python para obtener datos técnicos (BPM, Tonalidad)
            $metadataTecnica = $this->ejecutarAnalisisPython($rutaTemporalOriginal);

            // 3. Generar nuevo nombre y ruta de destino para el MP3
            $nuevoNombreBase = $this->generarNuevoNombre($nombreOriginal, $metadataTecnica);
            $rutaMp3Ligero = $this->tempDir . '/' . $nuevoNombreBase . '.mp3';

            // 4. Convertir a MP3 ligero con FFMPEG
            $this->convertirAudio($rutaTemporalOriginal, $rutaMp3Ligero);

            casielLog("Procesamiento de audio completado para " . basename($rutaMp3Ligero));

            return [
                'ruta_mp3' => $rutaMp3Ligero,
                'nombre_mp3' => $nuevoNombreBase . '.mp3',
                'nombre_original' => $nombreOriginal,
                'metadata_tecnica' => $metadataTecnica,
            ];
        } finally {
            // 5. Limpiar siempre el archivo original descargado
            if ($rutaTemporalOriginal && file_exists($rutaTemporalOriginal)) {
                unlink($rutaTemporalOriginal);
            }
        }
    }

    private function descargarArchivo(string $url): string
    {
        $nombreArchivo = basename(parse_url($url, PHP_URL_PATH));
        $rutaDestino = $this->tempDir . '/' . uniqid('orig_', true) . '_' . $nombreArchivo;

        $contenido = @file_get_contents($url);
        if ($contenido === false) {
            throw new RuntimeException("No se pudo descargar el archivo de audio: $url");
        }
        file_put_contents($rutaDestino, $contenido);
        casielLog("Audio descargado en: $rutaDestino");
        return $rutaDestino;
    }

    private function ejecutarAnalisisPython(string $rutaAudio): array
    {
        $proceso = new Process([$this->pythonPath, $this->scriptPath, $rutaAudio]);
        try {
            $proceso->mustRun();
            $salidaJson = $proceso->getOutput();
            casielLog("Análisis de Python exitoso.", ['output' => $salidaJson]);
            return json_decode($salidaJson, true) ?? [];
        } catch (ProcessFailedException $e) {
            $error = "El script de Python falló: " . $e->getMessage() . " | STDERR: " . $proceso->getErrorOutput();
            casielLog($error, [], 'error');
            // Lanzamos una excepción detallada en lugar de devolver null
            throw new RuntimeException($error);
        }
    }

    private function convertirAudio(string $origen, string $destino): void
    {
        $comando = [$this->ffmpegPath, '-y', '-i', $origen, '-b:a', '128k', $destino];
        $proceso = new Process($comando);
        try {
            $proceso->mustRun();
            casielLog("Conversión a MP3 ligero exitosa: " . basename($destino));
        } catch (ProcessFailedException $e) {
            $error = "La conversión con FFMPEG falló: " . $e->getMessage() . " | STDERR: " . $proceso->getErrorOutput();
            casielLog($error, [], 'error');
            // Lanzamos una excepción detallada en lugar de devolver false
            throw new RuntimeException($error);
        }
    }

    private function generarNuevoNombre(string $nombreOriginal, array $metadataTecnica): string
    {
        // Usa la tonalidad si está disponible, si no 'sample'
        $keyword = $metadataTecnica['tonalidad'] ?? 'sample';
        $randomDigits = substr(str_shuffle("0123456789"), 0, 5);
        $keywordLimpio = preg_replace('/[^a-z0-9]+/', '_', strtolower($keyword));
        return "kamples_{$keywordLimpio}_{$randomDigits}";
    }
}

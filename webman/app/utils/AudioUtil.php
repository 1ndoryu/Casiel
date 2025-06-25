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
    private int $pythonTimeout;
    private string $storageOriginals;
    private string $storagePublicos;
    private string $publicUrlBase;

    public function __construct()
    {
        $config = config('casiel.audio_util');
        $this->pythonPath = $config['python_path'];
        $this->scriptPath = $config['script_path'];
        $this->ffmpegPath = $config['ffmpeg_path'];
        $this->tempDir = $config['temp_dir'];
        $this->pythonTimeout = $config['python_timeout'];
        $this->storageOriginals = $config['storage_originals'];
        $this->storagePublicos = $config['storage_publicos'];
        $this->publicUrlBase = $config['public_url_base'];

        // Asegurarse de que los directorios existen
        if (!is_dir($this->tempDir)) mkdir($this->tempDir, 0777, true);
        if (!is_dir($this->storageOriginals)) mkdir($this->storageOriginals, 0777, true);
        if (!is_dir($this->storagePublicos)) mkdir($this->storagePublicos, 0777, true);
    }

    public function procesarDesdeUrl(string $urlAudio, string $nombreArchivoBase, string $extensionOriginal): ?array
    {
        casielLog("Iniciando procesamiento de audio para: $urlAudio");
        $rutaTemporalOriginal = null;
        $rutaMp3Ligero = null;

        try {
            // 1. Descargar el archivo original a una ubicación temporal
            $rutaTemporalOriginal = $this->descargarArchivo($urlAudio);

            // 2. Analizar con Python para obtener datos técnicos (BPM, Tonalidad)
            $metadataTecnica = $this->ejecutarAnalisisPython($rutaTemporalOriginal);

            // 3. Definir rutas de destino para los archivos finales
            $nombreLigero = $nombreArchivoBase . '_ligero.mp3';
            $nombreOriginalFinal = $nombreArchivoBase . '_original.' . $extensionOriginal;

            $rutaFinalLigero = $this->storagePublicos . '/' . $nombreLigero;
            $rutaFinalOriginal = $this->storageOriginals . '/' . $nombreOriginalFinal;

            // 4. Convertir a MP3 ligero con FFMPEG y guardarlo en su destino final
            $this->convertirAudio($rutaTemporalOriginal, $rutaFinalLigero);

            // 5. Mover el archivo original descargado a su destino final
            rename($rutaTemporalOriginal, $rutaFinalOriginal);
            $rutaTemporalOriginal = null; // Para evitar que el finally lo borre

            casielLog("Procesamiento y almacenamiento de audio completado.");

            return [
                'ruta_ligero'       => $rutaFinalLigero,
                'ruta_original'     => $rutaFinalOriginal,
                'nombre_ligero'     => $nombreLigero,
                'nombre_original'   => $nombreOriginalFinal,
                'metadata_tecnica'  => $metadataTecnica,
                'url_stream'        => rtrim($this->publicUrlBase, '/') . '/' . $nombreLigero,
            ];
        } catch (\Throwable $e) {
            casielLog("Error fatal en procesarDesdeUrl: " . $e->getMessage(), [], 'error');
            // Limpiar el archivo ligero si se creó antes del fallo
            if ($rutaMp3Ligero && file_exists($rutaMp3Ligero)) {
                unlink($rutaMp3Ligero);
            }
            return null; // Devolver null para indicar el fallo
        } finally {
            // Limpiar siempre el archivo original temporal si aún existe
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
        $proceso->setTimeout($this->pythonTimeout);

        try {
            $proceso->mustRun();
            $salidaJson = $proceso->getOutput();
            casielLog("Análisis de Python exitoso.", ['output' => $salidaJson]);
            return json_decode($salidaJson, true) ?? [];
        } catch (ProcessFailedException $e) {
            $error = "El script de Python falló: " . $e->getMessage() . " | STDERR: " . $proceso->getErrorOutput();
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
            throw new RuntimeException($error);
        }
    }
}

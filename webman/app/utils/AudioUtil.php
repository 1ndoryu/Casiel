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

        if (!is_dir($this->tempDir)) mkdir($this->tempDir, 0777, true);
        if (!is_dir($this->storageOriginals)) mkdir($this->storageOriginals, 0777, true);
        if (!is_dir($this->storagePublicos)) mkdir($this->storagePublicos, 0777, true);
    }

    public function descargarAudio(string $url): string
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

    public function crearVersionLigeraTemporal(string $rutaAudioOriginal): string
    {
        $rutaDestino = $this->tempDir . '/' . uniqid('light_', true) . '.mp3';
        $this->convertirAudio($rutaAudioOriginal, $rutaDestino, '128k');
        casielLog("Versión ligera temporal creada en: $rutaDestino");
        return $rutaDestino;
    }

    public function ejecutarAnalisisPython(string $rutaAudio): array
    {
        $proceso = new Process([$this->pythonPath, $this->scriptPath, 'analyze', $rutaAudio]);
        $proceso->setTimeout($this->pythonTimeout);

        try {
            $proceso->mustRun();
            $salidaJson = $proceso->getOutput();
            casielLog("Análisis de Python exitoso.", ['output' => $salidaJson]);
            return json_decode($salidaJson, true) ?? [];
        } catch (ProcessFailedException $e) {
            $error = "El script de Python (analyze) falló: " . $e->getMessage() . " | STDERR: " . $proceso->getErrorOutput();
            throw new RuntimeException($error);
        }
    }

    public function generarHashPerceptual(string $rutaAudio): ?string
    {
        $proceso = new Process([$this->pythonPath, $this->scriptPath, 'hash', $rutaAudio]);
        $proceso->setTimeout($this->pythonTimeout);

        try {
            $proceso->mustRun();
            $salidaJson = $proceso->getOutput();
            $resultado = json_decode($salidaJson, true);
            if (isset($resultado['audio_hash'])) {
                casielLog("Hash perceptual generado exitosamente.", ['hash' => $resultado['audio_hash']]);
                return $resultado['audio_hash'];
            }
            casielLog("El script de hash no devolvió un hash.", ['output' => $salidaJson], 'warning');
            return null;
        } catch (ProcessFailedException $e) {
            $error = "El script de Python (hash) falló: " . $e->getMessage() . " | STDERR: " . $proceso->getErrorOutput();
            throw new RuntimeException($error);
        }
    }
    
    public function guardarArchivosPermanentes(string $rutaTemporalOriginal, string $rutaTemporalLigero, string $nombreArchivoBaseFinal, string $extensionOriginal): array
    {
        // Limpiamos el nombre base para que sea seguro para un nombre de archivo
        $nombreBaseSeguro = preg_replace('/[\\\\\/:"*?<>|]/', '', $nombreArchivoBaseFinal);
        
        $nombreLigeroFinal = $nombreBaseSeguro . '_ligero.mp3';
        $nombreOriginalFinal = $nombreBaseSeguro . '_original.' . $extensionOriginal;

        $rutaFinalLigero = $this->storagePublicos . '/' . $nombreLigeroFinal;
        $rutaFinalOriginal = $this->storageOriginals . '/' . $nombreOriginalFinal;

        // Mover los archivos a su destino final (más eficiente que copiar)
        rename($rutaTemporalLigero, $rutaFinalLigero);
        rename($rutaTemporalOriginal, $rutaFinalOriginal);

        casielLog("Archivos movidos a su almacenamiento permanente.");

        return [
            'ruta_ligero'     => $rutaFinalLigero,
            'ruta_original'   => $rutaFinalOriginal,
            'nombre_ligero'   => $nombreLigeroFinal,
            'nombre_original' => $nombreOriginalFinal,
            'url_stream'      => rtrim($this->publicUrlBase, '/') . '/' . rawurlencode($nombreLigeroFinal),
        ];
    }
    
    public function limpiarTemporal(array $rutas)
    {
        foreach($rutas as $ruta) {
            if ($ruta && file_exists($ruta)) {
                unlink($ruta);
            }
        }
    }

    private function convertirAudio(string $origen, string $destino, string $bitrate = '128k'): void
    {
        $comando = [$this->ffmpegPath, '-y', '-i', $origen, '-b:a', $bitrate, $destino];
        $proceso = new Process($comando);
        try {
            $proceso->mustRun();
        } catch (ProcessFailedException $e) {
            $error = "La conversión con FFMPEG falló: " . $e->getMessage() . " | STDERR: " . $proceso->getErrorOutput();
            throw new RuntimeException($error);
        }
    }
}
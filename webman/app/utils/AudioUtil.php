<?php

namespace app\utils;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

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

        // Asegurarse de que el directorio temporal exista
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    /**
     * Procesa un archivo de audio desde una URL.
     * @param string $urlAudio URL del archivo de audio original.
     * @param string $nombreOriginal Nombre del archivo original para usar como base.
     * @return array|null Un array con las rutas y metadata, o null si falla.
     */
    public function procesarDesdeUrl(string $urlAudio, string $nombreOriginal): ?array
    {
        casielLog("Iniciando procesamiento de audio para: $urlAudio");

        // 1. Descargar el archivo
        $rutaTemporalOriginal = $this->descargarArchivo($urlAudio);
        if (!$rutaTemporalOriginal) return null;

        // 2. Analizar con Python para obtener datos técnicos (BPM, Tonalidad)
        $metadataTecnica = $this->ejecutarAnalisisPython($rutaTemporalOriginal);
        if (!$metadataTecnica) {
            casielLog("Fallo en el análisis con Python.", [], 'warning');
            // No es un error fatal, podríamos continuar sin estos datos.
            $metadataTecnica = [];
        }

        // 3. Generar nuevo nombre de archivo
        $instrumento = $metadataTecnica['instrumento_detectado'] ?? 'sample'; // Usar 'sample' si no se detecta
        $nuevoNombreBase = $this->generarNuevoNombre($instrumento, $nombreOriginal);
        $rutaMp3Ligero = $this->tempDir . '/' . $nuevoNombreBase . '.mp3';

        // 4. Convertir a MP3 ligero con FFMPEG
        $exitoConversion = $this->convertirAudio($rutaTemporalOriginal, $rutaMp3Ligero);
        if (!$exitoConversion) {
            unlink($rutaTemporalOriginal); // Limpieza
            return null;
        }

        // 5. Limpiar archivo original descargado
        unlink($rutaTemporalOriginal);

        casielLog("Procesamiento de audio completado para " . basename($rutaMp3Ligero));

        return [
            'ruta_mp3' => $rutaMp3Ligero,
            'nombre_mp3' => $nuevoNombreBase . '.mp3',
            'nombre_original' => $nombreOriginal,
            'metadata_tecnica' => $metadataTecnica,
        ];
    }

    private function descargarArchivo(string $url): ?string
    {
        $nombreArchivo = basename($url);
        $rutaDestino = $this->tempDir . '/' . uniqid() . '_' . $nombreArchivo;

        $contenido = file_get_contents($url);
        if ($contenido === false) {
            casielLog("No se pudo descargar el archivo de audio: $url", [], 'error');
            return null;
        }
        file_put_contents($rutaDestino, $contenido);
        casielLog("Audio descargado en: $rutaDestino");
        return $rutaDestino;
    }

    private function ejecutarAnalisisPython(string $rutaAudio): ?array
    {
        $proceso = new Process([$this->pythonPath, $this->scriptPath, $rutaAudio]);
        try {
            $proceso->mustRun();
            $salida = $proceso->getOutput();
            // La salida de audio.py puede tener logs, buscamos solo la línea del JSON
            $lineas = explode("\n", trim($salida));
            $jsonLinea = end($lineas); // Asumimos que el JSON es la última línea
            return json_decode($jsonLinea, true);
        } catch (ProcessFailedException $exception) {
            casielLog("El script de Python falló.", [
                'error' => $exception->getMessage(),
                'stdout' => $proceso->getOutput(),
                'stderr' => $proceso->getErrorOutput()
            ], 'error');
            return null;
        }
    }

    private function convertirAudio(string $origen, string $destino): bool
    {
        $comando = [
            $this->ffmpegPath,
            '-i',
            $origen,
            '-b:a',
            '128k',
            $destino
        ];

        $proceso = new Process($comando);
        try {
            $proceso->mustRun();
            casielLog("Conversión a MP3 ligero exitosa: " . basename($destino));
            return true;
        } catch (ProcessFailedException $exception) {
            casielLog("La conversión con FFMPEG falló.", ['error' => $exception->getMessage()], 'error');
            return false;
        }
    }

    private function generarNuevoNombre(string $instrumento, string $nombreOriginal): string
    {
        // Regla: si el nombre original contiene '2upra', se usa 'kamples'
        $keyword = strpos(strtolower($nombreOriginal), '2upra') !== false ? 'kamples' : strtolower($instrumento);
        $randomDigits = substr(str_shuffle("0123456789"), 0, 5);

        // Limpiamos el nombre del instrumento para que sea URL-friendly
        $keywordLimpio = preg_replace('/[^a-z0-9]+/', '_', strtolower($keyword));

        return $keywordLimpio . '_kamples_' . $randomDigits;
    }
}

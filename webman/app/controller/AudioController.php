<?php

namespace app\controller;

use support\Request;
use support\Response;

class AudioController
{
    /**
     * Sirve un archivo de audio ligero para streaming.
     */
    public function stream(Request $request, $file)
    {
        $config = config('casiel.audio_util');
        $publicPath = $config['storage_publicos'];

        // Validar el nombre del archivo para evitar ataques de directory traversal
        if (basename($file) !== $file) {
            return new Response(400, [], 'Invalid filename');
        }

        $filePath = $publicPath . '/' . $file;

        if (!file_exists($filePath)) {
            return new Response(404, [], 'File not found');
        }

        // Devolver el archivo. Webman/Workerman gestiona eficientemente los encabezados.
        return response()->file($filePath);
    }

    /**
     * (PENDIENTE) Sirve un archivo de audio original protegido.
     * Aquí se implementaría la lógica de autorización.
     */
    public function downloadOriginal(Request $request, $file)
    {
        // 1. Verificar si el usuario está autenticado y tiene permisos.
        // Ejemplo: if (!Auth::check() || !Auth::user()->can('download_original')) { ... }

        // Si no tiene permisos:
        // return new Response(403, [], 'Forbidden');

        $config = config('casiel.audio_util');
        $originalsPath = $config['storage_originals'];

        if (basename($file) !== $file) {
            return new Response(400, [], 'Invalid filename');
        }

        $filePath = $originalsPath . '/' . $file;

        if (!file_exists($filePath)) {
            return new Response(404, [], 'File not found');
        }

        // Devolver el archivo con cabecera para forzar la descarga
        return response()->download($filePath);
    }
}

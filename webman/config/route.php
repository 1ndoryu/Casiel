<?php

use Webman\Route;
use app\controller\TestController;
use app\controller\AudioController;
use app\middleware\InternalAuthMiddleware;

// (MODIFICADO) Ruta principal para la suite de pruebas
Route::get('/test', [TestController::class, 'index']);
Route::post('/test/run-full', [TestController::class, 'ejecutarTestCompleto']);

// Ruta para servir los audios ligeros públicamente
// Se modifica para asegurar que el nombre de archivo pueda contener espacios y otros caracteres
Route::get('/samples/stream/{file:.+}', [AudioController::class, 'stream']);

// Ruta protegida para que Sword pueda descargar audios originales
Route::get('/samples/original/{file:.+}', [AudioController::class, 'downloadOriginal'])
    ->middleware(InternalAuthMiddleware::class);
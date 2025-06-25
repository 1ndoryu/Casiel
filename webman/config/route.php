<?php

use Webman\Route;
use app\controller\TestController;
use app\controller\AudioController;
use app\middleware\InternalAuthMiddleware; // Importar Middleware

// Rutas para la interfaz de pruebas de Casiel
Route::get('/test', [TestController::class, 'index']);
Route::post('/test/run', [TestController::class, 'ejecutarTest']);
Route::post('/test/force-run', [TestController::class, 'ejecutarTestForzado']);

// Ruta para servir los audios ligeros públicamente
Route::get('/samples/stream/{file:.+}', [AudioController::class, 'stream']);

// (MODIFICADO) Ruta protegida para que Sword pueda descargar audios originales
Route::get('/samples/original/{file:.+}', [AudioController::class, 'downloadOriginal'])
    ->middleware(InternalAuthMiddleware::class);

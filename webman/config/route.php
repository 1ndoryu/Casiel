<?php

use Webman\Route;
use app\controller\TestController;
use app\controller\AudioController; // Añadir

// Rutas para la interfaz de pruebas de Casiel
Route::get('/test', [TestController::class, 'index']);
Route::post('/test/run', [TestController::class, 'ejecutarTest']);
Route::post('/test/force-run', [TestController::class, 'ejecutarTestForzado']);

// (NUEVO) Ruta para servir los audios ligeros públicamente
Route::get('/samples/{file:.+}', [AudioController::class, 'stream']);

// (NUEVO - EJEMPLO FUTURO) Ruta protegida para descargar audios originales
// Route::get('/samples/original/{file:.+}', [AudioController::class, 'downloadOriginal'])->middleware([...]);
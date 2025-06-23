<?php

use Webman\Route;
use app\controller\TestController;

// Rutas para la interfaz de pruebas de Casiel
Route::get('/test', [TestController::class, 'index']);
Route::post('/test/run', [TestController::class, 'ejecutarTest']);
Route::post('/test/force-run', [TestController::class, 'ejecutarTestForzado']);
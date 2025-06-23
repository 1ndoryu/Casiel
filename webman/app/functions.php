<?php

/**
 * Here is your custom functions.
 */

use support\Log;

/**
 * Escribe un mensaje de log estandarizado para el proyecto Casiel.
 * @param string $mensaje El mensaje a registrar.
 * @param array $contexto Datos adicionales.
 * @param string $nivel Nivel de log (info, error, warning, etc).
 */
function casielLog(string $mensaje, array $contexto = [], string $nivel = 'info')
{
    Log::channel('default')->log($nivel, '[CASIEL] ' . $mensaje, $contexto);
}

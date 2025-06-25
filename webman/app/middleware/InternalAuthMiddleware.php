<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class InternalAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $internalApiKey = config('casiel.security.internal_api_key');
        $providedKey = $request->header('X-Internal-Auth-Key');

        if (!$internalApiKey || $providedKey !== $internalApiKey) {
            // Clave incorrecta o no proporcionada. Acceso denegado.
            return new Response(403, [], 'Forbidden: Access Denied');
        }

        // La clave es correcta, continuar con la petición.
        return $handler($request);
    }
}
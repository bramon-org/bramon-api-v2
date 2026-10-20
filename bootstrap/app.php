<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            // Validation and auth failures already have a correct JSON shape.
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'detail' => 'Parâmetros inválidos.',
                    'errors' => $e->errors(),
                ], $e->status);
            }

            if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return $e->getResponse();
            }

            // Preserve the intended HTTP status. The previous blanket 500
            // turned every 404 (unknown route, bad id) into a server error.
            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $e->getStatusCode()
                : 500;

            $payload = ['detail' => $e->getMessage()];

            // Only expose internals outside production.
            if (config('app.debug') && $status >= 500) {
                $payload['exception'] = $e::class;
                $payload['file'] = $e->getFile();
                $payload['line'] = $e->getLine();
            }

            return response()->json($payload, $status);
        });
    })
    ->create();
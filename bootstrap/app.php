<?php

use Illuminate\Console\Scheduling\Schedule;
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
    ->withSchedule(function (Schedule $schedule) {
        // The listing endpoints are served from the prebuilt index, so they only
        // reflect new captures once it is rebuilt. `schedule:work` already runs
        // under supervisord, which means without this entry /captures answers
        // from an index frozen at whatever moment it was last built.
        //
        // A rebuild streams the whole captures tree and takes minutes, so:
        //   - withoutOverlapping() keeps a slow run from being joined by the
        //     next tick, which would have two builds staging over each other;
        //   - the release of the cache lock on failure is left to the default
        //     24h expiry, since a build that never completes would otherwise
        //     pin the lock and silently stop every future rebuild.
        $schedule->command('bramon:index --force')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();
    })
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
<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\RequireFeatureEnabled;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\RequireSecurityEnrollment;
use App\Http\Middleware\SetSecurityHeaders;
use App\Http\Middleware\TrustConfiguredProxies;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (): ?string => null);

        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        )));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        $middleware->append(TrustConfiguredProxies::class);
        $middleware->append(SetSecurityHeaders::class);

        // Host-header allowlist (e.g. "api.example.org,staging-api.example.org").
        // Laravel skips this check in local and test environments.
        $trustedHosts = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_HOSTS', '')),
        )));

        if ($trustedHosts !== []) {
            $middleware->trustHosts(
                at: array_map(fn (string $host): string => '^'.preg_quote($host).'$', $trustedHosts),
                subdomains: false,
            );
        }

        $middleware->alias([
            'requireActiveAccount' => EnsureAccountActive::class,
            'requireFeature' => RequireFeatureEnabled::class,
            'requireRole' => RequireRole::class,
            'requireSecurityEnrollment' => RequireSecurityEnrollment::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => in_array('api', $request->route()?->gatherMiddleware() ?? [], true)
                || $request->expectsJson(),
        );

        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if (! in_array('api', $request->route()?->gatherMiddleware() ?? [], true)) {
                return null;
            }

            return response()->json([
                'message' => 'CSRF token mismatch.',
            ], 419);
        });

        $exceptions->render(function (DecryptException $exception, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()
                ->json([
                    'message' => 'Encrypted session or account security data could not be read. Refresh and sign in again; if this persists, reset the affected security enrollment or restore the original application key.',
                ], 419)
                ->withoutCookie(Cookie::forget('laravel-session'))
                ->withoutCookie(Cookie::forget('XSRF-TOKEN'))
                ->withoutCookie(Cookie::forget('cm_device_id'));
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (
                $exception->getStatusCode() !== 419
                || ! in_array('api', $request->route()?->gatherMiddleware() ?? [], true)
            ) {
                return null;
            }

            return response()->json([
                'message' => 'CSRF token mismatch.',
            ], 419);
        });
    })->create();

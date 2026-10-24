<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\LogRequest;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\RequireActiveSubscription;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SetTenantContext;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'agent' => AuthenticateAgent::class,
            'tenant' => SetTenantContext::class,
            'permission' => RequirePermission::class,
            'subscription.active' => RequireActiveSubscription::class,
        ]);
        $middleware->api(prepend: [AssignRequestId::class, SecurityHeaders::class, LogRequest::class]);

        // Derrière un reverse proxy (Caddy) : sans cela, toutes les requêtes semblent venir du proxy (limitation par IP faussée)
        // et HTTPS n'est pas détecté. TRUSTED_PROXIES = '*' (réseau Docker privé) ou liste d'IP séparées par des virgules.
        if (($proxies = env('TRUSTED_PROXIES')) !== null && $proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies)));
        }
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Un SEUL rendu pour toute l'API. Laravel convertit d'abord ModelNotFoundException et
        // AuthorizationException en NotFoundHttpException / AccessDeniedHttpException avant d'appeler
        // les callbacks : on traite donc ces types-là, sinon la réponse sortirait de l'enveloppe standard.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($e instanceof ApiException) {
                return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status, meta: $e->context);
            }

            if ($e instanceof ValidationException) {
                $errors = [];
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = ['code' => 'validation_failed', 'field' => $field, 'message' => $message];
                    }
                }

                return ApiResponse::error('validation_failed', 'Données invalides.', 422, $errors);
            }

            return match (true) {
                $e instanceof AuthenticationException => ApiResponse::error('unauthenticated', 'Authentification requise.', 401),
                $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiResponse::error('forbidden', 'Action non autorisée.', 403),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error('not_found', 'Ressource introuvable.', 404),
                $e instanceof TooManyRequestsHttpException => ApiResponse::error('rate_limited', 'Trop de requêtes.', 429),
                $e instanceof HttpExceptionInterface => ApiResponse::error('http_error', 'Requête refusée.', $e->getStatusCode()),
                config('app.debug') => null, // en dev, Laravel affiche la trace
                // Jamais de stack trace en production.
                default => ApiResponse::error('server_error', 'Erreur interne.', 500),
            };
        });
    })->create();

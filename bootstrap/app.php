<?php

use App\Http\Middleware\ApiEnvelope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Razorpay\Api\Errors\Error as RazorpayError;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [ApiEnvelope::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $r) => $r->is('api/*') || $r->expectsJson();
        $exceptions->shouldRenderJsonWhen($isApi);

        $fail = fn (string $code, string $message, int $status, array $extra = []) => response()->json(
            ['success' => false, 'code' => $code, 'message' => $message] + $extra,
            $status,
        );

        $exceptions->render(function (ValidationException $e, Request $r) use ($isApi, $fail) {
            if (! $isApi($r)) {
                return null;
            }
            $first = collect($e->errors())->flatten()->first() ?? 'The given data was invalid.';

            return $fail('validation_failed', $first, 422, ['errors' => $e->errors()]);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $r) use ($isApi, $fail) {
            if (! $isApi($r)) {
                return null;
            }
            $model = $e->getPrevious() instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                ? class_basename($e->getPrevious()->getModel())
                : null;

            return $fail('not_found', $model ? "{$model} not found." : 'Endpoint not found.', 404);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $r) use ($isApi, $fail) {
            if (! $isApi($r)) {
                return null;
            }

            return $fail('method_not_allowed', 'This endpoint does not support '.$r->method().'.', 405)
                ->withHeaders(['Allow' => $e->getHeaders()['Allow'] ?? '']);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $r) use ($isApi, $fail) {
            return $isApi($r) ? $fail('too_many_requests', 'Too many requests. Please slow down.', 429) : null;
        });

        $exceptions->render(function (RazorpayError $e, Request $r) use ($isApi, $fail) {
            if (! $isApi($r)) {
                return null;
            }
            report($e);

            return $fail('payment_gateway_error', 'Payment provider error: '.($e->getMessage() ?: 'request failed').'. Please try again.', 502);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($isApi, $fail) {
            if (! $isApi($r)) {
                return null;
            }
            $status = $e->getStatusCode();
            $codes = [400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 409 => 'conflict', 419 => 'expired', 422 => 'validation_failed', 503 => 'unavailable'];

            return $fail($codes[$status] ?? 'error', $e->getMessage() ?: 'Request failed.', $status);
        });

        $exceptions->render(function (\Throwable $e, Request $r) use ($isApi, $fail) {
            if (! $isApi($r)) {
                return null;
            }
            $extra = config('app.debug')
                ? ['debug' => ['exception' => $e::class, 'message' => $e->getMessage(), 'where' => basename($e->getFile()).':'.$e->getLine()]]
                : [];

            return $fail('server_error', 'Something went wrong on our side. Please try again.', 500, $extra);
        });
    })->create();

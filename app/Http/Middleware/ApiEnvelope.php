<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiEnvelope
{
    private const CODES = [
        400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found',
        405 => 'method_not_allowed', 409 => 'conflict', 422 => 'validation_failed',
        429 => 'too_many_requests', 500 => 'server_error', 502 => 'payment_gateway_error',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || $response->getStatusCode() === 204) {
            return $response;
        }

        $body = $response->getData(true);
        if (! is_array($body) || array_key_exists('success', $body)) {
            return $response;
        }

        $status = $response->getStatusCode();
        if ($status < 400) {
            $body = ['success' => true] + $body;
        } else {
            $body = ['success' => false, 'code' => self::CODES[$status] ?? 'error'] + $body;
        }

        return $response->setData($body);
    }
}

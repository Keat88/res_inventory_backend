<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        abort_if(strlen($key) > 100, 422, 'Idempotency-Key is too long.');

        $userId = $request->user()?->id;
        $requestHash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $existing = IdempotencyKey::query()->where('key', $key)->first();

        if ($existing) {
            abort_unless(
                $existing->request_hash === $requestHash && $existing->user_id === $userId,
                409,
                'This Idempotency-Key was already used with a different request.'
            );

            return response($existing->response_body, $existing->response_status)
                ->header('Content-Type', 'application/json')
                ->header('Idempotent-Replayed', 'true');
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            IdempotencyKey::query()->create([
                'key' => $key,
                'user_id' => $userId,
                'route' => $request->path(),
                'method' => $request->method(),
                'request_hash' => $requestHash,
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'expires_at' => now()->addHours(24),
            ]);
        }

        return $response;
    }
}

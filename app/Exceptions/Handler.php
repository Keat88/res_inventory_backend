<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends \Illuminate\Foundation\Exceptions\Handler
{
    protected $dontReport = [
        BusinessException::class,
    ];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if ($request instanceof Request && ($request->is('api/*') || $request->expectsJson())) {
            return $this->renderApiException($e);
        }

        return parent::render($request, $e);
    }

    protected function renderApiException(Throwable $e)
    {
        if ($e instanceof BusinessException) {
            return ApiResponse::error($e->getMessage(), $e->details(), $e->statusCode(), $e->errorCode());
        }

        if ($e instanceof ValidationException) {
            return ApiResponse::error('The given data was invalid.', $e->errors(), 422, 'VALIDATION_ERROR');
        }

        if ($e instanceof AuthenticationException) {
            return ApiResponse::error('Unauthenticated.', null, 401, 'UNAUTHENTICATED');
        }

        if ($e instanceof AuthorizationException) {
            return ApiResponse::error($e->getMessage() ?: 'This action is unauthorized.', null, 403, 'FORBIDDEN');
        }

        if ($e instanceof ModelNotFoundException) {
            return ApiResponse::error('Resource not found.', null, 404, 'NOT_FOUND');
        }

        if ($e instanceof NotFoundHttpException) {
            return ApiResponse::error('Endpoint not found.', null, 404, 'NOT_FOUND');
        }

        if ($e instanceof ThrottleRequestsException) {
            return ApiResponse::error('Too many requests. Please slow down.', null, 429, 'RATE_LIMITED');
        }

        if ($e instanceof HttpExceptionInterface) {
            return ApiResponse::error(
                $e->getMessage() ?: 'Request failed.',
                null,
                $e->getStatusCode(),
                'HTTP_ERROR'
            );
        }

        report($e);

        return ApiResponse::error(
            config('app.debug') ? $e->getMessage() : 'Internal server error.',
            config('app.debug') ? ['exception' => $e::class] : null,
            500,
            'SERVER_ERROR'
        );
    }
}

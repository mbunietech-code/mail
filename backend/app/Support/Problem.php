<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every API error as application/problem+json (RFC 9457) with a stable `code`
 * and the request id. Unexpected errors never expose messages or traces.
 */
final class Problem
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $title, $detail, $headers, $errors] = self::classify($e);

        $body = array_filter([
            'type' => 'https://mbuniemail.docs/errors/'.strtolower(str_replace('_', '-', $code)),
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            'request_id' => $request->attributes->get('request_id'),
            'errors' => $errors,
        ], fn ($v) => $v !== null);

        return new JsonResponse($body, $status, $headers + ['Content-Type' => 'application/problem+json']);
    }

    /**
     * @return array{int, string, string, ?string, array<string, string>, ?array<string, mixed>}
     */
    private static function classify(Throwable $e): array
    {
        return match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->title, $e->detail, $e->headers, null],
            $e instanceof ValidationException => [422, 'VALIDATION_FAILED', 'The given data was invalid.', null, [], $e->errors()],
            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Authentication required.', null, [], null],
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [403, 'FORBIDDEN', 'This action is not permitted.', null, [], null],
            // Unknown and foreign resources are indistinguishable (no existence oracle).
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'NOT_FOUND', 'Resource not found.', null, [], null],
            $e instanceof ThrottleRequestsException => [429, 'RATE_LIMITED', 'Too many requests. Try again later.', null, self::retryHeaders($e), null],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), self::codeForStatus($e->getStatusCode()), self::titleForStatus($e->getStatusCode()), null, [], null],
            default => [500, 'INTERNAL_ERROR', 'An unexpected error occurred.', null, [], null],
        };
    }

    /** @return array<string, string> */
    private static function retryHeaders(ThrottleRequestsException $e): array
    {
        return array_intersect_key(
            array_map('strval', $e->getHeaders()),
            array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'])
        );
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            413 => 'PAYLOAD_TOO_LARGE',
            415 => 'UNSUPPORTED_MEDIA_TYPE',
            419 => 'CSRF_TOKEN_MISMATCH',
            503 => 'SERVICE_UNAVAILABLE',
            default => $status >= 500 ? 'INTERNAL_ERROR' : 'HTTP_'.$status,
        };
    }

    private static function titleForStatus(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            405 => 'Method not allowed.',
            409 => 'Conflict.',
            413 => 'Payload too large.',
            415 => 'Unsupported media type.',
            419 => 'Session expired. Reload and try again.',
            503 => 'Service temporarily unavailable.',
            default => $status >= 500 ? 'An unexpected error occurred.' : 'Request failed.',
        };
    }
}

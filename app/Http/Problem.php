<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Builds RFC 9457 problem+json responses; the one shape every non-2xx /api/v1 response has. */
final class Problem
{
    /**
     * @param  array<string, mixed>  $extra  merged into the body (e.g. `errors`)
     */
    public static function response(int $status, string $title, ?string $detail = null, ?string $code = null, array $extra = []): JsonResponse
    {
        $body = ['type' => 'about:blank', 'title' => $title, 'status' => $status]
            + array_filter(['detail' => $detail, 'code' => $code], fn (?string $v) => $v !== null)
            + $extra;

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }

    /** Maps any HTTP exception (404, 419, 429, ...) to its problem, keeping Retry-After. */
    public static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        [$title, $code] = match ($status) {
            403 => ['Forbidden', 'forbidden'],
            404 => ['Not Found', 'not_found'],
            405 => ['Method Not Allowed', 'method_not_allowed'],
            419 => ['Page Expired', 'csrf_mismatch'],
            429 => ['Too Many Requests', 'too_many_requests'],
            default => [Response::$statusTexts[$status] ?? 'Error', $status >= 500 ? 'server_error' : 'http_error'],
        };
        $detail = match ($status) {
            419 => 'The CSRF token is missing or does not match.',
            429 => 'Too many requests. Try again later.',
            default => $status >= 500 ? 'Something went wrong.' : null,
        };

        $response = Problem::response($status, $title, $detail, $code);
        $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
        if (is_string($retryAfter) || is_int($retryAfter)) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }
}

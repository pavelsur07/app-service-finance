<?php

declare(strict_types=1);

namespace App\Api\Infrastructure\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ApiProblem
{
    /** @param array<string, string> $headers */
    public static function response(int $status, string $detail, array $headers = []): JsonResponse
    {
        if (401 === $status) {
            $headers['WWW-Authenticate'] = 'Bearer';
        }

        return new JsonResponse([
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
        ], $status, $headers + ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }
}

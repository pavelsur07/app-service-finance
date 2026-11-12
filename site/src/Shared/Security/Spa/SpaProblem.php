<?php

declare(strict_types=1);

namespace App\Shared\Security\Spa;

use Symfony\Component\HttpFoundation\JsonResponse;

final class SpaProblem
{
    public static function response(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status, ['Cache-Control' => 'no-store']);
    }
}

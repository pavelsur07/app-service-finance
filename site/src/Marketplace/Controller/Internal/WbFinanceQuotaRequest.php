<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Internal;

use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Общее для эндпоинтов квоты WB finance: проверка ключа кабинета, бакет
 * лимитера (как `Ingestion\…\WbFinanceReportClient::sellerBucketId`), ответ 422.
 */
final class WbFinanceQuotaRequest
{
    public static function isConnectionRef(string $ref): bool
    {
        return Uuid::isValid($ref);
    }

    public static function bucket(string $connectionRef): string
    {
        return 'connection:'.$connectionRef;
    }

    public static function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => 'invalid_request', 'message' => $message]], 422, ['Cache-Control' => 'no-store']);
    }
}

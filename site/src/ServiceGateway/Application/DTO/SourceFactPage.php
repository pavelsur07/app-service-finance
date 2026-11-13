<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

/**
 * Страница пачки фактов. `body` — ответ сервиса как пришёл (проверенный по контракту):
 * записи режет PostgreSQL (`jsonb_array_elements(body->'records')`), чтобы числа источника
 * не проходили через float PHP.
 */
final readonly class SourceFactPage
{
    public function __construct(
        public string $batchId,
        public int $page,
        public int $pageCount,
        public int $recordCount,
        public string $body,
    ) {
    }
}

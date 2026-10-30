<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

final readonly class ImportAiResultsResult
{
    public function __construct(
        public int $received,
        public int $acked,
        /** Результаты без потребителя: не подтверждены, остаются в сервисе (карантин). */
        public int $unhandled,
    ) {
    }
}

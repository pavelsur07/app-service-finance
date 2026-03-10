<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Триггер первичной загрузки при создании новой интеграции.
 * Отправляется из контроллера сразу после persist connection.
 */
final class TriggerInitialSyncMessage
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $connectionId,
        public readonly string $marketplace, // MarketplaceType::value
    ) {
    }
}

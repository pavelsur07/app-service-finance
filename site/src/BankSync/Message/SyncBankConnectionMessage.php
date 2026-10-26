<?php

declare(strict_types=1);

namespace App\BankSync\Message;

use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Загрузить выписку по одному подключению к банку. Транспорт — `async_sync` (внешний HTTP).
 */
final readonly class SyncBankConnectionMessage
{
    public string $syncId;

    public function __construct(
        public string $companyId,
        public string $connectionId,
        ?string $syncId = null,
    ) {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        $this->syncId = $syncId ?? Uuid::uuid7()->toString();
    }
}

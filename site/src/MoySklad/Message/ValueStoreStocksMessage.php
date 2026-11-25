<?php

declare(strict_types=1);

namespace App\MoySklad\Message;

use Webmozart\Assert\Assert;

/** Оценить склады completed-снимка по себестоимости МойСклад и установить остатки счетов Баланса. */
final readonly class ValueStoreStocksMessage
{
    public function __construct(public string $companyId, public string $connectionId, public string $snapshotId, public int $attempt = 0)
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::uuid($snapshotId);
        Assert::range($attempt, 0, 3);
    }
}

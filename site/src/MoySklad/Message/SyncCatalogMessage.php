<?php

declare(strict_types=1);

namespace App\MoySklad\Message;

use Webmozart\Assert\Assert;

final readonly class SyncCatalogMessage
{
    public function __construct(public string $companyId, public string $connectionId, public int $attempt = 0)
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::range($attempt, 0, 3);
    }
}

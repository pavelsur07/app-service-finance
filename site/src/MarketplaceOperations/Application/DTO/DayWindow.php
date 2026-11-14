<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Application\DTO;

use Webmozart\Assert\Assert;

/** День кабинета в каноне: единица замены и пересчёта витрины. */
final readonly class DayWindow
{
    public function __construct(
        public string $companyId,
        public string $channel,
        public string $connectionRef,
        /** Y-m-d */
        public string $date,
    ) {
        Assert::uuid($companyId);
        Assert::regex($date, '/^\d{4}-\d{2}-\d{2}$/D');
    }

    public function key(): string
    {
        return implode(':', [$this->companyId, $this->channel, $this->connectionRef, $this->date]);
    }
}

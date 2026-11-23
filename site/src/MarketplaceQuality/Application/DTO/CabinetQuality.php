<?php

declare(strict_types=1);

namespace App\MarketplaceQuality\Application\DTO;

/** Звенья кабинета за период: последние дни и Σ фактов (по переводчику), канона и витрины, копейки. */
final class CabinetQuality
{
    public ?string $lastFactDay = null;
    public ?string $lastTranslatedDay = null;
    public ?string $lastMartDay = null;
    public int $untranslatedDays = 0;
    public int $factsMinor = 0;
    public int $quarantineMinor = 0;
    public int $canonMinor = 0;
    public int $martMinor = 0;
    public int $unallocatedMinor = 0;
    public int $costsMinor = 0;

    public function __construct(public readonly string $channel, public readonly string $connectionRef)
    {
    }

    public function unallocatedPercent(): ?float
    {
        return $this->costsMinor > 0 ? round($this->unallocatedMinor * 100 / $this->costsMinor, 2) : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Builders\MoySklad;

use App\MoySklad\Domain\StoreSnapshot;
use App\MoySklad\Entity\MoySkladStore;
use Webmozart\Assert\Assert;

final class MoySkladStoreBuilder
{
    public const DEFAULT_ID = '44444444-4444-7444-8444-444444444444';
    public const DEFAULT_EXTERNAL_ID = '55555555-5555-4555-8555-555555555555';

    private string $id = self::DEFAULT_ID;
    private string $externalId = self::DEFAULT_EXTERNAL_ID;
    private string $companyId = MoySkladConnectionBuilder::DEFAULT_COMPANY_ID;
    private string $connectionId = MoySkladConnectionBuilder::DEFAULT_ID;

    private function __construct()
    {
    }

    public static function aStore(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        Assert::range($index, 0, 999999999999);
        $copy = clone $this;
        $copy->id = sprintf('44444444-4444-7444-8444-%012d', $index);
        $copy->externalId = sprintf('55555555-5555-4555-8555-%012d', $index);

        return $copy;
    }

    public function withTenant(string $companyId, string $connectionId): self
    {
        $copy = clone $this;
        $copy->companyId = $companyId;
        $copy->connectionId = $connectionId;

        return $copy;
    }

    public function build(): MoySkladStore
    {
        $now = new \DateTimeImmutable('2026-09-21T08:00:00+00:00');

        return new MoySkladStore($this->id, $this->companyId, $this->connectionId, new StoreSnapshot($this->externalId, 'Test store', 'store-external', null, '', false, $now), $now);
    }
}

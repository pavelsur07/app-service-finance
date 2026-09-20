<?php

declare(strict_types=1);

namespace App\Tests\Builders\MoySklad;

use App\MoySklad\Domain\CounterpartySnapshot;
use App\MoySklad\Entity\MoySkladCounterparty;
use Webmozart\Assert\Assert;

final class MoySkladCounterpartyBuilder
{
    public const DEFAULT_ID = '44444444-4444-7444-8444-444444444444';
    public const DEFAULT_COMPANY_ID = MoySkladConnectionBuilder::DEFAULT_COMPANY_ID;
    public const DEFAULT_CONNECTION_ID = MoySkladConnectionBuilder::DEFAULT_ID;
    public const DEFAULT_EXTERNAL_ID = '55555555-5555-4555-8555-555555555555';

    private string $id = self::DEFAULT_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;
    private string $connectionId = self::DEFAULT_CONNECTION_ID;
    private string $externalId = self::DEFAULT_EXTERNAL_ID;
    private string $name = 'Тестовый контрагент';
    private bool $archived = false;

    private function __construct()
    {
    }

    public static function aCounterparty(): self
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

    public function withCompanyId(string $companyId): self
    {
        $copy = clone $this;
        $copy->companyId = $companyId;

        return $copy;
    }

    public function withConnectionId(string $connectionId): self
    {
        $copy = clone $this;
        $copy->connectionId = $connectionId;

        return $copy;
    }

    public function asArchived(): self
    {
        $copy = clone $this;
        $copy->archived = true;

        return $copy;
    }

    public function build(): MoySkladCounterparty
    {
        $now = new \DateTimeImmutable('2026-09-20T09:00:00+00:00');

        return new MoySkladCounterparty(
            $this->id,
            $this->companyId,
            $this->connectionId,
            new CounterpartySnapshot($this->externalId, $this->name, 'legal', null, null, null, null, null, null, $this->archived, $now),
            $now,
        );
    }
}

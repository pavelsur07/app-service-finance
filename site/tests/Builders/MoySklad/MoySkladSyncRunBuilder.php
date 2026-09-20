<?php

declare(strict_types=1);

namespace App\Tests\Builders\MoySklad;

use App\MoySklad\Entity\MoySkladSyncRun;
use Webmozart\Assert\Assert;

final class MoySkladSyncRunBuilder
{
    public const DEFAULT_ID = '77777777-7777-7777-8777-777777777777';

    private string $id = self::DEFAULT_ID;
    private string $companyId = MoySkladConnectionBuilder::DEFAULT_COMPANY_ID;
    private string $connectionId = MoySkladConnectionBuilder::DEFAULT_ID;

    private function __construct()
    {
    }

    public static function aRun(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        Assert::range($index, 0, 999999999999);
        $copy = clone $this;
        $copy->id = sprintf('77777777-7777-7777-8777-%012d', $index);

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

    public function build(): MoySkladSyncRun
    {
        return new MoySkladSyncRun($this->id, $this->companyId, $this->connectionId, 'counterparty', new \DateTimeImmutable('2026-09-20T09:00:00+00:00'));
    }
}

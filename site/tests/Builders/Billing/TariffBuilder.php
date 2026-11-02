<?php

declare(strict_types=1);

namespace App\Tests\Builders\Billing;

use App\Billing\Entity\Tariff;
use App\Billing\Enum\TariffCode;

/**
 * Тариф для unit-тестов. В integration-тестах справочник уже засеян миграцией —
 * брать оттуда через TariffRepository::findOneByCode().
 */
final class TariffBuilder
{
    public const DEFAULT_ID = '01930000-0000-7000-8000-00000000b001';

    private string $id = self::DEFAULT_ID;
    private TariffCode $code = TariffCode::START;
    private string $name = 'Старт';
    private bool $public = true;
    private bool $perpetual = false;

    public static function aTariff(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withCode(TariffCode $code, string $name = 'Тариф'): self
    {
        $clone = clone $this;
        $clone->code = $code;
        $clone->name = $name;

        return $clone;
    }

    /** Скрытый тариф с периодом (не в публичном прайсе). */
    public function hidden(): self
    {
        $clone = clone $this;
        $clone->public = false;

        return $clone;
    }

    public function perpetual(): self
    {
        $clone = clone $this;
        $clone->code = TariffCode::FRIEND;
        $clone->name = 'Друг';
        $clone->public = false;
        $clone->perpetual = true;

        return $clone;
    }

    public function build(): Tariff
    {
        return new Tariff($this->code, $this->name, $this->public, $this->perpetual, 10, new \DateTimeImmutable('2026-11-01 10:00:00'), $this->id);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Builders\Cash;

use App\Cash\Entity\Transaction\CashAiCategorizationSetting;

final class CashAiCategorizationSettingBuilder
{
    public const DEFAULT_COMPANY_ID = CashCategorySuggestionBuilder::DEFAULT_COMPANY_ID;

    private string $companyId = self::DEFAULT_COMPANY_ID;
    private bool $autoApply = false;

    private function __construct()
    {
    }

    public static function aSetting(): self
    {
        return new self();
    }

    public function forCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withAutoApply(): self
    {
        $clone = clone $this;
        $clone->autoApply = true;

        return $clone;
    }

    public function build(): CashAiCategorizationSetting
    {
        $setting = new CashAiCategorizationSetting($this->companyId, new \DateTimeImmutable('2026-10-30 12:00:00'));
        $setting->changeAutoApply($this->autoApply, new \DateTimeImmutable('2026-10-30 12:00:00'));

        return $setting;
    }
}

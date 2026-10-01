<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Marketplace\Entity\OzonReconciliationLine;
use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Enum\OzonReconciliationCheck;
use App\Marketplace\Enum\OzonReconciliationStatus;

final class OzonReconciliationLineBuilder
{
    public const DEFAULT_ID = '66666666-6666-6666-6666-666666666666';

    private string $id = self::DEFAULT_ID;
    private string $companyId = OzonReconciliationRunBuilder::DEFAULT_COMPANY_ID;
    private string $runId = OzonReconciliationRunBuilder::DEFAULT_ID;
    private OzonReconciliationCheck $check = OzonReconciliationCheck::RAW_VS_LEDGER;
    private OzonReconciliationBlock $block = OzonReconciliationBlock::SALES;
    private string $categoryCode = '';
    private ?int $sourceMinor = 100000;
    private ?int $targetMinor = 100000;
    private OzonReconciliationStatus $status = OzonReconciliationStatus::MATCHED;

    private function __construct()
    {
    }

    public static function aLine(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('66666666-6666-6666-6666-%012d', $index);

        return $clone;
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withRunId(string $runId): self
    {
        $clone = clone $this;
        $clone->runId = $runId;

        return $clone;
    }

    public function forBlock(OzonReconciliationBlock $block, string $categoryCode = ''): self
    {
        $clone = clone $this;
        $clone->block = $block;
        $clone->categoryCode = $categoryCode;

        return $clone;
    }

    public function forCheck(OzonReconciliationCheck $check): self
    {
        $clone = clone $this;
        $clone->check = $check;

        return $clone;
    }

    public function withAmounts(?int $sourceMinor, ?int $targetMinor, OzonReconciliationStatus $status): self
    {
        $clone = clone $this;
        $clone->sourceMinor = $sourceMinor;
        $clone->targetMinor = $targetMinor;
        $clone->status = $status;

        return $clone;
    }

    public function build(): OzonReconciliationLine
    {
        return new OzonReconciliationLine(
            $this->id,
            $this->companyId,
            $this->runId,
            $this->check,
            $this->block,
            $this->categoryCode,
            $this->sourceMinor,
            $this->targetMinor,
            $this->status,
        );
    }
}

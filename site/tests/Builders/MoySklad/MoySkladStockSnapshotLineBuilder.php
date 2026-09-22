<?php

declare(strict_types=1);

namespace App\Tests\Builders\MoySklad;

use App\MoySklad\Entity\MoySkladStockSnapshotLine;
use Webmozart\Assert\Assert;

final class MoySkladStockSnapshotLineBuilder
{
    public const DEFAULT_ID = '77777777-7777-7777-8777-777777777777';

    private string $id = self::DEFAULT_ID;
    private string $companyId = MoySkladConnectionBuilder::DEFAULT_COMPANY_ID;
    private string $connectionId = MoySkladConnectionBuilder::DEFAULT_ID;
    private string $snapshotId = MoySkladStockSnapshotBuilder::DEFAULT_ID;
    private string $storeExternalId = MoySkladStoreBuilder::DEFAULT_EXTERNAL_ID;
    private string $assortmentType = 'product';
    private string $assortmentExternalId = '88888888-8888-4888-8888-888888888888';

    private function __construct()
    {
    }

    public static function aLine(): self
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

    public function build(): MoySkladStockSnapshotLine
    {
        return new MoySkladStockSnapshotLine($this->id, $this->companyId, $this->connectionId, $this->snapshotId, $this->storeExternalId, $this->assortmentType, $this->assortmentExternalId, '1', '0', '0');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Builders\MoySklad;

use App\MoySklad\Entity\MoySkladSyncCursor;
use Webmozart\Assert\Assert;

final class MoySkladSyncCursorBuilder
{
    public const DEFAULT_ID = '66666666-6666-7666-8666-666666666666';

    private string $id = self::DEFAULT_ID;
    private string $companyId = MoySkladConnectionBuilder::DEFAULT_COMPANY_ID;
    private string $connectionId = MoySkladConnectionBuilder::DEFAULT_ID;

    private function __construct()
    {
    }

    public static function aCursor(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        Assert::range($index, 0, 999999999999);
        $copy = clone $this;
        $copy->id = sprintf('66666666-6666-7666-8666-%012d', $index);

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

    public function build(): MoySkladSyncCursor
    {
        return new MoySkladSyncCursor($this->id, $this->companyId, $this->connectionId, 'counterparty');
    }
}

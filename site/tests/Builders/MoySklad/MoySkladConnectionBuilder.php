<?php

declare(strict_types=1);

namespace App\Tests\Builders\MoySklad;

use App\MoySklad\Entity\MoySkladConnection;
use Webmozart\Assert\Assert;

final class MoySkladConnectionBuilder
{
    public const DEFAULT_ID = '33333333-3333-7333-8333-333333333333';
    public const DEFAULT_COMPANY_ID = '11111111-1111-7111-8111-111111111111';

    private string $id = self::DEFAULT_ID;
    private string $companyId = self::DEFAULT_COMPANY_ID;

    private function __construct()
    {
    }

    public static function aConnection(): self
    {
        return new self();
    }

    public function withIndex(int $index): self
    {
        Assert::range($index, 0, 999999999999);
        $copy = clone $this;
        $copy->id = sprintf('33333333-3333-7333-8333-%012d', $index);

        return $copy;
    }

    public function withCompanyId(string $companyId): self
    {
        Assert::uuid($companyId);
        $copy = clone $this;
        $copy->companyId = $companyId;

        return $copy;
    }

    public function build(): MoySkladConnection
    {
        return new MoySkladConnection($this->id, $this->companyId, 'Test connection', 'https://api.moysklad.ru/api/remap/1.2');
    }
}

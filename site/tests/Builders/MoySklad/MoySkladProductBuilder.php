<?php

declare(strict_types=1);

namespace App\Tests\Builders\MoySklad;

use App\MoySklad\Domain\ProductSnapshot;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Entity\MoySkladProduct;

final class MoySkladProductBuilder
{
    private int $index = 1;
    private string $companyId = MoySkladConnectionBuilder::DEFAULT_COMPANY_ID;
    private string $connectionId = MoySkladConnectionBuilder::DEFAULT_ID;
    private string $name = 'Товар';
    private ?string $article = '001';
    private ?string $code = 'CODE';
    private int $variantsCount = 0;
    private bool $archived = false;

    public static function aProduct(): self
    {
        return new self();
    }

    public function forConnection(MoySkladConnection $connection): self
    {
        $copy = clone $this;
        $copy->companyId = $connection->getCompanyId();
        $copy->connectionId = $connection->getId();

        return $copy;
    }

    public function withIndex(int $index): self
    {
        $copy = clone $this;
        $copy->index = $index;

        return $copy;
    }

    public function withName(string $name): self
    {
        $copy = clone $this;
        $copy->name = $name;

        return $copy;
    }

    public function withArticle(?string $article): self
    {
        $copy = clone $this;
        $copy->article = $article;

        return $copy;
    }

    public function withCode(?string $code): self
    {
        $copy = clone $this;
        $copy->code = $code;

        return $copy;
    }

    public function withVariantsCount(int $count): self
    {
        $copy = clone $this;
        $copy->variantsCount = $count;

        return $copy;
    }

    public function archived(): self
    {
        $copy = clone $this;
        $copy->archived = true;

        return $copy;
    }

    public function build(): MoySkladProduct
    {
        $now = new \DateTimeImmutable('2026-11-15T00:00:00+00:00');

        return new MoySkladProduct(
            sprintf('44444444-4444-7444-8444-%012d', $this->index), $this->companyId, $this->connectionId,
            new ProductSnapshot(sprintf('44444444-4444-4444-8444-%012d', $this->index), $this->name, 'external', $this->code, $this->article, $this->variantsCount, $this->archived, $now), $now,
        );
    }
}

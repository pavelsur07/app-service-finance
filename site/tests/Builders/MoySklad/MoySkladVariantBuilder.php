<?php

declare(strict_types=1);

namespace App\Tests\Builders\MoySklad;

use App\MoySklad\Domain\VariantSnapshot;
use App\MoySklad\Entity\MoySkladProduct;
use App\MoySklad\Entity\MoySkladVariant;

final class MoySkladVariantBuilder
{
    private int $index = 1;
    private ?MoySkladProduct $product = null;
    private string $name = 'Товар / M';
    private ?string $article = '001-M';
    private bool $archived = false;

    public static function aVariant(): self
    {
        return new self();
    }

    public function forProduct(MoySkladProduct $product): self
    {
        $copy = clone $this;
        $copy->product = $product;

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

    public function archived(): self
    {
        $copy = clone $this;
        $copy->archived = true;

        return $copy;
    }

    public function build(): MoySkladVariant
    {
        $product = $this->product ?? throw new \LogicException('Product is required.');
        $now = new \DateTimeImmutable('2026-11-15T00:00:00+00:00');

        return new MoySkladVariant(
            sprintf('66666666-6666-7666-8666-%012d', $this->index), $product->getCompanyId(), $product->getConnectionId(),
            new VariantSnapshot(sprintf('66666666-6666-4666-8666-%012d', $this->index), $product->getExternalId(), $this->name, 'external', 'VAR', $this->article, [['id' => 'size', 'name' => 'Размер', 'value' => 'M']], $this->archived, $now), $now,
        );
    }
}

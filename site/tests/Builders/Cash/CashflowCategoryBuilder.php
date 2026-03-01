<?php

declare(strict_types=1);

namespace App\Tests\Builders\Cash;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Tests\Builders\Company\CompanyBuilder;

final class CashflowCategoryBuilder
{
    public const DEFAULT_ID = '550e8400-e29b-41d4-a716-446655440002';
    public const DEFAULT_NAME = 'Test Category';

    private string $id;
    private ?object $company;
    private string $name;

    private function __construct()
    {
        $this->id = self::DEFAULT_ID;
        $this->company = null;
        $this->name = self::DEFAULT_NAME;
    }

    public static function aCashflowCategory(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;
        return $clone;
    }

    public function withCompany(object $company): self
    {
        $clone = clone $this;
        $clone->company = $company;
        return $clone;
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;
        return $clone;
    }

    /**
     * Генерирует уникальный ID на основе индекса (детерминированно)
     */
    public function withIndex(int $index): self
    {
        $uuid = sprintf(
            '550e8400-e29b-41d4-a716-%012d',
            $index + 1000 // Offset чтобы не пересекался с другими builders
        );

        return $this->withId($uuid);
    }

    public function build(): CashflowCategory
    {
        // Если компания не задана - создаем дефолтную
        $company = $this->company ?? CompanyBuilder::aCompany()->build();

        $category = new CashflowCategory($this->id, $company);
        $category->setName($this->name);

        return $category;
    }
}

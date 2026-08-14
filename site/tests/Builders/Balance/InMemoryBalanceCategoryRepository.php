<?php

declare(strict_types=1);

namespace App\Tests\Builders\Balance;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Repository\BalanceCategoryRepositoryInterface;

final class InMemoryBalanceCategoryRepository implements BalanceCategoryRepositoryInterface
{
    /** @var array<string, BalanceCategory> */
    private array $categories = [];

    public function save(BalanceCategory $category): void
    {
        $this->categories[$category->getId()] = $category;
    }

    public function findByIdAndCompany(string $id, string $companyId): ?BalanceCategory
    {
        $category = $this->categories[$id] ?? null;
        if (null === $category || $category->getCompanyId() !== $companyId) {
            return null;
        }

        return $category;
    }

    public function findRootByCompany(string $companyId): array
    {
        return array_values(array_filter(
            $this->categories,
            static fn (BalanceCategory $c): bool => $c->getCompanyId() === $companyId && null === $c->getParent(),
        ));
    }

    public function findTreeByCompany(string $companyId): array
    {
        return array_values(array_filter(
            $this->categories,
            static fn (BalanceCategory $c): bool => $c->getCompanyId() === $companyId,
        ));
    }

    public function getNextSortOrder(string $companyId, ?BalanceCategory $parent): int
    {
        $siblings = $this->findSiblings($companyId, $parent);
        $max = 0;
        foreach ($siblings as $sibling) {
            $max = max($max, $sibling->getSortOrder());
        }

        return $max + 10;
    }

    public function findSiblings(string $companyId, ?BalanceCategory $parent): array
    {
        return array_values(array_filter(
            $this->categories,
            static fn (BalanceCategory $c): bool => $c->getCompanyId() === $companyId && $c->getParent() === $parent,
        ));
    }

    public function swapSortOrder(BalanceCategory $a, BalanceCategory $b): void
    {
        $aSort = $a->getSortOrder();
        $bSort = $b->getSortOrder();
        $a->setSortOrder($bSort);
        $b->setSortOrder($aSort);
    }

    public function existsWithCode(string $companyId, string $code, ?string $excludeId = null): bool
    {
        foreach ($this->categories as $category) {
            if ($category->getCompanyId() !== $companyId) {
                continue;
            }
            if ($category->getCode() !== $code) {
                continue;
            }
            if (null !== $excludeId && $category->getId() === $excludeId) {
                continue;
            }

            return true;
        }

        return false;
    }
}

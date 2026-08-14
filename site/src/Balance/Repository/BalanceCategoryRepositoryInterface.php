<?php

declare(strict_types=1);

namespace App\Balance\Repository;

use App\Balance\Entity\BalanceCategory;

interface BalanceCategoryRepositoryInterface
{
    public function findByIdAndCompany(string $id, string $companyId): ?BalanceCategory;

    /**
     * @return BalanceCategory[]
     */
    public function findRootByCompany(string $companyId): array;

    /**
     * @return BalanceCategory[]
     */
    public function findTreeByCompany(string $companyId): array;

    public function getNextSortOrder(string $companyId, ?BalanceCategory $parent): int;

    /**
     * @return BalanceCategory[]
     */
    public function findSiblings(string $companyId, ?BalanceCategory $parent): array;

    public function swapSortOrder(BalanceCategory $a, BalanceCategory $b): void;

    public function existsWithCode(string $companyId, string $code, ?string $excludeId = null): bool;
}

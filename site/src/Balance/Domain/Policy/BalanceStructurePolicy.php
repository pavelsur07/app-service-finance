<?php

declare(strict_types=1);

namespace App\Balance\Domain\Policy;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Exception\BalanceCategoryCycleException;
use App\Balance\Exception\BalanceDepthExceededException;
use App\Balance\Repository\BalanceCategoryRepositoryInterface;
use Webmozart\Assert\Assert;

final readonly class BalanceStructurePolicy
{
    public function __construct(private BalanceCategoryRepositoryInterface $balanceCategoryRepository)
    {
    }

    public function assertCanSetParent(
        BalanceCategory $category,
        ?string $parentId,
        string $companyId,
        int $maxLevel = 5,
    ): void {
        if (null === $parentId) {
            if ($category->getLevel() > 1) {
                $category->setParent(null);
            }

            return;
        }

        if ($parentId === $category->getId()) {
            throw new BalanceCategoryCycleException($category->getId(), $parentId);
        }

        $parent = $this->balanceCategoryRepository->findByIdAndCompany($parentId, $companyId);
        if (null === $parent) {
            throw new \DomainException(sprintf('Родительская категория %s не найдена.', $parentId));
        }

        if ($parent->getLevel() >= $maxLevel) {
            throw new BalanceDepthExceededException($maxLevel);
        }

        $this->assertNotAncestor($category, $parent);

        $category->setParent($parent);
    }

    public function assertCodeIsUnique(
        string $companyId,
        ?string $code,
        ?string $excludeCategoryId = null,
    ): void {
        if (null === $code || '' === $code) {
            return;
        }

        Assert::uuid($companyId);

        if ($this->balanceCategoryRepository->existsWithCode($companyId, $code, $excludeCategoryId)) {
            throw new \DomainException('Код должен быть уникален в рамках компании.');
        }
    }

    private function assertNotAncestor(BalanceCategory $candidate, BalanceCategory $proposedParent): void
    {
        $current = $proposedParent->getParent();
        while (null !== $current) {
            if ($current->getId() === $candidate->getId()) {
                throw new BalanceCategoryCycleException($candidate->getId(), $proposedParent->getId());
            }

            $current = $current->getParent();
        }
    }
}

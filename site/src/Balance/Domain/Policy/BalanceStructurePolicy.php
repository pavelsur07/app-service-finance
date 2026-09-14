<?php

declare(strict_types=1);

namespace App\Balance\Domain\Policy;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Exception\BalanceCategoryCycleException;
use App\Balance\Exception\BalanceDepthExceededException;
use App\Balance\Exception\BalanceLedgerException;
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
        int $maxLevel = 4,
    ): void {
        Assert::uuid($companyId);
        if ($category->getCompanyId() !== $companyId) {
            throw new BalanceLedgerException('Статья другой компании.');
        }
        $parent = null;
        if (null !== $parentId) {
            if (!\Ramsey\Uuid\Uuid::isValid($parentId)) {
                throw new BalanceLedgerException('Родительская статья не найдена.', 404);
            }
            if ($parentId === $category->getId()) {
                throw new BalanceCategoryCycleException($category->getId(), $parentId);
            }
            $parent = $this->balanceCategoryRepository->findByIdAndCompany($parentId, $companyId);
            if (null === $parent) {
                throw new BalanceLedgerException('Родительская статья не найдена.');
            }
            $this->assertNotAncestor($category, $parent);
        }
        $newLevel = null === $parent ? 1 : $parent->getLevel() + 1;
        if ($newLevel === $category->getLevel() && $category->getParent()?->getId() === $parentId) {
            $category->setParent($parent);

            return;
        }
        $descendants = [];
        foreach ($this->balanceCategoryRepository->findTreeByCompany($companyId) as $candidate) {
            $current = $candidate->getParent();
            $distance = 1;
            while (null !== $current) {
                if ($current->getId() === $category->getId()) {
                    if ($newLevel + $distance > $maxLevel) {
                        throw new BalanceDepthExceededException($maxLevel);
                    }
                    $descendants[] = $candidate;
                    break;
                }
                $current = $current->getParent();
                ++$distance;
            }
        }
        if ($newLevel > $maxLevel) {
            throw new BalanceDepthExceededException($maxLevel);
        }
        usort($descendants, static fn (BalanceCategory $a, BalanceCategory $b): int => $a->getLevel() <=> $b->getLevel());
        $category->setParent($parent);
        foreach ($descendants as $descendant) {
            $descendant->refreshLevel();
        }
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
            throw new BalanceLedgerException('Код должен быть уникален в рамках компании.');
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

<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Exception\BalanceCategoryNotFoundException;
use App\Balance\Repository\BalanceCategoryRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

final readonly class MoveBalanceCategoryAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BalanceCategoryRepositoryInterface $balanceCategoryRepository,
    ) {
    }

    public function __invoke(string $companyId, string $categoryId, string $direction): void
    {
        Assert::uuid($companyId);
        Assert::uuid($categoryId);
        Assert::inArray($direction, ['up', 'down']);

        $category = $this->balanceCategoryRepository->findByIdAndCompany($categoryId, $companyId);
        if (null === $category) {
            throw new BalanceCategoryNotFoundException($categoryId);
        }

        $siblings = $this->balanceCategoryRepository->findSiblings($companyId, $category->getParent());
        $siblingIds = array_map(static fn ($sibling): string => $sibling->getId(), $siblings);
        $index = array_search($category->getId(), $siblingIds, true);

        if (false === $index) {
            return;
        }

        $swapWith = null;
        if ('up' === $direction && $index > 0) {
            $swapWith = $siblings[$index - 1];
        } elseif ('down' === $direction && $index < \count($siblings) - 1) {
            $swapWith = $siblings[$index + 1];
        }

        if (null === $swapWith) {
            return;
        }

        $this->balanceCategoryRepository->swapSortOrder($category, $swapWith);
        $this->entityManager->flush();
    }
}

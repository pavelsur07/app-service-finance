<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Exception\BalanceCategoryNotFoundException;
use App\Balance\Repository\BalanceCategoryRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

final readonly class DeleteBalanceCategoryAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BalanceCategoryRepositoryInterface $balanceCategoryRepository,
    ) {
    }

    public function __invoke(string $companyId, string $categoryId): void
    {
        Assert::uuid($companyId);
        Assert::uuid($categoryId);

        $category = $this->balanceCategoryRepository->findByIdAndCompany($categoryId, $companyId);
        if (null === $category) {
            throw new BalanceCategoryNotFoundException($categoryId);
        }

        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }
}

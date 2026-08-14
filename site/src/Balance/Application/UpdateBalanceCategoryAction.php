<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Application\DTO\UpdateBalanceCategoryCommand;
use App\Balance\Domain\Policy\BalanceStructurePolicy;
use App\Balance\Exception\BalanceCategoryNotFoundException;
use App\Balance\Repository\BalanceCategoryRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

final readonly class UpdateBalanceCategoryAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BalanceCategoryRepositoryInterface $balanceCategoryRepository,
        private BalanceStructurePolicy $balanceStructurePolicy,
    ) {
    }

    public function __invoke(string $companyId, UpdateBalanceCategoryCommand $command): void
    {
        Assert::uuid($companyId);

        $category = $this->balanceCategoryRepository->findByIdAndCompany($command->id, $companyId);
        if (null === $category) {
            throw new BalanceCategoryNotFoundException($command->id);
        }

        $this->balanceStructurePolicy->assertCodeIsUnique($companyId, $command->code, $category->getId());

        $originalParent = $category->getParent();

        $category->setName($command->name);
        $category->setType($command->type);
        $category->setCode($command->code);
        $category->setIsVisible($command->isVisible);

        $parentId = $command->parentId;
        if (null !== $parentId || $category->getParent() !== null) {
            $this->balanceStructurePolicy->assertCanSetParent($category, $parentId, $companyId);
        }

        if ($category->getParent() !== $originalParent) {
            $sortOrder = $this->balanceCategoryRepository->getNextSortOrder($companyId, $category->getParent());
            $category->setSortOrder($sortOrder);
        }

        $this->entityManager->flush();
    }
}

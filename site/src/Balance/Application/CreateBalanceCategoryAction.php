<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Application\DTO\CreateBalanceCategoryCommand;
use App\Balance\Domain\Policy\BalanceStructurePolicy;
use App\Balance\Entity\BalanceCategory;
use App\Balance\Repository\BalanceCategoryRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final readonly class CreateBalanceCategoryAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BalanceCategoryRepositoryInterface $balanceCategoryRepository,
        private BalanceStructurePolicy $balanceStructurePolicy,
    ) {
    }

    public function __invoke(string $companyId, CreateBalanceCategoryCommand $command): string
    {
        Assert::uuid($companyId);

        $this->balanceStructurePolicy->assertCodeIsUnique($companyId, $command->code);

        $category = new BalanceCategory(Uuid::uuid7()->toString(), $companyId);
        $category->setName($command->name);
        $category->setType($command->type);
        $category->setCode($command->code);
        $category->setIsVisible($command->isVisible);

        if (null !== $command->parentId) {
            $this->balanceStructurePolicy->assertCanSetParent($category, $command->parentId, $companyId);
        }

        $sortOrder = $this->balanceCategoryRepository->getNextSortOrder($companyId, $category->getParent());
        $category->setSortOrder($sortOrder);

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category->getId();
    }
}

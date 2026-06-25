<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\ExternalCategoryMappingRepository;
use App\Ingestion\Repository\ExternalCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UpdateExternalCategoryMappingAction
{
    public function __construct(
        private ExternalCategoryRepository $categoryRepository,
        private ExternalCategoryMappingRepository $mappingRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        string $categoryId,
        string $canonicalCode,
        string $canonicalLabel,
        string $canonicalGroup,
        TransactionType $transactionType,
        int $sortOrder,
        ExternalCategoryMappingStatus $status,
        bool $known,
        ?TransactionDirection $defaultDirection = null,
        ?string $updatedBy = null,
    ): void {
        $category = $this->categoryRepository->find($categoryId);
        if (!$category instanceof ExternalCategory) {
            throw new \InvalidArgumentException('External category was not found.');
        }

        $mapping = $this->mappingRepository->findByCategory($category);
        if ($mapping instanceof ExternalCategoryMapping) {
            $mapping->update(
                canonicalCode: $canonicalCode,
                canonicalLabel: $canonicalLabel,
                canonicalGroup: $canonicalGroup,
                transactionType: $transactionType,
                sortOrder: $sortOrder,
                defaultDirection: $defaultDirection,
                known: $known,
                status: $status,
                updatedBy: $updatedBy,
            );
        } else {
            $this->entityManager->persist(new ExternalCategoryMapping(
                externalCategory: $category,
                canonicalCode: $canonicalCode,
                canonicalLabel: $canonicalLabel,
                canonicalGroup: $canonicalGroup,
                transactionType: $transactionType,
                sortOrder: $sortOrder,
                defaultDirection: $defaultDirection,
                known: $known,
                status: $status,
                updatedBy: $updatedBy,
            ));
        }

        if (ExternalCategoryMappingStatus::NEEDS_REVIEW === $status) {
            $category->markNew();
        }
        if (ExternalCategoryMappingStatus::DISABLED === $status) {
            $category->markIgnored();
        }

        $this->entityManager->flush();
    }
}

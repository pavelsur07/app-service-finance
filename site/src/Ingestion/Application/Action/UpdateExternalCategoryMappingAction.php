<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryCompanyMapping;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Entity\ExternalCategoryMappingAudit;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\ExternalCategoryCompanyMappingRepository;
use App\Ingestion\Repository\ExternalCategoryMappingRepository;
use App\Ingestion\Repository\ExternalCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

final readonly class UpdateExternalCategoryMappingAction
{
    public function __construct(
        private ExternalCategoryRepository $categoryRepository,
        private ExternalCategoryMappingRepository $mappingRepository,
        private ExternalCategoryCompanyMappingRepository $companyMappingRepository,
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
        ?string $displayLabel = null,
        ?TransactionDirection $defaultDirection = null,
        ?string $updatedBy = null,
        ?string $companyId = null,
    ): void {
        $category = $this->categoryRepository->find($categoryId);
        if (!$category instanceof ExternalCategory) {
            throw new \InvalidArgumentException('External category was not found.');
        }

        $companyId = $this->optionalString($companyId);
        if (null !== $companyId) {
            Assert::uuid($companyId);
        }

        $this->entityManager->wrapInTransaction(function () use (
            $category,
            $canonicalCode,
            $canonicalLabel,
            $canonicalGroup,
            $transactionType,
            $sortOrder,
            $status,
            $known,
            $displayLabel,
            $defaultDirection,
            $updatedBy,
            $companyId,
        ): void {
            if (null !== $companyId) {
                $mapping = $this->companyMappingRepository->findByCategoryAndCompany($category, $companyId);
                $oldValues = $this->snapshot($mapping);

                if ($mapping instanceof ExternalCategoryCompanyMapping) {
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
                    $mapping = new ExternalCategoryCompanyMapping(
                        externalCategory: $category,
                        companyId: $companyId,
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
                    $this->entityManager->persist($mapping);
                }

                $this->audit($category, 'company', null === $oldValues ? 'create' : 'update', $oldValues, $this->snapshot($mapping), $updatedBy, $companyId);

                return;
            }

            $mapping = $this->mappingRepository->findByCategory($category);
            $oldValues = $this->snapshot($mapping);
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
                $mapping = new ExternalCategoryMapping(
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
                );
                $this->entityManager->persist($mapping);
            }

            if (ExternalCategoryMappingStatus::NEEDS_REVIEW === $status) {
                $category->markNew();
            }
            if (ExternalCategoryMappingStatus::DISABLED === $status) {
                $category->markIgnored();
            }
            $category->updateDisplayLabel($displayLabel);

            $this->audit($category, 'default', null === $oldValues ? 'create' : 'update', $oldValues, $this->snapshot($mapping), $updatedBy);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshot(ExternalCategoryMapping|ExternalCategoryCompanyMapping|null $mapping): ?array
    {
        if (null === $mapping) {
            return null;
        }

        return [
            'canonicalCode' => $mapping->getCanonicalCode(),
            'canonicalLabel' => $mapping->getCanonicalLabel(),
            'canonicalGroup' => $mapping->getCanonicalGroup(),
            'transactionType' => $mapping->getTransactionType()->value,
            'defaultDirection' => $mapping->getDefaultDirection()?->value,
            'sortOrder' => $mapping->getSortOrder(),
            'known' => $mapping->isKnown(),
            'status' => $mapping->getStatus()->value,
        ];
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed> $newValues
     */
    private function audit(
        ExternalCategory $category,
        string $scope,
        string $action,
        ?array $oldValues,
        array $newValues,
        ?string $updatedBy,
        ?string $companyId = null,
    ): void {
        $this->entityManager->persist(new ExternalCategoryMappingAudit(
            externalCategory: $category,
            scope: $scope,
            action: $action,
            oldValues: $oldValues,
            newValues: $newValues,
            companyId: $companyId,
            updatedBy: $updatedBy,
        ));
    }

    private function optionalString(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}

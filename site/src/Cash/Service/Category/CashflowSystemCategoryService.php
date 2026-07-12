<?php

namespace App\Cash\Service\Category;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

class CashflowSystemCategoryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashflowCategoryRepository $cashflowCategoryRepository,
    ) {
    }

    /**
     * @return array<string, CashflowCategory>
     */
    public function ensureStructure(Company $company): array
    {
        $operating = $this->ensureCategory($company, CashflowCategory::CODE_OPERATING, 'Операционная деятельность', CashflowFlowKind::OPERATING, 10);
        $financing = $this->ensureCategory($company, CashflowCategory::CODE_FINANCING, 'Финансовая деятельность', CashflowFlowKind::FINANCING, 20);
        $investing = $this->ensureCategory($company, CashflowCategory::CODE_INVESTING, 'Инвестиционная деятельность', CashflowFlowKind::INVESTING, 30);
        $technical = $this->ensureCategory($company, CashflowCategory::CODE_TECHNICAL, 'Технические операции', CashflowFlowKind::TECHNICAL, 40);
        $technicalIn = $this->ensureCategory($company, CashflowCategory::CODE_TECHNICAL_IN, 'Поступления', CashflowFlowKind::TECHNICAL, 10, $technical);
        $technicalOut = $this->ensureCategory($company, CashflowCategory::CODE_TECHNICAL_OUT, 'Выбытия', CashflowFlowKind::TECHNICAL, 20, $technical);
        $unallocated = $this->getOrCreateUnallocated($company);

        return [
            CashflowCategory::CODE_OPERATING => $operating,
            CashflowCategory::CODE_FINANCING => $financing,
            CashflowCategory::CODE_INVESTING => $investing,
            CashflowCategory::CODE_TECHNICAL => $technical,
            CashflowCategory::CODE_TECHNICAL_IN => $technicalIn,
            CashflowCategory::CODE_TECHNICAL_OUT => $technicalOut,
            CashflowCategory::CODE_UNALLOCATED => $unallocated,
        ];
    }

    public function getOrCreateUnallocated(Company $company): CashflowCategory
    {
        $existing = $this->cashflowCategoryRepository->findSystemUnallocatedByCompany($company);
        if (null !== $existing) {
            return $this->assertSystemCategory($existing, CashflowCategory::CODE_UNALLOCATED);
        }

        return $this->createCategory(
            $company,
            CashflowCategory::CODE_UNALLOCATED,
            'Не распределено',
            CashflowFlowKind::OPERATING,
            50,
        );
    }

    private function ensureCategory(
        Company $company,
        string $code,
        string $name,
        CashflowFlowKind $flowKind,
        int $sort,
        ?CashflowCategory $parent = null,
    ): CashflowCategory {
        $existing = $this->cashflowCategoryRepository->findOneByCompanyAndCode($company, $code);
        if (null !== $existing) {
            return $this->assertSystemCategory($existing, $code);
        }

        return $this->createCategory($company, $code, $name, $flowKind, $sort, $parent);
    }

    private function createCategory(
        Company $company,
        string $code,
        string $name,
        CashflowFlowKind $flowKind,
        int $sort,
        ?CashflowCategory $parent = null,
    ): CashflowCategory {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        $category->setParent($parent);
        $category->setSort($sort);
        $category->setFlowKind($flowKind);
        $category->markAsSystem($code);

        $this->entityManager->persist($category);

        return $category;
    }

    private function assertSystemCategory(CashflowCategory $category, string $code): CashflowCategory
    {
        if (!$category->isSystem()) {
            throw new \DomainException(sprintf('Код %s занят обычной категорией.', $code));
        }

        return $category;
    }
}

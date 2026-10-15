<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Company\Facade\CompanyFacade;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Создаёт системные статьи ДДС компании (виды деятельности, технические,
 * «нераспределённое»). Идемпотентно: существующие статьи не трогает.
 */
final class EnsureCashflowSystemCategoriesAction
{
    public function __construct(
        private readonly CompanyFacade $companyFacade,
        private readonly CashflowSystemCategoryService $systemCategories,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(string $companyId): void
    {
        $company = $this->companyFacade->findById($companyId)
            ?? throw new \DomainException(sprintf('Компания %s не найдена.', $companyId));

        $this->systemCategories->ensureStructure($company);
        $this->entityManager->flush();
    }
}

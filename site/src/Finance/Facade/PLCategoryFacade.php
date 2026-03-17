<?php

declare(strict_types=1);

namespace App\Finance\Facade;

use App\Company\Facade\CompanyFacade;
use App\Finance\DTO\PLCategoryDTO;
use App\Repository\PLCategoryRepository;

/**
 * Публичный API модуля Finance для других модулей.
 *
 * Возвращает scalar DTO — не Entity.
 * Модули Marketplace, Cash и др. используют только этот Facade,
 * не обращаясь к PLCategoryRepository напрямую.
 */
final class PLCategoryFacade
{
    public function __construct(
        private readonly PLCategoryRepository $repository,
        private readonly CompanyFacade        $companyFacade,
    ) {
    }

    /**
     * Дерево категорий ОПиУ компании в порядке обхода.
     *
     * @return PLCategoryDTO[]
     */
    public function getTreeByCompanyId(string $companyId): array
    {
        $company = $this->companyFacade->findById($companyId);
        if ($company === null) {
            return [];
        }

        $entities = $this->repository->findTreeByCompany($company);

        return array_map(
            static fn($cat) => new PLCategoryDTO(
                id:        (string) $cat->getId(),
                name:      $cat->getName(),
                level:     $cat->getLevel(),
                parentId:  $cat->getParent()?->getId(),
                sortOrder: $cat->getSortOrder(),
                code:      $cat->getCode(),
            ),
            $entities,
        );
    }

    /**
     * Найти одну категорию по ID с проверкой принадлежности компании.
     */
    public function findByIdAndCompany(string $categoryId, string $companyId): ?PLCategoryDTO
    {
        $company = $this->companyFacade->findById($companyId);
        if ($company === null) {
            return null;
        }

        $cat = $this->repository->find($categoryId);
        if ($cat === null || (string) $cat->getCompany()->getId() !== $companyId) {
            return null;
        }

        return new PLCategoryDTO(
            id:        (string) $cat->getId(),
            name:      $cat->getName(),
            level:     $cat->getLevel(),
            parentId:  $cat->getParent()?->getId(),
            sortOrder: $cat->getSortOrder(),
            code:      $cat->getCode(),
        );
    }
}

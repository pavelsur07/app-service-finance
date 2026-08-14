<?php

declare(strict_types=1);

namespace App\Balance\Facade;

use App\Balance\Infrastructure\Query\BalanceReportQuery;
use App\Balance\ReadModel\BalanceReport;
use App\Balance\Repository\BalanceCategoryRepositoryInterface;

final readonly class BalanceFacade
{
    public function __construct(
        private BalanceCategoryRepositoryInterface $balanceCategoryRepository,
        private BalanceReportQuery $balanceReportQuery,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, level: int, type: string, children: list<mixed>}>
     */
    public function getCategoriesForCompany(string $companyId): array
    {
        /** @var array<string, array{id: string, name: string, level: int, type: string, children: list<mixed>}> $byId */
        $byId = [];
        /** @var list<string> $rootIds */
        $rootIds = [];

        foreach ($this->balanceCategoryRepository->findTreeByCompany($companyId) as $category) {
            $byId[$category->getId()] = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'level' => $category->getLevel(),
                'type' => $category->getType()->value,
                'children' => [],
            ];

            $parent = $category->getParent();
            if (null === $parent) {
                $rootIds[] = $category->getId();
            } else {
                $byId[$parent->getId()]['children'][] = $category->getId();
            }
        }

        return $this->buildTree($byId, $rootIds);
    }

    /**
     * @param array<string, array{id: string, name: string, level: int, type: string, children: list<mixed>}> $byId
     * @param list<string> $ids
     *
     * @return list<array{id: string, name: string, level: int, type: string, children: list<mixed>}>
     */
    private function buildTree(array $byId, array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $node = $byId[$id];
            $node['children'] = $this->buildTree($byId, $node['children']);
            $result[] = $node;
        }

        return $result;
    }

    public function getReportForCompany(string $companyId, \DateTimeImmutable $date): BalanceReport
    {
        return $this->balanceReportQuery->buildForCompanyAndDate($companyId, $date);
    }

    /**
     * @param list<string> $excludeCategoryIds
     *
     * @return array<string, string> display label => id
     */
    public function getCategoryChoicesForCompany(string $companyId, array $excludeCategoryIds = []): array
    {
        $excludeCategoryIds = array_flip($excludeCategoryIds);
        $choices = [];
        foreach ($this->balanceCategoryRepository->findTreeByCompany($companyId) as $category) {
            if (isset($excludeCategoryIds[$category->getId()])) {
                continue;
            }

            $label = str_repeat('—', max($category->getLevel() - 1, 0)).' '.$category->getName();
            $choices[$label] = $category->getId();
        }

        return $choices;
    }
}

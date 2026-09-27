<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Application\DTO\CostPLMappingRow;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;

/**
 * Список страницы маппинга затрат одной выборкой: активные категории затрат
 * компании с их маппингом, без гидрации сущностей.
 */
final readonly class CostPLMappingListQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<CostPLMappingRow>
     */
    public function fetch(string $companyId, ?MarketplaceType $marketplace): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'cc.id',
                'cc.name',
                'cc.code',
                'cc.marketplace',
                'cc.is_system',
                'm.id AS mapping_id',
                'm.pl_category_id',
                'm.include_in_pl',
                'm.sort_order',
            )
            ->from('marketplace_cost_categories', 'cc')
            ->leftJoin(
                'cc',
                'marketplace_cost_pl_mappings',
                'm',
                'm.cost_category_id = cc.id AND m.company_id = cc.company_id',
            )
            ->where('cc.company_id = :companyId')
            ->andWhere('cc.is_active = true')
            ->andWhere('cc.deleted_at IS NULL')
            ->setParameter('companyId', $companyId)
            ->orderBy('cc.name', 'ASC')
            ->addOrderBy('cc.id', 'ASC');

        if (null !== $marketplace) {
            $qb->andWhere('cc.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace->value);
        }

        return array_map(
            static fn (array $row): CostPLMappingRow => new CostPLMappingRow(
                costCategoryId: (string) $row['id'],
                name: (string) $row['name'],
                code: (string) $row['code'],
                marketplace: (string) $row['marketplace'],
                isSystem: (bool) $row['is_system'],
                hasMapping: null !== $row['mapping_id'],
                plCategoryId: null !== $row['pl_category_id'] ? (string) $row['pl_category_id'] : null,
                includeInPl: null === $row['mapping_id'] || (bool) $row['include_in_pl'],
                sortOrder: (int) ($row['sort_order'] ?? 0),
            ),
            $qb->executeQuery()->fetchAllAssociative(),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\DTO\OzonAccrualTypeQueueItem;
use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonAccrualTypeProposer;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Infrastructure\Query\OzonAccrualTypeQueueQuery;
use App\Ingestion\Repository\ExternalCategoryRepository;
use App\Ingestion\Repository\OzonAccrualTypeRepository;

/**
 * Очередь «Новые типы Ozon»: типы справочника с неразобранными начислениями и
 * предложение статьи для каждого. Тип вне справочника сюда не попадает — он
 * остаётся в общей очереди неразобранных категорий.
 */
final readonly class OzonAccrualTypeQueue
{
    public function __construct(
        private OzonAccrualTypeQueueQuery $query,
        private OzonAccrualTypeRepository $typeRepository,
        private OzonAccrualTypeProposer $proposer,
        private ExternalCategoryRepository $categoryRepository,
    ) {
    }

    /**
     * @return list<OzonAccrualTypeQueueItem>
     */
    public function items(int $limit = 100): array
    {
        $usage = $this->query->unknownUsage($limit);
        if ([] === $usage) {
            return [];
        }

        $dictionary = $this->typeRepository->findAllIndexedByTypeId();
        $types = [];
        foreach ($usage as $row) {
            if (isset($dictionary[$row->typeId])) {
                $types[] = $dictionary[$row->typeId];
            }
        }
        $proposals = $this->proposer->proposeAll($types);

        $items = [];
        foreach ($usage as $row) {
            $type = $dictionary[$row->typeId] ?? null;
            if (null === $type) {
                continue;
            }

            $items[] = new OzonAccrualTypeQueueItem(
                typeId: $row->typeId,
                name: $type->getName(),
                description: $type->getDescription(),
                removed: $type->isRemoved(),
                transactions: $row->transactions,
                firstDate: $row->firstDate,
                lastDate: $row->lastDate,
                transactionType: $row->transactionType,
                proposal: $proposals[$row->typeId],
                awaitingRefresh: [] === $this->categoryRepository->findByTypeIdOrKeys(
                    IngestSource::OZON,
                    OzonResourceType::ACCRUAL_BY_DAY,
                    (string) $row->typeId,
                    array_values(array_filter([
                        OzonAccrualCategoryTaxonomyResolver::typeKey((string) $row->typeId),
                        OzonAccrualCategoryTaxonomyResolver::codeKey($type->getName()),
                        OzonAccrualCategoryTaxonomyResolver::nameKey($type->getName()),
                    ])),
                    [ExternalCategoryStatus::NEW, ExternalCategoryStatus::NEEDS_IDENTIFICATION],
                ),
            );
        }

        return $items;
    }
}

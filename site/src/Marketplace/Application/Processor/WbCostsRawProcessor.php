<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\ProcessWbCostsAction;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceCostExistingExternalIdsQuery;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Service\CostCalculator\CostCalculatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class WbCostsRawProcessor implements MarketplaceRawProcessorInterface
{
    /** @var iterable<CostCalculatorInterface> */
    private iterable $costCalculators;

    public function __construct(
        private readonly ProcessWbCostsAction $action,
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly WbListingResolverService $listingResolver,
        private readonly MarketplaceCostExistingExternalIdsQuery $costExistingIdsQuery,
        private readonly MarketplaceCostCategoryResolver $categoryResolver,
        private readonly LoggerInterface $logger,
        iterable $costCalculators,
    ) {
        $this->costCalculators = $costCalculators;
    }

    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::COST
                && $marketplace === MarketplaceType::WILDBERRIES;
        }

        return $type === MarketplaceType::WILDBERRIES->value && $kind === 'costs';
    }

    public function process(string $companyId, string $rawDocId): int
    {
        return ($this->action)($companyId, $rawDocId);
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows): void
    {
        if (empty($rawRows)) {
            return;
        }

        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: ' . $companyId);
        }

        $costsData = array_filter($rawRows, static function (array $item): bool {
            return ($item['doc_type_name'] ?? '') !== 'Возврат';
        });

        if (empty($costsData)) {
            return;
        }

        // Предзагрузка листингов
        $allNmIdsMap = [];
        foreach ($costsData as $item) {
            $nmId = trim((string) ($item['nm_id'] ?? ''));
            if ($nmId !== '' && $nmId !== '0') {
                $allNmIdsMap[$nmId] = true;
            }
        }

        $listingsCache = [];
        if (!empty($allNmIdsMap)) {
            $listingsCache = $this->listingRepository->findListingsByNmIdsIndexed(
                $company,
                MarketplaceType::WILDBERRIES,
                array_keys($allNmIdsMap),
            );
        }

        // Создаём отсутствующие листинги
        $newListings = 0;
        foreach ($costsData as $item) {
            $nmId = trim((string) ($item['nm_id'] ?? ''));
            if ($nmId === '' || $nmId === '0') {
                continue;
            }

            $tsName = $item['ts_name'] ?? null;
            $size = trim((string) $tsName) !== '' ? trim((string) $tsName) : 'UNKNOWN';
            $cacheKey = $nmId . '_' . $size;

            if (isset($listingsCache[$cacheKey])) {
                continue;
            }

            $listing = $this->listingResolver->resolve($company, $nmId, $tsName, [
                'sa_name'      => (string) ($item['sa_name'] ?? ''),
                'brand_name'   => (string) ($item['brand_name'] ?? ''),
                'subject_name' => (string) ($item['subject_name'] ?? ''),
                'retail_price' => (string) ($item['retail_price'] ?? '0'),
            ]);
            $listingsCache[$cacheKey] = $listing;
            $newListings++;
        }

        if ($newListings > 0) {
            $this->em->flush();
        }

        // Прогрев категорий
        $this->categoryResolver->preload($company, MarketplaceType::WILDBERRIES);

        // Собираем все cost entries
        $allEntries = [];
        foreach ($costsData as $item) {
            $nmId = trim((string) ($item['nm_id'] ?? ''));
            $listing = null;
            if ($nmId !== '' && $nmId !== '0') {
                $tsName = $item['ts_name'] ?? null;
                $size = trim((string) $tsName) !== '' ? trim((string) $tsName) : 'UNKNOWN';
                $listing = $listingsCache[$nmId . '_' . $size] ?? null;
            }

            foreach ($this->costCalculators as $calculator) {
                if (!$calculator->supports($item)) {
                    continue;
                }
                foreach ($calculator->calculate($item, $listing) as $costData) {
                    $allEntries[] = ['costData' => $costData, 'listing' => $listing];
                }
            }
        }

        if (empty($allEntries)) {
            return;
        }

        // Дедупликация
        $allExternalIds = array_unique(array_map(
            static fn (array $row): string => $row['costData']['external_id'],
            $allEntries,
        ));
        $existingMap = $this->costExistingIdsQuery->execute($companyId, $allExternalIds);

        // Сохраняем
        foreach ($allEntries as $row) {
            $costData = $row['costData'];
            $externalId = $costData['external_id'];

            if (isset($existingMap[$externalId])) {
                continue;
            }

            $categoryCode = $costData['category_code'];
            $categoryName = $costData['category_name'] ?? $costData['description'] ?? $categoryCode;
            $category = $this->categoryResolver->resolve(
                $company,
                MarketplaceType::WILDBERRIES,
                $categoryCode,
                $categoryName,
            );

            $cost = new MarketplaceCost(
                Uuid::uuid4()->toString(),
                $company,
                MarketplaceType::WILDBERRIES,
                $category,
            );

            $cost->setExternalId($externalId);
            $cost->setCostDate($costData['cost_date']);
            $cost->setAmount($costData['amount']);
            $cost->setDescription($costData['description']);

            if ($row['listing']) {
                $cost->setListing($row['listing']);
            }

            $this->em->persist($cost);
            $existingMap[$externalId] = true;
        }

        $this->em->flush();
    }
}

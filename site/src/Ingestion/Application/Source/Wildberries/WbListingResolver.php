<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\DTO\ListingResolution;
use App\Ingestion\Domain\Contract\BulkListingResolverInterface;
use App\Ingestion\Enum\IngestSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceListingLinkingFacade;

/**
 * Привязка позиций заказов Wildberries к листингам Marketplace.
 *
 * Резолвер ЧИТАЮЩИЙ: в отличие от Ozon он не заводит недостающие листинги.
 * Для Ozon это возможно потому, что каталог загружается отдельной задачей и
 * `ensureOzonListings` знает, чем заполнить карточку. Аналога для WB пока нет,
 * а создавать листинг из одного номенклатурного номера значило бы плодить
 * пустые карточки, которые потом никто не отличит от настоящих.
 *
 * Нерезолвленная позиция не теряется: она сохраняет `listingSku` при
 * `listingId = null` — это видимая очередь на разбор.
 *
 * Порядок поиска: сначала `nmId` как SKU маркетплейса, затем артикул продавца.
 * Именно в таком порядке, потому что `nmId` присваивает WB и он уникален, а
 * артикул задаёт продавец и может повторяться между карточками.
 */
final readonly class WbListingResolver implements BulkListingResolverInterface
{
    public function __construct(private MarketplaceListingLinkingFacade $listingFacade)
    {
    }

    public function supports(IngestSource $source): bool
    {
        return IngestSource::WILDBERRIES === $source;
    }

    /**
     * @param array<string, mixed> $sourceData
     */
    public function resolve(string $companyId, array $sourceData): ?ListingResolution
    {
        return $this->resolveMany($companyId, [0 => $sourceData])[0] ?? null;
    }

    /**
     * @param array<int|string, array<string, mixed>> $sourceDataRows
     *
     * @return array<int|string, ListingResolution|null>
     */
    public function resolveMany(string $companyId, array $sourceDataRows): array
    {
        $result = array_fill_keys(array_keys($sourceDataRows), null);
        if ([] === $sourceDataRows) {
            return $result;
        }

        $nmIdByKey = [];
        $articleByKey = [];
        foreach ($sourceDataRows as $key => $sourceData) {
            $nmId = $this->stringOrNull($sourceData['nm_id'] ?? null);
            if (null !== $nmId) {
                $nmIdByKey[$key] = $nmId;
            }

            $article = $this->stringOrNull($sourceData['supplier_article'] ?? null);
            if (null !== $article) {
                $articleByKey[$key] = $article;
            }
        }

        // Два запроса на весь батч, а не два на позицию.
        $byNmId = [] === $nmIdByKey
            ? []
            : $this->listingFacade->findByMarketplaceSkus(
                $companyId,
                MarketplaceType::WILDBERRIES->value,
                array_values(array_unique($nmIdByKey)),
            );

        foreach ($nmIdByKey as $key => $nmId) {
            $reference = $byNmId[$nmId] ?? null;
            if (null !== $reference) {
                $result[$key] = new ListingResolution($reference->listingId, $reference->marketplaceSku);
            }
        }

        $unresolvedArticles = [];
        foreach ($articleByKey as $key => $article) {
            if (null === $result[$key]) {
                $unresolvedArticles[$key] = $article;
            }
        }

        if ([] === $unresolvedArticles) {
            return $result;
        }

        $byArticle = $this->listingFacade->findBySupplierSkus(
            $companyId,
            MarketplaceType::WILDBERRIES->value,
            array_values(array_unique($unresolvedArticles)),
        );

        foreach ($unresolvedArticles as $key => $article) {
            $reference = $byArticle[$article] ?? null;
            if (null !== $reference) {
                $result[$key] = new ListingResolution($reference->listingId, $reference->marketplaceSku);
            }
        }

        return $result;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $string = trim((string) $value);

        return '' === $string ? null : $string;
    }
}

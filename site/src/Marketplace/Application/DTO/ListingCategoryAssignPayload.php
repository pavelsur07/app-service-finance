<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Exception\InvalidListingTagPayloadException;
use Ramsey\Uuid\Uuid;

/**
 * Тело `POST /api/marketplace/listings/categories/assign`: ровно одно из
 * `listingIds` (до 500 uuid) или `filter`, и обязательный `categoryId` (uuid или null — снять).
 */
final readonly class ListingCategoryAssignPayload
{
    public const MAX_LISTING_IDS = 500;

    /**
     * @param list<string> $listingIds
     */
    private function __construct(
        public array $listingIds,
        public ?ListingsFilter $filter,
        public ?string $categoryId,
    ) {
    }

    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new InvalidListingTagPayloadException('invalid_json', 'Тело запроса должно быть JSON-объектом.');
        }

        if (!array_key_exists('categoryId', $data)) {
            throw new InvalidListingTagPayloadException('category_id_required', 'categoryId обязателен (null — снять категорию).');
        }
        $categoryId = $data['categoryId'];
        if (null !== $categoryId && (!is_string($categoryId) || !Uuid::isValid($categoryId))) {
            throw new InvalidListingTagPayloadException('category_id_invalid', 'categoryId должен быть uuid или null.');
        }

        if (array_key_exists('filter', $data)) {
            if (array_key_exists('listingIds', $data)) {
                throw new InvalidListingTagPayloadException('listing_selection_required', 'Укажите ровно одно из полей: listingIds или filter.');
            }

            return new self([], ListingsFilter::fromPayload($data['filter']), $categoryId);
        }

        $listingIds = $data['listingIds'] ?? null;
        if (!is_array($listingIds) || [] === $listingIds) {
            throw new InvalidListingTagPayloadException('listing_ids_required', 'listingIds должен быть непустым массивом.');
        }
        if (count($listingIds) > self::MAX_LISTING_IDS) {
            throw new InvalidListingTagPayloadException('listing_ids_limit_exceeded', sprintf('За один раз можно обработать не более %d листингов.', self::MAX_LISTING_IDS));
        }
        foreach ($listingIds as $listingId) {
            if (!is_string($listingId) || !Uuid::isValid($listingId)) {
                throw new InvalidListingTagPayloadException('listing_id_invalid', 'Каждый элемент listingIds должен быть uuid.');
            }
        }

        return new self(array_values(array_unique($listingIds)), null, $categoryId);
    }
}

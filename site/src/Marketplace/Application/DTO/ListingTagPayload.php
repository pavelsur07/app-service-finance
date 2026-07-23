<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Entity\MarketplaceListingTag;
use App\Marketplace\Exception\InvalidListingTagPayloadException;
use Ramsey\Uuid\Uuid;

/**
 * Разбор тела запросов assign/detach. Один класс на оба endpoint'а: список
 * listingIds валидируется одинаково, разъехавшиеся копии этой проверки —
 * прямой путь к дыре в скоупе компании.
 */
final readonly class ListingTagPayload
{
    public const MAX_LISTING_IDS = 500;

    /**
     * @param list<string> $listingIds
     */
    private function __construct(
        public array $listingIds,
        public ?string $tagId,
        public ?string $tagName,
    ) {
    }

    public static function forAssign(mixed $data): self
    {
        $data = self::asArray($data);
        $listingIds = self::parseListingIds($data);

        $tagId = self::parseOptionalString($data, 'tagId');
        $tagName = self::parseOptionalString($data, 'name');

        if ((null === $tagId) === (null === $tagName)) {
            throw new InvalidListingTagPayloadException('tag_reference_required', 'Укажите ровно одно из полей: tagId или name.');
        }

        if (null !== $tagId && !Uuid::isValid($tagId)) {
            throw new InvalidListingTagPayloadException('tag_id_invalid', 'tagId должен быть uuid.');
        }

        if (null !== $tagName) {
            $tagName = trim($tagName);
            if ('' === $tagName || \mb_strlen($tagName) > MarketplaceListingTag::NAME_MAX_LENGTH) {
                throw new InvalidListingTagPayloadException('tag_name_invalid', sprintf('Название тега должно быть от 1 до %d символов.', MarketplaceListingTag::NAME_MAX_LENGTH));
            }
        }

        return new self($listingIds, $tagId, $tagName);
    }

    public static function forDetach(mixed $data): self
    {
        $data = self::asArray($data);
        $listingIds = self::parseListingIds($data);

        $tagId = self::parseOptionalString($data, 'tagId');
        if (null === $tagId) {
            throw new InvalidListingTagPayloadException('tag_reference_required', 'tagId обязателен.');
        }

        if (!Uuid::isValid($tagId)) {
            throw new InvalidListingTagPayloadException('tag_id_invalid', 'tagId должен быть uuid.');
        }

        return new self($listingIds, $tagId, null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function asArray(mixed $data): array
    {
        if (!is_array($data)) {
            throw new InvalidListingTagPayloadException('invalid_json', 'Тело запроса должно быть JSON-объектом.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function parseListingIds(array $data): array
    {
        $listingIds = $data['listingIds'] ?? null;

        if (!is_array($listingIds) || [] === $listingIds) {
            throw new InvalidListingTagPayloadException('listing_ids_required', 'listingIds должен быть непустым массивом.');
        }

        if (count($listingIds) > self::MAX_LISTING_IDS) {
            throw new InvalidListingTagPayloadException('listing_ids_limit_exceeded', sprintf('За один раз можно обработать не более %d листингов.', self::MAX_LISTING_IDS));
        }

        $parsed = [];
        foreach ($listingIds as $listingId) {
            if (!is_string($listingId) || !Uuid::isValid($listingId)) {
                throw new InvalidListingTagPayloadException('listing_id_invalid', 'Каждый элемент listingIds должен быть uuid.');
            }

            $parsed[] = $listingId;
        }

        return array_values(array_unique($parsed));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseOptionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidListingTagPayloadException('tag_reference_required', sprintf('Поле %s должно быть строкой.', $key));
        }

        return $value;
    }
}

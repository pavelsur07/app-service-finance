<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\InvalidListingTagPayloadException;
use Ramsey\Uuid\Uuid;

/**
 * Фильтр реестра листингов. Один класс на страницу и на назначение тега по
 * фильтру: «показали N» и «назначили N» должны считаться одним и тем же
 * запросом, иначе тег уйдёт не тем листингам.
 */
final readonly class ListingsFilter
{
    public const SEARCH_MAX_LENGTH = 100;
    public const TAG_NONE = 'none';

    private const FILTER_MAPPED = 'mapped';
    private const FILTER_UNMAPPED = 'unmapped';
    private const FILTER_ALL = 'all';

    public function __construct(
        public ?bool $mapped = null,
        public ?MarketplaceType $marketplace = null,
        public ?string $search = null,
        public ?string $tagId = null,
        public bool $withoutTags = false,
    ) {
    }

    /**
     * Страница реестра: невалидные значения игнорируются, как раньше
     * игнорировался неизвестный marketplace. Массив, а не InputBag: `q[]=`
     * из адресной строки не должен превращаться в 400.
     *
     * @param array<mixed> $query `$request->query->all()`
     */
    public static function fromQuery(array $query): self
    {
        $string = static fn (string $key): string => is_string($query[$key] ?? null) ? $query[$key] : '';

        $search = trim($string('q'));
        $tag = $string('tag');

        return new self(
            mapped: self::mappedFromString($string('filter')),
            marketplace: MarketplaceType::tryFrom($string('marketplace')),
            search: '' === $search ? null : trim(mb_substr($search, 0, self::SEARCH_MAX_LENGTH)),
            tagId: Uuid::isValid($tag) ? $tag : null,
            withoutTags: self::TAG_NONE === $tag,
        );
    }

    /**
     * Тело API: фильтр решает, каким листингам уйдёт тег, поэтому
     * невалидное значение — 422, а не молчаливое расширение выборки.
     */
    public static function fromPayload(mixed $data): self
    {
        if (!is_array($data) || ([] !== $data && array_is_list($data))) {
            throw self::invalid('filter должен быть объектом.');
        }

        $filter = self::optionalString($data, 'filter') ?? self::FILTER_ALL;
        if (!in_array($filter, [self::FILTER_ALL, self::FILTER_MAPPED, self::FILTER_UNMAPPED], true)) {
            throw self::invalid('filter.filter: допустимо all, mapped, unmapped.');
        }

        $marketplaceValue = self::optionalString($data, 'marketplace');
        $marketplace = null;
        if (null !== $marketplaceValue && '' !== $marketplaceValue) {
            $marketplace = MarketplaceType::tryFrom($marketplaceValue)
                ?? throw self::invalid('filter.marketplace: неизвестный маркетплейс.');
        }

        $search = trim(self::optionalString($data, 'q') ?? '');
        if (\mb_strlen($search) > self::SEARCH_MAX_LENGTH) {
            throw self::invalid(sprintf('filter.q: не длиннее %d символов.', self::SEARCH_MAX_LENGTH));
        }

        $tag = self::optionalString($data, 'tag') ?? '';
        if ('' !== $tag && self::TAG_NONE !== $tag && !Uuid::isValid($tag)) {
            throw self::invalid('filter.tag: uuid тега или none.');
        }

        return new self(
            mapped: self::mappedFromString($filter),
            marketplace: $marketplace,
            search: '' === $search ? null : $search,
            tagId: '' !== $tag && self::TAG_NONE !== $tag ? $tag : null,
            withoutTags: self::TAG_NONE === $tag,
        );
    }

    /**
     * Параметры строки запроса страницы: ссылки пагинации, форма фильтров и
     * фильтр для назначения тега. Пустые значения не попадают.
     *
     * @return array<string, string>
     */
    public function toQueryParams(): array
    {
        $params = [
            'filter' => match ($this->mapped) {
                true => self::FILTER_MAPPED,
                false => self::FILTER_UNMAPPED,
                null => self::FILTER_ALL,
            },
        ];

        if (null !== $this->marketplace) {
            $params['marketplace'] = $this->marketplace->value;
        }
        if (null !== $this->search) {
            $params['q'] = $this->search;
        }
        if ($this->withoutTags) {
            $params['tag'] = self::TAG_NONE;
        } elseif (null !== $this->tagId) {
            $params['tag'] = $this->tagId;
        }

        return $params;
    }

    public function isEmpty(): bool
    {
        return null === $this->mapped
            && null === $this->marketplace
            && null === $this->search
            && null === $this->tagId
            && !$this->withoutTags;
    }

    private static function mappedFromString(string $filter): ?bool
    {
        return match ($filter) {
            self::FILTER_MAPPED => true,
            self::FILTER_UNMAPPED => false,
            default => null,
        };
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw self::invalid(sprintf('filter.%s должен быть строкой.', $key));
        }

        return $value;
    }

    private static function invalid(string $message): InvalidListingTagPayloadException
    {
        return new InvalidListingTagPayloadException('filter_invalid', $message);
    }
}

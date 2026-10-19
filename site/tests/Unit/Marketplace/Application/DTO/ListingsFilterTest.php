<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\DTO;

use App\Marketplace\Application\DTO\ListingsFilter;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\InvalidListingTagPayloadException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListingsFilterTest extends TestCase
{
    private const TAG_ID = '0199f000-0000-7000-8000-000000000001';

    public function testFromQueryReadsAllParameters(): void
    {
        $filter = ListingsFilter::fromQuery([
            'filter' => 'unmapped',
            'marketplace' => 'ozon',
            'q' => '  Навол  ',
            'tag' => self::TAG_ID,
        ]);

        self::assertFalse($filter->mapped);
        self::assertSame(MarketplaceType::OZON, $filter->marketplace);
        self::assertSame('Навол', $filter->search);
        self::assertSame(self::TAG_ID, $filter->tagId);
        self::assertFalse($filter->withoutTags);
        self::assertFalse($filter->isEmpty());
    }

    public function testFromQueryIgnoresInvalidValues(): void
    {
        $filter = ListingsFilter::fromQuery([
            'filter' => 'whatever',
            'marketplace' => 'amazon',
            'q' => '   ',
            'tag' => 'not-a-uuid',
        ]);

        self::assertEquals(new ListingsFilter(), $filter);
        self::assertTrue($filter->isEmpty());
    }

    public function testFromQueryTruncatesLongSearchByCharacters(): void
    {
        $filter = ListingsFilter::fromQuery(['q' => str_repeat('я', 150)]);

        self::assertSame(str_repeat('я', ListingsFilter::SEARCH_MAX_LENGTH), $filter->search);
    }

    public function testTruncatedSearchMatchesWhatPayloadWouldAccept(): void
    {
        // Пробел на границе обрезки: страница и назначение по фильтру должны
        // искать одну и ту же строку.
        $filter = ListingsFilter::fromQuery(['q' => str_repeat('я', 99).' хвост']);

        self::assertSame(str_repeat('я', 99), $filter->search);
        self::assertEquals($filter, ListingsFilter::fromPayload($filter->toQueryParams()));
    }

    public function testFromQueryIgnoresArrayValues(): void
    {
        self::assertTrue(ListingsFilter::fromQuery(['q' => ['навол'], 'tag' => ['none']])->isEmpty());
    }

    public function testTagNoneMeansWithoutTags(): void
    {
        $filter = ListingsFilter::fromQuery(['tag' => 'none']);

        self::assertTrue($filter->withoutTags);
        self::assertNull($filter->tagId);
        self::assertSame(['filter' => 'all', 'tag' => 'none'], $filter->toQueryParams());
    }

    public function testToQueryParamsRoundTripsThroughPayload(): void
    {
        $filter = new ListingsFilter(true, MarketplaceType::WILDBERRIES, 'Навол', self::TAG_ID);

        $params = $filter->toQueryParams();

        self::assertSame([
            'filter' => 'mapped',
            'marketplace' => 'wildberries',
            'q' => 'Навол',
            'tag' => self::TAG_ID,
        ], $params);
        self::assertEquals($filter, ListingsFilter::fromPayload($params));
        self::assertEquals($filter, ListingsFilter::fromQuery($params));
    }

    public function testFromPayloadAcceptsEmptyObject(): void
    {
        self::assertTrue(ListingsFilter::fromPayload([])->isEmpty());
    }

    /**
     * @param array<mixed>|string $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testFromPayloadRejectsInvalidValues(array|string $payload): void
    {
        try {
            ListingsFilter::fromPayload($payload);
            self::fail('Ожидалось исключение filter_invalid.');
        } catch (InvalidListingTagPayloadException $e) {
            self::assertSame('filter_invalid', $e->errorCode);
        }
    }

    /**
     * @return iterable<string, array{array<mixed>|string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'не объект' => ['навол'];
        yield 'список' => [['навол']];
        yield 'неизвестный filter' => [['filter' => 'everything']];
        yield 'неизвестный маркетплейс' => [['marketplace' => 'amazon']];
        yield 'q не строка' => [['q' => 42]];
        yield 'q длиннее лимита' => [['q' => str_repeat('я', ListingsFilter::SEARCH_MAX_LENGTH + 1)]];
        yield 'tag не uuid' => [['tag' => 'зима']];
    }
}

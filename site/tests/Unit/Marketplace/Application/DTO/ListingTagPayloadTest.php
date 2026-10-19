<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\DTO;

use App\Marketplace\Application\DTO\ListingsFilter;
use App\Marketplace\Application\DTO\ListingTagPayload;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\InvalidListingTagPayloadException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListingTagPayloadTest extends TestCase
{
    private const LISTING_ID = '33333333-3333-4333-8333-000000000001';

    public function testAssignByListingIdsHasNoFilter(): void
    {
        $payload = ListingTagPayload::forAssign(['listingIds' => [self::LISTING_ID], 'name' => 'Зима']);

        self::assertSame([self::LISTING_ID], $payload->listingIds);
        self::assertNull($payload->filter);
    }

    public function testAssignByFilterHasNoListingIds(): void
    {
        $payload = ListingTagPayload::forAssign([
            'filter' => ['q' => 'навол', 'marketplace' => 'ozon'],
            'name' => 'Склад 1',
        ]);

        self::assertSame([], $payload->listingIds);
        self::assertEquals(new ListingsFilter(marketplace: MarketplaceType::OZON, search: 'навол'), $payload->filter);
        self::assertSame('Склад 1', $payload->tagName);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidSelections')]
    public function testRejectsInvalidSelection(array $data, string $expectedCode): void
    {
        try {
            ListingTagPayload::forAssign($data);
            self::fail('Ожидалось исключение '.$expectedCode);
        } catch (InvalidListingTagPayloadException $e) {
            self::assertSame($expectedCode, $e->errorCode);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSelections(): iterable
    {
        yield 'и список, и фильтр' => [
            ['listingIds' => [self::LISTING_ID], 'filter' => [], 'name' => 'Зима'],
            'listing_selection_required',
        ];
        yield 'ни списка, ни фильтра' => [['name' => 'Зима'], 'listing_ids_required'];
        yield 'фильтр строкой' => [['filter' => 'навол', 'name' => 'Зима'], 'filter_invalid'];
        yield 'фильтр null' => [['filter' => null, 'name' => 'Зима'], 'filter_invalid'];
        yield 'фильтр без тега' => [['filter' => ['q' => 'навол']], 'tag_reference_required'];
    }
}

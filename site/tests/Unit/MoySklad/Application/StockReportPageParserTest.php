<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Application;

use App\MoySklad\Application\StockReportPageParser;
use App\MoySklad\Exception\StockSyncException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StockReportPageParserTest extends TestCase
{
    public function testParsesDocumentedProductAndVariantStockRows(): void
    {
        $page = (new StockReportPageParser())->parse($this->fixture());
        $rows = $page->rows;

        self::assertSame(2, $page->size);
        self::assertSame(2, $page->limit);
        self::assertSame(0, $page->offset);
        self::assertCount(2, $rows);
        self::assertSame('product', $rows[0]->assortmentType);
        self::assertSame('00000000-0000-4000-8000-000000000001', $rows[0]->assortmentExternalId);
        self::assertCount(2, $rows[0]->levels);
        self::assertSame('00000000-0000-4000-8000-000000000401', $rows[0]->levels[0]->storeExternalId);
        self::assertSame('-30', $rows[0]->levels[0]->stock);
        self::assertSame('1.25', $rows[0]->levels[0]->reserve);
        self::assertSame('3', $rows[0]->levels[0]->inTransit);
        self::assertSame('variant', $rows[1]->assortmentType);
        self::assertSame('4.125', $rows[1]->levels[0]->stock);
    }

    #[DataProvider('sanitizedLiveReport')]
    public function testParsesSanitizedLiveReportWithExpandedAssortmentHref(string $fixture, int $offset, string $firstAssortmentId): void
    {
        $page = (new StockReportPageParser())->parse($this->fixture($fixture));

        self::assertSame(4, $page->size);
        self::assertSame(2, $page->limit);
        self::assertSame($offset, $page->offset);
        self::assertCount(2, $page->rows);
        self::assertSame($firstAssortmentId, $page->rows[0]->assortmentExternalId);
        self::assertCount(16, $page->rows[0]->levels);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function sanitizedLiveReport(): iterable
    {
        yield 'first page' => ['stock_bystore_live_page_0.json', 0, '00000000-0000-4000-8000-000000000001'];
        yield 'last page' => ['stock_bystore_live_page_2.json', 2, '00000000-0000-4000-8000-000000000003'];
    }

    #[DataProvider('invalidReport')]
    public function testRejectsInvalidReport(callable $mutate): void
    {
        $page = json_decode($this->fixture(), true, 512, \JSON_THROW_ON_ERROR);
        $mutate($page);

        $this->expectException(StockSyncException::class);
        (new StockReportPageParser())->parse(json_encode($page, \JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void}> */
    public static function invalidReport(): iterable
    {
        yield 'unknown assortment type' => [static function (array &$page): void { $page['rows'][0]['meta']['type'] = 'bundle'; }];
        yield 'foreign assortment href' => [static function (array &$page): void { $page['rows'][0]['meta']['href'] = 'https://evil.example/api/remap/1.2/entity/product/00000000-0000-4000-8000-000000000001'; }];
        yield 'wrong assortment href type' => [static function (array &$page): void { $page['rows'][0]['meta']['href'] = 'https://api.moysklad.ru/api/remap/1.2/entity/variant/00000000-0000-4000-8000-000000000001'; }];
        yield 'foreign store href' => [static function (array &$page): void { $page['rows'][0]['stockByStore'][0]['meta']['href'] = 'https://evil.example/api/remap/1.2/entity/store/00000000-0000-4000-8000-000000000401'; }];
        yield 'wrong store meta type' => [static function (array &$page): void { $page['rows'][0]['stockByStore'][0]['meta']['type'] = 'product'; }];
        yield 'numeric string' => [static function (array &$page): void { $page['rows'][0]['stockByStore'][0]['stock'] = '1.25'; }];
        yield 'more than ten decimals' => [static function (array &$page): void { $page['rows'][0]['stockByStore'][0]['stock'] = 1.12345678901; }];
        yield 'missing metric' => [static function (array &$page): void { unset($page['rows'][0]['stockByStore'][0]['reserve']); }];
        yield 'duplicate store' => [static function (array &$page): void { $page['rows'][0]['stockByStore'][] = $page['rows'][0]['stockByStore'][0]; }];
        yield 'duplicate assortment' => [static function (array &$page): void { $page['rows'][] = $page['rows'][0]; }];
        yield 'missing size' => [static function (array &$page): void { unset($page['meta']['size']); }];
        yield 'negative offset' => [static function (array &$page): void { $page['meta']['offset'] = -1; }];
        yield 'offset beyond size' => [static function (array &$page): void {
            $page['meta']['offset'] = 3;
            $page['rows'] = [];
        }];
        yield 'inconsistent size' => [static function (array &$page): void { $page['meta']['size'] = 1; }];
    }

    public function testErrorDoesNotContainStoreName(): void
    {
        $page = json_decode($this->fixture(), true, 512, \JSON_THROW_ON_ERROR);
        $page['rows'][0]['stockByStore'][0]['stock'] = [];

        try {
            (new StockReportPageParser())->parse(json_encode($page, \JSON_THROW_ON_ERROR));
            self::fail('Invalid stock must fail.');
        } catch (StockSyncException $error) {
            self::assertSame('invalid_response', $error->category);
            self::assertStringNotContainsString('Основной тестовый склад', $error->getMessage());
        }
    }

    public function testPreservesLargeDecimalLexemeWithoutFloatConversion(): void
    {
        $body = str_replace('"stock": -30', '"stock": 9007199254740990.1', $this->fixture());

        $page = (new StockReportPageParser())->parse($body);

        self::assertSame('9007199254740990.1', $page->rows[0]->levels[0]->stock);
    }

    public function testNormalizesScientificNotationWithoutFloatConversion(): void
    {
        $body = str_replace(
            ['"stock": -30', '"reserve": 1.25'],
            ['"stock": 1.0E7', '"reserve": 1.0E-4'],
            $this->fixture(),
        );

        $page = (new StockReportPageParser())->parse($body);

        self::assertSame('10000000', $page->rows[0]->levels[0]->stock);
        self::assertSame('0.0001', $page->rows[0]->levels[0]->reserve);
    }

    #[DataProvider('scientificNotationOutsideStorageRange')]
    public function testRejectsScientificNotationOutsideStorageRange(string $lexeme): void
    {
        $body = str_replace('"stock": -30', '"stock": '.$lexeme, $this->fixture());

        $this->expectException(StockSyncException::class);
        (new StockReportPageParser())->parse($body);
    }

    /** @return iterable<string, array{string}> */
    public static function scientificNotationOutsideStorageRange(): iterable
    {
        yield 'more than twenty integer digits' => ['1E20'];
        yield 'more than ten fractional digits' => ['1E-11'];
    }

    public function testRejectsObjectThatImpersonatesInternalDecimalMarker(): void
    {
        $body = str_replace('"stock": -30', '"stock": {"__moysklad_decimal":"1"}', $this->fixture());

        $this->expectException(StockSyncException::class);
        (new StockReportPageParser())->parse($body);
    }

    public function testParsesMaximumPageWithoutRepeatedSuffixCopies(): void
    {
        $rows = [];
        for ($index = 0; $index < 1000; ++$index) {
            $rows[] = sprintf(
                '{"meta":{"href":"https://api.moysklad.ru/api/remap/1.2/entity/product/00000000-0000-4000-8000-%012d","type":"product"},"stockByStore":[{"meta":{"href":"https://api.moysklad.ru/api/remap/1.2/entity/store/00000000-0000-4000-8000-000000000401","type":"store"},"name":"Store","stock":1000000000000000.1,"reserve":0,"inTransit":0}]}',
                $index,
            );
        }
        $body = sprintf('{"meta":{"type":"stockbystore","size":1000,"limit":1000,"offset":0},"rows":[%s]}', implode(',', $rows));

        $page = (new StockReportPageParser())->parse($body);

        self::assertCount(1000, $page->rows);
        self::assertSame('1000000000000000.1', $page->rows[999]->levels[0]->stock);
    }

    private function fixture(string $name = 'stock_bystore_page_0.json'): string
    {
        $body = file_get_contents(__DIR__.'/../../../Fixtures/MoySklad/Stock/'.$name);
        self::assertIsString($body);

        return $body;
    }
}

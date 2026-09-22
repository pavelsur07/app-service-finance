<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Application;

use App\MoySklad\Application\StorePageParser;
use App\MoySklad\Exception\StockSyncException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorePageParserTest extends TestCase
{
    private const ACCOUNT_ID = '00000000-0000-4000-8000-000000000003';

    public function testParsesDocumentedStorePage(): void
    {
        $page = (new StorePageParser())->parse($this->fixture('Store/stores_active_page_0.json'), self::ACCOUNT_ID, false);
        $stores = $page->rows;

        self::assertSame(2, $page->size);
        self::assertSame(2, $page->limit);
        self::assertSame(0, $page->offset);
        self::assertCount(2, $stores);
        self::assertSame('00000000-0000-4000-8000-000000000401', $stores[0]->externalId);
        self::assertSame('Основной тестовый склад', $stores[0]->name);
        self::assertSame('store-main', $stores[0]->code);
        self::assertSame('store-external-1', $stores[0]->externalCode);
        self::assertSame('', $stores[0]->pathName);
        self::assertFalse($stores[0]->archived);
        self::assertSame('2026-01-02 00:04:06.123', $stores[0]->sourceUpdatedAt->format('Y-m-d H:i:s.v'));
        self::assertNull($stores[1]->code);
    }

    #[DataProvider('invalidStore')]
    public function testRejectsInvalidStore(string $path, mixed $value): void
    {
        $page = $this->fixture('Store/stores_active_page_0.json');
        $target = &$page['rows'][0];
        foreach (explode('.', $path) as $part) {
            $target = &$target[$part];
        }
        $target = $value;

        $this->expectException(StockSyncException::class);
        (new StorePageParser())->parse($page, self::ACCOUNT_ID, false);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidStore(): iterable
    {
        yield 'tenant mismatch' => ['accountId', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
        yield 'archive mismatch' => ['archived', true];
        yield 'wrong meta type' => ['meta.type', 'product'];
        yield 'foreign href' => ['meta.href', 'https://evil.example/api/remap/1.2/entity/store/00000000-0000-4000-8000-000000000401'];
        yield 'href id mismatch' => ['meta.href', 'https://api.moysklad.ru/api/remap/1.2/entity/store/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
        yield 'invalid updated' => ['updated', '2026-02-31 03:04:06.123'];
        yield 'blank name' => ['name', '  '];
        yield 'long path' => ['pathName', str_repeat('x', 4097)];
    }

    public function testRejectsInvalidAccountOnEmptyPage(): void
    {
        $this->expectException(StockSyncException::class);
        (new StorePageParser())->parse(['meta' => ['size' => 0], 'rows' => []], 'bad-account', false);
    }

    public function testRejectsWrongPageMetaType(): void
    {
        $page = $this->fixture('Store/stores_active_page_0.json');
        $page['meta']['type'] = 'product';

        $this->expectException(StockSyncException::class);
        (new StorePageParser())->parse($page, self::ACCOUNT_ID, false);
    }

    #[DataProvider('invalidPageMeta')]
    public function testRejectsInvalidPageMeta(string $key, mixed $value): void
    {
        $page = $this->fixture('Store/stores_active_page_0.json');
        $page['meta'][$key] = $value;

        $this->expectException(StockSyncException::class);
        (new StorePageParser())->parse($page, self::ACCOUNT_ID, false);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidPageMeta(): iterable
    {
        yield 'missing size' => ['size', null];
        yield 'negative size' => ['size', -1];
        yield 'zero limit' => ['limit', 0];
        yield 'negative offset' => ['offset', -1];
        yield 'offset beyond size' => ['offset', 3];
        yield 'inconsistent size' => ['size', 1];
    }

    public function testErrorDoesNotContainStorePayload(): void
    {
        $page = $this->fixture('Store/stores_active_page_0.json');
        unset($page['rows'][0]['externalCode']);

        try {
            (new StorePageParser())->parse($page, self::ACCOUNT_ID, false);
            self::fail('Missing external code must fail.');
        } catch (StockSyncException $error) {
            self::assertSame('invalid_response', $error->category);
            self::assertStringNotContainsString('Основной тестовый склад', $error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $body = file_get_contents(__DIR__.'/../../../Fixtures/MoySklad/'.$name);
        self::assertIsString($body);

        return json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
    }
}

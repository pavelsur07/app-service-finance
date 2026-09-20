<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Application;

use App\MoySklad\Application\CatalogPageParser;
use App\MoySklad\Exception\CatalogSyncException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogPageParserTest extends TestCase
{
    private const ACCOUNT_ID = '00000000-0000-4000-8000-000000000003';

    public function testParsesCapturedProductAndDocumentedVariant(): void
    {
        $parser = new CatalogPageParser();
        $products = $parser->parseProducts($this->fixture('Product/products_active_page_0.json'), self::ACCOUNT_ID, false);
        self::assertCount(2, $products);
        self::assertSame('00000000-0000-4000-8000-000000000001', $products[0]->externalId);
        self::assertSame(0, $products[0]->variantsCount);
        self::assertSame('2026-01-02 00:04:06.123', $products[0]->sourceUpdatedAt->format('Y-m-d H:i:s.v'));

        $variants = $parser->parseVariants($this->fixture('Variant/variants_documented_page_0.json'), self::ACCOUNT_ID, false);
        self::assertCount(1, $variants);
        self::assertSame($products[0]->externalId, $variants[0]->productExternalId);
        self::assertSame([['id' => '00000000-0000-4000-8000-000000000201', 'name' => 'Цвет', 'value' => 'Синий']], $variants[0]->characteristics);
    }

    #[DataProvider('invalidProduct')]
    public function testRejectsInvalidProduct(string $field, mixed $value): void
    {
        $page = $this->fixture('Product/products_active_page_0.json');
        $page['rows'][0][$field] = $value;
        $this->expectException(CatalogSyncException::class);
        (new CatalogPageParser())->parseProducts($page, self::ACCOUNT_ID, false);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidProduct(): iterable
    {
        yield 'tenant mismatch' => ['accountId', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
        yield 'wrong archive pass' => ['archived', true];
        yield 'invalid date' => ['updated', '2026-02-31 03:04:06.123'];
        yield 'negative variants count' => ['variantsCount', -1];
        yield 'wrong external code' => ['externalCode', 123];
    }

    #[DataProvider('invalidVariant')]
    public function testRejectsInvalidVariant(string $field, mixed $value): void
    {
        $page = $this->fixture('Variant/variants_documented_page_0.json');
        $page['rows'][0][$field] = $value;
        $this->expectException(CatalogSyncException::class);
        (new CatalogPageParser())->parseVariants($page, self::ACCOUNT_ID, false);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidVariant(): iterable
    {
        yield 'tenant mismatch' => ['accountId', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
        yield 'wrong archive pass' => ['archived', true];
        yield 'invalid parent host' => ['product', ['meta' => ['href' => 'https://evil.example/api/remap/1.2/entity/product/00000000-0000-4000-8000-000000000001', 'type' => 'product']]];
        yield 'wrong parent type' => ['product', ['meta' => ['href' => 'https://api.moysklad.ru/api/remap/1.2/entity/service/00000000-0000-4000-8000-000000000001', 'type' => 'service']]];
        yield 'bad characteristics' => ['characteristics', [['name' => 'Цвет', 'value' => ['Синий']]]];
    }

    public function testRejectsMissingRequiredFieldWithoutIncludingPayloadInError(): void
    {
        $page = $this->fixture('Variant/variants_documented_page_0.json');
        unset($page['rows'][0]['product']);
        try {
            (new CatalogPageParser())->parseVariants($page, self::ACCOUNT_ID, false);
            self::fail('Missing product should fail.');
        } catch (CatalogSyncException $error) {
            self::assertSame('invalid_response', $error->category);
            self::assertStringNotContainsString('Тестовая модификация', $error->getMessage());
        }
    }

    public function testRejectsBadAccountEvenWhenPageIsEmpty(): void
    {
        $page = ['meta' => ['size' => 0], 'rows' => []];
        foreach (['parseProducts', 'parseVariants'] as $method) {
            try {
                (new CatalogPageParser())->$method($page, 'not-a-uuid', false);
                self::fail('Invalid account must fail on empty page.');
            } catch (CatalogSyncException $error) {
                self::assertSame('invalid_response', $error->category);
            }
        }
    }

    public function testBlankOptionalCodeNormalizesToNull(): void
    {
        $page = $this->fixture('Product/products_active_page_0.json');
        $page['rows'][0]['code'] = '  ';
        self::assertNull((new CatalogPageParser())->parseProducts($page, self::ACCOUNT_ID, false)[0]->code);
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $body = file_get_contents(__DIR__.'/../../../Fixtures/MoySklad/'.$name);
        self::assertIsString($body);

        return json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
    }
}

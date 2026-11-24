<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Domain;

use App\Marketplace\DTO\ListingMetaDTO;
use App\MoySklad\Application\DTO\AutomaticMappingCandidate;
use App\MoySklad\Application\DTO\AutomaticMappingCandidates;
use App\MoySklad\Application\DTO\CatalogTargetDTO;
use App\MoySklad\Domain\AutomaticListingMappingPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AutomaticListingMappingPolicyTest extends TestCase
{
    /** @param array<string,mixed> $scenario */
    #[DataProvider('decisions')]
    public function testOnlyProvenUniqueIdentityBecomesReady(array $scenario): void
    {
        $policy = new AutomaticListingMappingPolicy();
        $rows = $scenario['rows'] ?? [[]];
        $items = [];
        foreach ($rows as $index => $row) {
            $type = $row['type'] ?? 'product';
            $id = sprintf('44444444-4444-4444-8444-%012d', $row['index'] ?? $index + 1);
            $items[] = new AutomaticMappingCandidate(new CatalogTargetDTO($type, $id,
                'variant' === $type ? ($row['parentId'] ?? '55555555-5555-4555-8555-000000000001') : null,
                $row['name'] ?? 'Позиция', $row['code'] ?? null, array_key_exists('article', $row) ? $row['article'] : '001', $row['characteristics'] ?? [],
                $row['selectable'] ?? true, $row['reason'] ?? null, $row['barcodes'] ?? null), $row['parent'] ?? null, $row['leaf'] ?? true,
                $row['linkedBarcode'] ?? false, $row['linkedElsewhere'] ?? false);
        }
        $source = new ListingMetaDTO('11111111-1111-4111-8111-000000000001', $scenario['title'] ?? 'Листинг', 'SKU',
            $scenario['marketplace'] ?? 'ozon', array_key_exists('article', $scenario) ? $scenario['article'] : '001',
            $scenario['size'] ?? null, $scenario['active'] ?? true);
        $decision = $policy->decide($source, new AutomaticMappingCandidates($items,
            $scenario['total'] ?? count($items), $scenario['truncated'] ?? false), $scenario['characteristic'] ?? null, $scenario['barcodes'] ?? [], $scenario['preset'] ?? false);
        self::assertSame($scenario['status'], $decision->status->value);
        if ('ready' === $scenario['status']) {
            self::assertNotNull($decision->target);
            self::assertSame(sprintf('44444444-4444-4444-8444-%012d', $scenario['target'] ?? 1), $decision->target->externalId);
            self::assertSame($scenario['rules'] ?? ['direct_article'], $decision->matchedRules);
        } else {
            self::assertNull($decision->target, 'An unresolved identity must never expose an automatic target.');
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function decisions(): iterable
    {
        $m = [['id' => 'size', 'name' => 'Размер', 'value' => 'M']];
        $l = [['id' => 'size', 'name' => 'Размер', 'value' => 'L']];
        $variant = ['type' => 'variant', 'characteristics' => $m];
        yield 'exact code independent from article' => [['rows' => [['article' => 'OTHER', 'code' => '001']], 'status' => 'ready', 'rules' => ['exact_code']]];
        yield 'article and code same target merge grounds' => [['rows' => [['code' => '001']], 'status' => 'ready', 'rules' => ['direct_article', 'exact_code']]];
        yield 'article versus code conflict' => [['rows' => [[], ['article' => 'OTHER', 'code' => '001']], 'status' => 'ambiguous']];
        yield 'code zeros stay significant' => [['rows' => [['article' => null, 'code' => '1']], 'status' => 'no_match']];
        yield 'sized variant cannot hide unrelated parent code' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [array_replace($variant, ['article' => 'OTHER', 'parent' => '001']), ['article' => 'OTHER', 'code' => '001', 'leaf' => false]], 'status' => 'ambiguous']];
        yield 'sized variant cannot hide unrelated parent barcode' => [['size' => 'M', 'characteristic' => 'size', 'barcodes' => ['000123'], 'rows' => [array_replace($variant, ['article' => 'OTHER', 'parent' => '001']), ['article' => 'OTHER', 'barcodes' => ['000123'], 'leaf' => false]], 'status' => 'ambiguous']];
        yield 'parent code without leaf stays unresolved' => [['rows' => [['article' => null, 'code' => '001', 'leaf' => false]], 'status' => 'variant_required']];
        yield 'WB suffix off by default' => [['marketplace' => 'wildberries', 'article' => '001W', 'status' => 'no_match']];
        yield 'WB upper suffix enabled' => [['marketplace' => 'wildberries', 'preset' => true, 'article' => '001W', 'status' => 'ready', 'rules' => ['wb_article_alias']]];
        yield 'WB lower suffix enabled' => [['marketplace' => 'wildberries', 'preset' => true, 'article' => '001w', 'status' => 'ready', 'rules' => ['wb_article_alias']]];
        yield 'WB removes only one suffix' => [['marketplace' => 'wildberries', 'preset' => true, 'article' => '001WW', 'status' => 'no_match']];
        yield 'WB never removes other suffix' => [['marketplace' => 'wildberries', 'preset' => true, 'article' => '001X', 'status' => 'no_match']];
        yield 'WB preset never applies to Ozon' => [['preset' => true, 'article' => '001W', 'status' => 'no_match']];
        yield 'barcode independent without article' => [['article' => null, 'barcodes' => ['000123'], 'rows' => [['barcodes' => ['000123']]], 'status' => 'ready', 'rules' => ['exact_barcode']]];
        yield 'barcode duplicate' => [['barcodes' => ['000123'], 'rows' => [['article' => null, 'barcodes' => ['000123']], ['article' => null, 'barcodes' => ['000123']]], 'status' => 'ambiguous']];
        // Штрихкод — основание связи (docs/tasks/moysklad-wb-mapping/plan.md): перебивает артикул, расхождение блокирует.
        yield 'barcode beats article' => [['barcodes' => ['000123'], 'rows' => [[], ['article' => 'OTHER', 'barcodes' => ['000123']]], 'status' => 'ready', 'target' => 2, 'rules' => ['exact_barcode']]];
        yield 'barcode conflict blocks article' => [['barcodes' => ['000123'], 'rows' => [['barcodes' => ['999']]], 'status' => 'barcode_conflict']];
        yield 'target barcodes without listing barcodes do not block' => [['rows' => [['barcodes' => ['999']]], 'status' => 'ready']];
        yield 'own barcode confirms size of product without variants' => [['marketplace' => 'wildberries', 'size' => 'M', 'barcodes' => ['000123'], 'rows' => [['article' => 'X', 'barcodes' => ['000123']]], 'status' => 'ready', 'rules' => ['exact_barcode']]];
        yield 'barcode of linked listing on other marketplace' => [['marketplace' => 'wildberries', 'size' => 'M', 'article' => 'WB-1', 'rows' => [['article' => 'OTHER', 'linkedBarcode' => true]], 'status' => 'ready', 'rules' => ['linked_barcode']]];
        yield 'linked barcode versus article size' => [['marketplace' => 'wildberries', 'size' => 'M', 'article' => 'WJ-1', 'rows' => [['article' => 'OTHER', 'linkedBarcode' => true], ['article' => 'WJ-1-M']], 'status' => 'ambiguous']];
        yield 'own barcode beats linked barcode' => [['barcodes' => ['000123'], 'rows' => [['article' => 'X', 'linkedBarcode' => true], ['article' => 'Y', 'barcodes' => ['000123']]], 'status' => 'ready', 'target' => 2, 'rules' => ['exact_barcode']]];
        // Каталог без модификаций: размер в артикуле или в названии.
        yield 'article size with cyrillic size' => [['marketplace' => 'wildberries', 'article' => 'WJ-1', 'size' => 'М', 'rows' => [['article' => 'WJ-1-M']], 'status' => 'ready', 'rules' => ['article_size']]];
        yield 'article size underscore any case' => [['marketplace' => 'wildberries', 'article' => 'wj-1', 'size' => 'xl', 'rows' => [['article' => 'WJ-1_XL']], 'status' => 'ready', 'rules' => ['article_size']]];
        yield 'article size other size is no identity' => [['marketplace' => 'wildberries', 'article' => 'WJ-1', 'size' => 'M', 'rows' => [['article' => 'WJ-1-L']], 'status' => 'no_match']];
        yield 'name size picks one of same article' => [['marketplace' => 'wildberries', 'article' => 'WJ-2', 'size' => 'M', 'rows' => [['article' => 'WJ-2', 'name' => 'Брюки (S, черный)'], ['article' => 'WJ-2', 'name' => 'Брюки (М, черный)'], ['article' => 'WJ-2', 'name' => 'Брюки (L)']], 'status' => 'ready', 'target' => 2, 'rules' => ['name_size']]];
        yield 'name without size stays unverifiable' => [['marketplace' => 'wildberries', 'article' => 'WJ-2', 'size' => 'M', 'rows' => [['article' => 'WJ-2', 'name' => 'Брюки']], 'status' => 'size_unverifiable']];
        yield 'name size twice is ambiguous' => [['marketplace' => 'wildberries', 'article' => 'WJ-2', 'size' => 'M', 'rows' => [['article' => 'WJ-2', 'name' => 'Брюки (M)'], ['article' => 'WJ-2', 'name' => 'Брюки (M, синий)']], 'status' => 'ambiguous']];
        // Один товар — несколько листингов; связанный с другой площадкой кандидат приоритетнее.
        yield 'linked elsewhere breaks tie' => [['article' => 'A', 'rows' => [['article' => 'A'], ['article' => 'A', 'linkedElsewhere' => true]], 'status' => 'ready', 'target' => 2]];
        yield 'two linked elsewhere stay ambiguous' => [['article' => 'A', 'rows' => [['article' => 'A', 'linkedElsewhere' => true], ['article' => 'A', 'linkedElsewhere' => true]], 'status' => 'ambiguous']];
        yield 'single target linked elsewhere is still proposed' => [['rows' => [['linkedElsewhere' => true]], 'status' => 'ready']];
        yield 'barcode leading zeros preserved' => [['barcodes' => ['000123'], 'rows' => [['article' => 'OTHER', 'barcodes' => ['123']]], 'status' => 'no_match']];
        yield 'barcode contradictory size blocks other match' => [['size' => 'M', 'characteristic' => 'size', 'barcodes' => ['000123'], 'rows' => [$variant, array_replace($variant, ['article' => 'OTHER', 'barcodes' => ['000123'], 'characteristics' => $l])], 'status' => 'size_mismatch']];
        foreach (['3 шт', '5 шт.', '2 штуки', '3 штук', '2 ШТУКА'] as $title) {
            yield 'pack mismatch '.$title => [['title' => 'Набор '.$title, 'rows' => [['name' => 'Товар 1 шт']], 'status' => 'package_review']];
        }
        yield 'ambiguous source package' => [['title' => 'Набор 2 шт + 3 шт', 'rows' => [['name' => 'Товар']], 'status' => 'package_review']];
        yield 'ambiguous target package' => [['rows' => [['name' => 'Набор 2 шт + 3 шт']], 'status' => 'package_review']];
        yield 'same pack repeated is not ambiguous' => [['title' => 'Набор 3 шт / 3 штуки', 'rows' => [['name' => 'Товар 3 штук']], 'status' => 'ready']];
        // Штуки только с одной стороны: «10 штук» против позиции без количества, «1 шт для компл.» против товара.
        yield 'pieces only in listing' => [['title' => 'Набор 3 шт', 'status' => 'package_review']];
        yield 'pieces only in target' => [['rows' => [['name' => '1шт для компл. Товар']], 'status' => 'package_review']];
        yield 'no pieces on both sides' => [['title' => 'Набор', 'rows' => [['name' => 'Товар']], 'status' => 'ready']];
        yield 'decimal is not integer quantity' => [['title' => 'Вес 1.5 шт', 'rows' => [['name' => 'Товар']], 'status' => 'ready']];
        // Объём и вес: расходятся, только если указаны с обеих сторон и ни одно значение не совпало.
        yield 'volume mismatch' => [['title' => 'Средство 80мл', 'rows' => [['name' => 'Средство 50 мл']], 'status' => 'package_review']];
        yield 'mass mismatch' => [['title' => 'Порошок 500г', 'rows' => [['name' => 'Порошок 200 гр']], 'status' => 'package_review']];
        yield 'litres equal millilitres' => [['title' => 'Жидкость 1 литр', 'rows' => [['name' => 'Жидкость 1000 мл']], 'status' => 'ready']];
        yield 'kilograms equal grams with comma' => [['title' => 'Соль 0,5 кг', 'rows' => [['name' => 'Соль 500 г']], 'status' => 'ready']];
        yield 'one common measure is enough' => [['title' => 'Набор 500 г (2 по 250 г)', 'rows' => [['name' => 'Набор 500г']], 'status' => 'ready']];
        yield 'measure only on one side is unknown' => [['title' => 'Гремучая смесь 40 тестобрикетов', 'rows' => [['name' => 'Гремучая смесь 500 г']], 'status' => 'ready']];
        yield 'volume and mass are not compared' => [['title' => 'Средство 100 мл', 'rows' => [['name' => 'Средство 100 г']], 'status' => 'ready']];
        yield 'thousands separator is one number' => [['title' => 'Вода 1 000 мл', 'rows' => [['name' => 'Вода 1000 мл']], 'status' => 'ready']];
        yield 'decimal litres equal millilitres' => [['title' => 'Средство 0.25 л', 'rows' => [['name' => 'Средство 250 мл']], 'status' => 'ready']];
        yield 'full words are measures' => [['title' => 'Порошок 500 грамм', 'rows' => [['name' => 'Порошок 1 килограмм']], 'status' => 'package_review']];
        yield 'latin units are measures' => [['title' => 'Крем 50 ml', 'rows' => [['name' => 'Крем 30 мл']], 'status' => 'package_review']];
        yield 'single l with dot is litre' => [['title' => 'Бак 5 л.', 'rows' => [['name' => 'Бак 3 л']], 'status' => 'package_review']];
        yield 'years are not litres' => [['title' => 'Гарантия 5 лет', 'rows' => [['name' => 'Гарантия 3 л']], 'status' => 'ready']];
        yield 'unit inside word is not measure' => [['title' => 'Модель 5глянец', 'rows' => [['name' => 'Модель 7 г']], 'status' => 'ready']];
        yield 'simple exact article' => [['status' => 'ready']];
        yield 'missing source article' => [['article' => null, 'status' => 'no_article']];
        yield 'blank source article' => [['article' => " \t\r\n\v\f ", 'status' => 'no_article']];
        yield 'ASCII whitespace is identical on both sides' => [['article' => "\t001\r", 'rows' => [['article' => " 001\n"]], 'status' => 'ready']];
        yield 'NBSP is not removed' => [['article' => "\u{00a0}001", 'status' => 'no_match']];
        yield 'case remains significant' => [['article' => 'abc', 'rows' => [['article' => 'ABC']], 'status' => 'no_match']];
        yield 'leading zeros remain significant' => [['rows' => [['article' => '1']], 'status' => 'no_match']];
        yield 'no candidate' => [['rows' => [], 'status' => 'no_match']];
        yield 'inactive source' => [['active' => false, 'status' => 'listing_unavailable']];
        yield 'duplicate active targets' => [['rows' => [[], []], 'status' => 'ambiguous']];
        yield 'archived duplicate is not hidden' => [['rows' => [[], ['selectable' => false, 'reason' => 'archived']], 'status' => 'ambiguous']];
        yield 'single archived target' => [['rows' => [['selectable' => false, 'reason' => 'archived']], 'status' => 'target_unavailable']];
        yield 'parent duplicate cannot hide unrelated product without size' => [['rows' => [['leaf' => false], []], 'status' => 'ambiguous']];
        yield 'parent duplicate cannot hide unrelated variant without size' => [['rows' => [['leaf' => false], $variant], 'status' => 'ambiguous']];
        yield 'own variant and own parent remain one identity' => [['rows' => [['leaf' => false], array_replace($variant, ['parentId' => '44444444-4444-4444-8444-000000000001'])], 'status' => 'ready', 'target' => 2]];
        yield 'parent is not a SKU' => [['rows' => [['leaf' => false, 'selectable' => false, 'reason' => 'requires_variant']], 'status' => 'variant_required']];
        yield 'variant own article with no source size' => [['rows' => [$variant], 'status' => 'ready']];
        yield 'parent article needs source size' => [['rows' => [array_replace($variant, ['article' => 'CHILD', 'parent' => '001'])], 'status' => 'size_unknown']];
        yield 'rule A remains available without size' => [['rows' => [$variant, array_replace($variant, ['article' => 'OTHER', 'parent' => '001'])], 'status' => 'ready']];
        yield 'size is not guessed for product' => [['size' => 'M', 'status' => 'size_unverifiable']];
        yield 'size characteristic must be explicit' => [['size' => 'M', 'rows' => [$variant], 'status' => 'size_rule_required']];
        yield 'variant own article plus exact size' => [['size' => ' M ', 'characteristic' => 'size', 'rows' => [$variant], 'status' => 'ready']];
        yield 'parent article and exact size' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [array_replace($variant, ['article' => 'CHILD', 'parent' => '001'])], 'status' => 'ready', 'rules' => ['parent_article_size']]];
        yield 'parent article works when variant article is null' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [array_replace($variant, ['article' => null, 'parent' => '001'])], 'status' => 'ready', 'rules' => ['parent_article_size']]];
        yield 'one target found by two rules' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [array_replace($variant, ['parent' => '001'])], 'status' => 'ready', 'rules' => ['direct_article', 'parent_article_size']]];
        yield 'different rules cannot choose different targets' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [$variant, array_replace($variant, ['article' => 'CHILD', 'parent' => '001'])], 'status' => 'ambiguous']];
        yield 'same target is counted once' => [['rows' => [[], ['index' => 1]], 'status' => 'ready']];
        yield 'wrong size is rejected' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [array_replace($variant, ['characteristics' => $l])], 'status' => 'size_mismatch']];
        yield 'proven wrong size does not create ambiguity' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [array_replace($variant, ['characteristics' => $l]), $variant], 'status' => 'ready', 'target' => 2]];
        foreach (['' => 'empty', " \t\n" => 'ASCII blank'] as $value => $label) {
            $unknown = array_replace($variant, ['characteristics' => [['id' => 'size', 'name' => 'Размер', 'value' => $value]]]);
            yield $label.' target size is unresolved' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [$unknown], 'status' => 'size_unverifiable']];
            yield $label.' target size cannot hide competing variant' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [$variant, $unknown], 'status' => 'size_unverifiable']];
        }
        yield 'missing characteristic is unresolved' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [array_replace($variant, ['characteristics' => []])], 'status' => 'size_unverifiable']];
        yield 'unknown size cannot hide another candidate' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [$variant, array_replace($variant, ['characteristics' => []])], 'status' => 'size_unverifiable']];
        yield 'repeated characteristic even with equal values is unresolved' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [array_replace($variant, ['characteristics' => [...$m, ...$m]])], 'status' => 'size_unverifiable']];
        yield 'characteristic names are not identity' => [['size' => 'M', 'characteristic' => 'actual', 'rows' => [array_replace($variant, ['characteristics' => [['id' => 'other', 'name' => 'Размер', 'value' => 'L'], ['id' => 'actual', 'name' => 'Размер', 'value' => 'M']]])], 'status' => 'ready']];
        yield 'Latin and Cyrillic M remain different' => [['size' => 'М', 'characteristic' => 'size', 'rows' => [$variant], 'status' => 'size_mismatch']];
        yield 'zero is a literal size' => [['size' => '0', 'characteristic' => 'size', 'rows' => [array_replace($variant, ['characteristics' => [['id' => 'size', 'name' => 'Размер', 'value' => '0']]])], 'status' => 'ready']];
        yield 'same size different colors ambiguous' => [['size' => 'M', 'characteristic' => 'size', 'rows' => [array_replace($variant, ['article' => 'BLUE', 'parent' => '001']), array_replace($variant, ['article' => 'RED', 'parent' => '001'])], 'status' => 'ambiguous']];
        yield 'archived variant parent is unavailable' => [['rows' => [array_replace($variant, ['selectable' => false, 'reason' => 'parent_archived'])], 'status' => 'target_unavailable']];
        yield 'truncation never proves uniqueness' => [['total' => 51, 'truncated' => true, 'status' => 'too_many_candidates']];
    }

    public function testFingerprintUsesNamesBecauseTheyAffectPackageChecks(): void
    {
        $policy = new AutomaticListingMappingPolicy();
        $base = ['id' => '11111111-1111-4111-8111-000000000001', 'title' => 'Название', 'sku' => 'SKU',
            'marketplace' => 'wildberries', 'supplierSku' => '001', 'size' => 'M', 'isActive' => true, 'marketplaceVariantId' => '123'];
        $fingerprint = $policy->sourceFingerprint(new ListingMetaDTO(...$base));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $fingerprint);
        self::assertSame($fingerprint, $policy->sourceFingerprint(new ListingMetaDTO(...array_replace($base, ['supplierSku' => "\t001 ", 'size' => ' M ']))));
        $variations = [
            new ListingMetaDTO(...array_replace($base, ['title' => 'Набор 3 шт'])),
            new ListingMetaDTO(...array_replace($base, ['supplierSku' => '1'])),
            new ListingMetaDTO(...array_replace($base, ['size' => 'L'])),
            new ListingMetaDTO(...array_replace($base, ['sku' => 'OTHER'])),
            new ListingMetaDTO(...array_replace($base, ['marketplaceVariantId' => '124'])),
            new ListingMetaDTO(...array_replace($base, ['isActive' => false])),
            new ListingMetaDTO(...array_replace($base, ['marketplace' => 'ozon'])),
        ];
        foreach ($variations as $source) {
            self::assertNotSame($fingerprint, $policy->sourceFingerprint($source));
        }
    }
}

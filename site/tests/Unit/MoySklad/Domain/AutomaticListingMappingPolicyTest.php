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
                'Позиция', null, array_key_exists('article', $row) ? $row['article'] : '001', $row['characteristics'] ?? [],
                $row['selectable'] ?? true, $row['reason'] ?? null), $row['parent'] ?? null, $row['leaf'] ?? true);
        }
        $source = new ListingMetaDTO('11111111-1111-4111-8111-000000000001', 'Листинг', 'SKU',
            'ozon', array_key_exists('article', $scenario) ? $scenario['article'] : '001',
            $scenario['size'] ?? null, $scenario['active'] ?? true);
        $decision = $policy->decide($source, new AutomaticMappingCandidates($items,
            $scenario['total'] ?? count($items), $scenario['truncated'] ?? false), $scenario['characteristic'] ?? null);
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

    public function testFingerprintUsesIdentityAndIgnoresDisplayName(): void
    {
        $policy = new AutomaticListingMappingPolicy();
        $base = ['id' => '11111111-1111-4111-8111-000000000001', 'title' => 'Название', 'sku' => 'SKU',
            'marketplace' => 'wildberries', 'supplierSku' => '001', 'size' => 'M', 'isActive' => true, 'marketplaceVariantId' => '123'];
        $fingerprint = $policy->sourceFingerprint(new ListingMetaDTO(...$base));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $fingerprint);
        self::assertSame($fingerprint, $policy->sourceFingerprint(new ListingMetaDTO(...array_replace($base, ['title' => 'Другое название', 'supplierSku' => "\t001 ", 'size' => ' M ']))));
        $variations = [
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

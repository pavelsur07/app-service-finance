<?php

declare(strict_types=1);

namespace App\MoySklad\Domain;

use App\Marketplace\DTO\ListingMetaDTO;
use App\MoySklad\Application\DTO\AutomaticMappingCandidates;
use App\MoySklad\Application\DTO\AutomaticMappingDecision;
use App\MoySklad\Enum\AutomaticMappingDecisionStatus as Status;

final readonly class AutomaticListingMappingPolicy
{
    public const TRIM_CHARACTERS = " \t\n\r\v\f";

    public static function normalizeKey(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value, self::TRIM_CHARACTERS);

        return '' === $value ? null : $value;
    }

    /** @param list<string> $barcodes */
    public function sourceFingerprint(ListingMetaDTO $source, array $barcodes = [], ?\App\MoySklad\Application\DTO\MappingSettings $settings = null): string
    {
        sort($barcodes, \SORT_STRING);

        return hash('sha256', json_encode([
            'id' => $source->id, 'marketplace' => $source->marketplace, 'sku' => $source->sku,
            'supplierSku' => self::normalizeKey($source->supplierSku), 'size' => self::normalizeKey($source->size),
            'variantId' => $source->marketplaceVariantId, 'isActive' => $source->isActive,
            'title' => $source->title, 'barcodes' => $barcodes, 'settings' => ($settings ?? new \App\MoySklad\Application\DTO\MappingSettings())->fingerprint(),
        ], \JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $barcodes */
    public function packageReviewFingerprint(ListingMetaDTO $source, \App\MoySklad\Application\DTO\CatalogTargetDTO $target, array $barcodes = []): string
    {
        return hash('sha256', $this->sourceFingerprint($source, $barcodes).self::targetFingerprint($target));
    }

    public static function targetFingerprint(\App\MoySklad\Application\DTO\CatalogTargetDTO $target): string
    {
        return hash('sha256', json_encode($target->toSnapshot(), \JSON_THROW_ON_ERROR));
    }

    public static function articleAlias(ListingMetaDTO $source, bool $enabled): ?string
    {
        $article = self::normalizeKey($source->supplierSku);
        if (!$enabled || 'wildberries' !== $source->marketplace || null === $article || !in_array(substr($article, -1), ['W', 'w'], true)) {
            return null;
        }

        return self::normalizeKey(substr($article, 0, -1));
    }

    /** @return list<string> Explicit integer piece quantities only; absence is unknown. */
    public static function quantities(?string $name): array
    {
        preg_match_all('/(?<![\pL\pN.,])([0-9]+)\s*(?:штук|штуки|штука|шт\.?)(?!\pL)/iu', $name ?? '', $matches);
        $values = array_values(array_unique(array_map(static fn (string $value): string => ltrim($value, '0') ?: '0', $matches[1])));
        sort($values, \SORT_STRING);

        return $values;
    }

    public static function packageNeedsReview(?string $sourceName, ?string $targetName): bool
    {
        $source = self::quantities($sourceName);
        $target = self::quantities($targetName);

        return count($source) > 1 || count($target) > 1 || ([] !== $source && [] !== $target && $source !== $target);
    }

    /** Кириллица, которая пишется как латиница (М/M, Х/X …), регистр и пробелы — без словарей под компанию. */
    public static function normalizeSize(?string $value): ?string
    {
        $value = self::normalizeKey($value);
        if (null === $value) {
            return null;
        }
        $value = strtr(mb_strtoupper(preg_replace('/\s+/u', '', $value) ?? $value), self::CYRILLIC_AS_LATIN);

        return '' === $value ? null : $value;
    }

    /** @return list<string> Ключи «артикул + разделитель + размер» в нормализованном виде (тот же вид даёт SQL кандидатов). */
    public static function articleSizeKeys(?string $article, ?string $size): array
    {
        $article = self::normalizeKey($article);
        $size = self::normalizeSize($size);
        if (null === $article || null === $size) {
            return [];
        }
        $article = strtr(mb_strtoupper($article), self::CYRILLIC_AS_LATIN);

        return array_map(static fn (string $separator): string => $article.$separator.$size, ['-', '_', ' ']);
    }

    public static function normalizeArticle(?string $article): ?string
    {
        $article = self::normalizeKey($article);

        return null === $article ? null : strtr(mb_strtoupper($article), self::CYRILLIC_AS_LATIN);
    }

    /** Первый токен в скобках названия: «Брюки (M, черный)» → M; null — размера в названии нет. */
    public static function nameSize(?string $name): ?string
    {
        return 1 === preg_match('/\(\s*([^,()]+?)\s*[,)]/u', $name ?? '', $match) ? self::normalizeSize($match[1]) : null;
    }

    /** Сила правила: меньше — сильнее. Штрихкод — основание; артикульные правила его не перебивают. */
    private const TIERS = ['exact_barcode' => 1, 'linked_barcode' => 2, 'direct_article' => 3, 'parent_article_size' => 3, 'exact_code' => 3, 'wb_article_alias' => 3, 'article_size' => 4, 'name_size' => 5];

    public const CYRILLIC_AS_LATIN = ['А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X'];

    /** @param list<string> $rules */
    private static function tier(array $rules): int
    {
        $tier = [] === $rules ? 3 : \PHP_INT_MAX;
        foreach ($rules as $rule) {
            $tier = min($tier, self::TIERS[$rule] ?? 3);
        }

        return $tier;
    }

    /** @param list<string> $barcodes */
    public function decide(ListingMetaDTO $source, AutomaticMappingCandidates $candidates, ?string $sizeCharacteristicId, array $barcodes = [], bool $wbTrailingW = false): AutomaticMappingDecision
    {
        if (!$source->isActive) {
            return new AutomaticMappingDecision(Status::LISTING_UNAVAILABLE);
        }
        $article = self::normalizeKey($source->supplierSku);
        if (null === $article && [] === $barcodes) {
            return new AutomaticMappingDecision(Status::NO_ARTICLE);
        }
        if ($candidates->truncated || $candidates->totalCount > 50) {
            return new AutomaticMappingDecision(Status::TOO_MANY_CANDIDATES, candidates: $candidates->items);
        }
        $alias = self::articleAlias($source, $wbTrailingW);
        $size = self::normalizeKey($source->size);
        $sizeKeys = self::articleSizeKeys($article, $size);
        $barcodeSizeConflict = $barcodeConflict = false;
        /** @var array<string,array{target:\App\MoySklad\Application\DTO\CatalogTargetDTO,rules:list<string>,linked:bool}> $matches */
        $matches = [];
        $parentIds = [];
        $parentFound = $sizeMissing = $sizeMismatch = $unverifiable = $ruleMissing = false;
        foreach ($candidates->items as $candidate) {
            $target = $candidate->target;
            $direct = null !== $article && self::normalizeKey($target->article) === $article;
            $parent = null !== $article && 'variant' === $target->type && self::normalizeKey($candidate->parentArticle) === $article;
            $code = null !== $article && self::normalizeKey($target->code) === $article;
            $aliasDirect = null !== $alias && self::normalizeKey($target->article) === $alias;
            $aliasParent = null !== $alias && 'variant' === $target->type && self::normalizeKey($candidate->parentArticle) === $alias;
            $barcode = [] !== array_intersect($barcodes, $target->barcodes ?? []);
            $linked = $candidate->linkedBarcode;
            $articleSize = [] !== $sizeKeys && in_array(self::normalizeArticle($target->article), $sizeKeys, true);
            $ownIdentity = $direct || $code || $aliasDirect || $barcode || $linked || $articleSize;
            if (!$candidate->leaf) {
                $parentFound = $parentFound || $ownIdentity;
                if ($ownIdentity) {
                    $parentIds[] = $target->externalId;
                }
                continue;
            }
            if (!$ownIdentity && !$parent && !$aliasParent) {
                continue;
            }
            // Штрихкоды есть с обеих сторон и не пересекаются — артикул не может это перебить.
            if (!$barcode && [] !== $barcodes && [] !== ($target->barcodes ?? [])) {
                $barcodeConflict = true;
                continue;
            }
            $nameSize = false;
            if ($barcode || $linked) {
                // Штрихкод сам определяет размер; противоречит ему только явная характеристика модификации.
                if (null !== $size && 'variant' === $target->type && null !== $sizeCharacteristicId) {
                    $values = array_values(array_filter($target->characteristics, static fn (array $item): bool => $item['id'] === $sizeCharacteristicId));
                    $targetSize = 1 === count($values) ? self::normalizeSize($values[0]['value']) : null;
                    if (null !== $targetSize && $targetSize !== self::normalizeSize($size)) {
                        $sizeMismatch = $barcodeSizeConflict = true;
                        continue;
                    }
                }
            } elseif (null === $size) {
                if (!$ownIdentity) {
                    $sizeMissing = true;
                    continue;
                }
            } elseif ($articleSize) {
                // Размер подтверждён самим артикулом позиции.
            } elseif ('variant' !== $target->type) {
                $targetSize = self::nameSize($target->name);
                if (null === $targetSize || !($direct || $code || $aliasDirect)) {
                    $unverifiable = true;
                    continue;
                }
                if ($targetSize !== self::normalizeSize($size)) {
                    $sizeMismatch = true;
                    continue;
                }
                $nameSize = true;
            } else {
                if (null === $sizeCharacteristicId) {
                    $ruleMissing = true;
                    continue;
                }
                $values = array_values(array_filter($target->characteristics, static fn (array $item): bool => $item['id'] === $sizeCharacteristicId));
                if (1 !== count($values)) {
                    $unverifiable = true;
                    continue;
                }
                $targetSize = self::normalizeKey($values[0]['value']);
                if (null === $targetSize) {
                    $unverifiable = true;
                    continue;
                }
                if ($targetSize !== $size) {
                    $sizeMismatch = true;
                    continue;
                }
            }
            if ($nameSize) {
                $rules = ['name_size'];
            } else {
                $rules = $direct ? ['direct_article'] : [];
                if ($parent && null !== $size) {
                    $rules[] = 'parent_article_size';
                }
                if ($code) {
                    $rules[] = 'exact_code';
                }
                if ($aliasDirect || ($aliasParent && null !== $size)) {
                    $rules[] = 'wb_article_alias';
                }
                if ($articleSize) {
                    $rules[] = 'article_size';
                }
            }
            if ($linked) {
                $rules[] = 'linked_barcode';
            }
            if ($barcode) {
                $rules[] = 'exact_barcode';
            }
            $key = $target->getValue();
            $matches[$key] = ['target' => $target, 'rules' => array_values(array_unique(array_merge($matches[$key]['rules'] ?? [], $rules))), 'linked' => $candidate->linkedElsewhere];
        }
        $tier = static fn (array $match): int => self::tier($match['rules']);
        $best = null;
        foreach ($matches as $match) {
            $best = min($best ?? \PHP_INT_MAX, $tier($match));
        }
        // Собственный штрихкод решает сам; иначе сравниваются все найденные позиции, и любая вторая — неоднозначность.
        $pool = 1 === $best ? array_filter($matches, static fn (array $match): bool => 1 === $tier($match)) : $matches;
        if (count($pool) > 1 && null !== $best && $best >= 3) {
            $linkedPool = array_filter($pool, static fn (array $match): bool => $match['linked']);
            $pool = 1 === count($linkedPool) ? $linkedPool : $pool;
        }
        $chosen = 1 === count($pool) ? array_values($pool)[0] : null;
        $unrelatedParent = null !== $chosen && [] !== array_filter($parentIds, static fn (string $id): bool => $id !== $chosen['target']->parentExternalId);
        $articleOnly = null === $best || $best >= 3;
        $status = match (true) {
            $barcodeSizeConflict => Status::SIZE_MISMATCH,
            count($pool) > 1, $unrelatedParent => Status::AMBIGUOUS,
            $articleOnly && $barcodeConflict => Status::BARCODE_CONFLICT,
            $articleOnly && $ruleMissing => Status::SIZE_RULE_REQUIRED,
            $articleOnly && $unverifiable => Status::SIZE_UNVERIFIABLE,
            null !== $chosen && self::packageNeedsReview($source->title, $chosen['target']->name) => Status::PACKAGE_REVIEW,
            null !== $chosen => Status::READY,
            $sizeMismatch => Status::SIZE_MISMATCH,
            $sizeMissing => Status::SIZE_UNKNOWN,
            $parentFound => Status::VARIANT_REQUIRED,
            default => Status::NO_MATCH,
        };
        $allRules = [];
        foreach ($matches as $match) {
            $allRules = array_values(array_unique(array_merge($allRules, $match['rules'])));
        }
        if (Status::READY !== $status) {
            return new AutomaticMappingDecision($status, matchedRules: $allRules, comparedSize: $size, selectedCharacteristicId: $sizeCharacteristicId, candidates: $candidates->items);
        }
        if (!$chosen['target']->selectable) {
            return new AutomaticMappingDecision(Status::TARGET_UNAVAILABLE, matchedRules: $allRules, comparedSize: $size, selectedCharacteristicId: $sizeCharacteristicId, candidates: $candidates->items);
        }

        return new AutomaticMappingDecision(Status::READY, $chosen['target'], $chosen['rules'], $size, $sizeCharacteristicId, $candidates->items);
    }
}

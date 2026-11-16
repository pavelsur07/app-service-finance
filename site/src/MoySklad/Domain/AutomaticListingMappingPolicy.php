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
        $packageConflict = $barcodeSizeConflict = false;
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
            $ownIdentity = $direct || $code || $aliasDirect || $barcode;
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
            if (null === $size) {
                if (!$ownIdentity) {
                    $sizeMissing = true;
                    continue;
                }
            } else {
                if ('variant' !== $target->type) {
                    $unverifiable = true;
                    continue;
                }
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
                    $barcodeSizeConflict = $barcodeSizeConflict || $barcode;
                    continue;
                }
            }
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
            if ($barcode) {
                $rules[] = 'exact_barcode';
            }
            $packageConflict = $packageConflict || self::packageNeedsReview($source->title, $target->name);
            $key = $target->getValue();
            $matches[$key] = ['target' => $target, 'rules' => array_values(array_unique(array_merge($matches[$key]['rules'] ?? [], $rules)))];
        }
        $unrelatedParent = false;
        if (1 === count($matches)) {
            $matchedTarget = array_values($matches)[0]['target'];
            $unrelatedParent = [] !== array_filter($parentIds, static fn (string $id): bool => $id !== $matchedTarget->parentExternalId);
        }
        $status = match (true) {
            $barcodeSizeConflict => Status::SIZE_MISMATCH,
            $unrelatedParent => Status::AMBIGUOUS,
            count($matches) > 1 => Status::AMBIGUOUS,
            $ruleMissing => Status::SIZE_RULE_REQUIRED,
            $unverifiable => Status::SIZE_UNVERIFIABLE,
            $packageConflict => Status::PACKAGE_REVIEW,
            [] !== $matches => Status::READY,
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
        $match = array_values($matches)[0];
        if (!$match['target']->selectable) {
            return new AutomaticMappingDecision(Status::TARGET_UNAVAILABLE, matchedRules: $allRules, comparedSize: $size, selectedCharacteristicId: $sizeCharacteristicId, candidates: $candidates->items);
        }

        return new AutomaticMappingDecision(Status::READY, $match['target'], $match['rules'], $size, $sizeCharacteristicId, $candidates->items);
    }
}

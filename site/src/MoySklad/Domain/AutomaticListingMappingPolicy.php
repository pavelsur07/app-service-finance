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

    public function sourceFingerprint(ListingMetaDTO $source): string
    {
        return hash('sha256', json_encode([
            'id' => $source->id, 'marketplace' => $source->marketplace, 'sku' => $source->sku,
            'supplierSku' => self::normalizeKey($source->supplierSku), 'size' => self::normalizeKey($source->size),
            'variantId' => $source->marketplaceVariantId, 'isActive' => $source->isActive,
        ], \JSON_THROW_ON_ERROR));
    }

    public function decide(ListingMetaDTO $source, AutomaticMappingCandidates $candidates, ?string $sizeCharacteristicId): AutomaticMappingDecision
    {
        if (!$source->isActive) {
            return new AutomaticMappingDecision(Status::LISTING_UNAVAILABLE);
        }
        $article = self::normalizeKey($source->supplierSku);
        if (null === $article) {
            return new AutomaticMappingDecision(Status::NO_ARTICLE);
        }
        if ($candidates->truncated || $candidates->totalCount > 50) {
            return new AutomaticMappingDecision(Status::TOO_MANY_CANDIDATES);
        }
        $size = self::normalizeKey($source->size);
        $matches = [];
        $parentIds = [];
        $parentFound = $sizeMissing = $sizeMismatch = $unverifiable = $ruleMissing = false;
        foreach ($candidates->items as $candidate) {
            $target = $candidate->target;
            $direct = self::normalizeKey($target->article) === $article;
            $parent = 'variant' === $target->type && self::normalizeKey($candidate->parentArticle) === $article;
            if (!$candidate->leaf) {
                $parentFound = $parentFound || $direct;
                if ($direct) {
                    $parentIds[] = $target->externalId;
                }
                continue;
            }
            if (!$direct && !$parent) {
                continue;
            }
            if (null === $size) {
                if (!$direct) {
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
                if (self::normalizeKey($values[0]['value']) !== $size) {
                    $sizeMismatch = true;
                    continue;
                }
            }
            $rules = $direct ? ['direct_article'] : [];
            if ($parent && null !== $size) {
                $rules[] = 'parent_article_size';
            }
            $key = $target->getValue();
            $matches[$key] = ['target' => $target, 'rules' => array_values(array_unique(array_merge($matches[$key]['rules'] ?? [], $rules)))];
        }
        $unrelatedParent = false;
        if (null === $size && 1 === count($matches)) {
            $matchedTarget = array_values($matches)[0]['target'];
            $unrelatedParent = [] !== array_filter($parentIds, static fn (string $id): bool => $id !== $matchedTarget->parentExternalId);
        }
        $status = match (true) {
            $unrelatedParent => Status::AMBIGUOUS,
            count($matches) > 1 => Status::AMBIGUOUS,
            $ruleMissing => Status::SIZE_RULE_REQUIRED,
            $unverifiable => Status::SIZE_UNVERIFIABLE,
            [] !== $matches => Status::READY,
            $sizeMismatch => Status::SIZE_MISMATCH,
            $sizeMissing => Status::SIZE_UNKNOWN,
            $parentFound => Status::VARIANT_REQUIRED,
            default => Status::NO_MATCH,
        };
        if (Status::READY !== $status) {
            return new AutomaticMappingDecision($status, comparedSize: $size, selectedCharacteristicId: $sizeCharacteristicId);
        }
        $match = array_values($matches)[0];
        if (!$match['target']->selectable) {
            return new AutomaticMappingDecision(Status::TARGET_UNAVAILABLE, comparedSize: $size, selectedCharacteristicId: $sizeCharacteristicId);
        }

        return new AutomaticMappingDecision(Status::READY, $match['target'], $match['rules'], $size, $sizeCharacteristicId);
    }
}

<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Domain;

use App\MarketplaceOperations\Enum\OperationKind;

/**
 * Правило для операции канона на день: код источника → вид × канал → вид; при равной точности —
 * с более поздним `validFrom`. `null` — правила нет («не сопоставлено»).
 */
final class RuleResolver
{
    /** @param iterable<Rule> $rules */
    public static function resolve(iterable $rules, OperationKind $kind, string $channel, string $sourceType, string $day): ?Rule
    {
        $best = null;
        $bestRank = '';
        foreach ($rules as $rule) {
            if ($rule->validFrom > $day) {
                continue;
            }
            if (null !== $rule->sourceType) {
                $precision = $rule->sourceType === $sourceType && $rule->channel === $channel ? 2 : null;
            } elseif ($rule->kind !== $kind) {
                $precision = null;
            } elseif (null !== $rule->channel) {
                $precision = $rule->channel === $channel ? 1 : null;
            } else {
                $precision = 0;
            }
            if (null === $precision) {
                continue;
            }
            $rank = $precision.$rule->validFrom;
            if ($rank > $bestRank) {
                $best = $rule;
                $bestRank = $rank;
            }
        }

        return $best;
    }
}

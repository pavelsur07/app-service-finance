<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

/**
 * Статья ОПиУ «по образцу компании» для правил шаблона, чьей статьи у компании нет.
 *
 * У компании со своим деревом ОПиУ статьи шаблона (`pl_code`) может не быть, но
 * другие затраты того же типа — с тем же `pl_code` в шаблоне — она уже куда-то
 * относит. Если все такие правила указывают на одну статью, новая затрата идёт
 * туда же. Разнобой или отсутствие образцов — решения нет: угадывать статью
 * нельзя, такое правило остаётся человеку (его показывает гейт unmapped-check).
 */
final readonly class DefaultCostMappingSiblingResolver
{
    /**
     * @param array<string, string> $templatePlCodes код затраты => pl_code шаблона
     * @param array<string, string> $companyMapping код затраты => статья ОПиУ: включённые правила
     *                                              компании со статьёй LEAF_INPUT
     *
     * @return array<string, string> pl_code шаблона => статья ОПиУ компании
     */
    public function resolve(array $templatePlCodes, array $companyMapping): array
    {
        $targetsByPlCode = [];
        foreach ($templatePlCodes as $costCode => $plCode) {
            $plCategoryId = $companyMapping[$costCode] ?? null;
            if (null !== $plCategoryId) {
                $targetsByPlCode[$plCode][$plCategoryId] = true;
            }
        }

        $resolved = [];
        foreach ($targetsByPlCode as $plCode => $targets) {
            if (1 === \count($targets)) {
                $resolved[$plCode] = (string) array_key_first($targets);
            }
        }

        return $resolved;
    }
}

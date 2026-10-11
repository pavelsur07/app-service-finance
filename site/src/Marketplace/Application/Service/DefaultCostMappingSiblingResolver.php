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
 *
 * Образцы сначала берутся из того же маркетплейса. Только если там у компании нет
 * ни одного решения по этому `pl_code` — ни правила со статьёй, ни исключённого из
 * ОПиУ, — берутся правила других маркетплейсов той же компании (тот же `pl_code` в
 * их шаблоне), тоже при единогласии. Разнобой или исключение внутри своего
 * маркетплейса чужими образцами не перекрывается: это решение компании.
 */
final readonly class DefaultCostMappingSiblingResolver
{
    /**
     * @param string $marketplace маркетплейс, для которого ищутся статьи
     * @param array<string, array<string, string>> $templatePlCodes маркетплейс => код затраты => pl_code шаблона
     * @param array<string, array<string, string|null>> $companyMapping маркетплейс => код затраты => статья ОПиУ
     *                                                                  (включённое правило со статьёй LEAF_INPUT)
     *                                                                  либо null (решение без статьи-образца)
     *
     * @return array<string, array{pl_category_id: string, cross_marketplace: bool}> pl_code шаблона => статья ОПиУ компании
     */
    public function resolve(string $marketplace, array $templatePlCodes, array $companyMapping): array
    {
        $ownDecided = [];
        $own = [];
        $foreign = [];
        foreach ($templatePlCodes as $sampleMarketplace => $codes) {
            foreach ($codes as $costCode => $plCode) {
                if (!\array_key_exists($costCode, $companyMapping[$sampleMarketplace] ?? [])) {
                    continue;
                }

                $plCategoryId = $companyMapping[$sampleMarketplace][$costCode];
                if ($sampleMarketplace === $marketplace) {
                    $ownDecided[$plCode] = true;
                }
                if (null === $plCategoryId) {
                    continue;
                }

                if ($sampleMarketplace === $marketplace) {
                    $own[$plCode][$plCategoryId] = true;
                } else {
                    $foreign[$plCode][$plCategoryId] = true;
                }
            }
        }

        $resolved = [];
        foreach ($own as $plCode => $targets) {
            if (1 === \count($targets)) {
                $resolved[$plCode] = ['pl_category_id' => (string) array_key_first($targets), 'cross_marketplace' => false];
            }
        }

        foreach ($foreign as $plCode => $targets) {
            if (!isset($ownDecided[$plCode]) && 1 === \count($targets)) {
                $resolved[$plCode] = ['pl_category_id' => (string) array_key_first($targets), 'cross_marketplace' => true];
            }
        }

        return $resolved;
    }
}

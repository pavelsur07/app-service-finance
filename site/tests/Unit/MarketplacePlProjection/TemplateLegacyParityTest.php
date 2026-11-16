<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplacePlProjection;

use App\Marketplace\Ozon\Domain\OzonCostCategory;
use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceOzon\Domain\OzonAccrualTypeSeed;
use App\MarketplacePlProjection\Domain\MappingTemplate;
use App\MarketplacePlProjection\Domain\Rule;
use App\MarketplacePlProjection\Domain\RuleResolver;
use App\MarketplacePlProjection\Enum\RuleOrigin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Шаблон ведёт каждую услугу Ozon в ту же статью, что базовый маппинг легаси
 * (`default_cost_mapping.yaml`): тип начисления → имя справочника Ozon → категория легаси → статья.
 * Расхождение — либо исключение по коду источника в шаблоне, либо осознанная строка в KNOWN.
 */
final class TemplateLegacyParityTest extends TestCase
{
    /** Тип начисления => [статья легаси, статья шаблона]: осознанные расхождения. */
    private const KNOWN = [];

    public function testOzonServicesGoToTheSameArticleAsLegacyDefault(): void
    {
        $root = __DIR__.'/../../..';
        $nameById = [];
        foreach (json_decode((string) file_get_contents($root.'/tests/Fixtures/Marketplace/Ozon/accrual_types.json'), true, flags: \JSON_THROW_ON_ERROR)['accrual_types'] as $type) {
            $nameById[(int) $type['id']] = (string) $type['name'];
        }
        $legacyCodeByName = [];
        foreach (OzonCostCategory::all() as $category) {
            foreach ($category->accrualTypeNames as $name) {
                $legacyCodeByName[$name] = $category->code;
            }
        }
        $legacyArticle = [];
        foreach (Yaml::parseFile($root.'/config/marketplace/default_cost_mapping.yaml')['marketplaces']['ozon']['cost_mappings'] as $rule) {
            $legacyArticle[(string) $rule['cost_code']] = true === $rule['include_in_pl'] ? (string) $rule['pl_code'] : null;
        }
        $rules = [];
        foreach (MappingTemplate::rules() as $rule) {
            $rules[] = new Rule($rule->kind, $rule->channel, $rule->sourceType, $rule->plCode, RuleOrigin::PRESET, Rule::BASE_DATE);
        }

        $diff = [];
        $checked = 0;
        foreach (OzonAccrualTypeSeed::TYPES as $id => [, $kind]) {
            $legacyCode = $legacyCodeByName[$nameById[$id] ?? ''] ?? null;
            // Тип без вида в справочнике получает вид решением администратора: статью держит только исключение по коду.
            $rule = RuleResolver::resolve($rules, $kind ?? OperationKind::SPP, 'ozon', 'ozon:service:'.$id, '2026-11-16');
            if (null === $legacyCode || !\array_key_exists($legacyCode, $legacyArticle) || (null === $kind && null === $rule?->sourceType)) {
                continue;
            }
            ++$checked;
            $template = $rule?->plCategoryId;
            if ($template !== $legacyArticle[$legacyCode]) {
                $diff[$id] = [$legacyArticle[$legacyCode], $template];
            }
        }

        self::assertGreaterThan(34, $checked);
        self::assertSame(self::KNOWN, $diff);
    }
}

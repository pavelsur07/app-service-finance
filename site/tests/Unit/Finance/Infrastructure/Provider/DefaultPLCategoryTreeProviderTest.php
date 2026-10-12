<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Infrastructure\Provider;

use App\Finance\Application\Service\PLCategoryTreeFileReader;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Exception\DefaultPLTreeTemplateNotFoundException;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Шаблон дерева ОПиУ и шаблоны маппинга маркетплейсов живут в разных файлах:
 * статья, на которую ссылается маппинг, но которой нет в дереве, снова оставит
 * затраты новой компании вне ОПиУ. Тест держит файлы в согласии.
 */
final class DefaultPLCategoryTreeProviderTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../../..';

    public function testTemplateHasEveryCodeUsedByMarketplaceMappingTemplates(): void
    {
        $codes = [];
        foreach ($this->provider()->nodes() as $node) {
            self::assertNotNull($node->code, \sprintf('Template category "%s" has no code: re-applying could not match it.', $node->name));
            $codes[$node->code] = $node->type;
        }

        $mappingCodes = [];
        foreach (['default_cost_mapping.yaml', 'default_sale_mapping.yaml'] as $file) {
            $config = Yaml::parseFile(self::ROOT.'/config/marketplace/'.$file);
            self::assertIsArray($config);
            array_walk_recursive(
                $config,
                static function (mixed $value, int|string $key) use (&$mappingCodes): void {
                    if ('pl_code' === $key && \is_string($value) && '' !== $value) {
                        $mappingCodes[$value] = true;
                    }
                },
            );
        }

        self::assertNotEmpty($mappingCodes);
        self::assertSame([], array_keys(array_diff_key($mappingCodes, $codes)));
        // Маппинг пишет факты в строку ввода: подытог или KPI их не показали бы.
        foreach (array_keys($mappingCodes) as $code) {
            self::assertSame(PLCategoryType::LEAF_INPUT, $codes[$code], $code);
        }
    }

    public function testMissingTemplateFileFailsLoudly(): void
    {
        $this->expectException(DefaultPLTreeTemplateNotFoundException::class);

        (new DefaultPLCategoryTreeProvider('/nonexistent/default_pl_tree.json', new PLCategoryTreeFileReader()))->nodes();
    }

    private function provider(): DefaultPLCategoryTreeProvider
    {
        return new DefaultPLCategoryTreeProvider(self::ROOT.'/config/finance/default_pl_tree.json', new PLCategoryTreeFileReader());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Onboarding;

use App\Balance\Application\BalanceStructureTemplateReader;
use App\Balance\DTO\BalanceStructureTemplateNode;
use App\Company\Enum\BusinessProfile;
use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Application\Service\PLCategoryTreeFileReader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Реестр стартовых шаблонов по профилю бизнеса и словарь кодов.
 *
 * Шаблоны профилей — отдельные файлы, но код статьи значит одно и то же во всех: на коды
 * опираются дашборды, KPI, маппинги маркетплейсов и дополнение дерева чужим шаблоном
 * (импорт «добавить недостающее» матчит по коду). Код с разным типом, потоком или родителем
 * в двух шаблонах сломал бы это молча.
 */
final class TemplateRegistryTest extends KernelTestCase
{
    public function testEveryProfileHasExistingTemplates(): void
    {
        foreach (['finance.pl_templates', 'balance.structure_templates'] as $registry) {
            $paths = $this->registry($registry);
            self::assertSame(
                array_map(static fn (BusinessProfile $p): string => $p->value, BusinessProfile::cases()),
                array_keys($paths),
                $registry,
            );
            foreach ($paths as $profile => $path) {
                self::assertFileExists($path, sprintf('%s[%s]', $registry, $profile));
            }
        }
    }

    public function testTemplateDeclaresItsProfileOrTheMarketplaceFallback(): void
    {
        foreach ($this->registry('finance.pl_templates') as $profile => $path) {
            $declared = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR)['profile'] ?? null;
            self::assertContains($declared, [$profile, BusinessProfile::MARKETPLACE->value], sprintf('ОПиУ %s → %s', $profile, $path));
        }
        foreach ($this->registry('balance.structure_templates') as $profile => $path) {
            $declared = Yaml::parseFile($path)['profile'] ?? null;
            self::assertContains($declared, [$profile, BusinessProfile::MARKETPLACE->value], sprintf('Баланс %s → %s', $profile, $path));
        }
    }

    public function testPlCodesMeanTheSameInEveryTemplate(): void
    {
        $reader = new PLCategoryTreeFileReader();
        $templates = [];
        // Расширение «Базовый + маркетплейсы» — тот же словарь: статьи маркетплейса на своих местах.
        $extension = self::getContainer()->getParameter('finance.pl_marketplace_extension');
        foreach ([...array_unique($this->registry('finance.pl_templates')), $extension] as $path) {
            $templates[basename($path)] = self::plSignatures($reader->read((string) file_get_contents($path)));
        }

        self::assertCount(3, $templates);
        self::assertSame([], self::conflicts($templates));
    }

    /**
     * Расширение содержит каждый код, на который ссылаются шаблоны маппинга затрат и продаж
     * маркетплейсов: иначе у компании «Базовый» затрата осталась бы без статьи.
     */
    public function testMarketplaceExtensionCoversMappingTemplateCodes(): void
    {
        $extension = self::getContainer()->getParameter('finance.pl_marketplace_extension');
        $codes = array_map(static fn ($n): ?string => $n->code, (new PLCategoryTreeFileReader())->read((string) file_get_contents($extension)));

        $root = (string) self::getContainer()->getParameter('kernel.project_dir');
        $mappingCodes = [];
        foreach (['default_cost_mapping.yaml', 'default_sale_mapping.yaml'] as $file) {
            preg_match_all("/pl_code:\\s*'?([A-Z0-9_]+)/", (string) file_get_contents($root.'/config/marketplace/'.$file), $m);
            $mappingCodes = [...$mappingCodes, ...$m[1]];
        }
        $mappingCodes = array_values(array_unique($mappingCodes));
        self::assertGreaterThanOrEqual(20, \count($mappingCodes));

        self::assertSame([], array_values(array_diff($mappingCodes, $codes)));
    }

    public function testBalanceCodesMeanTheSameInEveryTemplate(): void
    {
        $reader = new BalanceStructureTemplateReader([]);
        $templates = [];
        foreach (array_unique($this->registry('balance.structure_templates')) as $path) {
            $templates[basename($path)] = self::balanceSignatures($reader->parse((string) file_get_contents($path)));
        }

        self::assertSame([], self::conflicts($templates));
    }

    /**
     * Проверка самой проверки: расхождение кода между шаблонами находится.
     */
    public function testConflictingCodeIsReported(): void
    {
        self::assertSame(
            ['REV_TOTAL: a.json KPI/NONE/MONEY/-, b.json SUBTOTAL/INCOME/MONEY/-'],
            self::conflicts([
                'a.json' => ['REV_TOTAL' => 'KPI/NONE/MONEY/-', 'EBITDA' => 'KPI/NONE/MONEY/-'],
                'b.json' => ['REV_TOTAL' => 'SUBTOTAL/INCOME/MONEY/-', 'EBITDA' => 'KPI/NONE/MONEY/-', 'GROSS' => 'KPI/NONE/MONEY/-'],
            ]),
        );
    }

    /**
     * @param list<PLCategoryTreeNode> $nodes
     *
     * @return array<string, string> код → тип/поток/формат/код родителя
     */
    private static function plSignatures(array $nodes): array
    {
        $signatures = [];
        foreach ($nodes as $node) {
            self::assertNotNull($node->code, sprintf('Статья шаблона «%s» без кода', $node->name));
            $signatures[$node->code] = implode('/', [$node->type->value, $node->flow->value, $node->format->value, $node->parent->code ?? '-']);
        }

        return $signatures;
    }

    /**
     * @param list<BalanceStructureTemplateNode> $nodes
     *
     * @return array<string, string> код статьи → сторона/вид/код родителя; код счёта → статья
     */
    private static function balanceSignatures(array $nodes, string $parent = '-'): array
    {
        $signatures = [];
        foreach ($nodes as $node) {
            $signatures[$node->code] = implode('/', [$node->type->value, $node->kind, $parent]);
            foreach ($node->accounts as $account) {
                $signatures['account:'.$account->code] = 'on '.$node->code;
            }
            $signatures += self::balanceSignatures($node->children, $node->code);
        }

        return $signatures;
    }

    /**
     * @param array<string, array<string, string>> $templates файл → код → сигнатура
     *
     * @return list<string> коды с разной сигнатурой в разных файлах
     */
    private static function conflicts(array $templates): array
    {
        $byCode = [];
        foreach ($templates as $file => $signatures) {
            foreach ($signatures as $code => $signature) {
                $byCode[$code][$file] = $signature;
            }
        }

        $conflicts = [];
        foreach ($byCode as $code => $signatures) {
            if (\count(array_unique($signatures)) > 1) {
                $conflicts[] = $code.': '.implode(', ', array_map(static fn (string $file, string $sig): string => $file.' '.$sig, array_keys($signatures), $signatures));
            }
        }

        return $conflicts;
    }

    /**
     * @return array<string, string>
     */
    private function registry(string $parameter): array
    {
        $paths = self::getContainer()->getParameter($parameter);
        self::assertIsArray($paths);

        /* @var array<string, string> $paths */
        return $paths;
    }
}

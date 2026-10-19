<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonCategoryGroupSeed;
use App\Ingestion\Entity\CategoryGroup;
use App\Ingestion\Entity\CategoryGroupRule;
use App\Ingestion\Enum\CategoryGroupRuleConfidence;
use App\Ingestion\Enum\IngestSource;
use DoctrineMigrations\Version20261018160000;
use PHPUnit\Framework\TestCase;

/**
 * Миграция засевает прод, `OzonCategoryGroupSeed` — тестовую БД после TRUNCATE.
 * Расхождение дало бы на проде одни правила, а в тестах другие при зелёном CI.
 */
final class OzonCategoryGroupSeedTest extends TestCase
{
    public function testMigrationSeedsExactlyTheDefinitions(): void
    {
        require_once \dirname(__DIR__, 4).'/migrations/Version20261018160000.php';
        $migration = new \ReflectionClass(Version20261018160000::class);

        $groupsConstant = $migration->getReflectionConstant('GROUPS');
        $rulesConstant = $migration->getReflectionConstant('RULES');
        self::assertNotFalse($groupsConstant);
        self::assertNotFalse($rulesConstant);

        /** @var list<array{id: string, code: string, label: string, ozon_label: ?string, sort: int}> $groups */
        $groups = $groupsConstant->getValue();
        /** @var list<array{id: string, priority: int, group: string, confidence: string, pattern: string, note: ?string}> $rules */
        $rules = $rulesConstant->getValue();

        $migrationGroups = [];
        foreach ($groups as $group) {
            $migrationGroups[$group['id']] = ['code' => $group['code'], 'label' => $group['label'], 'ozonLabel' => $group['ozon_label'], 'sortOrder' => $group['sort']];
        }
        $migrationRules = [];
        foreach ($rules as $rule) {
            $migrationRules[$rule['id']] = ['priority' => $rule['priority'], 'groupCode' => $rule['group'], 'confidence' => $rule['confidence'], 'pattern' => $rule['pattern'], 'note' => $rule['note']];
        }

        self::assertSame(OzonCategoryGroupSeed::groups(), $migrationGroups);
        self::assertSame(OzonCategoryGroupSeed::rules(), $migrationRules);
    }

    public function testDefinitionsSatisfyEntityInvariantsAndCompile(): void
    {
        $codes = [];
        foreach (OzonCategoryGroupSeed::groups() as $id => $group) {
            $entity = new CategoryGroup(IngestSource::OZON, $group['code'], $group['label'], $group['sortOrder'], $group['ozonLabel'], $id);
            $codes[$entity->getCode()] = true;
        }

        foreach (OzonCategoryGroupSeed::rules() as $id => $rule) {
            $entity = new CategoryGroupRule(IngestSource::OZON, $rule['priority'], $rule['pattern'], $rule['groupCode'], CategoryGroupRuleConfidence::from($rule['confidence']), note: $rule['note'], id: $id);
            self::assertNotFalse(preg_match($entity->regex(), ''), $rule['pattern']);
            self::assertArrayHasKey($rule['groupCode'], $codes);
        }
    }

    public function testRuleRejectsDelimiterAndGroupRejectsControlCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CategoryGroupRule(IngestSource::OZON, 1, 'a~b', 'other', CategoryGroupRuleConfidence::HIGH);
    }

    public function testGroupLabelWithLineBreakIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CategoryGroup(IngestSource::OZON, 'other', "Другие услуги и\nштрафы", 900);
    }
}

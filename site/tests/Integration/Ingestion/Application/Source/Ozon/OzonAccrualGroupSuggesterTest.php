<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonAccrualGroupSuggester;
use App\Ingestion\Domain\OzonCategoryGroupSeed;
use App\Ingestion\Entity\CategoryGroupRule;
use App\Ingestion\Enum\CategoryGroupRuleConfidence;
use App\Ingestion\Enum\IngestSource;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class OzonAccrualGroupSuggesterTest extends IntegrationTestCase
{
    private const FIXTURE = __DIR__.'/../../../../../Fixtures/Marketplace/Ozon/accrual_types.json';

    /**
     * Группы Ozon из отчёта кабинета «Детализация начислений» (Stage 0) по type_id.
     */
    private const OZON_REFERENCE = [
        1 => 'Услуги партнёров', 10 => 'Компенсации', 16 => 'Услуги доставки', 17 => 'Услуги партнёров',
        25 => 'Компенсации', 29 => 'Услуги партнёров', 32 => 'Услуги доставки', 38 => 'Другие услуги и штрафы',
        39 => 'Услуги партнёров', 41 => 'Продвижение и реклама', 45 => 'Услуги партнёров', 46 => 'Услуги FBO',
        52 => 'Продвижение и реклама', 54 => 'Продвижение и реклама', 59 => 'Услуги доставки', 74 => 'Услуги партнёров',
        76 => 'Услуги партнёров', 79 => 'Услуги партнёров', 84 => 'Услуги FBO', 92 => 'Другие услуги и штрафы',
        94 => 'Другие услуги и штрафы', 95 => 'Продвижение и реклама', 98 => 'Услуги доставки',
    ];

    /**
     * Известный промах: в описании 29 («Доставка до места выдачи») нет «партнёр»;
     * тип размечен каталогом, подсказка ему не нужна.
     */
    private const KNOWN_MISSES = [29];

    public function testSeedMatchesDefinitions(): void
    {
        self::assertSame(
            array_keys(OzonCategoryGroupSeed::groups()),
            $this->connection->fetchFirstColumn('SELECT id::text FROM ingest_category_groups ORDER BY sort_order'),
        );
        self::assertEqualsCanonicalizing(
            array_keys(OzonCategoryGroupSeed::rules()),
            $this->connection->fetchFirstColumn('SELECT id::text FROM ingest_category_group_rules'),
        );
    }

    public function testSeedRulesMatchOzonGroupsOnReference(): void
    {
        $types = $this->fixtureTypes();
        $suggester = $this->suggester();

        $misses = [];
        foreach (self::OZON_REFERENCE as $typeId => $ozonGroup) {
            $suggestion = $suggester->suggest($types[$typeId]['name'], $types[$typeId]['description']);
            if (null === $suggestion || !$suggestion->isHigh() || $suggestion->groupLabel !== $ozonGroup) {
                $misses[] = $typeId;
            }
        }

        self::assertSame(self::KNOWN_MISSES, $misses);
        self::assertGreaterThanOrEqual(0.9, 1 - \count($misses) / \count(self::OZON_REFERENCE));
    }

    public function testWeakRuleIsOnlyMedium(): void
    {
        $suggestion = $this->suggester()->suggest('AnalyticsPlus', null);

        self::assertNotNull($suggestion);
        self::assertSame('Продвижение и реклама', $suggestion->groupLabel);
        self::assertSame(CategoryGroupRuleConfidence::MEDIUM, $suggestion->confidence);
        self::assertFalse($suggestion->isHigh());
    }

    public function testCaseAndYoAreIgnored(): void
    {
        self::assertSame('Услуги партнёров', $this->suggester()->suggest('X', 'УПАКОВКА ТОВАРА ПАРТНЕРАМИ')?->groupLabel);
    }

    public function testNoMatchGivesNull(): void
    {
        self::assertNull($this->suggester()->suggest('OzonData', 'Данные'));
    }

    public function testBrokenAndInactiveRulesAreSkipped(): void
    {
        $this->em->persist(new CategoryGroupRule(IngestSource::OZON, 1, 'данные(', 'other', CategoryGroupRuleConfidence::HIGH));
        $this->em->persist(new CategoryGroupRule(IngestSource::OZON, 2, 'данные', 'missing_group', CategoryGroupRuleConfidence::HIGH));
        $this->em->persist(new CategoryGroupRule(IngestSource::OZON, 3, 'данные', 'other', CategoryGroupRuleConfidence::HIGH, active: false));
        $this->em->persist(new CategoryGroupRule(IngestSource::OZON, 4, 'эквайринг', 'fbo', CategoryGroupRuleConfidence::HIGH));
        $this->em->flush();

        $suggester = $this->suggester();

        self::assertNull($suggester->suggest('OzonData', 'Данные'));
        self::assertSame('Услуги FBO', $suggester->suggest('Acquiring', 'Эквайринг')?->groupLabel);
    }

    public function testRuleFailingAtRuntimeIsSkippedAndNextRuleStillWorks(): void
    {
        $this->em->persist(new CategoryGroupRule(IngestSource::OZON, 1, '(a+)+$', 'other', CategoryGroupRuleConfidence::HIGH));
        $this->em->flush();
        $suggester = $this->suggester();

        self::assertNull($suggester->suggest(str_repeat('a', 5000).'b', null));
        self::assertSame('Услуги партнёров', $suggester->suggest('Acquiring', 'Эквайринг')?->groupLabel);
    }

    public function testResetPicksUpChangedRules(): void
    {
        $suggester = $this->suggester();
        self::assertNull($suggester->suggest('OzonData', 'Данные'));

        $this->em->persist(new CategoryGroupRule(IngestSource::OZON, 1, 'данные', 'other', CategoryGroupRuleConfidence::HIGH));
        $this->em->flush();

        self::assertNull($suggester->suggest('OzonData', 'Данные'), 'cached until reset');
        $suggester->reset();
        self::assertSame('Другие услуги и штрафы', $suggester->suggest('OzonData', 'Данные')?->groupLabel);
    }

    private function suggester(): OzonAccrualGroupSuggester
    {
        /** @var OzonAccrualGroupSuggester $suggester */
        $suggester = self::getContainer()->get(OzonAccrualGroupSuggester::class);
        $suggester->reset();

        return $suggester;
    }

    /**
     * @return array<int, array{id: int, name: string, description: string}>
     */
    private function fixtureTypes(): array
    {
        /** @var array{accrual_types: list<array{id: int, name: string, description: string}>} $fixture */
        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true, flags: \JSON_THROW_ON_ERROR);

        return array_column($fixture['accrual_types'], null, 'id');
    }
}

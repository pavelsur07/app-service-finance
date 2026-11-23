<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplacePlProjection;

use App\Company\Entity\Company;
use App\Finance\Enum\PLCategoryType;
use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\MarketplacePlProjection\Application\Action\SyncTemplateAction;
use App\MarketplacePlProjection\Domain\MappingTemplate;
use App\MarketplacePlProjection\Domain\Rule;
use App\MarketplacePlProjection\Domain\RuleResolver;
use App\MarketplacePlProjection\Enum\RuleOrigin;
use App\MarketplacePlProjection\Infrastructure\Query\RuleStore;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncTemplateTest extends IntegrationTestCase
{
    private SyncTemplateAction $sync;
    private RuleStore $store;
    private string $companyId;
    /** @var array<string, string> код статьи → id */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sync = self::getContainer()->get(SyncTemplateAction::class);
        $this->store = self::getContainer()->get(RuleStore::class);

        $owner = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->companyId = (string) $company->getId();
        // COGS_DELIVERY намеренно нет; PROMO_INTERNAL — не лист, правило на него не ставится.
        foreach (['REV_NOT_SPP', 'COGS_MP_COMMISSION', 'OPEX_WH_MP_DEDUCTIONS', 'OPEX_WH_PENALTIES'] as $code) {
            $this->categories[$code] = $this->category($company, $code);
        }
        $this->category($company, 'PROMO_INTERNAL', PLCategoryType::SUBTOTAL);
        $this->em->flush();
        $this->em->clear();
    }

    public function testPresetRulesFollowCompanyTreeAndRepeatChangesNothing(): void
    {
        $plan = ($this->sync)($this->companyId, true);

        self::assertGreaterThan(0, $plan->created);
        self::assertContains('COGS_DELIVERY', $plan->missingCodes);
        self::assertContains('PROMO_INTERNAL', $plan->missingCodes);

        $rules = $this->store->rules($this->companyId);
        self::assertCount($plan->created, $rules);
        self::assertSame($this->categories['COGS_MP_COMMISSION'], $this->resolve($rules, OperationKind::COMMISSION, 'ozon')?->plCategoryId);
        self::assertSame($this->categories['OPEX_WH_MP_DEDUCTIONS'], $this->resolve($rules, OperationKind::FINE, 'ozon')?->plCategoryId);
        self::assertSame($this->categories['OPEX_WH_PENALTIES'], $this->resolve($rules, OperationKind::FINE, 'wildberries')?->plCategoryId);
        self::assertNull($this->resolve($rules, OperationKind::LOGISTICS_TO, 'ozon'), 'статьи нет — правила нет, сумма будет «не сопоставлено»');
        $spp = $this->resolve($rules, OperationKind::SPP, 'ozon');
        self::assertNotNull($spp, 'справочный вид — правило «исключено»');
        self::assertNull($spp->plCategoryId);
        self::assertSame(RuleOrigin::PRESET, $spp->origin);

        $again = ($this->sync)($this->companyId, true);
        self::assertSame([0, 0, 0], [$again->created, $again->updated, $again->deleted]);
        self::assertSame(\count($rules), $again->unchanged);
        self::assertCount(\count($rules), $this->store->rules($this->companyId));
    }

    public function testDryRunWritesNothing(): void
    {
        $plan = ($this->sync)($this->companyId, false);

        self::assertGreaterThan(0, $plan->created);
        self::assertSame([], $this->store->rules($this->companyId));
    }

    public function testCustomRuleIsKeptAndStalePresetIsRepairedOrRemoved(): void
    {
        ($this->sync)($this->companyId, true);
        // компания перенесла комиссию; у вида «продажа» preset указывает не туда; preset на ключ вне шаблона
        $this->connection->executeStatement("UPDATE mpp_rule SET origin = 'custom', pl_category_id = ? WHERE company_id = ? AND kind = 'commission'", [$this->categories['REV_NOT_SPP'], $this->companyId]);
        $this->connection->executeStatement("UPDATE mpp_rule SET pl_category_id = ?, template_version = 0 WHERE company_id = ? AND kind = 'sale'", [$this->categories['OPEX_WH_PENALTIES'], $this->companyId]);
        $this->connection->executeStatement("UPDATE mpp_rule SET source_type = 'wb:gone', kind = NULL WHERE company_id = ? AND kind = 'fine' AND channel = 'wildberries'", [$this->companyId]);

        $plan = ($this->sync)($this->companyId, true);

        self::assertSame([1, 1, 1, 1], [$plan->created, $plan->updated, $plan->deleted, $plan->custom]);
        $rules = $this->store->rules($this->companyId);
        self::assertSame($this->categories['REV_NOT_SPP'], $this->resolve($rules, OperationKind::COMMISSION, 'ozon')?->plCategoryId);
        self::assertSame($this->categories['REV_NOT_SPP'], $this->resolve($rules, OperationKind::SALE, 'ozon')?->plCategoryId);
        self::assertSame($this->categories['OPEX_WH_PENALTIES'], $this->resolve($rules, OperationKind::FINE, 'wildberries')?->plCategoryId);
        self::assertSame(MappingTemplate::VERSION, (int) $this->connection->fetchOne("SELECT template_version FROM mpp_rule WHERE company_id = ? AND kind = 'sale'", [$this->companyId]));
    }

    public function testRulesOfAnotherCompanyAreNotTouched(): void
    {
        ($this->sync)($this->companyId, true);
        $owner = UserBuilder::aUser()->withIndex(2)->build();
        $other = CompanyBuilder::aCompany()->withIndex(2)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($other);
        $this->em->flush();
        $before = \count($this->store->rules($this->companyId));

        ($this->sync)((string) $other->getId(), true);

        self::assertCount($before, $this->store->rules($this->companyId));
        // у другой компании дерева нет — только правила «исключено»
        foreach ($this->store->rules((string) $other->getId()) as $rule) {
            self::assertNull($rule->plCategoryId);
        }
    }

    public function testCommandPrintsPlanAndWritesOnlyWithExecute(): void
    {
        $command = (new Application(self::$kernel ?? throw new \LogicException()))->find('app:marketplace-pl-projection:sync-template');
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--company-id' => [$this->companyId]]));
        self::assertStringContainsString('dry-run', $tester->getDisplay());
        self::assertStringContainsString('COGS_DELIVERY', $tester->getDisplay());
        self::assertSame([], $this->store->rules($this->companyId));

        self::assertSame(0, $tester->execute(['--company-id' => [$this->companyId], '--execute' => true]));
        self::assertNotSame([], $this->store->rules($this->companyId));

        self::assertSame(1, $tester->execute(['--company-id' => ['not-a-uuid']]));
        self::assertSame(1, $tester->execute([]));
    }

    public function testAllTakesCompaniesFromCanonAndCanonSumsAreScopedToCompanyAndChannel(): void
    {
        $other = '11111111-1111-1111-1111-000000000971';
        foreach ([[$this->companyId, 'ozon', 'sale', 100], [$this->companyId, 'ozon', 'sale', 50], [$this->companyId, 'wildberries', 'fine', -7], [$other, 'ozon', 'sale', 999]] as [$company, $channel, $kind, $amount]) {
            $this->connection->insert('mo_operation', ['id' => \Ramsey\Uuid\Uuid::uuid7()->toString(), 'company_id' => $company, 'channel' => $channel, 'connection_ref' => 'cab-1',
                'operation_date' => '2026-11-05', 'source_type' => 'code:'.$kind, 'kind' => $kind, 'amount_minor' => $amount, 'currency' => 'RUB', 'source_refs' => '[]',
                'rules_version' => 'test', 'created_at' => '2026-11-14 10:00:00']);
        }
        $operations = self::getContainer()->get(MarketplaceOperationsFacadeInterface::class);
        $day = new \DateTimeImmutable('2026-11-05');

        $sums = $operations->plSums($this->companyId, $day, $day);
        self::assertSame([['ozon', 'sale', 150], ['wildberries', 'fine', -7]], array_map(static fn ($s): array => [$s->channel, $s->kind->value, $s->amountMinor], $sums));
        self::assertCount(1, $operations->plSums($this->companyId, $day, $day, 'wildberries'));
        self::assertSame([], $operations->plSums($this->companyId, $day->modify('+1 day'), $day->modify('+1 day')));
        self::assertContains($this->companyId, $operations->companyIds());

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:marketplace-pl-projection:sync-template'));
        self::assertSame(0, $tester->execute(['--all' => true, '--execute' => true]));
        self::assertNotSame([], $this->store->rules($this->companyId));
        self::assertNotSame([], $this->store->rules($other), 'компания из канона без дерева — только правила «исключено»');
    }

    /** @param list<Rule> $rules */
    private function resolve(array $rules, OperationKind $kind, string $channel): ?Rule
    {
        return RuleResolver::resolve($rules, $kind, $channel, 'no-such-code', '2026-11-10');
    }

    private function category(Company $company, string $code, PLCategoryType $type = PLCategoryType::LEAF_INPUT): string
    {
        $category = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName($code)->withCode($code)->withType($type)->build();
        $this->em->persist($category);

        return (string) $category->getId();
    }
}

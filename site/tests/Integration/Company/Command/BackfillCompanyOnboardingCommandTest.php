<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company\Command;

use App\Balance\Application\SeedBalanceStructureAction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Company\Application\CreateCompanyOwnerAccountAction;
use App\Company\Entity\Company;
use App\Company\Enum\BusinessProfile;
use App\Company\Event\CompanyOnboardingBackfillEvent;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class BackfillCompanyOnboardingCommandTest extends IntegrationTestCase
{
    public function testDryRunReportsGapsAndWritesNothing(): void
    {
        $bare = $this->bareCompany();
        $complete = (self::getContainer()->get(CreateCompanyOwnerAccountAction::class))(
            UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build(),
            'plain-password',
            'Complete Co',
            false,
        );
        $before = $this->counts((string) $bare->getId());

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));

        $display = $tester->getDisplay();
        self::assertStringContainsString((string) $bare->getId().' balance_accounts=2, balance_structure=1, money_accounts=2, pl_categories=70', $display);
        self::assertStringNotContainsString((string) $complete->getId(), $display);
        self::assertSame($before, $this->counts((string) $bare->getId()));
    }

    public function testExecuteRequiresMatchingExpectedCount(): void
    {
        $companyId = (string) $this->bareCompany()->getId();
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--company' => $companyId, '--execute' => true]));
        self::assertStringContainsString('--expected-count', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['--company' => $companyId, '--execute' => true, '--expected-count' => '2']));
        self::assertStringContainsString('ожидалось 2', $tester->getDisplay());

        self::assertSame(['money' => 0, 'pl' => 0, 'balance_articles' => 0, 'balance_accounts' => 0], $this->counts($companyId));
    }

    public function testExecuteFillsGapsOnceAndKeepsOwnData(): void
    {
        $bare = (string) $this->bareCompany()->getId();
        // Компания, которая уже настроила банк, ОПиУ и Баланс сама: недостаёт только кассы.
        $configured = $this->bareCompany();
        $this->em->persist(MoneyAccountBuilder::aMoneyAccount()->withIndex(911)->forCompany($configured)->withType(MoneyAccountType::BANK)->withName('Сбер')->build());
        $this->em->persist(PLCategoryBuilder::aPLCategory()->forCompany($configured)->withName('Выручка')->withCode(null)->build());
        $this->em->flush();
        $configuredId = (string) $configured->getId();
        self::assertTrue((self::getContainer()->get(SeedBalanceStructureAction::class))($configuredId));
        $articleId = (string) $this->connection->fetchOne("SELECT id FROM balance_articles WHERE company_id=? AND code='CASH_BANK'", [$configuredId]);
        $this->connection->insert('balance_accounts', ['id' => Uuid::uuid7()->toString(), 'company_id' => $configuredId, 'article_id' => $articleId, 'code' => 'OWN', 'name' => 'Свой', 'allow_negative' => 0, 'is_archived' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->em->clear();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--company' => $bare, '--execute' => true, '--expected-count' => '1']));
        self::assertSame(Command::SUCCESS, $tester->execute(['--company' => $configuredId, '--execute' => true, '--expected-count' => '1']));
        self::assertStringContainsString('Дозаполнено компаний: 1.', $tester->getDisplay());

        self::assertSame(['money' => 2, 'pl' => 70, 'balance_articles' => 33, 'balance_accounts' => 2], $this->counts($bare));
        self::assertSame(['money' => 2, 'pl' => 1, 'balance_articles' => 33, 'balance_accounts' => 1], $this->counts($configuredId));
        self::assertSame(['bank:Сбер', 'cash:Основная'], $this->connection->fetchFirstColumn("SELECT type || ':' || name FROM money_account WHERE company_id=? ORDER BY type", [$configuredId]));

        foreach ([$bare, $configuredId] as $companyId) {
            self::assertSame(Command::SUCCESS, $tester->execute(['--company' => $companyId]));
            self::assertMatchesRegularExpression('/candidates\s+0/', $tester->getDisplay());
        }
    }

    public function testFailureRollsBackCompanyAndStopsBeforeTheRest(): void
    {
        $failing = (string) $this->bareCompany()->getId();
        $untouched = (string) $this->bareCompany()->getId();
        // Последним слушателем: Cash и Finance уже записали своё в транзакции этой компании.
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            CompanyOnboardingBackfillEvent::class,
            static function (CompanyOnboardingBackfillEvent $event) use ($failing): void {
                if ($event->execute && $event->companyId === $failing) {
                    throw new \RuntimeException('subscriber failed');
                }
            },
            -1000,
        );
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(1, preg_match('/candidates\s+(\d+)/', $tester->getDisplay(), $m));

        self::assertSame(Command::FAILURE, $tester->execute(['--execute' => true, '--expected-count' => $m[1]]));
        self::assertMatchesRegularExpression('/completed\s+0/', $tester->getDisplay());
        self::assertStringContainsString('новый dry-run', $tester->getDisplay());

        $empty = ['money' => 0, 'pl' => 0, 'balance_articles' => 0, 'balance_accounts' => 0];
        self::assertSame($empty, $this->counts($failing));
        self::assertSame($empty, $this->counts($untouched));
    }

    public function testNonNumericExpectedCountIsRejected(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--execute' => true, '--expected-count' => '-1']));
        self::assertStringContainsString('неотрицательное целое', $tester->getDisplay());
    }

    public function testEventCarriesEachCompanyProfile(): void
    {
        $marketplace = (string) $this->bareCompany()->getId();
        $universal = $this->bareCompany();
        $universal->setBusinessProfile(BusinessProfile::UNIVERSAL);
        $this->em->flush();
        $seen = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            CompanyOnboardingBackfillEvent::class,
            static function (CompanyOnboardingBackfillEvent $event) use (&$seen): void {
                $seen[$event->companyId] = $event->businessProfile;
            },
        );

        self::assertSame(Command::SUCCESS, $this->tester()->execute([]));

        self::assertSame(BusinessProfile::MARKETPLACE, $seen[$marketplace] ?? null);
        self::assertSame(BusinessProfile::UNIVERSAL, $seen[(string) $universal->getId()] ?? null);
    }

    public function testUnknownCompanyIsRejected(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--company' => Uuid::uuid7()->toString()]));
        self::assertStringContainsString('не найдена', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        // Ядро уже поднято setUp(): повторный bootKernel() отцепил бы $this->em от контейнера команды.
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('app:company:onboarding-backfill'));
    }

    /** Компания «до онбординга»: создана без CompanyCreatedEvent. */
    private function bareCompany(): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    /**
     * @return array{money: int, pl: int, balance_articles: int, balance_accounts: int}
     */
    private function counts(string $companyId): array
    {
        $count = fn (string $table): int => (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE company_id = ?', $table), [$companyId]);

        return ['money' => $count('money_account'), 'pl' => $count('pl_categories'), 'balance_articles' => $count('balance_articles'), 'balance_accounts' => $count('balance_accounts')];
    }
}

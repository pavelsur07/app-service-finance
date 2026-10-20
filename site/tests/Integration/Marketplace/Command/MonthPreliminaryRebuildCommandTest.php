<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Command;

use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Marketplace\Application\MonthClosePreflightAction;
use App\Marketplace\Application\RebuildPreliminaryForPeriodAction;
use App\Marketplace\Command\MonthPreliminaryRebuildCommand;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\MonthCloseStageStatus;
use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use App\Marketplace\Message\RebuildPreliminaryForPeriodMessage;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MonthPreliminaryRebuildCommandTest extends IntegrationTestCase
{
    public function testCronModeDispatchesCurrentMonthPerConnectionAndSurvivesDispatchFailure(): void
    {
        $a = $this->company(961);
        $b = $this->company(962);
        $this->connection($a, MarketplaceType::OZON);
        $this->connection($a, MarketplaceType::WILDBERRIES);
        $this->connection($b, MarketplaceType::OZON);
        $this->em->flush();

        $bus = new class((string) $b->getId()) implements MessageBusInterface {
            /** @var list<RebuildPreliminaryForPeriodMessage> */
            public array $messages = [];

            public function __construct(private readonly string $failingCompanyId)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                \assert($message instanceof RebuildPreliminaryForPeriodMessage);
                if ($message->companyId === $this->failingCompanyId) {
                    throw new \RuntimeException('queue down');
                }
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ('error' === $level) {
                    $this->errors[] = (string) $message;
                }
            }
        };

        $tester = new CommandTester($this->commandWith($bus, $logger));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $own = array_values(array_filter($bus->messages, static fn (RebuildPreliminaryForPeriodMessage $m): bool => $m->companyId === $a->getId()));
        self::assertSame(['ozon', 'wildberries'], array_map(static fn (RebuildPreliminaryForPeriodMessage $m): string => $m->marketplace, $own));
        self::assertSame((int) date('Y'), $own[0]->year);
        self::assertSame((int) date('n'), $own[0]->month);
        self::assertSame(['[PreliminaryRebuild] Dispatch failed'], $logger->errors);
        self::assertStringContainsString('ошибок: 1', $tester->getDisplay());
    }

    public function testManualPreviewWritesNothingAndExecuteClosesPastMonthStage(): void
    {
        $company = $this->company(963);
        $saleId = $this->seedWbSale($company, '2026-03-10');
        $options = $this->manualOptions($company);

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute($options));
        self::assertStringContainsString('С --execute этап будет закрыт предварительно', $tester->getDisplay());
        self::assertNull($this->monthClose($company), 'предпросмотр ничего не пишет');

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute($options + ['--execute' => true]));
        self::assertStringContainsString('Этап закрыт предварительно', $tester->getDisplay());

        $monthClose = $this->monthClose($company);
        self::assertNotNull($monthClose);
        self::assertSame(MonthCloseStageStatus::CLOSED, $monthClose->getStageStatus(CloseStage::SALES_RETURNS));
        self::assertTrue($monthClose->isStageLastCloseWasPreliminary(CloseStage::SALES_RETURNS));
        self::assertSame(MonthCloseStageStatus::PENDING, $monthClose->getStageStatus(CloseStage::COSTS), 'другой этап не трогается');
        self::assertNotNull($this->connection->fetchOne('SELECT document_id FROM marketplace_sales WHERE id = :id', ['id' => $saleId]));

        // Повторный запуск переоткрывает предварительное закрытие и закрывает заново.
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute($options));
        self::assertStringContainsString('переоткрыт и закрыт заново', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $this->tester()->execute($options + ['--execute' => true]));
    }

    public function testManualModeLeavesFinalCloseAndBlockedPeriodUntouched(): void
    {
        $final = $this->company(964);
        $this->seedWbSale($final, '2026-03-10');
        self::assertSame(Command::SUCCESS, $this->tester()->execute($this->manualOptions($final) + ['--execute' => true]));
        // Финальное закрытие требует отчётов за каждый день месяца, поэтому
        // здесь оно имитируется флагом, которым пересборка их различает.
        $this->connection->executeStatement(
            'UPDATE marketplace_month_closes SET settings = :settings WHERE company_id = :c',
            ['settings' => '{"last_close_was_preliminary":{"sales_returns":false}}', 'c' => $final->getId()],
        );
        $documentIds = $this->monthClose($final)?->getStagePLDocumentIds(CloseStage::SALES_RETURNS);

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute($this->manualOptions($final) + ['--execute' => true]));
        self::assertStringContainsString('закрыт окончательно', $tester->getDisplay());
        self::assertSame($documentIds, $this->monthClose($final)?->getStagePLDocumentIds(CloseStage::SALES_RETURNS));

        $locked = $this->company(965);
        $this->seedWbSale($locked, '2026-03-10');
        $locked = $this->em->find(Company::class, $locked->getId());
        self::assertNotNull($locked);
        $locked->setFinanceLockBefore(new \DateTimeImmutable('2026-04-30'));
        $this->em->flush();

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute($this->manualOptions($locked)));
        self::assertStringContainsString('finance_lock', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $this->tester()->execute($this->manualOptions($locked) + ['--execute' => true]));
        self::assertNotSame(MonthCloseStageStatus::CLOSED, $this->monthClose($locked)?->getStageStatus(CloseStage::SALES_RETURNS));
    }

    public function testRejectsIncompleteOrInvalidManualOptions(): void
    {
        $companyId = Uuid::uuid4()->toString();
        $valid = ['--company-id' => $companyId, '--marketplace' => 'wildberries', '--month' => '2026-03', '--stage' => 'sales_returns'];

        self::assertSame(Command::INVALID, $this->tester()->execute(['--execute' => true]));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--company-id' => $companyId]));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--month' => '2026-13'] + $valid));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--stage' => 'all'] + $valid));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--marketplace' => 'yandex_market'] + $valid));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--month' => (new \DateTimeImmutable('first day of next month'))->format('Y-m')] + $valid));

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute($valid));
        self::assertStringContainsString('не найдена', $tester->getDisplay());
    }

    private function company(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function connection(Company $company, MarketplaceType $marketplace): void
    {
        $connection = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, $marketplace);
        $connection->setApiKey('test-key');
        $this->em->persist($connection);
    }

    private function seedWbSale(Company $company, string $date): string
    {
        $plCategory = new PLCategory(Uuid::uuid4()->toString(), $company);
        $plCategory->setName('Себестоимость продаж WB');
        $plCategory->setFlow(PLFlow::EXPENSE);
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku('rebuild-command-'.$company->getId())
            ->build();
        $mapping = new MarketplaceSaleMapping(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES, AmountSource::SALE_COST_PRICE, $plCategory);
        $sale = MarketplaceSaleBuilder::aSale()
            ->forCompany($company)
            ->forListing($listing)
            ->withSaleDate(new \DateTimeImmutable($date))
            ->withCostPrice('100.00')
            ->build();
        foreach ([$plCategory, $listing, $mapping, $sale] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        return $sale->getId();
    }

    /**
     * @return array<string, string>
     */
    private function manualOptions(Company $company): array
    {
        return ['--company-id' => (string) $company->getId(), '--marketplace' => 'wildberries', '--month' => '2026-03', '--stage' => 'sales_returns'];
    }

    private function monthClose(Company $company): ?MarketplaceMonthClose
    {
        $this->em->clear();
        /** @var MarketplaceMonthCloseRepository $repository */
        $repository = self::getContainer()->get(MarketplaceMonthCloseRepository::class);

        return $repository->findByPeriod((string) $company->getId(), MarketplaceType::WILDBERRIES, 2026, 3);
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('app:marketplace:month-preliminary-rebuild'));
    }

    private function commandWith(MessageBusInterface $bus, AbstractLogger $logger): MonthPreliminaryRebuildCommand
    {
        $container = self::getContainer();

        return new MonthPreliminaryRebuildCommand(
            $container->get(ActiveSellerConnectionsQuery::class),
            $bus,
            $logger,
            $container->get(CompanyFacade::class),
            $container->get(MonthClosePreflightAction::class),
            $container->get(RebuildPreliminaryForPeriodAction::class),
            $container->get(MarketplaceMonthCloseRepository::class),
            $container->get(EntityManagerInterface::class),
        );
    }
}

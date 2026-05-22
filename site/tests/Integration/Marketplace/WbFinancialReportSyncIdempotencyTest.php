<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlanner;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFinanceSalesReportClient;
use App\Marketplace\Infrastructure\Query\ActiveWbConnectionsQuery;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\ProcessRawDocumentStepMessage;
use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use App\Marketplace\MessageHandler\ProcessDayReportHandler;
use App\Marketplace\MessageHandler\ProcessRawDocumentStepMessageHandler;
use App\Marketplace\MessageHandler\SyncWbFinancialReportDayHandler;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class WbFinancialReportSyncIdempotencyTest extends IntegrationTestCase
{
    public function testDailySameDateTwiceCreatesSingleRawAndSingleStatus(): void
    {
        [$company, $connection] = $this->createCompanyAndConnection(301);
        $this->swapWbClient([
            new MockResponse('[{"rrd_id": 1}]', ['http_code' => 200]),
            new MockResponse('[{"rrd_id": 2}]', ['http_code' => 200]),
        ]);
        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);

        $message = new SyncWbFinancialReportDayMessage($company->getId(), $connection->getId(), '2026-05-19', FinancialReportSyncMode::DAILY->value, false);
        $handler($message);
        $handler($message);

        self::assertSame(1, $this->countStatuses($company->getId(), $connection->getId(), '2026-05-19'));
        self::assertSame(1, $this->countRawDocuments($company->getId(), '2026-05-19'));
    }

    public function testInitialSameRangeTwiceDoesNotDuplicateStatusesOrRawDocuments(): void
    {
        [$company, $connection] = $this->createCompanyAndConnection(302);
        $this->swapWbClient([
            new MockResponse('[{"rrd_id": 10}]', ['http_code' => 200]),
            new MockResponse('[{"rrd_id": 10}]', ['http_code' => 200]),
            new MockResponse('[{"rrd_id": 10}]', ['http_code' => 200]),
            new MockResponse('[{"rrd_id": 10}]', ['http_code' => 200]),
            new MockResponse('[{"rrd_id": 10}]', ['http_code' => 200]),
            new MockResponse('[{"rrd_id": 10}]', ['http_code' => 200]),
        ]);

        $plannerBus = new InMemoryMessageBus();
        $planner = $this->planner(new \DateTimeImmutable('2026-05-05 12:00:00 Europe/Moscow'), $plannerBus);
        $planned1 = $planner->planInitial($company->getId(), $connection->getId(), new \DateTimeImmutable('2026-05-02 00:00:00 Europe/Moscow'));
        $planned2 = $planner->planInitial($company->getId(), $connection->getId(), new \DateTimeImmutable('2026-05-02 00:00:00 Europe/Moscow'));
        self::assertSame(3, $planned1);
        self::assertSame(3, $planned2);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        foreach (['2026-05-02', '2026-05-03', '2026-05-04'] as $day) {
            $msg = new SyncWbFinancialReportDayMessage($company->getId(), $connection->getId(), $day, FinancialReportSyncMode::INITIAL->value, false);
            $handler($msg);
            $handler($msg);
        }

        self::assertSame(3, $this->countStatusesInRange($company->getId(), $connection->getId(), '2026-05-02', '2026-05-04'));
        self::assertSame(3, $this->countRawDocumentsInRange($company->getId(), '2026-05-02', '2026-05-04'));
    }

    public function testRefresh14SameDayTwiceDoesNotDuplicateSalesRows(): void
    {
        [$company, $connection] = $this->createCompanyAndConnection(303);
        $this->swapWbClient([
            new MockResponse('[{"doc_type_name":"Продажа","supplier_oper_name":"Продажа","srid":"SR-1","nm_id":"100","quantity":1,"retail_price_withdisc_rub":100,"sale_dt":"2026-05-19 12:00:00","rr_dt":"2026-05-19 12:00:00"}]', ['http_code' => 200]),
            new MockResponse('[{"doc_type_name":"Продажа","supplier_oper_name":"Продажа","srid":"SR-1","nm_id":"100","quantity":1,"retail_price_withdisc_rub":100,"sale_dt":"2026-05-19 12:00:00","rr_dt":"2026-05-19 12:00:00"}]', ['http_code' => 200]),
        ]);

        $bus = $this->swapBusSpy();
        $syncHandler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $processDayHandler = self::getContainer()->get(ProcessDayReportHandler::class);
        $stepHandler = self::getContainer()->get(ProcessRawDocumentStepMessageHandler::class);
        $msg = new SyncWbFinancialReportDayMessage($company->getId(), $connection->getId(), '2026-05-19', FinancialReportSyncMode::REFRESH_14D->value, true);

        $syncHandler($msg);
        $this->runDispatchedPipeline($bus, $processDayHandler, $stepHandler);

        $syncHandler($msg);
        $this->runDispatchedPipeline($bus, $processDayHandler, $stepHandler);

        self::assertSame(1, $this->countRawDocuments($company->getId(), '2026-05-19'));
        self::assertSame(1, $this->countStatuses($company->getId(), $connection->getId(), '2026-05-19'));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_sales WHERE company_id=:c AND marketplace=:m AND external_order_id=:e',
            ['c' => $company->getId(), 'm' => 'wildberries', 'e' => 'SR-1'],
        ));
    }


    public function testRefresh14SameDayTwiceDoesNotDuplicateReturnsRows(): void
    {
        [$company, $connection] = $this->createCompanyAndConnection(307);
        $payload = '[{"doc_type_name":"Возврат","supplier_oper_name":"Возврат покупателем","srid":"RET-1","nm_id":"200","quantity":1,"retail_price_withdisc_rub":123.45,"sale_dt":"2026-05-19 12:00:00","rr_dt":"2026-05-19 12:00:00"}]';
        $this->swapWbClient([
            new MockResponse($payload, ['http_code' => 200]),
            new MockResponse($payload, ['http_code' => 200]),
        ]);

        $this->runRefreshTwiceThroughFullFlow($company->getId(), $connection->getId(), '2026-05-19');

        self::assertSame(1, $this->countRawDocuments($company->getId(), '2026-05-19'));
        self::assertSame(1, $this->countStatuses($company->getId(), $connection->getId(), '2026-05-19'));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_returns WHERE company_id=:c AND marketplace=:m AND external_return_id=:e',
            ['c' => $company->getId(), 'm' => 'wildberries', 'e' => 'RET-1'],
        ));
    }

    public function testRefresh14SameDayTwiceDoesNotDuplicateCostsRows(): void
    {
        [$company, $connection] = $this->createCompanyAndConnection(308);
        $payload = '[{"doc_type_name":"Услуги","supplier_oper_name":"Логистика","srid":"COST-1","sale_dt":"2026-05-19 12:00:00","rrd_id":"5001","delivery_amount":1,"return_amount":0,"delivery_rub":50}]';
        $this->swapWbClient([
            new MockResponse($payload, ['http_code' => 200]),
            new MockResponse($payload, ['http_code' => 200]),
        ]);

        $this->runRefreshTwiceThroughFullFlow($company->getId(), $connection->getId(), '2026-05-19');

        self::assertSame(1, $this->countRawDocuments($company->getId(), '2026-05-19'));
        self::assertSame(1, $this->countStatuses($company->getId(), $connection->getId(), '2026-05-19'));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_costs WHERE company_id=:c AND marketplace=:m AND external_id=:e',
            ['c' => $company->getId(), 'm' => 'wildberries', 'e' => 'wb:5001:logistics_delivery'],
        ));
    }

    public function testEmptyDayMarkedEmptyAndNotPlannedAsMissing(): void
    {
        [$company, $connection] = $this->createCompanyAndConnection(304);
        $this->swapWbClient([new MockResponse('', ['http_code' => 204])]);
        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $handler(new SyncWbFinancialReportDayMessage($company->getId(), $connection->getId(), '2026-05-19', FinancialReportSyncMode::DAILY->value, false));

        $bus = new InMemoryMessageBus();
        $planner = $this->planner(new \DateTimeImmutable('2026-05-21 12:00:00 Europe/Moscow'), $bus);
        $planner->planMissing($company->getId(), $connection->getId(), 10);

        self::assertSame('empty', $this->statusValue($company->getId(), $connection->getId(), '2026-05-19'));
        self::assertNotContains('2026-05-19', array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->businessDate, $bus->messages));
    }

    public function testFailedDayRetryInPastIsPlannedButFailedFinalIsNot(): void
    {
        [$company, $connection] = $this->createCompanyAndConnection(305);
        $repo = self::getContainer()->get(MarketplaceFinancialReportSyncStatusRepository::class);

        $retryable = $this->newStatus($company->getId(), $connection->getId(), '2026-05-18');
        $retryable->markFailedRetryable('X', 'msg', 500, null, new \DateTimeImmutable('2026-05-20 00:00:00'));
        $repo->save($retryable);

        $final = $this->newStatus($company->getId(), $connection->getId(), '2026-05-19');
        $final->markFailedFinal('X', 'msg', 400, null);
        $repo->save($final);
        $this->em->flush();

        $bus = new InMemoryMessageBus();
        $planner = $this->planner(new \DateTimeImmutable('2026-05-21 12:00:00 Europe/Moscow'), $bus);
        self::assertSame(1, $planner->planMissing($company->getId(), $connection->getId(), 1));
        self::assertSame(['2026-05-18'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->businessDate, $bus->messages));
        self::assertNotContains('2026-05-19', array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->businessDate, $bus->messages));
    }

    public function testRawProcessingCompletedMarksStatusSuccess(): void
    {
        [$company, $connection] = $this->createCompanyAndConnection(306);
        $this->swapWbClient([new MockResponse('[{"doc_type_name":"Продажа","supplier_oper_name":"Продажа","srid":"SR-2","nm_id":"101","quantity":1,"retail_price_withdisc_rub":200,"sale_dt":"2026-05-19 12:00:00","rr_dt":"2026-05-19 12:00:00"}]', ['http_code' => 200])]);

        $syncHandler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $stepHandler = self::getContainer()->get(ProcessRawDocumentStepMessageHandler::class);
        $syncHandler(new SyncWbFinancialReportDayMessage($company->getId(), $connection->getId(), '2026-05-19', FinancialReportSyncMode::DAILY->value, false));

        $rawId = (string) $this->connection->fetchOne('SELECT id FROM marketplace_raw_documents WHERE company_id=:c AND period_from=:d', ['c' => $company->getId(), 'd' => '2026-05-19 00:00:00']);
        foreach (['sales', 'returns', 'costs'] as $step) {
            $stepHandler(new ProcessRawDocumentStepMessage($rawId, $step, $company->getId()));
        }

        self::assertSame('success', $this->statusValue($company->getId(), $connection->getId(), '2026-05-19'));
    }

    private function planner(\DateTimeImmutable $now, ?MessageBusInterface $bus = null): WbFinancialReportSyncPlanner
    {
        return new WbFinancialReportSyncPlanner(
            self::getContainer()->get(ActiveWbConnectionsQuery::class),
            new WbFinancialReportPeriodResolver(new MockClock($now)),
            self::getContainer()->get(MarketplaceFinancialReportSyncStatusRepository::class),
            $bus ?? self::getContainer()->get('messenger.default_bus'),
        );
    }

    private function createCompanyAndConnection(int $index): array
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);

        $connection = new MarketplaceConnection(sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $index), $company, MarketplaceType::WILDBERRIES, MarketplaceConnectionType::SELLER);
        $connection->setApiKey('wb-token')->setIsActive(true);
        $this->em->persist($connection);
        $this->em->flush();

        return [$company, $connection];
    }

    private function newStatus(string $companyId, string $connectionId, string $date): MarketplaceFinancialReportSyncStatus
    {
        return new MarketplaceFinancialReportSyncStatus(Uuid::uuid7()->toString(), $companyId, $connectionId, MarketplaceType::WILDBERRIES, 'sales_report', 'endpoint', new \DateTimeImmutable($date));
    }

    private function swapWbClient(array $responses): void
    {
        self::getContainer()->set(WbFinanceSalesReportClient::class, new WbFinanceSalesReportClient(new MockHttpClient($responses), new MockClock('2026-05-21 12:00:00 UTC')));
    }

    private function countStatuses(string $companyId, string $connectionId, string $day): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_financial_report_sync_statuses WHERE company_id=:c AND connection_id=:n AND business_date=:d', ['c'=>$companyId, 'n'=>$connectionId, 'd'=>$day.' 00:00:00']);
    }

    private function countStatusesInRange(string $companyId, string $connectionId, string $from, string $to): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_financial_report_sync_statuses WHERE company_id=:c AND connection_id=:n AND business_date BETWEEN :f AND :t', ['c'=>$companyId, 'n'=>$connectionId, 'f'=>$from.' 00:00:00', 't'=>$to.' 23:59:59']);
    }

    private function countRawDocuments(string $companyId, string $day): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_raw_documents WHERE company_id=:c AND period_from=:d AND marketplace=:m AND document_type=:t', ['c'=>$companyId, 'd'=>$day.' 00:00:00', 'm'=>'wildberries', 't'=>'sales_report']);
    }

    private function countRawDocumentsInRange(string $companyId, string $from, string $to): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_raw_documents WHERE company_id=:c AND period_from BETWEEN :f AND :t AND marketplace=:m AND document_type=:t2', ['c'=>$companyId, 'f'=>$from.' 00:00:00', 't'=>$to.' 23:59:59', 'm'=>'wildberries', 't2'=>'sales_report']);
    }



    private function swapBusSpy(): SpyMessageBus
    {
        $spyBus = new SpyMessageBus();
        self::getContainer()->set(MessageBusInterface::class, $spyBus);

        return $spyBus;
    }

    private function runDispatchedPipeline(SpyMessageBus $bus, ProcessDayReportHandler $dayHandler, ProcessRawDocumentStepMessageHandler $stepHandler): void
    {
        $dayMessages = array_values(array_filter($bus->messages, static fn (object $message): bool => $message instanceof ProcessDayReportMessage));
        $bus->messages = [];

        foreach ($dayMessages as $dayMessage) {
            $dayHandler($dayMessage);
        }

        $stepMessages = array_values(array_filter($bus->messages, static fn (object $message): bool => $message instanceof ProcessRawDocumentStepMessage));
        $bus->messages = [];

        foreach ($stepMessages as $stepMessage) {
            $stepHandler($stepMessage);
        }
    }


    private function runRefreshTwiceThroughFullFlow(string $companyId, string $connectionId, string $businessDate): void
    {
        $bus = $this->swapBusSpy();
        $syncHandler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $processDayHandler = self::getContainer()->get(ProcessDayReportHandler::class);
        $stepHandler = self::getContainer()->get(ProcessRawDocumentStepMessageHandler::class);
        $msg = new SyncWbFinancialReportDayMessage($companyId, $connectionId, $businessDate, FinancialReportSyncMode::REFRESH_14D->value, true);

        $syncHandler($msg);
        $this->runDispatchedPipeline($bus, $processDayHandler, $stepHandler);

        $syncHandler($msg);
        $this->runDispatchedPipeline($bus, $processDayHandler, $stepHandler);
    }

    private function statusValue(string $companyId, string $connectionId, string $date): ?string
    {
        $value = $this->connection->fetchOne('SELECT status FROM marketplace_financial_report_sync_statuses WHERE company_id=:c AND connection_id=:n AND business_date=:d', ['c'=>$companyId, 'n'=>$connectionId, 'd'=>$date.' 00:00:00']);

        return $value === false ? null : (string) $value;
    }
}


final class SpyMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}

final class InMemoryMessageBus implements MessageBusInterface
{
    /** @var list<SyncWbFinancialReportDayMessage> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($message instanceof SyncWbFinancialReportDayMessage) {
            $this->messages[] = $message;
        }

        return new Envelope($message, $stamps);
    }
}

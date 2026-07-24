<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Application;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\LoadWbAdSpendDayAction;
use App\MarketplaceAds\Application\ProcessAdRawDocumentAction;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Exception\WbAdSpendReconciliationException;
use App\MarketplaceAds\Infrastructure\Api\Wildberries\WildberriesAdClient;
use App\MarketplaceAds\Infrastructure\Api\Wildberries\WildberriesAdRawDataParser;
use App\MarketplaceAds\Infrastructure\Query\WbAdSpendReconciliationQuery;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class LoadWbAdSpendDayActionTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const CONNECTION_ID = '22222222-2222-2222-2222-222222222222';

    public function testCreatesProcessesAndSummarizesDailyRawDocument(): void
    {
        $payload = $this->payload();
        $client = $this->createMock(WildberriesAdClient::class);
        $client
            ->expects(self::once())
            ->method('fetchAdStatisticsForConnection')
            ->with(
                self::COMPANY_ID,
                self::CONNECTION_ID,
                self::callback(static fn (\DateTimeImmutable $date): bool => '2026-07-20' === $date->format('Y-m-d')),
            )
            ->willReturn($payload);

        $saved = null;
        $repository = $this->createMock(AdRawDocumentRepository::class);
        $repository
            ->expects(self::once())
            ->method('findBySourceKey')
            ->with(
                self::COMPANY_ID,
                MarketplaceType::WILDBERRIES->value,
                'wb-ad-spend:'.self::CONNECTION_ID.':2026-07-20',
            )
            ->willReturn(null);
        $repository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function ($document) use (&$saved): void {
                $saved = $document;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $processAction = $this->createMock(ProcessAdRawDocumentAction::class);
        $processAction
            ->expects(self::once())
            ->method('__invoke')
            ->willReturnCallback(static function (string $companyId, string $rawDocumentId) use (&$saved): void {
                self::assertSame(self::COMPANY_ID, $companyId);
                self::assertSame($saved->getId(), $rawDocumentId);
                $saved->markAsProcessed();
            });

        $result = (new LoadWbAdSpendDayAction(
            $client,
            new WildberriesAdRawDataParser(),
            $repository,
            $processAction,
            $this->reconciliationQuery('15.25', '10.00', '5.25', '5.25'),
            $entityManager,
            new NullLogger(),
        ))(self::COMPANY_ID, self::CONNECTION_ID, new \DateTimeImmutable('2026-07-20T18:00:00+03:00'));

        self::assertSame('wb-ad-spend:'.self::CONNECTION_ID.':2026-07-20', $saved->getSourceKey());
        self::assertSame(AdRawDocumentStatus::PROCESSED, $result->status);
        self::assertSame(2, $result->campaignCount);
        self::assertSame(1, $result->skuCount);
        self::assertSame('10.00', $result->attributedTotal);
        self::assertSame('5.25', $result->unallocatedTotal);
        self::assertSame('5.25', $result->persistedUnallocatedTotal);
        self::assertSame('15.25', $result->actualTotal);
        self::assertSame('15.25', $result->documentTotal);
        self::assertSame('10.00', $result->lineTotal);
        self::assertSame('5.25', $result->withoutLineTotal);
        self::assertSame('0.00', $result->unmappedTotal);
        self::assertSame(0, $result->unmappedCount);
        self::assertTrue($result->reconciled);
    }

    public function testRerunUpdatesExistingFailedRawAndClearsStaleError(): void
    {
        $payload = $this->payload();
        $existing = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withSourceKey('wb-ad-spend:'.self::CONNECTION_ID.':2026-07-20')
            ->asFailed('stale failure')
            ->build();

        $client = $this->createMock(WildberriesAdClient::class);
        $client->method('fetchAdStatisticsForConnection')->willReturn($payload);

        $repository = $this->createMock(AdRawDocumentRepository::class);
        $repository->method('findBySourceKey')->willReturn($existing);
        $repository->expects(self::never())->method('save');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $processAction = $this->createMock(ProcessAdRawDocumentAction::class);
        $processAction
            ->expects(self::once())
            ->method('__invoke')
            ->willReturnCallback(static function () use ($existing): void {
                self::assertSame(AdRawDocumentStatus::DRAFT, $existing->getStatus());
                self::assertNull($existing->getProcessingError());
                $existing->markAsProcessed();
            });

        $result = (new LoadWbAdSpendDayAction(
            $client,
            new WildberriesAdRawDataParser(),
            $repository,
            $processAction,
            $this->reconciliationQuery('15.25', '10.00', '5.25', '5.25'),
            $entityManager,
            new NullLogger(),
        ))(self::COMPANY_ID, self::CONNECTION_ID, new \DateTimeImmutable('2026-07-20'));

        self::assertSame($payload, $existing->getRawPayload());
        self::assertSame(AdRawDocumentStatus::PROCESSED, $result->status);
    }

    public function testKeepsFetchedRawDraftWhenProjectionFails(): void
    {
        $client = $this->createMock(WildberriesAdClient::class);
        $client->method('fetchAdStatisticsForConnection')->willReturn($this->payload());

        $saved = null;
        $repository = $this->createMock(AdRawDocumentRepository::class);
        $repository->method('findBySourceKey')->willReturn(null);
        $repository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function ($document) use (&$saved): void {
                $saved = $document;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $processAction = $this->createMock(ProcessAdRawDocumentAction::class);
        $processAction
            ->method('__invoke')
            ->willThrowException(new \RuntimeException('projection failed'));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAssociative');
        $reconciliationQuery = new WbAdSpendReconciliationQuery($connection);

        $action = new LoadWbAdSpendDayAction(
            $client,
            new WildberriesAdRawDataParser(),
            $repository,
            $processAction,
            $reconciliationQuery,
            $entityManager,
            new NullLogger(),
        );

        try {
            $action(self::COMPANY_ID, self::CONNECTION_ID, new \DateTimeImmutable('2026-07-20'));
            self::fail('Expected projection failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('projection failed', $exception->getMessage());
            self::assertSame(AdRawDocumentStatus::DRAFT, $saved->getStatus());
            self::assertSame($this->payload(), $saved->getRawPayload());
        }
    }

    public function testEmptySpendCreatesProcessedCoverageWithZeroSummary(): void
    {
        $payload = '{"schema":"wb-ad-daily-spend-v1","expenses":[],"statistics":[]}';
        $client = $this->createMock(WildberriesAdClient::class);
        $client->method('fetchAdStatisticsForConnection')->willReturn($payload);

        $saved = null;
        $repository = $this->createMock(AdRawDocumentRepository::class);
        $repository->method('findBySourceKey')->willReturn(null);
        $repository
            ->method('save')
            ->willReturnCallback(static function ($document) use (&$saved): void {
                $saved = $document;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $processAction = $this->createMock(ProcessAdRawDocumentAction::class);
        $processAction
            ->method('__invoke')
            ->willReturnCallback(static function () use (&$saved): void {
                $saved->markAsProcessed();
            });

        $result = (new LoadWbAdSpendDayAction(
            $client,
            new WildberriesAdRawDataParser(),
            $repository,
            $processAction,
            $this->reconciliationQuery('0.00', '0.00', '0.00', '0.00'),
            $entityManager,
            new NullLogger(),
        ))(self::COMPANY_ID, self::CONNECTION_ID, new \DateTimeImmutable('2026-07-20'));

        self::assertSame(AdRawDocumentStatus::PROCESSED, $result->status);
        self::assertSame(0, $result->campaignCount);
        self::assertSame(0, $result->skuCount);
        self::assertSame('0.00', $result->actualTotal);
        self::assertTrue($result->reconciled);
    }

    public function testMismatchResetsRawToDraftAndThrows(): void
    {
        $client = $this->createMock(WildberriesAdClient::class);
        $client->method('fetchAdStatisticsForConnection')->willReturn($this->payload());

        $saved = null;
        $repository = $this->createMock(AdRawDocumentRepository::class);
        $repository->method('findBySourceKey')->willReturn(null);
        $repository
            ->method('save')
            ->willReturnCallback(static function ($document) use (&$saved): void {
                $saved = $document;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('flush');

        $processAction = $this->createMock(ProcessAdRawDocumentAction::class);
        $processAction
            ->method('__invoke')
            ->willReturnCallback(static function () use (&$saved): void {
                $saved->markAsProcessed();
            });

        $action = new LoadWbAdSpendDayAction(
            $client,
            new WildberriesAdRawDataParser(),
            $repository,
            $processAction,
            $this->reconciliationQuery('15.24', '10.00', '5.24', '5.24'),
            $entityManager,
            new NullLogger(),
        );

        try {
            $action(self::COMPANY_ID, self::CONNECTION_ID, new \DateTimeImmutable('2026-07-20'));
            self::fail('Expected reconciliation failure.');
        } catch (WbAdSpendReconciliationException) {
            self::assertSame(AdRawDocumentStatus::DRAFT, $saved->getStatus());
        }
    }

    public function testMismatchOnAlreadyDraftRawStillLogsAndThrowsReconciliationException(): void
    {
        $client = $this->createMock(WildberriesAdClient::class);
        $client->method('fetchAdStatisticsForConnection')->willReturn($this->payload());

        $saved = null;
        $repository = $this->createMock(AdRawDocumentRepository::class);
        $repository->method('findBySourceKey')->willReturn(null);
        $repository
            ->method('save')
            ->willReturnCallback(static function ($document) use (&$saved): void {
                $saved = $document;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $processAction = $this->createMock(ProcessAdRawDocumentAction::class);
        $processAction->method('__invoke');

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'WB daily ad spend reconciliation failed.',
                self::callback(static fn (array $context): bool => 'wb_ad_spend_reconciliation_failed' === ($context['event'] ?? null)
                    && '5.25' === ($context['sourceUnallocatedTotal'] ?? null)
                    && '5.24' === ($context['persistedUnallocatedTotal'] ?? null)),
            );

        $action = new LoadWbAdSpendDayAction(
            $client,
            new WildberriesAdRawDataParser(),
            $repository,
            $processAction,
            $this->reconciliationQuery('15.25', '10.00', '5.25', '5.24', '0.01', 1),
            $entityManager,
            $logger,
        );

        try {
            $action(self::COMPANY_ID, self::CONNECTION_ID, new \DateTimeImmutable('2026-07-20'));
            self::fail('Expected reconciliation failure.');
        } catch (WbAdSpendReconciliationException) {
            self::assertSame(AdRawDocumentStatus::DRAFT, $saved->getStatus());
        }
    }

    public function testExposesReconciledUnmappedSpend(): void
    {
        $client = $this->createMock(WildberriesAdClient::class);
        $client->method('fetchAdStatisticsForConnection')->willReturn($this->payload());

        $saved = null;
        $repository = $this->createMock(AdRawDocumentRepository::class);
        $repository->method('findBySourceKey')->willReturn(null);
        $repository
            ->method('save')
            ->willReturnCallback(static function ($document) use (&$saved): void {
                $saved = $document;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $processAction = $this->createMock(ProcessAdRawDocumentAction::class);
        $processAction->method('__invoke');

        $result = (new LoadWbAdSpendDayAction(
            $client,
            new WildberriesAdRawDataParser(),
            $repository,
            $processAction,
            $this->reconciliationQuery('15.25', '8.00', '7.25', '5.25', '2.00', 1),
            $entityManager,
            new NullLogger(),
        ))(self::COMPANY_ID, self::CONNECTION_ID, new \DateTimeImmutable('2026-07-20'));

        self::assertSame(AdRawDocumentStatus::DRAFT, $result->status);
        self::assertSame('2.00', $result->unmappedTotal);
        self::assertSame(1, $result->unmappedCount);
        self::assertTrue($result->reconciled);
    }

    private function payload(): string
    {
        return json_encode([
            'schema' => 'wb-ad-daily-spend-v1',
            'expenses' => [
                ['advertId' => '10', 'updSum' => '10.00', 'campName' => 'Attributed'],
                ['advertId' => '20', 'updSum' => '5.25', 'campName' => 'Unallocated'],
            ],
            'statistics' => [[
                'advertId' => '10',
                'days' => [[
                    'apps' => [[
                        'nms' => [[
                            'nmId' => '123456',
                            'sum' => '9.50',
                            'views' => '100',
                            'clicks' => '5',
                        ]],
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }

    private function reconciliationQuery(
        string $documentTotal,
        string $lineTotal,
        string $withoutLineTotal,
        string $unallocatedTotal,
        string $unmappedTotal = '0.00',
        int $unmappedCount = 0,
    ): WbAdSpendReconciliationQuery {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'document_total' => $documentTotal,
            'line_total' => $lineTotal,
            'without_line_total' => $withoutLineTotal,
            'unallocated_total' => $unallocatedTotal,
            'unmapped_total' => $unmappedTotal,
            'unmapped_count' => (string) $unmappedCount,
        ]);

        return new WbAdSpendReconciliationQuery($connection);
    }
}

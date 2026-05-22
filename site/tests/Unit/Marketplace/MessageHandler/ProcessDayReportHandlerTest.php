<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\WbFinancialReportSyncStatusUpdaterInterface;
use App\Marketplace\Application\Service\WbGeneratedRowsSafeReplaceServiceInterface;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\WbGeneratedRowsConflictException;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\MessageHandler\ProcessDayReportHandler;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusLookupInterface;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

final class ProcessDayReportHandlerTest extends TestCase
{
    public function testConflictMarksSyncStatusAndThrowsUnrecoverableWithoutDispatch(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('11111111-1111-4111-8111-111111111111');
        $doc = new MarketplaceRawDocument('22222222-2222-4222-8222-222222222222', $company, MarketplaceType::WILDBERRIES, 'sales_report');
        $doc->setPeriodFrom(new \DateTimeImmutable('2026-05-10'))->setPeriodTo(new \DateTimeImmutable('2026-05-10'));

        $repo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repo->method('find')->willReturn($doc);

        $safe = $this->createMock(WbGeneratedRowsSafeReplaceServiceInterface::class);
        $safe->expects(self::once())->method('cleanupForRawDocument')->willThrowException(new WbGeneratedRowsConflictException('conflict'));

        $status = $this->createMock(MarketplaceFinancialReportSyncStatus::class);
        $statusRepo = $this->createMock(MarketplaceFinancialReportSyncStatusLookupInterface::class);
        $statusRepo->method('findByRawDocumentId')->willReturn($status);

        $updater = $this->createMock(WbFinancialReportSyncStatusUpdaterInterface::class);
        $updater->expects(self::once())->method('markConflict')->with($status, WbGeneratedRowsConflictException::class, 'conflict');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $flushes = 0;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(static function () use (&$flushes): void { $flushes++; });

        $handler = new ProcessDayReportHandler($repo, $bus, $em, new NullLogger(), $safe, $statusRepo, $updater);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        try {
            $handler(new ProcessDayReportMessage((string)$company->getId(), $doc->getId(), true));
        } finally {
            self::assertSame(1, $flushes);
        }
    }

    public function testNonWbOrNonSalesReportSkipsCleanup(): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('11111111-1111-4111-8111-111111111112');
        $doc = new MarketplaceRawDocument('22222222-2222-4222-8222-222222222223', $company, MarketplaceType::OZON, 'sales_report');
        $doc->setPeriodFrom(new \DateTimeImmutable('2026-05-10'))->setPeriodTo(new \DateTimeImmutable('2026-05-10'));

        $repo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repo->method('find')->willReturn($doc);

        $safe = $this->createMock(WbGeneratedRowsSafeReplaceServiceInterface::class);
        $safe->expects(self::never())->method('cleanupForRawDocument');

        $statusRepo = $this->createMock(MarketplaceFinancialReportSyncStatusLookupInterface::class);
        $updater = $this->createMock(WbFinancialReportSyncStatusUpdaterInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(3))->method('dispatch');
        $em = $this->createMock(EntityManagerInterface::class);

        $handler = new ProcessDayReportHandler($repo, $bus, $em, new NullLogger(), $safe, $statusRepo, $updater);
        $handler(new ProcessDayReportMessage((string)$company->getId(), $doc->getId(), true));

        self::assertTrue(true);
    }
}

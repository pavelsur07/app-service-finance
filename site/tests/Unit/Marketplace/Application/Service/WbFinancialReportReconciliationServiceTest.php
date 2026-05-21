<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\WbFinancialReportReconciliationService;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Exception\WbRawDocumentRefreshConflictException;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WbFinancialReportReconciliationServiceTest extends TestCase
{
    public function testCreatesRawDocumentWhenNoExistingDocument(): void
    {
        [$company, $connection] = $this->buildCompanyAndConnection();
        $businessDate = new \DateTimeImmutable('2026-04-17');

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->expects(self::once())
            ->method('findActiveExactPeriodDocuments')
            ->willReturn([]);

        $service = new WbFinancialReportReconciliationService($repository, $this->createMock(LoggerInterface::class));
        $rows = [['id' => 1], ['id' => 2]];

        $document = $service->createOrRefreshRawDocument($company, $connection, $businessDate, $rows, false);

        self::assertSame($rows, $document->getRawData());
        self::assertSame('sales_report', $document->getDocumentType());
        self::assertSame('wildberries::finance-sales-reports-detailed', $document->getApiEndpoint());
        self::assertSame($businessDate, $document->getPeriodFrom());
        self::assertSame($businessDate, $document->getPeriodTo());
        self::assertSame(PipelineStatus::PENDING, $document->getProcessingStatus());
    }

    public function testRefreshesExistingCompletedDocument(): void
    {
        [$company, $connection] = $this->buildCompanyAndConnection();
        $businessDate = new \DateTimeImmutable('2026-04-17');

        $existing = $this->buildRawDocument($company, $businessDate);
        $existing->markCompleted();
        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('findActiveExactPeriodDocuments')->willReturn([$existing]);

        $service = new WbFinancialReportReconciliationService($repository, $this->createMock(LoggerInterface::class));
        $rows = [['fresh' => true]];

        $result = $service->createOrRefreshRawDocument($company, $connection, $businessDate, $rows, false);

        self::assertSame($existing->getId(), $result->getId());
        self::assertSame($rows, $result->getRawData());
        self::assertSame(PipelineStatus::PENDING, $result->getProcessingStatus());
        self::assertStringNotContainsString('Ozon', (string) $result->getSyncNotes());
    }

    public function testSkipsRefreshForInFlightDocumentWhenForceRefreshIsFalse(): void
    {
        [$company, $connection] = $this->buildCompanyAndConnection();
        $businessDate = new \DateTimeImmutable('2026-04-17');

        $existing = $this->buildRawDocument($company, $businessDate);
        $existing->resetProcessingStatus();
        $existing->setRawData([['old' => true]]);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('findActiveExactPeriodDocuments')->willReturn([$existing]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $service = new WbFinancialReportReconciliationService($repository, $logger);

        $result = $service->createOrRefreshRawDocument($company, $connection, $businessDate, [['fresh' => true]], false);

        self::assertSame($existing->getId(), $result->getId());
        self::assertSame([['old' => true]], $result->getRawData());
    }

    public function testThrowsConflictWhenForceRefreshOnInFlightDocument(): void
    {
        [$company, $connection] = $this->buildCompanyAndConnection();
        $businessDate = new \DateTimeImmutable('2026-04-17');

        $existing = $this->buildRawDocument($company, $businessDate);
        $this->forceProcessingStatus($existing, PipelineStatus::RUNNING);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('findActiveExactPeriodDocuments')->willReturn([$existing]);

        $service = new WbFinancialReportReconciliationService($repository, $this->createMock(LoggerInterface::class));

        $this->expectException(WbRawDocumentRefreshConflictException::class);
        $service->createOrRefreshRawDocument($company, $connection, $businessDate, [['fresh' => true]], true);
    }

    public function testLogsWarningWhenMultipleActiveDocumentsFound(): void
    {
        [$company, $connection] = $this->buildCompanyAndConnection();
        $businessDate = new \DateTimeImmutable('2026-04-17');

        $canonical = $this->buildRawDocument($company, $businessDate);
        $duplicate = $this->buildRawDocument($company, $businessDate);
        $canonical->markCompleted();
        $duplicate->markCompleted();

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('findActiveExactPeriodDocuments')->willReturn([$canonical, $duplicate]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = new WbFinancialReportReconciliationService($repository, $logger);
        $service->createOrRefreshRawDocument($company, $connection, $businessDate, [['fresh' => true]], false);
    }

    private function buildCompanyAndConnection(): array
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);

        return [$company, $connection];
    }

    private function buildRawDocument(Company $company, \DateTimeImmutable $day): MarketplaceRawDocument
    {
        return MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withDocumentType('sales_report')
            ->withApiEndpoint('wildberries::finance-sales-reports-detailed')
            ->withPeriod($day, $day)
            ->build();
    }

    private function forceProcessingStatus(MarketplaceRawDocument $doc, PipelineStatus $status): void
    {
        $reflection = new \ReflectionProperty($doc, 'processingStatus');
        $reflection->setAccessible(true);
        $reflection->setValue($doc, $status);
    }
}

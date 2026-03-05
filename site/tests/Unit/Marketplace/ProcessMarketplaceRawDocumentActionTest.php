<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Company\Entity\Company;
use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Facade\MarketplaceSyncFacade;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use App\Marketplace\Service\MarketplaceSyncService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProcessMarketplaceRawDocumentActionTest extends TestCase
{
    public function testSalesRouting(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $rawDocId = '22222222-2222-2222-2222-222222222222';
        [$company, $rawDoc, $syncFacade, $syncService] = $this->buildFacadeContext($companyId, $rawDocId);

        $syncService
            ->expects(self::once())
            ->method('processSalesFromRaw')
            ->with($company, $rawDoc)
            ->willReturn(10);

        $action = new ProcessMarketplaceRawDocumentAction($syncFacade);
        $result = $action(new ProcessMarketplaceRawDocumentCommand($companyId, $rawDocId, 'sales'));

        self::assertSame(10, $result);
    }

    public function testReturnsRouting(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $rawDocId = '33333333-3333-3333-3333-333333333333';
        [$company, $rawDoc, $syncFacade, $syncService] = $this->buildFacadeContext($companyId, $rawDocId);

        $syncService
            ->expects(self::once())
            ->method('processReturnsFromRaw')
            ->with($company, $rawDoc)
            ->willReturn(7);

        $action = new ProcessMarketplaceRawDocumentAction($syncFacade);
        $result = $action(new ProcessMarketplaceRawDocumentCommand($companyId, $rawDocId, 'returns'));

        self::assertSame(7, $result);
    }

    public function testCostsRouting(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $rawDocId = '44444444-4444-4444-4444-444444444444';
        [$company, $rawDoc, $syncFacade, $syncService] = $this->buildFacadeContext($companyId, $rawDocId);

        $syncService
            ->expects(self::once())
            ->method('processCostsFromRaw')
            ->with($company, $rawDoc)
            ->willReturn(3);

        $action = new ProcessMarketplaceRawDocumentAction($syncFacade);
        $result = $action(new ProcessMarketplaceRawDocumentCommand($companyId, $rawDocId, 'costs'));

        self::assertSame(3, $result);
    }

    public function testUnsupportedKindThrowsException(): void
    {
        $companyId = '11111111-1111-1111-1111-111111111111';
        $rawDocId = '55555555-5555-5555-5555-555555555555';
        [, , $syncFacade] = $this->buildFacadeContext($companyId, $rawDocId);

        $action = new ProcessMarketplaceRawDocumentAction($syncFacade);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported kind: unknown');

        $action(new ProcessMarketplaceRawDocumentCommand($companyId, $rawDocId, 'unknown'));
    }

    /**
     * @return array{0: Company, 1: MarketplaceRawDocument, 2: MarketplaceSyncFacade, 3: MarketplaceSyncService}
     */
    private function buildFacadeContext(string $companyId, string $rawDocId): array
    {
        $company = $this->createMock(Company::class);
        $rawDoc = $this->createMock(MarketplaceRawDocument::class);

        $company->method('getId')->willReturn($companyId);
        $rawDoc->method('getCompany')->willReturn($company);
        $rawDoc->method('getId')->willReturn($rawDocId);

        $em = $this->createMock(EntityManagerInterface::class);
        $syncService = $this->createMock(MarketplaceSyncService::class);

        $em
            ->method('find')
            ->willReturnCallback(static function (string $entityClass, string $id) use ($companyId, $rawDocId, $company, $rawDoc) {
                if ($entityClass === Company::class && $id === $companyId) {
                    return $company;
                }

                if ($entityClass === MarketplaceRawDocument::class && $id === $rawDocId) {
                    return $rawDoc;
                }

                return null;
            });

        $facade = new MarketplaceSyncFacade($em, $syncService, new MarketplaceAdapterRegistry([]));

        return [$company, $rawDoc, $facade, $syncService];
    }
}

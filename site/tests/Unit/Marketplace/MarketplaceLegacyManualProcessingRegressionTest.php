<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\Command\StartMarketplaceRawProcessingCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Application\ReprocessMarketplacePeriodAction;
use App\Marketplace\Application\StartMarketplaceRawProcessingAction;
use App\Marketplace\Domain\Service\ResolveMarketplaceRawProcessingProfile;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Enum\PipelineTrigger;
use PHPUnit\Framework\TestCase;

/**
 * Regression: проверяет, что:
 *   1. Ручной reprocess-flow (ReprocessMarketplacePeriodAction) не затронут daily pipeline.
 *   2. Daily pipeline (StartMarketplaceRawProcessingAction) явно отклоняет realization.
 *   3. ProfileResolver возвращает ожидаемые профили для каждого типа документа.
 */
final class MarketplaceLegacyManualProcessingRegressionTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const RAW_DOC_ID = '22222222-2222-2222-2222-222222222222';

    // -------------------------------------------------------------------------
    // Profile resolver: контракт не изменился
    // -------------------------------------------------------------------------

    public function testSalesReportProfileHasSalesReturnsAndCostsSteps(): void
    {
        $resolver = new ResolveMarketplaceRawProcessingProfile();
        $profile  = $resolver->resolve(MarketplaceType::WILDBERRIES, 'sales_report');

        self::assertTrue($profile->isDailyPipeline);
        self::assertCount(3, $profile->requiredSteps);
        self::assertContains(PipelineStep::SALES, $profile->requiredSteps);
        self::assertContains(PipelineStep::RETURNS, $profile->requiredSteps);
        self::assertContains(PipelineStep::COSTS, $profile->requiredSteps);
    }

    public function testRealizationProfileIsExcludedFromDailyPipeline(): void
    {
        $resolver = new ResolveMarketplaceRawProcessingProfile();
        $profile  = $resolver->resolve(MarketplaceType::OZON, 'realization');

        self::assertFalse($profile->isDailyPipeline);
        self::assertEmpty($profile->requiredSteps);
        self::assertNotEmpty($profile->skipReason);
    }

    // -------------------------------------------------------------------------
    // Daily pipeline must reject realization documents
    // -------------------------------------------------------------------------

    public function testDailyPipelineRejectsRealizationDocument(): void
    {
        $company = $this->createMock(\App\Company\Entity\Company::class);
        $company->method('getId')->willReturn(self::COMPANY_ID);

        $doc = $this->createMock(\App\Marketplace\Entity\MarketplaceRawDocument::class);
        $doc->method('getCompany')->willReturn($company);
        $doc->method('getDocumentType')->willReturn('realization');
        $doc->method('getMarketplace')->willReturn(MarketplaceType::OZON);
        $doc->method('getId')->willReturn(self::RAW_DOC_ID);

        $docRepo = $this->createMock(\App\Marketplace\Repository\MarketplaceRawDocumentRepository::class);
        $docRepo->method('find')->willReturn($doc);

        $em  = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $action = new StartMarketplaceRawProcessingAction(
            $docRepo,
            new ResolveMarketplaceRawProcessingProfile(),
            $em,
            $bus,
        );

        $this->expectException(\DomainException::class);

        $action(new StartMarketplaceRawProcessingCommand(
            self::COMPANY_ID,
            self::RAW_DOC_ID,
            PipelineTrigger::MANUAL,
        ));
    }

    // -------------------------------------------------------------------------
    // Legacy ReprocessMarketplacePeriodAction: uses ProcessMarketplaceRawDocumentAction
    // NOT StartMarketplaceRawProcessingAction — these are separate flows
    // -------------------------------------------------------------------------

    public function testLegacyReprocessActionDoesNotDependOnDailyPipeline(): void
    {
        // ReprocessMarketplacePeriodAction accepts ProcessMarketplaceRawDocumentAction (legacy).
        // It must not depend on StartMarketplaceRawProcessingAction.
        $reprocessRef = new \ReflectionClass(ReprocessMarketplacePeriodAction::class);
        $constructor  = $reprocessRef->getConstructor();
        $paramTypes   = array_map(
            fn(\ReflectionParameter $p) => (string) $p->getType(),
            $constructor->getParameters(),
        );

        self::assertContains(ProcessMarketplaceRawDocumentAction::class, $paramTypes,
            'Legacy reprocess action must use ProcessMarketplaceRawDocumentAction');

        self::assertNotContains(StartMarketplaceRawProcessingAction::class, $paramTypes,
            'Legacy reprocess action must NOT depend on the daily pipeline start action');
    }

    public function testLegacyReprocessSalesReportCallsThreeStepCommands(): void
    {
        $company = $this->createMock(\App\Company\Entity\Company::class);
        $company->method('getId')->willReturn(self::COMPANY_ID);

        $doc = $this->createMock(\App\Marketplace\Entity\MarketplaceRawDocument::class);
        $doc->method('getId')->willReturn(self::RAW_DOC_ID);
        $doc->method('getDocumentType')->willReturn('sales_report');
        $doc->method('getMarketplace')->willReturn(MarketplaceType::WILDBERRIES);

        $docRepo = $this->createMock(\App\Marketplace\Repository\MarketplaceRawDocumentRepository::class);
        $docRepo->method('findByCompanyAndPeriod')->willReturn([$doc]);

        $kinds = [];
        $processAction = $this->createMock(ProcessMarketplaceRawDocumentAction::class);
        $processAction->method('__invoke')
            ->willReturnCallback(function (ProcessMarketplaceRawDocumentCommand $cmd) use (&$kinds): int {
                $kinds[] = $cmd->kind;
                return 1;
            });

        $realizationAction = $this->createMock(\App\Marketplace\Application\ProcessOzonRealizationAction::class);
        $realizationAction->expects($this->never())->method('__invoke');

        $reprocess = new ReprocessMarketplacePeriodAction(
            $docRepo,
            $processAction,
            $realizationAction,
            new \Psr\Log\NullLogger(),
        );

        $stats = $reprocess(
            self::COMPANY_ID,
            'wildberries',
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-01-31'),
            'sales_report',
        );

        self::assertSame(['sales', 'returns', 'costs'], $kinds);
        self::assertSame(1, $stats['docs']);
        self::assertSame(1, $stats['sales']);
        self::assertSame(1, $stats['returns']);
        self::assertSame(1, $stats['costs']);
        self::assertSame(0, $stats['realization']);
    }

    public function testLegacyReprocessRealizationCallsRealizationActionNotPipelineSteps(): void
    {
        $doc = $this->createMock(\App\Marketplace\Entity\MarketplaceRawDocument::class);
        $doc->method('getId')->willReturn(self::RAW_DOC_ID);
        $doc->method('getDocumentType')->willReturn('realization');
        $doc->method('getMarketplace')->willReturn(MarketplaceType::OZON);

        $docRepo = $this->createMock(\App\Marketplace\Repository\MarketplaceRawDocumentRepository::class);
        $docRepo->method('findByCompanyAndPeriod')->willReturn([$doc]);

        $processAction = $this->createMock(ProcessMarketplaceRawDocumentAction::class);
        $processAction->expects($this->never())->method('__invoke');

        $realizationAction = $this->createMock(\App\Marketplace\Application\ProcessOzonRealizationAction::class);
        $realizationAction->method('__invoke')
            ->with(self::COMPANY_ID, self::RAW_DOC_ID)
            ->willReturn(['created' => 3, 'updated' => 1]);

        $reprocess = new ReprocessMarketplacePeriodAction(
            $docRepo,
            $processAction,
            $realizationAction,
            new \Psr\Log\NullLogger(),
        );

        $stats = $reprocess(
            self::COMPANY_ID,
            'ozon',
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-01-31'),
            'realization',
        );

        self::assertSame(1, $stats['docs']);
        self::assertSame(4, $stats['realization']); // 3 created + 1 updated
        self::assertSame(0, $stats['sales']);
    }
}

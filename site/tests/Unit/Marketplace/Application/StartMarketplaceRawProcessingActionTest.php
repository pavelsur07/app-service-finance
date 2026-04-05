<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Command\StartMarketplaceRawProcessingCommand;
use App\Marketplace\Application\StartMarketplaceRawProcessingAction;
use App\Marketplace\Domain\Service\ResolveMarketplaceRawProcessingProfile;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineTrigger;
use App\Marketplace\Message\StartMarketplaceRawProcessingMessage;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class StartMarketplaceRawProcessingActionTest extends TestCase
{
    private const COMPANY_ID  = '11111111-1111-1111-1111-111111111111';
    private const RAW_DOC_ID  = '22222222-2222-2222-2222-222222222222';

    public function testHappyPathCreateRunAndStepsAndDispatches(): void
    {
        $doc = $this->makeDoc(self::COMPANY_ID, 'sales_report', MarketplaceType::WILDBERRIES);

        $docRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $docRepo->method('find')->with(self::RAW_DOC_ID)->willReturn($doc);

        $em = $this->createMock(EntityManagerInterface::class);
        $persistedEntities = [];
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persistedEntities) {
            $persistedEntities[] = $entity;
        });
        $em->expects($this->once())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StartMarketplaceRawProcessingMessage::class))
            ->willReturn(new Envelope(new StartMarketplaceRawProcessingMessage(self::COMPANY_ID, 'run-id')));

        $action = new StartMarketplaceRawProcessingAction(
            $docRepo,
            new ResolveMarketplaceRawProcessingProfile(),
            $em,
            $bus,
        );

        $runId = $action(new StartMarketplaceRawProcessingCommand(
            self::COMPANY_ID,
            self::RAW_DOC_ID,
            PipelineTrigger::AUTO,
        ));

        self::assertNotEmpty($runId);
        // 1 run + 3 step runs (SALES, RETURNS, COSTS)
        self::assertCount(4, $persistedEntities);
    }

    public function testThrowsWhenDocumentNotFound(): void
    {
        $docRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $docRepo->method('find')->willReturn(null);

        $action = new StartMarketplaceRawProcessingAction(
            $docRepo,
            new ResolveMarketplaceRawProcessingProfile(),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MessageBusInterface::class),
        );

        $this->expectException(\DomainException::class);

        $action(new StartMarketplaceRawProcessingCommand(self::COMPANY_ID, self::RAW_DOC_ID, PipelineTrigger::MANUAL));
    }

    public function testThrowsWhenDocumentBelongsToDifferentCompany(): void
    {
        $doc = $this->makeDoc('99999999-9999-9999-9999-999999999999', 'sales_report', MarketplaceType::WILDBERRIES);

        $docRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $docRepo->method('find')->willReturn($doc);

        $action = new StartMarketplaceRawProcessingAction(
            $docRepo,
            new ResolveMarketplaceRawProcessingProfile(),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MessageBusInterface::class),
        );

        $this->expectException(\DomainException::class);

        $action(new StartMarketplaceRawProcessingCommand(self::COMPANY_ID, self::RAW_DOC_ID, PipelineTrigger::MANUAL));
    }

    public function testThrowsForRealizationDocument(): void
    {
        $doc = $this->makeDoc(self::COMPANY_ID, 'realization', MarketplaceType::OZON);

        $docRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $docRepo->method('find')->willReturn($doc);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $action = new StartMarketplaceRawProcessingAction(
            $docRepo,
            new ResolveMarketplaceRawProcessingProfile(),
            $em,
            $bus,
        );

        $this->expectException(\DomainException::class);

        $action(new StartMarketplaceRawProcessingCommand(self::COMPANY_ID, self::RAW_DOC_ID, PipelineTrigger::MANUAL));
    }

    private function makeDoc(string $companyId, string $documentType, MarketplaceType $marketplace): MarketplaceRawDocument
    {
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn($companyId);

        $doc = $this->createMock(MarketplaceRawDocument::class);
        $doc->method('getId')->willReturn(self::RAW_DOC_ID);
        $doc->method('getCompany')->willReturn($company);
        $doc->method('getDocumentType')->willReturn($documentType);
        $doc->method('getMarketplace')->willReturn($marketplace);

        return $doc;
    }
}

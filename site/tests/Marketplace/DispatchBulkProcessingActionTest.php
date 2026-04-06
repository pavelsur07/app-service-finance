<?php

declare(strict_types=1);

namespace App\Tests\Marketplace;

use App\Marketplace\Application\DispatchBulkProcessingAction;
use App\Marketplace\Application\DTO\BulkProcessMonthCommand;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Message\ProcessRawDocumentStepMessage;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Component\Messenger\Transport\InMemoryTransport;

final class DispatchBulkProcessingActionTest extends IntegrationTestCase
{
    private DispatchBulkProcessingAction $action;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action    = self::getContainer()->get(DispatchBulkProcessingAction::class);
        $this->transport = self::getContainer()->get('messenger.transport.async');
        $this->transport->reset();
    }

    /**
     * Happy-path: один sales_report-документ за месяц → 3 сообщения (sales/returns/costs),
     * статус документа сброшен в PENDING.
     */
    public function testDispatchesThreeStepMessagesForSalesReportDocument(): void
    {
        $user    = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $doc     = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('sales_report')
            ->withPeriod(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'))
            ->build();

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($doc);
        $this->em->flush();

        $companyId = $company->getId();
        $docId     = $doc->getId();

        $count = ($this->action)(new BulkProcessMonthCommand(
            companyId:   $companyId,
            marketplace: MarketplaceType::OZON,
            year:        2026,
            month:       1,
        ));

        // Действие вернуло 1 документ
        self::assertSame(1, $count);

        // Задиспатчено ровно 3 сообщения
        $envelopes = $this->transport->get();
        self::assertCount(3, $envelopes);

        // Каждое сообщение — ProcessRawDocumentStepMessage с правильными ID
        $steps = [];
        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(ProcessRawDocumentStepMessage::class, $message);
            self::assertSame($docId, $message->rawDocumentId);
            self::assertSame($companyId, $message->companyId);
            $steps[] = $message->step;
        }

        // Три шага: sales, returns, costs
        sort($steps);
        self::assertSame(['costs', 'returns', 'sales'], $steps);

        // Документ сброшен в PENDING (resetProcessingStatus)
        $this->em->clear();
        $reloaded = $this->em->find(MarketplaceRawDocument::class, $docId);
        self::assertNotNull($reloaded);
        self::assertSame(PipelineStatus::PENDING, $reloaded->getProcessingStatus());
    }

    /**
     * Фильтрация: документ с другим marketplace не попадает в выборку → 0 сообщений.
     */
    public function testReturnsZeroWhenMarketplaceDoesNotMatch(): void
    {
        $user    = UserBuilder::aUser()->withIndex(2)->build();
        $company = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();
        $doc     = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withDocumentType('sales_report')
            ->withPeriod(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'))
            ->build();

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($doc);
        $this->em->flush();

        $count = ($this->action)(new BulkProcessMonthCommand(
            companyId:   $company->getId(),
            marketplace: MarketplaceType::OZON,
            year:        2026,
            month:       1,
        ));

        self::assertSame(0, $count);
        self::assertCount(0, $this->transport->get());
    }
}

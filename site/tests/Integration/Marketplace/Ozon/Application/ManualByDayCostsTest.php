<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Application;

use App\Company\Entity\User;
use App\Finance\Entity\Document;
use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceSyncFacade;
use App\Marketplace\Message\ProcessRawDocumentStepMessage;
use App\Marketplace\MessageHandler\ProcessRawDocumentStepMessageHandler;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ManualByDayCostsTest extends IntegrationTestCase
{
    private const DAY = '2026-09-10';
    private MarketplaceRawDocument $doc;
    private User $owner;
    private string $companyId;
    private MarketplaceSyncFacade $facade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($this->owner)->build();
        $day = new \DateTimeImmutable(self::DAY);
        $this->doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)->withDocumentType('accrual_by_day')
            ->withPeriod($day, $day)->withApiEndpoint(MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value)->build();
        $this->doc->setRawData([
            'accruals' => [[
                'accrual_id' => 1005, 'date' => self::DAY, 'accrued_category' => 'NON_ITEM',
                'non_item_fee' => ['type_id' => 52, 'accrued' => ['amount' => '-249.90']],
            ]],
            'service_types' => [52 => 'PremiumSubscription'],
        ]);
        $this->em->persist($this->owner);
        $this->em->persist($company);
        $this->em->persist($this->doc);
        $this->em->flush();
        $this->companyId = (string) $company->getId();
        $this->facade = self::getContainer()->get(MarketplaceSyncFacade::class);

        self::getContainer()->get(ProcessRawDocumentStepMessageHandler::class)(
            new ProcessRawDocumentStepMessage($this->doc->getId(), 'costs', $this->companyId),
        );
    }

    public function testManualReprocessingRestoresTheSameCostsAsMessenger(): void
    {
        $expected = $this->costRows();
        self::assertSame([[
            'external_id' => 'ozon-accrual-1005-non-item-type-52', 'code' => 'ozon_premium_promotion',
            'amount' => '249.90', 'operation_type' => 'charge',
        ]], $expected);
        $this->changeStoredAmount();

        $this->facade->processCostsFromRaw($this->companyId, $this->doc->getId());
        self::assertSame($expected, $this->costRows());
        $this->facade->processCostsFromRaw($this->companyId, $this->doc->getId());
        self::assertSame($expected, $this->costRows());
    }

    public function testFinalClosePreservesCosts(): void
    {
        $plDocument = new Document(Uuid::uuid7()->toString(), $this->doc->getCompany());
        $this->em->persist($plDocument);
        $close = new MarketplaceMonthClose(Uuid::uuid7()->toString(), $this->companyId, MarketplaceType::OZON, 2026, 9);
        $close->closeStage(CloseStage::COSTS, (string) $this->owner->getId(), [$plDocument->getId()], []);
        $this->em->persist($close);
        $this->em->flush();

        $filter = ['raw_document_id' => $this->doc->getId()];
        self::assertSame(1, $this->connection->update('marketplace_costs', ['document_id' => $plDocument->getId()], $filter));

        $this->assertManualReprocessingSkipsCosts();
        self::assertSame($plDocument->getId(), $this->connection->fetchOne('SELECT document_id FROM marketplace_costs WHERE raw_document_id = :raw_document_id', $filter));
    }

    public function testFinanceLockPreservesCosts(): void
    {
        $this->doc->getCompany()->setFinanceLockBefore(new \DateTimeImmutable(self::DAY));
        $this->em->flush();

        $this->assertManualReprocessingSkipsCosts();
    }

    public function testForeignCompanyCannotChangeCosts(): void
    {
        $before = $this->costRows();
        self::assertCount(1, $before);
        $foreignCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($this->owner)->build();
        $this->em->persist($foreignCompany);
        $this->em->flush();
        try {
            $this->facade->processCostsFromRaw((string) $foreignCompany->getId(), $this->doc->getId());
            self::fail('Expected foreign document to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Raw document not found', $e->getMessage());
        }
        self::assertSame($before, $this->costRows());
    }

    public function testUnknownEndpointPreservesCosts(): void
    {
        $before = $this->costRows();
        self::assertCount(1, $before);
        $this->doc->setApiEndpoint('ozon::unknown');
        $this->em->flush();
        try {
            $this->facade->processCostsFromRaw($this->companyId, $this->doc->getId());
            self::fail('Expected unknown endpoint to be rejected.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertStringContainsString('ozon::unknown', $e->getMessage());
        }
        self::assertSame($before, $this->costRows());
    }

    private function assertManualReprocessingSkipsCosts(): void
    {
        $this->changeStoredAmount();
        $before = $this->costRows();
        self::assertSame(0, $this->facade->processCostsFromRaw($this->companyId, $this->doc->getId()));
        self::assertSame($before, $this->costRows());
    }

    private function changeStoredAmount(): void
    {
        self::assertSame(1, $this->connection->update('marketplace_costs', ['amount' => '17.00'], ['raw_document_id' => $this->doc->getId()]));
    }

    /** @return list<array<string, mixed>> */
    private function costRows(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT c.external_id, cat.code, c.amount, c.operation_type
             FROM marketplace_costs c JOIN marketplace_cost_categories cat ON cat.id = c.category_id
             WHERE c.company_id = :company AND c.raw_document_id = :doc ORDER BY c.external_id',
            ['company' => $this->companyId, 'doc' => $this->doc->getId()],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Query;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Entity\AdDocument;
use App\MarketplaceAds\Entity\AdDocumentLine;
use App\MarketplaceAds\Infrastructure\Query\WbAdSpendReconciliationQuery;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class WbAdSpendReconciliationQueryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000002';
    private const RAW_DOCUMENT_ID = '22222222-2222-2222-2222-000000000001';
    private const OTHER_RAW_DOCUMENT_ID = '22222222-2222-2222-2222-000000000002';

    private WbAdSpendReconciliationQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = new WbAdSpendReconciliationQuery($this->connection);
    }

    public function testReturnsExactPersistedTotalsAndExcludesAnotherCompany(): void
    {
        $mapped = $this->document(self::COMPANY_ID, 'campaign-1', '123456', '10.00');
        $this->em->persist(new AdDocumentLine(
            $mapped,
            '33333333-3333-3333-3333-000000000001',
            '60.0000',
            '6.00',
            60,
            6,
        ));
        $this->em->persist(new AdDocumentLine(
            $mapped,
            '33333333-3333-3333-3333-000000000002',
            '40.0000',
            '4.00',
            40,
            4,
        ));
        $this->document(
            self::COMPANY_ID,
            'campaign-2',
            AdRawEntry::UNALLOCATED_PARENT_SKU,
            '5.25',
        );
        $this->document(self::COMPANY_ID, 'campaign-3', '999999', '-1.20');
        $this->document(self::OTHER_COMPANY_ID, 'campaign-other', '999999', '999.00');
        $this->document(
            self::COMPANY_ID,
            'campaign-other-raw',
            '888888',
            '777.00',
            self::OTHER_RAW_DOCUMENT_ID,
        );
        $this->em->flush();

        $result = $this->query->get(self::COMPANY_ID, self::RAW_DOCUMENT_ID);

        self::assertSame('14.05', $result->documentTotal->toDecimalString());
        self::assertSame('10.00', $result->lineTotal->toDecimalString());
        self::assertSame('4.05', $result->withoutLineTotal->toDecimalString());
        self::assertSame('5.25', $result->unallocatedTotal->toDecimalString());
        self::assertSame('-1.20', $result->unmappedTotal->toDecimalString());
        self::assertSame(1, $result->unmappedCount);
        self::assertTrue($result->reconciles(
            Money::fromString('14.05', 'RUB'),
            Money::fromString('5.25', 'RUB'),
        ));
    }

    public function testEmptyProjectionReturnsReconciledZeros(): void
    {
        $result = $this->query->get(self::COMPANY_ID, self::RAW_DOCUMENT_ID);

        self::assertSame('0.00', $result->documentTotal->toDecimalString());
        self::assertSame('0.00', $result->lineTotal->toDecimalString());
        self::assertSame('0.00', $result->withoutLineTotal->toDecimalString());
        self::assertSame('0.00', $result->unallocatedTotal->toDecimalString());
        self::assertSame('0.00', $result->unmappedTotal->toDecimalString());
        self::assertSame(0, $result->unmappedCount);
        self::assertTrue($result->reconciles(
            Money::fromString('0.00', 'RUB'),
            Money::fromString('0.00', 'RUB'),
        ));
    }

    public function testDetectsDocumentLineMismatch(): void
    {
        $document = $this->document(self::COMPANY_ID, 'campaign-1', '123456', '10.00');
        $this->em->persist(new AdDocumentLine(
            $document,
            '33333333-3333-3333-3333-000000000001',
            '100.0000',
            '9.99',
            100,
            10,
        ));
        $this->em->flush();

        $result = $this->query->get(self::COMPANY_ID, self::RAW_DOCUMENT_ID);

        self::assertFalse($result->reconciles(
            Money::fromString('10.00', 'RUB'),
            Money::fromString('0.00', 'RUB'),
        ));
    }

    public function testUnallocatedDocumentWithLineFailsClosed(): void
    {
        $document = $this->document(
            self::COMPANY_ID,
            'campaign-unallocated',
            AdRawEntry::UNALLOCATED_PARENT_SKU,
            '5.25',
        );
        $this->em->persist(new AdDocumentLine(
            $document,
            '33333333-3333-3333-3333-000000000001',
            '100.0000',
            '5.25',
            100,
            10,
        ));
        $this->em->flush();

        $result = $this->query->get(self::COMPANY_ID, self::RAW_DOCUMENT_ID);

        self::assertFalse($result->reconciles(
            Money::fromString('5.25', 'RUB'),
            Money::fromString('5.25', 'RUB'),
        ));
    }

    private function document(
        string $companyId,
        string $campaignId,
        string $parentSku,
        string $totalCost,
        string $rawDocumentId = self::RAW_DOCUMENT_ID,
    ): AdDocument {
        $document = new AdDocument(
            companyId: $companyId,
            marketplace: MarketplaceType::WILDBERRIES,
            reportDate: new \DateTimeImmutable('2026-07-23'),
            campaignId: $campaignId,
            campaignName: $campaignId,
            parentSku: $parentSku,
            totalCost: $totalCost,
            totalImpressions: 100,
            totalClicks: 10,
            adRawDocumentId: $rawDocumentId,
        );
        $this->em->persist($document);

        return $document;
    }
}

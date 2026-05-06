<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Infrastructure\Query;

use App\Company\Entity\Company;
use App\Finance\Entity\Document;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\OzonRawDuplicateAuditQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class OzonRawDuplicateAuditQueryTest extends IntegrationTestCase
{
    public function testAuditQueries(): void
    {
        $a = CompanyBuilder::aCompany()->withIndex(1)->build();
        $b = CompanyBuilder::aCompany()->withIndex(2)->build();
        $this->em->persist($a); $this->em->persist($b);

        $a1 = $this->raw($a, '2026-04-22', '2026-04-22', 1);
        $a2 = $this->raw($a, '2026-04-20', '2026-04-25', 2);
        $a3 = $this->raw($a, '2026-04-01', '2026-04-30', 3);
        $a4 = $this->raw($a, '2026-04-01', '2026-04-30', 4);
        $b1 = $this->raw($b, '2026-04-01', '2026-04-30', 5);
        $b2 = $this->raw($b, '2026-04-01', '2026-04-30', 6);
        foreach ([$a1,$a2,$a3,$a4,$b1,$b2] as $d) { $this->em->persist($d); }

        $listingA = new MarketplaceListing(Uuid::uuid4()->toString(), $a, null, MarketplaceType::OZON);
        $listingA->setMarketplaceSku('sku-a')->setPrice('100');
        $listingB = new MarketplaceListing(Uuid::uuid4()->toString(), $b, null, MarketplaceType::OZON);
        $listingB->setMarketplaceSku('sku-b')->setPrice('100');
        $this->em->persist($listingA); $this->em->persist($listingB);
        $doc = new Document(Uuid::uuid4()->toString(), $a); $this->em->persist($doc);

        $s1 = (new MarketplaceSale(Uuid::uuid4()->toString(), $a, $listingA, MarketplaceType::OZON))->setExternalOrderId('a1')->setSaleDate(new \DateTimeImmutable('2026-04-22'))->setQuantity(1)->setPricePerUnit('10')->setTotalRevenue('10')->setRawDocumentId($a1->getId());
        $s2 = (new MarketplaceSale(Uuid::uuid4()->toString(), $a, $listingA, MarketplaceType::OZON))->setExternalOrderId('a2')->setSaleDate(new \DateTimeImmutable('2026-04-22'))->setQuantity(1)->setPricePerUnit('20')->setTotalRevenue('20')->setRawDocumentId($a2->getId())->setDocument($doc);
        $s3 = (new MarketplaceSale(Uuid::uuid4()->toString(), $a, $listingA, MarketplaceType::OZON))->setExternalOrderId('a3')->setSaleDate(new \DateTimeImmutable('2026-04-22'))->setQuantity(1)->setPricePerUnit('30')->setTotalRevenue('30');
        $s4 = (new MarketplaceSale(Uuid::uuid4()->toString(), $b, $listingB, MarketplaceType::OZON))->setExternalOrderId('b1')->setSaleDate(new \DateTimeImmutable('2026-04-22'))->setQuantity(1)->setPricePerUnit('10')->setTotalRevenue('10')->setRawDocumentId($b1->getId());
        $s5 = (new MarketplaceSale(Uuid::uuid4()->toString(), $b, $listingB, MarketplaceType::OZON))->setExternalOrderId('b2')->setSaleDate(new \DateTimeImmutable('2026-04-22'))->setQuantity(1)->setPricePerUnit('20')->setTotalRevenue('20')->setRawDocumentId($b2->getId());

        $r1 = (new MarketplaceReturn(Uuid::uuid4()->toString(), $a, $listingA, MarketplaceType::OZON))->setReturnDate(new \DateTimeImmutable('2026-04-22'))->setQuantity(1)->setRefundAmount('7')->setRawDocumentId($a1->getId());
        $r2 = (new MarketplaceReturn(Uuid::uuid4()->toString(), $a, $listingA, MarketplaceType::OZON))->setReturnDate(new \DateTimeImmutable('2026-04-22'))->setQuantity(1)->setRefundAmount('8')->setRawDocumentId($a2->getId())->setDocument($doc);

        $c1 = (new MarketplaceCost(Uuid::uuid4()->toString(), $a, MarketplaceType::OZON))->setCostDate(new \DateTimeImmutable('2026-04-22'))->setAmount('2')->setRawDocumentId($a1->getId());
        $c2 = (new MarketplaceCost(Uuid::uuid4()->toString(), $a, MarketplaceType::OZON))->setCostDate(new \DateTimeImmutable('2026-04-22'))->setAmount('3')->setRawDocumentId($a2->getId())->setDocument($doc);

        foreach ([$s1,$s2,$s3,$s4,$s5,$r1,$r2,$c1,$c2] as $e) { $this->em->persist($e); }
        $this->em->flush();

        $q = self::getContainer()->get(OzonRawDuplicateAuditQuery::class);
        $exact = $q->findExactRawDocumentDuplicates($a->getId(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-30'));
        self::assertCount(1, $exact); self::assertSame('2', (string) $exact[0]['docs_count']);

        $overlap = $q->findOverlappingRawDocuments($a->getId(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-30'));
        self::assertNotEmpty($overlap);

        $sales = $q->findProcessedSalesWithMultipleRawDocuments($a->getId(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-30'));
        self::assertCount(1, $sales); self::assertGreaterThan(1, (int) $sales[0]['raw_docs_count']); self::assertTrue((bool) $sales[0]['has_closed_rows']);

        $returns = $q->findProcessedReturnsWithMultipleRawDocuments($a->getId(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-30'));
        $costs = $q->findProcessedCostsWithMultipleRawDocuments($a->getId(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-30'));
        self::assertCount(1, $returns); self::assertTrue((bool) $returns[0]['has_closed_rows']);
        self::assertCount(1, $costs); self::assertTrue((bool) $costs[0]['has_closed_rows']);
    }

    public function testExactDailyDuplicatesAreNotReportedAsOverlappingRawDocuments(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(10)->build();
        $this->em->persist($company);
        $this->em->persist($this->raw($company, '2026-04-22', '2026-04-22', 10));
        $this->em->persist($this->raw($company, '2026-04-22', '2026-04-22', 11));
        $this->em->flush();

        $q = self::getContainer()->get(OzonRawDuplicateAuditQuery::class);
        $exact = $q->findExactRawDocumentDuplicates($company->getId(), new \DateTimeImmutable('2026-04-22'), new \DateTimeImmutable('2026-04-22'));
        self::assertCount(1, $exact);
        self::assertSame('2', (string) $exact[0]['docs_count']);

        $overlap = $q->findOverlappingRawDocuments($company->getId(), new \DateTimeImmutable('2026-04-22'), new \DateTimeImmutable('2026-04-22'));
        self::assertSame([], $overlap);
    }

    public function testDailyRawDocumentCoveredByRangeRawDocumentIsReportedAsOverlap(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(11)->build();
        $this->em->persist($company);
        $daily = $this->raw($company, '2026-04-22', '2026-04-22', 20);
        $range = $this->raw($company, '2026-04-20', '2026-04-25', 21);
        $this->em->persist($daily);
        $this->em->persist($range);
        $this->em->flush();

        $q = self::getContainer()->get(OzonRawDuplicateAuditQuery::class);
        $overlap = $q->findOverlappingRawDocuments($company->getId(), new \DateTimeImmutable('2026-04-20'), new \DateTimeImmutable('2026-04-25'));

        self::assertCount(1, $overlap);
        self::assertSame($daily->getId(), $overlap[0]['daily_doc_id']);
        self::assertSame($range->getId(), $overlap[0]['range_doc_id']);
    }

    private function raw(Company $company, string $from, string $to, int $index): MarketplaceRawDocument
    {
        return MarketplaceRawDocumentBuilder::aDocument()->withIndex($index)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withDocumentType('sales_report')->withPeriod(new \DateTimeImmutable($from), new \DateTimeImmutable($to))->build();
    }
}

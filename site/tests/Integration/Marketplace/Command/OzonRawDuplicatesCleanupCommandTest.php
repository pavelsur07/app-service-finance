<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Command;

use App\Company\Entity\Company;
use App\Finance\Entity\Document;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonRawDuplicatesCleanupCommandTest extends IntegrationTestCase
{
    public function testDryRunDoesNotChangeData(): void
    {
        [$company, $day] = $this->seedDuplicateDay(true, 600);
        $before = $this->counts($company->getId(), $day);

        $tester = $this->tester();
        $exit = $tester->execute(['--company-id' => $company->getId(), '--from' => '2026-04-15', '--to' => '2026-04-15']);
        self::assertSame(Command::SUCCESS, $exit);

        self::assertSame($before, $this->counts($company->getId(), $day));
        self::assertStringContainsString('DRY-RUN', $tester->getDisplay());
    }

    public function testApplyDeletesOnlyOpenRowsAndCanDispatch(): void
    {
        [$company, $day, $canonicalRawId] = $this->seedDuplicateDay(true, 700);

        $tester = $this->tester();
        $exit = $tester->execute(['--company-id' => $company->getId(), '--from' => '2026-04-15', '--to' => '2026-04-15', '--apply' => true, '--dispatch-reprocess' => true]);
        self::assertSame(Command::SUCCESS, $exit);

        $counts = $this->counts($company->getId(), $day);
        self::assertSame(1, $counts['sales']);
        self::assertSame(0, $counts['returns']);
        self::assertSame(0, $counts['costs']);

        $conn = $this->em->getConnection();
        self::assertSame($canonicalRawId, (string) $conn->fetchOne('SELECT raw_document_id FROM marketplace_sales WHERE company_id=:c AND sale_date=:d LIMIT 1', ['c' => $company->getId(), 'd' => $day->format('Y-m-d')]));

        $out = $tester->getDisplay();
        self::assertStringContainsString('deleted rows', $out);
        self::assertStringContainsString('cleaned days: 1', $out);
        self::assertStringContainsString('reprocess messages dispatched: 1', $out);
        self::assertStringContainsString($canonicalRawId, $out);
    }

    public function testApplyDoesNotDeleteClosedRows(): void
    {
        [$company, $day] = $this->seedDuplicateDay(false, 800);

        $before = $this->counts($company->getId(), $day);
        $tester = $this->tester();
        $exit = $tester->execute(['--company-id' => $company->getId(), '--from' => '2026-04-15', '--to' => '2026-04-15', '--apply' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame($before, $this->counts($company->getId(), $day));
        self::assertStringContainsString('canAutoCleanup: false', $tester->getDisplay());
    }

    public function testApplyDoesNotTouchAnotherCompany(): void
    {
        [$companyA, $day] = $this->seedDuplicateDay(true, 900);
        [$companyB] = $this->seedDuplicateDay(true, 1000);

        $beforeB = $this->counts($companyB->getId(), $day);
        $tester = $this->tester();
        $exit = $tester->execute(['--company-id' => $companyA->getId(), '--from' => '2026-04-15', '--to' => '2026-04-15', '--apply' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['sales' => 1, 'returns' => 0, 'costs' => 0], $this->counts($companyA->getId(), $day));
        self::assertSame($beforeB, $this->counts($companyB->getId(), $day));
    }

    public function testApplyDoesNotTouchRowsOutsideDateRange(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1100)->build();
        $this->em->persist($company);
        $this->seedDuplicateDay(true, 1110, $company, new \DateTimeImmutable('2026-04-15'));
        $this->seedDuplicateDay(true, 1120, $company, new \DateTimeImmutable('2026-04-20'));

        $beforeOutside = $this->counts($company->getId(), new \DateTimeImmutable('2026-04-20'));
        $tester = $this->tester();
        $exit = $tester->execute(['--company-id' => $company->getId(), '--from' => '2026-04-15', '--to' => '2026-04-15', '--apply' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['sales' => 1, 'returns' => 0, 'costs' => 0], $this->counts($company->getId(), new \DateTimeImmutable('2026-04-15')));
        self::assertSame($beforeOutside, $this->counts($company->getId(), new \DateTimeImmutable('2026-04-20')));
    }

    private function seedDuplicateDay(bool $open, int $baseIndex = 600, ?Company $company = null, ?\DateTimeImmutable $day = null): array
    {
        $company ??= CompanyBuilder::aCompany()->withIndex($baseIndex)->build();
        $this->em->persist($company);
        $day ??= new \DateTimeImmutable('2026-04-15');

        $listing = MarketplaceListingBuilder::aListing()->withIndex($baseIndex)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku(sprintf('oz-cleanup-%d', $baseIndex))->build();
        $this->em->persist($listing);

        $staleRaw = MarketplaceRawDocumentBuilder::aDocument()->withIndex($baseIndex + 1)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withDocumentType('sales_report')->withPeriod($day, $day)->withProcessingStatus('completed')->build();
        $canonicalRaw = MarketplaceRawDocumentBuilder::aDocument()->withIndex($baseIndex + 2)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withDocumentType('sales_report')->withPeriod($day, $day)->withProcessingStatus('completed')->withSyncedAt($day->modify('+1 day'))->build();
        $this->em->persist($staleRaw);
        $this->em->persist($canonicalRaw);

        $doc = null;
        if (!$open) {
            $doc = new Document(sprintf('77777777-7777-4777-8777-%012d', $baseIndex), $company);
            $this->em->persist($doc);
        }

        $sale1 = MarketplaceSaleBuilder::aSale()->withIndex($baseIndex + 3)->forCompany($company)->forListing($listing)->withMarketplace(MarketplaceType::OZON)->withSaleDate($day)->withExternalOrderId(sprintf('cleanup-stale-%d', $baseIndex))->build();
        $sale1->setRawDocumentId($staleRaw->getId());
        if ($doc !== null) { $sale1->setDocument($doc); }

        $sale2 = MarketplaceSaleBuilder::aSale()->withIndex($baseIndex + 4)->forCompany($company)->forListing($listing)->withMarketplace(MarketplaceType::OZON)->withSaleDate($day)->withExternalOrderId(sprintf('cleanup-canonical-%d', $baseIndex))->build();
        $sale2->setRawDocumentId($canonicalRaw->getId());
        $this->em->persist($sale1);
        $this->em->persist($sale2);

        $return = new MarketplaceReturn(sprintf('66666666-6666-4666-8666-%012d', $baseIndex), $company, $listing, MarketplaceType::OZON);
        $return->setExternalReturnId(sprintf('cleanup-return-%d', $baseIndex))->setReturnDate($day)->setQuantity(1)->setRefundAmount('1.00')->setRawDocumentId($staleRaw->getId());
        if ($doc !== null) { $return->setDocument($doc); }
        $this->em->persist($return);

        $cost = new MarketplaceCost(sprintf('55555555-5555-4555-8555-%012d', $baseIndex), $company, MarketplaceType::OZON);
        $cost->setExternalId(sprintf('cleanup-cost-%d', $baseIndex))->setCostDate($day)->setAmount('1.00')->setRawDocumentId($staleRaw->getId());
        if ($doc !== null) { $cost->setDocument($doc); }
        $this->em->persist($cost);

        $this->em->flush();

        return [$company, $day, $canonicalRaw->getId()];
    }

    private function counts(string $companyId, \DateTimeImmutable $day): array
    {
        $conn = $this->em->getConnection();

        return [
            'sales' => (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_sales WHERE company_id=:c AND sale_date=:d', ['c' => $companyId, 'd' => $day->format('Y-m-d')]),
            'returns' => (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_returns WHERE company_id=:c AND return_date=:d', ['c' => $companyId, 'd' => $day->format('Y-m-d')]),
            'costs' => (int) $conn->fetchOne('SELECT COUNT(*) FROM marketplace_costs WHERE company_id=:c AND cost_date=:d', ['c' => $companyId, 'd' => $day->format('Y-m-d')]),
        ];
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:marketplace:ozon-raw-duplicates-cleanup'));
    }
}

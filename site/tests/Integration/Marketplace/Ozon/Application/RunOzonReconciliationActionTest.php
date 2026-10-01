<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Application;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Enum\OzonReconciliationCheck;
use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Marketplace\Ozon\Application\Action\RunOzonReconciliationAction;
use App\Marketplace\Ozon\Application\Service\OzonAccrualServiceCategoryResolver;
use App\Marketplace\Ozon\Exception\InvalidReconciliationPeriodException;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonLedgerTotalsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonRawAccrualTotalsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonRealizationTotalsQuery;
use App\Marketplace\Repository\OzonReconciliationLineRepository;
use App\Marketplace\Repository\OzonReconciliationRunRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

final class RunOzonReconciliationActionTest extends IntegrationTestCase
{
    private const DAY = '2026-09-10';

    private string $companyId;
    private string $otherCompanyId;
    private string $listingId;
    private string $docId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->seedCompany(1);
        $this->otherCompanyId = $this->seedCompany(2);
        $company = $this->em->find(Company::class, $this->companyId);
        self::assertNotNull($company);
        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('700000000')->build();
        $this->em->persist($listing);

        $doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('accrual_by_day')->withProcessingStatus('completed')->withPeriod(new \DateTimeImmutable(self::DAY), new \DateTimeImmutable(self::DAY))->build();
        $doc->setApiEndpoint(MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value);
        $doc->setRawData(['accruals' => [[
            'accrual_id' => 1, 'date' => self::DAY, 'unit_number' => 'A',
            'posting' => ['products' => [[
                'sku' => '700000000',
                'delivery' => ['services' => [['type_id' => 32, 'accrued' => ['amount' => '-118']]]],
                'commission' => ['sale_amount' => ['amount' => '2999'], 'sale_price' => ['amount' => '1168.57'], 'seller_price' => ['amount' => '2999'], 'commission' => ['amount' => '-1379.54']],
            ]]],
        ]], 'service_types' => ['32' => 'Logistic']]);
        $this->em->persist($doc);
        $this->em->flush();

        $this->listingId = (string) $listing->getId();
        $this->docId = (string) $doc->getId();
    }

    public function testBuildsSnapshotAndIsIdempotent(): void
    {
        $this->seedLedger(2999.00, 1379.54, 118.00);

        $run = $this->action()($this->companyId, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));

        // Сентябрь: by-day разрешён с 08.09, сегодня 12.09 — ожидается 8–11 сентября, загружен один день.
        self::assertSame(4, $run->getRawDaysExpected());
        self::assertSame(1, $run->getRawDaysPresent());
        self::assertFalse($run->hasRealization());
        // Неполная загрузка не даёт «сошлось».
        self::assertSame(OzonReconciliationStatus::NO_DATA, $run->getOverallStatus());
        self::assertSame(0, $run->getMismatchCount());

        $lines = self::getContainer()->get(OzonReconciliationLineRepository::class)->findByRun($this->companyId, $run->getId());
        $sales = $this->lineOf($lines, OzonReconciliationCheck::RAW_VS_LEDGER, OzonReconciliationBlock::SALES);
        self::assertSame(299900, $sales->getSourceMinor());
        self::assertSame(OzonReconciliationStatus::MATCHED, $sales->getStatus());
        $realization = $this->lineOf($lines, OzonReconciliationCheck::REALIZATION_VS_RAW, OzonReconciliationBlock::SALES);
        self::assertSame(OzonReconciliationStatus::NO_DATA, $realization->getStatus());

        // Повторный запуск: тот же Run, строки пересозданы, дублей нет.
        $second = $this->action()($this->companyId, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));
        $this->em->clear();

        self::assertSame($run->getId(), $second->getId());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_ozon_reconciliation_runs WHERE company_id = :c', ['c' => $this->companyId]));
        self::assertSame(count($lines), (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_ozon_reconciliation_lines WHERE company_id = :c', ['c' => $this->companyId]));
    }

    public function testDetectsLedgerDivergenceAndRefreshesSnapshot(): void
    {
        $this->seedLedger(2999.00, 1379.54, 118.00);
        $first = $this->action()($this->companyId, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));
        self::assertSame(0, $first->getMismatchCount());

        // Затрата на логистику пропала из учёта.
        $this->connection->executeStatement('DELETE FROM marketplace_costs WHERE company_id = :c AND amount = 118.00', ['c' => $this->companyId]);
        $second = $this->action()($this->companyId, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));

        self::assertSame(OzonReconciliationStatus::MISMATCH, $second->getOverallStatus());
        self::assertSame(1, $second->getMismatchCount());
        $this->em->clear();
        $lines = self::getContainer()->get(OzonReconciliationLineRepository::class)->findByRun($this->companyId, $second->getId());
        $logistics = $this->lineOf($lines, OzonReconciliationCheck::RAW_VS_LEDGER, OzonReconciliationBlock::LOGISTICS, 'ozon_logistic_direct');
        self::assertSame(-11800, $logistics->getDeltaMinor());
    }

    public function testOtherCompanyGetsItsOwnEmptySnapshot(): void
    {
        $this->seedLedger(2999.00, 1379.54, 118.00);

        $run = $this->action()($this->otherCompanyId, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));

        self::assertSame(OzonReconciliationStatus::NO_DATA, $run->getOverallStatus());
        self::assertSame(0, $run->getRawDaysPresent());
        self::assertNull(self::getContainer()->get(OzonReconciliationRunRepository::class)->findByIdForCompany($this->companyId, $run->getId()));
    }

    public function testPeriodMustFitOneMonth(): void
    {
        $this->expectException(InvalidReconciliationPeriodException::class);

        $this->action()($this->companyId, new \DateTimeImmutable('2026-09-15'), new \DateTimeImmutable('2026-10-15'));
    }

    private function action(): RunOzonReconciliationAction
    {
        return new RunOzonReconciliationAction(
            new OzonRealizationTotalsQuery($this->connection),
            new OzonRawAccrualTotalsQuery($this->connection, new OzonAccrualServiceCategoryResolver()),
            new OzonLedgerTotalsQuery($this->connection),
            self::getContainer()->get(OzonReconciliationRunRepository::class),
            self::getContainer()->get(OzonReconciliationLineRepository::class),
            $this->em,
            new MockClock('2026-09-12 09:00:00'),
            new NullLogger(),
        );
    }

    private function seedCompany(int $index): string
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        return (string) $company->getId();
    }

    private function seedLedger(float $sale, float $commission, float $logistics): void
    {
        $this->connection->insert('marketplace_sales', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $this->companyId, 'listing_id' => $this->listingId, 'marketplace' => 'ozon',
            'external_order_id' => 'ozon-accrual-A-product-0', 'sale_date' => self::DAY, 'quantity' => 1,
            'price_per_unit' => number_format($sale, 2, '.', ''), 'total_revenue' => number_format($sale, 2, '.', ''),
            'raw_document_id' => $this->docId, 'created_at' => '2026-09-11 00:00:00', 'updated_at' => '2026-09-11 00:00:00',
        ]);

        foreach ([['ozon_sale_commission', $commission], ['ozon_logistic_direct', $logistics]] as [$code, $amount]) {
            $categoryId = Uuid::uuid4()->toString();
            $this->connection->insert('marketplace_cost_categories', [
                'id' => $categoryId, 'company_id' => $this->companyId, 'name' => $code, 'code' => $code, 'is_active' => 1, 'is_system' => 0,
                'marketplace' => 'ozon', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
            ], ['is_active' => \Doctrine\DBAL\ParameterType::BOOLEAN, 'is_system' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
            $this->connection->insert('marketplace_costs', [
                'id' => Uuid::uuid4()->toString(), 'company_id' => $this->companyId, 'marketplace' => 'ozon', 'category_id' => $categoryId,
                'amount' => number_format($amount, 2, '.', ''), 'operation_type' => 'charge', 'cost_date' => self::DAY,
                'raw_document_id' => $this->docId, 'created_at' => '2026-09-11 00:00:00', 'updated_at' => '2026-09-11 00:00:00',
            ]);
        }
    }

    /**
     * @param list<\App\Marketplace\Entity\OzonReconciliationLine> $lines
     */
    private function lineOf(array $lines, OzonReconciliationCheck $check, OzonReconciliationBlock $block, string $code = ''): \App\Marketplace\Entity\OzonReconciliationLine
    {
        foreach ($lines as $line) {
            if ($line->getCheck() === $check && $line->getBlock() === $block && $line->getCategoryCode() === $code) {
                return $line;
            }
        }

        self::fail(sprintf('Line %s/%s/%s not found.', $check->value, $block->value, $code));
    }
}

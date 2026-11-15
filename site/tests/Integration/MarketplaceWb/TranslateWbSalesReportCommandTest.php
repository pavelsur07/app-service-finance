<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceWb;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\MarketplaceWb\Application\Action\TranslateWbDayAction;
use App\MarketplaceWb\Command\TranslateWbSalesReportCommand;
use App\MarketplaceWb\Command\WbTranslationHealthCheckCommand;
use App\MarketplaceWb\Domain\AdsDayTranslator;
use App\MarketplaceWb\Infrastructure\Query\WbTranslationStore;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TranslateWbSalesReportCommandTest extends IntegrationTestCase
{
    private const DAY = '2026-11-10';
    private const CABINET = 'wb-cabinet-1';

    private string $companyId;
    private string $listingId;
    private string $otherListingId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('mwb-'.$this->companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($this->companyId)->withOwner($owner)->build();
        $this->listingId = Uuid::uuid7()->toString();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($this->listing($company, $this->listingId, '42'));
        $this->otherListingId = Uuid::uuid7()->toString();
        $this->em->persist($this->listing($company, $this->otherListingId, '44'));
        $this->em->flush();
    }

    public function testTranslatesDayToCanonAndRepeatChangesNothing(): void
    {
        $this->facts(1, [$this->sale(1, '42'), $this->sale(2, '50'), $this->cost(3, ['sellerOperName' => 'Хранение', 'paidStorage' => '12.50'])]);

        self::assertStringContainsString('1 due, 1 translated (9 operations)', $this->translate());

        $sale = $this->connection->fetchAllAssociative("SELECT listing_id, amount_minor, quantity FROM mo_operation WHERE company_id = ? AND kind = 'sale' ORDER BY listing_id NULLS LAST", [$this->companyId]);
        self::assertEquals([['listing_id' => $this->listingId, 'amount_minor' => 100000, 'quantity' => 1], ['listing_id' => null, 'amount_minor' => 100000, 'quantity' => 1]], $sale, 'размера 50 нет, у nmId два листинга — без SKU');
        // итог дня = 2 × forPay 750 − хранение 12,50; СПП — справочно
        self::assertSame([1, 3, 148750, 148750, 0, 4], $this->day());
        self::assertSame(148750, (int) $this->connection->fetchOne("SELECT SUM(amount_minor) FROM mo_operation WHERE company_id = ? AND kind <> 'spp'", [$this->companyId]));

        self::assertStringContainsString('0 due', $this->translate());
        self::assertSame(9, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mo_operation WHERE company_id = ?', [$this->companyId]));
    }

    public function testUndecidedCodeWaitsInQuarantineAndGateTurnsGreenAfterDecision(): void
    {
        $this->facts(1, [$this->cost(1, ['sellerOperName' => 'Услуга по обработке Товара', 'paidAcceptance' => '10']), $this->cost(2, ['sellerOperName' => 'Хранение', 'paidStorage' => '5'])]);
        $this->translate();

        self::assertSame([-1500, -500, -1000], \array_slice($this->day(), 2, 3));
        self::assertSame('pending', $this->connection->fetchOne("SELECT status FROM mwb_source_type WHERE source_type = 'wb:field:paidAcceptance'"));
        $this->connection->executeStatement("UPDATE mwb_quarantine SET quarantined_at = '2026-11-09 09:00:00' WHERE company_id = ?", [$this->companyId]);
        self::assertSame(Command::FAILURE, $this->healthCheck(), 'код без решения > 24 ч');

        $this->connection->executeStatement("UPDATE mwb_source_type SET kind = 'storage', status = 'confirmed' WHERE source_type = 'wb:field:paidAcceptance'");
        self::assertStringContainsString('1 due, 1 translated (2 operations)', $this->translate());
        self::assertSame(0, $this->day()[4]);
        self::assertSame(Command::SUCCESS, $this->healthCheck());

        $this->connection->executeStatement("DELETE FROM mo_operation WHERE company_id = ? AND source_type = 'wb:field:paidStorage'", [$this->companyId]);
        self::assertSame(Command::FAILURE, $this->healthCheck(), 'строка канона потеряна');
    }

    public function testAdsSpendIsAllocatedToListingsAndNewAdsRevisionRetranslatesDay(): void
    {
        $this->facts(1, [$this->sale(1, '42')]);
        self::assertStringContainsString('1 due, 1 translated', $this->translate());

        $this->facts(1, [['advertId' => '7', 'expenses' => [['advertId' => 7, 'updSum' => 30]], 'statistics' => ['advertId' => 7, 'days' => [['apps' => [['nms' => [['nmId' => 555, 'sum' => 3], ['nmId' => 999, 'sum' => 1]]]]]]]]], AdsDayTranslator::RESOURCE);
        self::assertStringContainsString('1 due, 1 translated', $this->translate(), 'новая ревизия рекламы — день заново');

        $ads = $this->connection->fetchAllAssociative("SELECT listing_id, amount_minor, allocation_key FROM mo_operation WHERE company_id = ? AND kind = 'ads_cpc' ORDER BY amount_minor", [$this->companyId]);
        // у nmId 555 два листинга (размеры 42, 44) — первый по id; nmId 999 без листинга
        $first = min($this->listingId, $this->otherListingId);
        self::assertEquals([['listing_id' => $first, 'amount_minor' => -2250, 'allocation_key' => 'ads_statistics_share'], ['listing_id' => null, 'amount_minor' => -750, 'allocation_key' => null]], $ads);
        self::assertSame([1, 1, 72000, 72000, 0, 1], $this->day(), 'итог дня = forPay 750 − реклама 30');

        self::assertStringContainsString('0 due', $this->translate());
    }

    public function testDisabledCompanyIsNotTranslated(): void
    {
        $this->facts(1, [$this->sale(1, '42')]);

        self::assertStringContainsString('выключено', $this->translate(''));
        self::assertStringContainsString('0 due', $this->translate(Uuid::uuid7()->toString()));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mwb_day WHERE company_id = ?', [$this->companyId]));
    }

    private function listing(Company $company, string $id, string $size): MarketplaceListing
    {
        return MarketplaceListingBuilder::aListing()->withId($id)->forCompany($company)->withMarketplace(MarketplaceType::WILDBERRIES)->withMarketplaceSku('555')->build()->setSize($size);
    }

    /**
     * @return array<string, mixed>
     */
    private function sale(int $rrdId, string $size): array
    {
        return ['rrdId' => $rrdId, 'docTypeName' => 'Продажа', 'sellerOperName' => 'Продажа', 'nmId' => 555, 'techSize' => $size, 'quantity' => 1,
            'retailPriceWithDisc' => '1000', 'retailAmount' => '800', 'forPay' => '750', 'acquiringFee' => '20', 'currency' => 'RUB'];
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    private function cost(int $rrdId, array $fields): array
    {
        return ['rrdId' => $rrdId, 'docTypeName' => '', 'nmId' => 0, 'quantity' => 0, 'currency' => 'RUB'] + $fields;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function facts(int $revision, array $rows, string $resource = TranslateWbDayAction::RESOURCE): void
    {
        $period = ['company' => $this->companyId, 'connection' => self::CABINET, 'resource' => $resource, 'period' => self::DAY];
        foreach ($rows as $row) {
            $this->connection->executeStatement(
                'INSERT INTO sf_fact (id, company_id, connection_ref, resource_type, period_key, source_key, payload_hash, payload, first_revision, last_seen_revision, created_at)
                 VALUES (gen_random_uuid(), :company, :connection, :resource, :period, :key, md5(:payload), CAST(:payload AS jsonb), :revision, :revision, now())',
                $period + ['key' => isset($row['advertId']) ? 'wb:ads.daily:'.$row['advertId'] : 'wb:sales-report.daily:'.$row['rrdId'], 'payload' => json_encode($row, \JSON_THROW_ON_ERROR), 'revision' => $revision],
            );
        }
        $this->connection->executeStatement(
            'INSERT INTO sf_period (id, company_id, connection_ref, resource_type, period_key, current_revision, fact_count, updated_at)
             VALUES (gen_random_uuid(), :company, :connection, :resource, :period, :revision, :count, now())
             ON CONFLICT (company_id, connection_ref, resource_type, period_key) DO UPDATE SET current_revision = EXCLUDED.current_revision, fact_count = EXCLUDED.fact_count',
            $period + ['revision' => $revision, 'count' => \count($rows)],
        );
    }

    private function translate(?string $companies = null): string
    {
        $c = self::getContainer();
        $tester = new CommandTester(new TranslateWbSalesReportCommand(
            $c->get(SourceFactsFacadeInterface::class),
            $c->get(WbTranslationStore::class),
            $c->get(TranslateWbDayAction::class),
            $c->get(Connection::class),
            new NullLogger(),
            $companies ?? $this->companyId,
        ));
        $tester->execute([]);

        return $tester->getDisplay();
    }

    private function healthCheck(): int
    {
        $c = self::getContainer();

        return (new CommandTester(new WbTranslationHealthCheckCommand(
            $c->get(SourceFactsFacadeInterface::class),
            $c->get(WbTranslationStore::class),
            $c->get(MarketplaceOperationsFacadeInterface::class),
            new MockClock('2026-11-11 09:25:00'),
            new NullLogger(),
            $this->companyId,
        )))->execute([]);
    }

    /**
     * @return list<int> facts_revision, fact_count, total, canon, quarantine, unresolved
     */
    private function day(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT facts_revision, fact_count, total_minor, canon_minor, quarantine_minor, unresolved_listing_count FROM mwb_day WHERE company_id = ? AND operation_date = ?',
            [$this->companyId, self::DAY],
        );
        self::assertIsArray($row);

        return array_values(array_map('intval', $row));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceOzon;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceOzon\Command\OzonTranslationHealthCheckCommand;
use App\MarketplaceOzon\Command\TranslateOzonAccrualsCommand;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TranslateOzonAccrualsCommandTest extends IntegrationTestCase
{
    private const RESOURCE = 'ozon.finance.accrual-by-day';
    private const DAY = '2026-11-10';
    private const CABINET = 'cabinet-1';

    private string $companyId;
    private string $listingId;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('moz-'.$this->companyId.'@example.test')->build();
        $company = $this->company = CompanyBuilder::aCompany()->withId($this->companyId)->withOwner($owner)->build();
        $this->listingId = Uuid::uuid7()->toString();
        $listing = MarketplaceListingBuilder::aListing()->withId($this->listingId)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('700')->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($listing);
        $this->em->flush();
    }

    public function testTranslatesDayAndRepeatChangesNothing(): void
    {
        $this->facts(1, [$this->sale(1, '700'), $this->sale(2, '999'), $this->fee(3, 46, '-50.00')]);

        self::assertStringContainsString('1 due, 1 translated (13 operations)', $this->translate());

        $ops = $this->connection->fetchAllAssociative('SELECT kind, source_type, amount_minor, listing_id FROM mo_operation WHERE company_id = ? ORDER BY source_refs::text, source_type', [$this->companyId]);
        self::assertSame(['sale', 'ozon:field:sale_amount', 100000, $this->listingId], array_values($this->find($ops, 'ozon:field:sale_amount', $this->listingId)));
        self::assertSame(['storage', 'ozon:service:46', -5000, null], array_values($this->find($ops, 'ozon:service:46', null)));

        $day = $this->day();
        // итог дня = Σ total_amount: 2 × (1000 − 150 − 80) − 50; без листинга — 6 операций SKU 999
        self::assertSame([1, 3, 149000, 149000, 0, 6], [$day['facts_revision'], $day['fact_count'], $day['total_minor'], $day['canon_minor'], $day['quarantine_minor'], $day['unresolved_listing_count']]);

        self::assertStringContainsString('0 due', $this->translate());
        self::assertSame(13, $this->rows('mo_operation'));
    }

    public function testNewRevisionReplacesDay(): void
    {
        $this->facts(1, [$this->sale(1, '700'), $this->fee(3, 46, '-50.00')]);
        $this->translate();

        // ревизия 2: продажа исчезла, хранение изменилось, появился эквайринг
        $this->facts(2, [$this->fee(3, 46, '-70.00'), $this->fee(4, 1, '-10.00')]);
        self::assertStringContainsString('1 due, 1 translated (2 operations)', $this->translate());

        self::assertSame(-8000, $this->day()['total_minor']);
        self::assertSame(['ozon:service:1' => -1000, 'ozon:service:46' => -7000], $this->connection->fetchAllKeyValue(
            'SELECT source_type, amount_minor FROM mo_operation WHERE company_id = ? ORDER BY source_type',
            [$this->companyId],
        ));
    }

    public function testUnknownTypeWaitsInQuarantineUntilDecided(): void
    {
        $this->facts(1, [$this->fee(1, 12, '-30.00'), $this->fee(2, 46, '-50.00')]);
        $this->translate();

        self::assertSame(['ozon:service:46'], $this->connection->fetchFirstColumn('SELECT source_type FROM mo_operation WHERE company_id = ?', [$this->companyId]));
        self::assertSame([-8000, -5000, -3000], [$this->day()['total_minor'], $this->day()['canon_minor'], $this->day()['quarantine_minor']]);
        self::assertSame(['pending', 'Кросс-докинг'], array_values((array) $this->connection->fetchAssociative("SELECT status, name FROM moz_source_type WHERE source_type = 'ozon:service:12'")));

        $this->connection->executeStatement("UPDATE moz_quarantine SET quarantined_at = quarantined_at - interval '2 days' WHERE company_id = ?", [$this->companyId]);
        self::assertSame(Command::FAILURE, $this->healthCheck());

        $this->connection->executeStatement("UPDATE moz_source_type SET kind = 'logistics_to', status = 'confirmed' WHERE source_type = 'ozon:service:12'");
        self::assertStringContainsString('1 due, 1 translated (2 operations)', $this->translate());
        self::assertSame(0, $this->rows('moz_quarantine'));
        self::assertSame(0, $this->day()['quarantine_minor']);
        self::assertSame(Command::SUCCESS, $this->healthCheck());
    }

    public function testRelinkResolvesListingThatAppearedLater(): void
    {
        $this->facts(1, [$this->sale(1, '800')]);
        $this->translate();
        self::assertSame(6, $this->day()['unresolved_listing_count']);

        $this->em->persist(MarketplaceListingBuilder::aListing()->withIndex(2)->forCompany($this->company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('800')->build());
        $this->em->flush();

        self::assertStringContainsString('0 due', $this->translate());
        self::assertStringContainsString('1 due', $this->translate(['--relink' => true]));
        self::assertSame(0, $this->day()['unresolved_listing_count']);
    }

    /**
     * @param list<array<string, mixed>> $accruals
     */
    private function facts(int $revision, array $accruals): void
    {
        $period = ['company' => $this->companyId, 'connection' => self::CABINET, 'resource' => self::RESOURCE, 'period' => self::DAY];
        foreach ($accruals as $accrual) {
            $this->connection->executeStatement(
                'INSERT INTO sf_fact (id, company_id, connection_ref, resource_type, period_key, source_key, payload_hash, payload, first_revision, last_seen_revision, created_at)
                 VALUES (gen_random_uuid(), :company, :connection, :resource, :period, :key, md5(:payload), CAST(:payload AS jsonb), :revision, :revision, now())',
                $period + ['key' => 'ozon:accrual-by-day:'.$accrual['accrual_id'], 'payload' => json_encode($accrual, \JSON_THROW_ON_ERROR), 'revision' => $revision],
            );
        }
        $this->connection->executeStatement(
            'INSERT INTO sf_period (id, company_id, connection_ref, resource_type, period_key, current_revision, fact_count, updated_at)
             VALUES (gen_random_uuid(), :company, :connection, :resource, :period, :revision, :count, now())
             ON CONFLICT (company_id, connection_ref, resource_type, period_key) DO UPDATE SET current_revision = EXCLUDED.current_revision, fact_count = EXCLUDED.fact_count',
            $period + ['revision' => $revision, 'count' => \count($accruals)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sale(int $id, string $sku): array
    {
        $m = static fn (string $a): array => ['amount' => $a, 'currency' => 'RUB'];

        return [
            'accrual_id' => $id,
            'accrued_category' => 'POSTING',
            'total_amount' => $m('770.00'),
            'posting' => ['products' => [[
                'sku' => (int) $sku,
                'quantity' => 1,
                'commission' => ['sale_amount' => $m('1000.00'), 'sale_price' => $m('600.00'), 'bonus' => $m('390.00'), 'coinvestment' => $m('10.00'), 'sale_commission' => $m('-150.00')],
                'delivery' => ['services' => [['type_id' => 32, 'accrued' => $m('-60.00')], ['type_id' => 29, 'accrued' => $m('-20.00')]]],
            ]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fee(int $id, int $typeId, string $amount): array
    {
        return ['accrual_id' => $id, 'accrued_category' => 'NON_ITEM', 'total_amount' => ['amount' => $amount, 'currency' => 'RUB'], 'non_item_fee' => ['type_id' => $typeId, 'accrued' => ['amount' => $amount, 'currency' => 'RUB']]];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function translate(array $options = []): string
    {
        $tester = new CommandTester(self::getContainer()->get(TranslateOzonAccrualsCommand::class));
        $tester->execute($options);

        return $tester->getDisplay();
    }

    private function healthCheck(): int
    {
        return (new CommandTester(self::getContainer()->get(OzonTranslationHealthCheckCommand::class)))->execute([]);
    }

    /**
     * @return array<string, int>
     */
    private function day(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT facts_revision, fact_count, total_minor, canon_minor, quarantine_minor, unresolved_listing_count FROM moz_day WHERE company_id = ? AND operation_date = ?',
            [$this->companyId, self::DAY],
        );
        self::assertIsArray($row);

        return array_map('intval', $row);
    }

    private function rows(string $table): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE company_id = ?', $table), [$this->companyId]);
    }

    /**
     * @param list<array<string, mixed>> $ops
     *
     * @return array<string, mixed>
     */
    private function find(array $ops, string $sourceType, ?string $listingId): array
    {
        foreach ($ops as $op) {
            if ($op['source_type'] === $sourceType && $op['listing_id'] === $listingId) {
                $op['amount_minor'] = (int) $op['amount_minor'];

                return $op;
            }
        }
        self::fail('no operation '.$sourceType);
    }
}

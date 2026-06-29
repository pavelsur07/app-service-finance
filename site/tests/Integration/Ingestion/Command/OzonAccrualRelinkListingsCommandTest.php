<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Infrastructure\Query\OzonAccrualProjectionHealthQuery;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonAccrualRelinkListingsCommandTest extends IntegrationTestCase
{
    public function testDryRunAndExecuteRecoverListingContextFromRawRecord(): void
    {
        $company = $this->createCompany();
        $companyId = (string) $company->getId();
        $connectionRef = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rows: [$this->postingRow()],
        );
        $transaction = $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            rawRecordId: $record->getId(),
            externalId: 'ozon:accrual-by-day:53675409100:sale:product-0',
        );
        $this->em->flush();

        $dryRun = $this->tester();
        $dryRunExit = $dryRun->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-01',
            '--to' => '2026-06-01',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $dryRunExit, $dryRun->getDisplay());
        self::assertStringContainsString('would-create-listing+update', $dryRun->getDisplay());
        self::assertStringContainsString('1234567890', $dryRun->getDisplay());
        self::assertNull($this->listingId($companyId, '1234567890'));
        self::assertSame([null, null], $this->transactionListing($transaction->getId()));

        $execute = $this->tester();
        $executeExit = $execute->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-01',
            '--to' => '2026-06-01',
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $executeExit, $execute->getDisplay());
        self::assertStringContainsString('updated', $execute->getDisplay());
        $createdListingId = $this->listingId($companyId, '1234567890');
        self::assertNotNull($createdListingId);
        self::assertSame([$createdListingId, '1234567890'], $this->transactionListing($transaction->getId()));
    }

    public function testExecuteCanBeScopedByShopRef(): void
    {
        $company = $this->createCompany();
        $companyId = (string) $company->getId();
        $firstShopRef = Uuid::uuid7()->toString();
        $secondShopRef = Uuid::uuid7()->toString();
        $firstRecord = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $firstShopRef,
            rows: [$this->postingRow(53675409101, '1234567891')],
        );
        $secondRecord = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $secondShopRef,
            rows: [$this->postingRow(53675409102, '1234567892')],
        );
        $firstTransaction = $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $firstShopRef,
            rawRecordId: $firstRecord->getId(),
            externalId: 'ozon:accrual-by-day:53675409101:sale:product-0',
        );
        $secondTransaction = $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $secondShopRef,
            rawRecordId: $secondRecord->getId(),
            externalId: 'ozon:accrual-by-day:53675409102:sale:product-0',
        );
        $this->em->flush();

        $execute = $this->tester();
        $executeExit = $execute->execute([
            '--company-id' => $companyId,
            '--shop-ref' => $firstShopRef,
            '--from' => '2026-06-01',
            '--to' => '2026-06-01',
            '--execute' => true,
        ]);

        self::assertSame(Command::SUCCESS, $executeExit, $execute->getDisplay());
        $firstListingId = $this->listingId($companyId, '1234567891');
        self::assertNotNull($firstListingId);
        self::assertSame([$firstListingId, '1234567891'], $this->transactionListing($firstTransaction->getId()));
        self::assertSame([null, null], $this->transactionListing($secondTransaction->getId()));
    }

    public function testProjectionHealthUsesWindowProjectionAndIgnoresRawUpdatedAt(): void
    {
        $company = $this->createCompany();
        $companyId = (string) $company->getId();
        $shopRef = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $shopRef,
            rows: [$this->postingRow(53675409103, '1234567893')],
        );
        $record->markNormalizationDone();
        $transaction = $this->persistExistingTransaction(
            companyId: $companyId,
            connectionRef: $shopRef,
            rawRecordId: Uuid::uuid7()->toString(),
            externalId: 'ozon:accrual-by-day:53675409103:sale:product-0',
        );
        $this->em->flush();
        $this->connection->executeStatement(
            'UPDATE ingest_financial_transactions SET updated_at = :updatedAt WHERE id = :id',
            ['updatedAt' => '2026-06-03 00:00:00', 'id' => $transaction->getId()],
        );
        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET updated_at = :updatedAt WHERE id = :id',
            ['updatedAt' => '2026-06-29 00:00:00', 'id' => $record->getId()],
        );

        /** @var OzonAccrualProjectionHealthQuery $query */
        $query = self::getContainer()->get(OzonAccrualProjectionHealthQuery::class);
        $rows = $query->rawProjectionRows(
            from: new \DateTimeImmutable('2026-06-01'),
            to: new \DateTimeImmutable('2026-06-01'),
            companyId: $companyId,
            shopRef: $shopRef,
            limit: 10,
            problemsOnly: false,
        );

        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['tx_count']);
        self::assertSame(0, (int) $rows[0]['direct_raw_tx_count']);
        self::assertSame(0, (int) $rows[0]['needs_normalization']);
        self::assertSame([], $query->rawProjectionRows(
            from: new \DateTimeImmutable('2026-06-01'),
            to: new \DateTimeImmutable('2026-06-01'),
            companyId: $companyId,
            shopRef: $shopRef,
            limit: 10,
            problemsOnly: true,
        ));
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:ingestion:ozon-accrual:relink-listings'));
    }

    private function createCompany(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('ozon-accrual-relink@example.com');
        $user->setPassword('password');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Ozon Accrual Relink Company');

        $this->em->persist($user);
        $this->em->persist($company);

        return $company;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeRawRecord(string $companyId, string $connectionRef, array $rows): IngestRawRecord
    {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        return $facade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            externalId: 'accrual-by-day:2026-06-01:2026-06-01',
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable('2026-06-02 03:00:00+00:00'),
            rows: $rows,
        ))[0];
    }

    private function persistExistingTransaction(
        string $companyId,
        string $connectionRef,
        string $rawRecordId,
        string $externalId,
    ): FinancialTransaction {
        $transaction = new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable('2026-06-02 03:00:00+00:00'),
            operationGroupId: Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:accrual-by-day:%s', $companyId, '53675409100'))->toString(),
            type: TransactionType::SALE,
            direction: TransactionDirection::IN,
            money: Money::fromMinor(10000, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-01 00:00:00+03:00'),
            rawRecordId: $rawRecordId,
            description: 'Existing Ozon accrual transaction',
            sourceData: [],
            sourceTz: 'Europe/Moscow',
        );

        $this->em->persist($transaction);

        return $transaction;
    }

    /**
     * @return array<string, mixed>
     */
    private function postingRow(int $accrualId = 53675409100, string $sku = '1234567890'): array
    {
        return [
            'accrual_id' => $accrualId,
            'date' => '2026-06-01',
            'unit_number' => '41774559-0885-1',
            'accrued_category' => 'POSTING',
            'posting' => [
                'products' => [[
                    'sku' => $sku,
                    'offer_id' => 'old-offer',
                    'name' => 'Old Ozon Product',
                    'commission' => [
                        'sale_amount' => ['amount' => '100.00', 'currency' => 'RUB'],
                    ],
                ]],
            ],
        ];
    }

    private function listingId(string $companyId, string $marketplaceSku): ?string
    {
        /** @var MarketplaceListingRepository $repository */
        $repository = self::getContainer()->get(MarketplaceListingRepository::class);

        return $repository->findByMarketplaceSku($companyId, MarketplaceType::OZON, $marketplaceSku)?->getId();
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function transactionListing(string $transactionId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT listing_id, listing_sku FROM ingest_financial_transactions WHERE id = :id',
            ['id' => $transactionId],
        );

        if (!is_array($row)) {
            throw new \RuntimeException('Transaction was not found.');
        }

        return [$row['listing_id'], $row['listing_sku']];
    }
}

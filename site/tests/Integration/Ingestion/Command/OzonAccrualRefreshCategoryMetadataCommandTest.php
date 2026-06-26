<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonAccrualRefreshCategoryMetadataCommandTest extends IntegrationTestCase
{
    public function testDryRunDoesNotMutateAndExecuteRefreshesOnlyCategoryMetadata(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $record = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-01:2026-06-07',
            fetchedAt: new \DateTimeImmutable('2026-06-08 03:00:00+00:00'),
            rows: [$this->itemFeeRow()],
        );
        $record->markNormalizationDone();

        $externalUpdatedAt = new \DateTimeImmutable('2026-06-08 03:00:00+00:00');
        $this->em->persist(new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            externalId: 'ozon:accrual-by-day:53675409101:item_fee:group-0:fee-0:type-1',
            externalUpdatedAt: $externalUpdatedAt,
            operationGroupId: Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:accrual-by-day:%s', $companyId, '53675409101'))->toString(),
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor(1234, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-01 00:00:00+03:00'),
            rawRecordId: $record->getId(),
            description: 'Existing Ozon accrual transaction',
            sourceData: [
                '_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY,
                '_ozon_category_code' => 'ozon_unknown_1',
                '_ozon_category_label' => 'Неизвестный type_id Ozon: 1',
                '_ozon_category_group' => 'Неизвестные категории Ozon',
                '_ozon_category_parent' => null,
                '_ozon_category_sort_order' => 9000,
                '_ozon_category_known' => false,
                'preserved' => 'yes',
            ],
            sourceTz: 'Europe/Moscow',
        ));
        $this->em->flush();

        $dryRunTester = $this->tester();
        $dryRunExit = $dryRunTester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-01',
            '--to' => '2026-06-07',
            '--shop-ref' => $connectionRef,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $dryRunExit, $dryRunTester->getDisplay());
        self::assertStringContainsString('Metadata refresh result', $dryRunTester->getDisplay());
        $afterDryRun = $this->transaction($companyId);
        $externalUpdatedAtBeforeExecute = $afterDryRun->getExternalUpdatedAt()->format(\DateTimeInterface::ATOM);
        self::assertSame('Неизвестный type_id Ozon: 1', $afterDryRun->getSourceData()['_ozon_category_label']);
        self::assertSame('Existing Ozon accrual transaction', $afterDryRun->getDescription());

        $executeTester = $this->tester();
        $executeExit = $executeTester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-01',
            '--to' => '2026-06-07',
            '--shop-ref' => $connectionRef,
            '--execute-inline' => true,
        ]);

        self::assertSame(Command::SUCCESS, $executeExit, $executeTester->getDisplay());
        self::assertStringContainsString('Metadata refresh result', $executeTester->getDisplay());
        $updated = $this->transaction($companyId);
        $sourceData = $updated->getSourceData();
        self::assertSame(1234, $updated->getAmountMinor());
        self::assertSame(TransactionDirection::OUT, $updated->getDirection());
        self::assertSame($externalUpdatedAtBeforeExecute, $updated->getExternalUpdatedAt()->format(\DateTimeInterface::ATOM));
        self::assertSame('yes', $sourceData['preserved']);
        self::assertSame('ozon_acquiring', $sourceData['_ozon_category_code']);
        self::assertSame('Эквайринг', $sourceData['_ozon_category_label']);
        self::assertSame('Услуги партнёров', $sourceData['_ozon_category_group']);
        self::assertSame(510, $sourceData['_ozon_category_sort_order']);
        self::assertTrue($sourceData['_ozon_category_known']);
        self::assertSame('Ozon: Эквайринг', $updated->getDescription());

        $secondTester = $this->tester();
        $secondExit = $secondTester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-01',
            '--to' => '2026-06-07',
            '--shop-ref' => $connectionRef,
            '--execute-inline' => true,
        ]);

        self::assertSame(Command::SUCCESS, $secondExit, $secondTester->getDisplay());
        self::assertStringContainsString('Refreshed Ozon category metadata on 0 canonical transactions.', $secondTester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:ingestion:ozon-accrual:refresh-category-metadata'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeRawRecord(
        string $companyId,
        string $connectionRef,
        string $externalId,
        \DateTimeImmutable $fetchedAt,
        array $rows,
    ): IngestRawRecord {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        return $facade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            externalId: $externalId,
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: $fetchedAt,
            rows: $rows,
        ))[0];
    }

    private function transaction(string $companyId): FinancialTransaction
    {
        $this->em->clear();

        /** @var FinancialTransactionRepository $repository */
        $repository = self::getContainer()->get(FinancialTransactionRepository::class);
        $transaction = $repository->findByNaturalKey(
            $companyId,
            IngestSource::OZON,
            'ozon:accrual-by-day:53675409101:item_fee:group-0:fee-0:type-1',
            TransactionType::FEE,
        );

        self::assertInstanceOf(FinancialTransaction::class, $transaction);

        return $transaction;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemFeeRow(): array
    {
        return [
            'accrual_id' => 53675409101,
            'date' => '2026-06-01',
            'unit_number' => '41774559-0885-1',
            'accrued_category' => 'ITEM',
            'item_fees' => [
                'fees' => [[
                    'fees' => [[
                        'type_id' => 1,
                        'name' => 'Acquiring',
                        'accrued' => ['amount' => '-12.34', 'currency' => 'RUB'],
                    ]],
                ]],
            ],
        ];
    }
}

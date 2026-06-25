<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\RefreshOzonAccrualCategoryMetadataAction;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayMapper;
use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\ExternalCategoryRepository;
use App\Ingestion\Repository\FinancialTransactionRepository;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class RefreshOzonAccrualCategoryMetadataActionTest extends IntegrationTestCase
{
    public function testFailedRowRollbackDoesNotLeakPendingUnknownCategoryIntoLaterFlush(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();

        $failedRecord = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-01:2026-06-01',
            rows: [
                $this->unknownFeeRow(777, 'UnknownBeforeFailure', '-10.00'),
            ],
        );
        $failedRecord->markNormalizationDone();

        $successfulRecord = $this->storeRawRecord(
            companyId: $companyId,
            connectionRef: $connectionRef,
            externalId: 'accrual-by-day:2026-06-02:2026-06-02',
            rows: [$this->itemFeeRow()],
        );
        $successfulRecord->markNormalizationDone();
        $this->em->persist($this->existingTransaction($companyId, $connectionRef, $successfulRecord));
        $this->em->flush();

        $action = $this->refreshActionWithFirstFlushFailure();
        $resultRows = $action->refresh($companyId, [
            ['id' => $failedRecord->getId()],
            ['id' => $successfulRecord->getId()],
        ], dryRun: false);

        self::assertSame('error', $resultRows[0]['status']);
        self::assertSame('done', $resultRows[1]['status']);
        self::assertSame(1, $resultRows[1]['updated']);

        /** @var ExternalCategoryRepository $categoryRepository */
        $categoryRepository = self::getContainer()->get(ExternalCategoryRepository::class);
        self::assertNull($categoryRepository->findByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            'type:777',
        ));
    }

    private function refreshActionWithFirstFlushFailure(): RefreshOzonAccrualCategoryMetadataAction
    {
        $entityManager = new class($this->em) extends EntityManagerDecorator {
            private int $flushCalls = 0;

            public function __construct(EntityManagerInterface $wrapped)
            {
                parent::__construct($wrapped);
            }

            public function flush(): void
            {
                ++$this->flushCalls;
                if (1 === $this->flushCalls) {
                    throw new \RuntimeException('Controlled refresh row failure after mapper side effects.');
                }

                parent::flush();
            }
        };

        return new RefreshOzonAccrualCategoryMetadataAction(
            connection: self::getContainer()->get(Connection::class),
            rawRecordRepository: self::getContainer()->get(IngestRawRecordRepository::class),
            financialTransactionRepository: self::getContainer()->get(FinancialTransactionRepository::class),
            rawStorageFacade: self::getContainer()->get(RawStorageFacade::class),
            mapper: self::getContainer()->get(OzonAccrualByDayMapper::class),
            entityManager: $entityManager,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function storeRawRecord(
        string $companyId,
        string $connectionRef,
        string $externalId,
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
            fetchedAt: new \DateTimeImmutable('2026-06-08 03:00:00+00:00'),
            rows: $rows,
        ))[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function unknownFeeRow(int|string $typeId, string $name, string $amount): array
    {
        return [
            'accrual_id' => $typeId,
            'date' => '2026-06-01',
            'unit_number' => 'unit-1',
            'accrued_category' => 'NON_ITEM',
            'non_item_fee' => [
                'type_id' => $typeId,
                'name' => $name,
                'accrued' => ['amount' => $amount, 'currency' => 'RUB'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemFeeRow(): array
    {
        return [
            'accrual_id' => 53675409101,
            'date' => '2026-06-02',
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

    private function existingTransaction(string $companyId, string $connectionRef, IngestRawRecord $record): FinancialTransaction
    {
        return new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            externalId: 'ozon:accrual-by-day:53675409101:item_fee:group-0:fee-0:type-1',
            externalUpdatedAt: new \DateTimeImmutable('2026-06-08 03:00:00+00:00'),
            operationGroupId: Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('%s:ozon:accrual-by-day:%s', $companyId, '53675409101'))->toString(),
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor(1234, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-02 00:00:00+03:00'),
            rawRecordId: $record->getId(),
            description: 'Existing Ozon accrual transaction',
            sourceData: [
                '_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY,
                '_ozon_category_code' => 'ozon_unknown_1',
                '_ozon_category_label' => 'Неизвестный type_id Ozon: 1',
                '_ozon_category_group' => 'Неизвестные категории Ozon',
                '_ozon_category_sort_order' => 9000,
                '_ozon_category_known' => false,
            ],
            sourceTz: 'Europe/Moscow',
        );
    }
}

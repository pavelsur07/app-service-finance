<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\Processor\OzonSalesRawProcessor;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Тесты версионирования external_order_id при повторных продажах
 * (sale → storno → re-sale по одному posting_number).
 */
final class OzonSalesRawProcessorVersioningTest extends TestCase
{
    /**
     * @var list<array{externalOrderId: string, rawDocumentId: string|null, totalRevenue: float}>
     */
    private array $persistedSales = [];

    /**
     * Sale → storno → re-sale по одному posting_number.
     * Третья операция должна получить суффикс _v2.
     */
    public function testResaleAfterStornoGetsVersionSuffix(): void
    {
        $processor = $this->buildProcessor(existingIds: []);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('X', +1584, '2026-02-24 10:00:00'),
            $this->makeOp('X', -1584, '2026-02-24 12:00:00'),
            $this->makeOp('X', +1584, '2026-02-24 14:00:00'),
        ]);

        self::assertSame(['X', 'X_storno', 'X_v2'], $this->getPersistedExternalIds());
    }

    /**
     * Повторное сторно: sale → storno → re-sale → storno.
     */
    public function testDoubleStornoGetsVersionSuffix(): void
    {
        $processor = $this->buildProcessor(existingIds: []);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('A', +1584, '2026-02-24 10:00:00'),
            $this->makeOp('A', -1584, '2026-02-24 12:00:00'),
            $this->makeOp('A', +1584, '2026-02-24 14:00:00'),
            $this->makeOp('A', -1584, '2026-02-24 16:00:00'),
        ]);

        self::assertSame(['A', 'A_storno', 'A_v2', 'A_storno_v2'], $this->getPersistedExternalIds());
    }

    /**
     * Базовый ключ уже есть в БД: не создаём искусственный _v2, строка пропускается.
     */
    public function testExistingInDbIsSkippedWithoutArtificialVersioning(): void
    {
        $processor = $this->buildProcessor(existingIds: ['A']);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('A', +1584, '2026-02-24 14:00:00'),
        ]);

        self::assertSame([], $this->getPersistedExternalIds());
    }

    /**
     * Обычный случай без повторов — поведение не меняется.
     */
    public function testNoDuplicatesNoVersionSuffix(): void
    {
        $processor = $this->buildProcessor(existingIds: []);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('A', +1584, '2026-02-24 10:00:00'),
            $this->makeOp('B', +2000, '2026-02-24 11:00:00'),
            $this->makeOp('C', +500,  '2026-02-24 12:00:00'),
        ]);

        self::assertSame(['A', 'B', 'C'], $this->getPersistedExternalIds());
    }

    /**
     * Только storno, без regular — создаётся одна запись.
     */
    public function testSingleStornoNoVersion(): void
    {
        $processor = $this->buildProcessor(existingIds: []);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('A', -1584, '2026-02-24 12:00:00'),
        ]);

        self::assertSame(['A_storno'], $this->getPersistedExternalIds());
    }

    /**
     * Неупорядоченные по дате входные данные — сортировка по operation_date ASC.
     */
    public function testUnsortedInputIsSortedChronologically(): void
    {
        $processor = $this->buildProcessor(existingIds: []);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('X', +1584, '2026-02-24 14:00:00'),
            $this->makeOp('X', -1584, '2026-02-24 12:00:00'),
            $this->makeOp('X', +1584, '2026-02-24 10:00:00'),
        ]);

        self::assertSame(['X', 'X_storno', 'X_v2'], $this->getPersistedExternalIds());
    }


    /**
     * Регрессия: повторная обработка одного raw_document_id не должна создавать _v2 дубли.
     *
     * resetPerRunState() моделирует новый invocation ProcessMarketplaceRawDocumentAction.
     * Внутри одного invocation cleanup должен выполниться только один раз, даже если
     * документ разбит на несколько batch.
     */
    public function testReprocessingSameRawDocumentIdDoesNotCreateDuplicates(): void
    {
        $processor = $this->buildProcessor(existingIds: []);

        $ops = [
            $this->makeOp('R-1', +1000, '2026-03-01 10:00:00'),
            $this->makeOp('R-2', +2000, '2026-03-01 11:00:00'),
        ];

        $processor->processBatch('company-1', MarketplaceType::OZON, $ops, '11111111-1111-1111-1111-111111111111');

        self::assertCount(2, $this->getPersistedExternalIds());
        self::assertSame(['R-1', 'R-2'], $this->getPersistedExternalIds());
        self::assertSame(3000.0, $this->getSumPersistedAccruals());
        self::assertSame([
            '11111111-1111-1111-1111-111111111111',
            '11111111-1111-1111-1111-111111111111',
        ], $this->getPersistedRawDocumentIds());

        $processor->resetPerRunState();
        $processor->processBatch('company-1', MarketplaceType::OZON, $ops, '11111111-1111-1111-1111-111111111111');

        self::assertCount(2, $this->getPersistedExternalIds(), 'Повторная обработка не должна увеличивать количество продаж.');
        self::assertSame(3000.0, $this->getSumPersistedAccruals(), 'Повторная обработка не должна увеличивать total revenue.');
        self::assertNotContains('R-1_v2', $this->getPersistedExternalIds());
        self::assertNotContains('R-2_v2', $this->getPersistedExternalIds());
        self::assertSame([
            '11111111-1111-1111-1111-111111111111',
            '11111111-1111-1111-1111-111111111111',
        ], $this->getPersistedRawDocumentIds());
    }

    /**
     * Ozon дополнил rawDoc новой строкой: повторная обработка должна заменить
     * результат raw_document_id на актуальный, без искусственных _v2 дублей.
     */
    public function testReprocessingSameRawDocumentIdWithNewRowReplacesResultWithoutVersionDuplicates(): void
    {
        $deleteByRawDocumentCalls = 0;
        $processor = $this->buildProcessor(
            existingIds: [],
            deleteByRawDocumentCalls: $deleteByRawDocumentCalls,
        );
        $rawDocumentId = '11111111-1111-1111-1111-111111111111';

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('A', +1000, '2026-03-01 10:00:00'),
            $this->makeOp('B', +2000, '2026-03-01 11:00:00'),
        ], $rawDocumentId);

        self::assertSame(1, $deleteByRawDocumentCalls);
        self::assertSame(['A', 'B'], $this->getPersistedExternalIds());
        self::assertSame(3000.0, $this->getSumPersistedAccruals());

        $processor->resetPerRunState();

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('A', +1000, '2026-03-01 10:00:00'),
            $this->makeOp('B', +2500, '2026-03-01 11:00:00'),
            $this->makeOp('C', +500, '2026-03-01 12:00:00'),
        ], $rawDocumentId);

        self::assertSame(2, $deleteByRawDocumentCalls);
        self::assertSame(['A', 'B', 'C'], $this->getPersistedExternalIds());
        self::assertSame(4000.0, $this->getSumPersistedAccruals());
        self::assertNotContains('A_v2', $this->getPersistedExternalIds());
        self::assertNotContains('B_v2', $this->getPersistedExternalIds());
        self::assertNotContains('C_v2', $this->getPersistedExternalIds());
    }

    /**
     * Две разные строки в одном raw-документе с одинаковым base ключом не теряются.
     */
    public function testDuplicateRowsInsideSingleBatchArePreserved(): void
    {
        $processor = $this->buildProcessor(existingIds: []);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('DUP', +1000, '2026-03-02 10:00:00'),
            $this->makeOp('DUP', +1100, '2026-03-02 10:05:00'),
        ], '11111111-1111-1111-1111-111111111111');

        self::assertSame(['DUP', 'DUP_v2'], $this->getPersistedExternalIds());
        self::assertSame(2100.0, $this->getSumPersistedAccruals());
    }

    public function testDuplicateRowsAcrossTwoBatchesInOneRunArePreserved(): void
    {
        $processor = $this->buildProcessor(existingIds: []);
        $rawDocumentId = '11111111-1111-1111-1111-111111111111';

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('DUP', +1000, '2026-03-02 10:00:00'),
        ], $rawDocumentId);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('DUP', +1100, '2026-03-02 10:05:00'),
        ], $rawDocumentId);

        self::assertSame(['DUP', 'DUP_v2'], $this->getPersistedExternalIds());
        self::assertSame(2100.0, $this->getSumPersistedAccruals());

        $processor->resetPerRunState();

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('DUP', +1000, '2026-03-02 10:00:00'),
        ], $rawDocumentId);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('DUP', +1100, '2026-03-02 10:05:00'),
        ], $rawDocumentId);

        self::assertSame(['DUP', 'DUP_v2'], $this->getPersistedExternalIds());
        self::assertSame(2100.0, $this->getSumPersistedAccruals());
        self::assertNotContains('DUP_v3', $this->getPersistedExternalIds());
        self::assertNotContains('DUP_v4', $this->getPersistedExternalIds());
    }

    public function testExistingBaseKeyDoesNotCreateArtificialVersionOnSecondRow(): void
    {
        $processor = $this->buildProcessor(existingIds: ['A']);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('A', +1000, '2026-03-03 10:00:00'),
            $this->makeOp('A', +1100, '2026-03-03 10:05:00'),
        ]);

        self::assertSame([], $this->getPersistedExternalIds());
        self::assertSame(0.0, $this->getSumPersistedAccruals());
        self::assertNotContains('A_v2', $this->getPersistedExternalIds());
    }

    public function testExistingStornoBaseKeyDoesNotCreateArtificialVersionOnSecondRow(): void
    {
        $processor = $this->buildProcessor(existingIds: ['A_storno']);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('A', -1000, '2026-03-03 10:00:00'),
            $this->makeOp('A', -1100, '2026-03-03 10:05:00'),
        ]);

        self::assertSame([], $this->getPersistedExternalIds());
        self::assertSame(0.0, $this->getSumPersistedAccruals());
        self::assertNotContains('A_storno_v2', $this->getPersistedExternalIds());
    }

    public function testReprocessThrowsWhenFinanceLockCoversRawDocumentPeriod(): void
    {
        $deleteByRawDocumentCalls = 0;
        $processor = $this->buildProcessor(
            existingIds: [],
            financeLockBefore: new \DateTimeImmutable('2026-03-15'),
            deleteByRawDocumentCalls: $deleteByRawDocumentCalls,
        );

        try {
            $processor->processBatch('company-1', MarketplaceType::OZON, [
                $this->makeOp('LOCK-1', +1000, '2026-03-01 10:00:00'),
            ], '11111111-1111-1111-1111-111111111111');

            self::fail('Expected DomainException for finance lock, but no exception was thrown.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('заблокирован для переобработки', $e->getMessage());
        }

        self::assertSame(0, $deleteByRawDocumentCalls);
        self::assertSame([], $this->getPersistedExternalIds());
    }

    public function testReprocessAllowedWhenFinanceLockIsEmptyOrOutsidePeriod(): void
    {
        $deleteByRawDocumentCalls = 0;
        $processor = $this->buildProcessor(
            existingIds: [],
            financeLockBefore: new \DateTimeImmutable('2026-02-15'),
            deleteByRawDocumentCalls: $deleteByRawDocumentCalls,
        );

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('UNLOCK-1', +1000, '2026-03-01 10:00:00'),
        ], '11111111-1111-1111-1111-111111111111');

        self::assertSame(1, $deleteByRawDocumentCalls);
        self::assertSame(['UNLOCK-1'], $this->getPersistedExternalIds());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<string> $existingIds
     */
    private function buildProcessor(
        array $existingIds,
        ?\DateTimeImmutable $financeLockBefore = null,
        int &$deleteByRawDocumentCalls = 0,
    ): OzonSalesRawProcessor
    {
        $this->persistedSales = [];

        $company = (new \ReflectionClass(Company::class))->newInstanceWithoutConstructor();
        $this->setProperty($company, 'id', 'company-1');
        $this->setProperty($company, 'financeLockBefore', $financeLockBefore?->setTime(0, 0));

        $listing = (new \ReflectionClass(MarketplaceListing::class))->newInstanceWithoutConstructor();
        $this->setProperty($listing, 'id', 'listing-1');
        $this->setProperty($listing, 'company', $company);

        $rawDoc = (new \ReflectionClass(MarketplaceRawDocument::class))->newInstanceWithoutConstructor();
        $this->setProperty($rawDoc, 'id', '11111111-1111-1111-1111-111111111111');
        $this->setProperty($rawDoc, 'company', $company);
        $this->setProperty($rawDoc, 'periodFrom', new \DateTimeImmutable('2026-03-01'));
        $this->setProperty($rawDoc, 'periodTo', new \DateTimeImmutable('2026-03-01'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static function (string $className, mixed $id) use ($company, $rawDoc): object|null {
            if ($className === Company::class) {
                return $company;
            }

            if ($className === MarketplaceRawDocument::class && $id === '11111111-1111-1111-1111-111111111111') {
                return $rawDoc;
            }

            return null;
        });
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof MarketplaceSale) {
                $this->persistedSales[] = [
                    'externalOrderId' => $entity->getExternalOrderId(),
                    'rawDocumentId' => $entity->getRawDocumentId(),
                    'totalRevenue' => (float) $entity->getTotalRevenue(),
                ];
            }
        });

        $existingMap = array_fill_keys($existingIds, true);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->method('getExistingExternalIds')
            ->willReturnCallback(function (string $companyId, array $ids) use ($existingMap): array {
                $dbMap = $existingMap;
                foreach ($this->getPersistedExternalIds() as $externalId) {
                    $dbMap[$externalId] = true;
                }

                $result = [];
                foreach ($ids as $id) {
                    if (isset($dbMap[$id])) {
                        $result[$id] = true;
                    }
                }

                return $result;
            });
        $saleRepository->method('deleteByRawDocument')
            ->willReturnCallback(function (Company $company, MarketplaceType $marketplace, string $rawDocumentId) use (&$deleteByRawDocumentCalls): int {
                $deleteByRawDocumentCalls++;
                return $this->deletePersistedSalesByRawDocumentId($rawDocumentId);
            });

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('executeStatement')->willReturn(0);

        $processor = (new \ReflectionClass(OzonSalesRawProcessor::class))->newInstanceWithoutConstructor();
        $this->setProperty($processor, 'em', $em);
        $this->setProperty($processor, 'connection', $connection);
        $this->setProperty($processor, 'saleRepository', $saleRepository);
        $this->setProperty($processor, 'logger', new NullLogger());
        $this->setProperty($processor, 'cleanedUpRawDocId', null);
        $this->setProperty($processor, 'perRunVersionCounters', []);
        $this->setProperty($processor, 'perRunBlockedBaseKeys', []);

        // OzonListingEnsureService — final class, create via reflection
        $listingEnsureRef = (new \ReflectionClass(\App\Marketplace\Application\Service\OzonListingEnsureService::class))->newInstanceWithoutConstructor();
        $this->setProperty($processor, 'listingEnsureService', $listingEnsureRef);

        $costPriceRef = (new \ReflectionClass(\App\Marketplace\Application\Service\MarketplaceCostPriceResolver::class))->newInstanceWithoutConstructor();
        $innerResolver = $this->createMock(\App\Marketplace\Inventory\CostPriceResolverInterface::class);
        $innerResolver->method('resolve')->willReturn('0.00');
        $this->setProperty($costPriceRef, 'costPriceResolver', $innerResolver);
        $this->setProperty($processor, 'costPriceResolver', $costPriceRef);

        $listingRepo = $this->createMock(\App\Marketplace\Repository\MarketplaceListingRepository::class);
        $listingRepo->method('findListingsBySkusIndexed')->willReturn(['SKU1' => $listing]);
        $this->setProperty($listingEnsureRef, 'listingRepository', $listingRepo);

        $upsertQuery = (new \ReflectionClass(\App\Marketplace\Infrastructure\Query\OzonListingUpsertQuery::class))->newInstanceWithoutConstructor();
        $this->setProperty($listingEnsureRef, 'upsertQuery', $upsertQuery);

        return $processor;
    }


    /** @return list<string> */
    private function getPersistedExternalIds(): array
    {
        return array_map(
            static fn (array $sale): string => $sale['externalOrderId'],
            $this->persistedSales,
        );
    }

    /** @return list<string|null> */
    private function getPersistedRawDocumentIds(): array
    {
        return array_map(
            static fn (array $sale): ?string => $sale['rawDocumentId'],
            $this->persistedSales,
        );
    }

    private function getSumPersistedAccruals(): float
    {
        return array_reduce(
            $this->persistedSales,
            static fn (float $sum, array $sale): float => $sum + $sale['totalRevenue'],
            0.0,
        );
    }

    private function deletePersistedSalesByRawDocumentId(string $rawDocumentId): int
    {
        $before = count($this->persistedSales);
        $this->persistedSales = array_values(array_filter(
            $this->persistedSales,
            static fn (array $sale): bool => $sale['rawDocumentId'] !== $rawDocumentId,
        ));

        return $before - count($this->persistedSales);
    }

    private function setProperty(object $obj, string $name, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $name);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }

    private function makeOp(string $postingNumber, float $accrual, string $date): array
    {
        return [
            'type' => 'orders',
            'operation_type' => $accrual < 0 ? 'OperationAgentStornoDeliveredToCustomer' : 'OperationAgentDeliveredToCustomer',
            'operation_id' => 'op_' . md5($postingNumber . $accrual . $date),
            'operation_date' => $date,
            'accruals_for_sale' => $accrual,
            'posting' => ['posting_number' => $postingNumber],
            'items' => [['sku' => 'SKU1', 'name' => 'Test Product']],
        ];
    }
}

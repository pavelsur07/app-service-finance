<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\Processor\OzonSalesRawProcessor;
use App\Marketplace\Entity\MarketplaceListing;
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
    /** @var list<string> */
    private array $persistedExternalIds = [];

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

        self::assertSame(['X', 'X_storno', 'X_v2'], $this->persistedExternalIds);
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

        self::assertSame(['A', 'A_storno', 'A_v2', 'A_storno_v2'], $this->persistedExternalIds);
    }

    /**
     * Базовый ключ уже есть в БД → первая regular получает _v2.
     */
    public function testExistingInDbStartsFromV2(): void
    {
        $processor = $this->buildProcessor(existingIds: ['A']);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('A', +1584, '2026-02-24 14:00:00'),
        ]);

        self::assertSame(['A_v2'], $this->persistedExternalIds);
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

        self::assertSame(['A', 'B', 'C'], $this->persistedExternalIds);
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

        self::assertSame(['A_storno'], $this->persistedExternalIds);
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

        self::assertSame(['X', 'X_storno', 'X_v2'], $this->persistedExternalIds);
    }

    /**
     * Existing в БД + повтор в батче.
     */
    public function testExistingPlusRepeatInBatch(): void
    {
        $processor = $this->buildProcessor(existingIds: ['A', 'A_storno']);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('A', +1584, '2026-02-25 10:00:00'),
            $this->makeOp('A', -1584, '2026-02-25 12:00:00'),
        ]);

        self::assertSame(['A_v2', 'A_storno_v2'], $this->persistedExternalIds);
    }

    /**
     * В БД уже есть A и A_v2 → следующая sale должна получить A_v3, не A_v2.
     */
    public function testExistingVersionedInDbBumpsToNextFree(): void
    {
        $processor = $this->buildProcessor(existingIds: ['A', 'A_v2']);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('A', +1584, '2026-02-26 10:00:00'),
        ]);

        self::assertSame(['A_v3'], $this->persistedExternalIds);
    }

    /**
     * В БД A, A_storno, A_v2, A_storno_v2 → следующая пара получает _v3.
     */
    public function testFullCycleExistingBumpsToV3(): void
    {
        $processor = $this->buildProcessor(existingIds: ['A', 'A_storno', 'A_v2', 'A_storno_v2']);

        $processor->processBatch('company-1', MarketplaceType::OZON, [
            $this->makeOp('A', +1584, '2026-02-27 10:00:00'),
            $this->makeOp('A', -1584, '2026-02-27 12:00:00'),
        ]);

        self::assertSame(['A_v3', 'A_storno_v3'], $this->persistedExternalIds);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<string> $existingIds
     */
    private function buildProcessor(array $existingIds): OzonSalesRawProcessor
    {
        $this->persistedExternalIds = [];

        $company = (new \ReflectionClass(Company::class))->newInstanceWithoutConstructor();
        $this->setProperty($company, 'id', 'company-1');

        $listing = (new \ReflectionClass(MarketplaceListing::class))->newInstanceWithoutConstructor();
        $this->setProperty($listing, 'id', 'listing-1');
        $this->setProperty($listing, 'company', $company);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($company);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof MarketplaceSale) {
                $this->persistedExternalIds[] = $entity->getExternalOrderId();
            }
        });

        $existingMap = array_fill_keys($existingIds, true);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->method('getExistingExternalIds')
            ->willReturnCallback(static function (string $companyId, array $ids) use ($existingMap): array {
                $result = [];
                foreach ($ids as $id) {
                    if (isset($existingMap[$id])) {
                        $result[$id] = true;
                    }
                }
                return $result;
            });

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);

        $processor = (new \ReflectionClass(OzonSalesRawProcessor::class))->newInstanceWithoutConstructor();
        $this->setProperty($processor, 'em', $em);
        $this->setProperty($processor, 'connection', $connection);
        $this->setProperty($processor, 'saleRepository', $saleRepository);
        $this->setProperty($processor, 'logger', new NullLogger());
        $this->setProperty($processor, 'cleanedUpRawDocId', null);

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

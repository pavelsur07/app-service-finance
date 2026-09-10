<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Application\Processor\OzonAccrualReturnsRawProcessor;
use App\Marketplace\Application\Service\MarketplaceCostPriceResolver;
use App\Marketplace\Application\Service\OzonListingEnsureService;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Inventory\CostPriceResolverInterface;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Возвраты из by-day. Возврат — это POSTING с отрицательным sale_amount;
 * установлено сверкой с «Реализацией» за июнь: 77 строк, суммы сошлись точно.
 */
final class OzonAccrualReturnsRawProcessorTest extends TestCase
{
    private const COMPANY_ID = 'company-1';
    private const RAW_DOC_ID = '11111111-1111-4111-8111-111111111111';

    /** @var array<int, array{externalId: string, quantity: int, refund: string}> */
    private array $persisted = [];

    public function testRefundAmountIsPositiveAndUsesSellerBasis(): void
    {
        // Та же база, что у легаси-строк в этой таблице: сверка за июнь сошлась
        // точно — 204 911.00 против суммы |sale_amount| 204 911 на 77 строках.
        // Сумма хранится положительной: знак несёт сам факт возврата.
        $this->processor()->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->returnRow()], self::RAW_DOC_ID);

        self::assertCount(1, $this->persisted);
        self::assertSame('2647.00', $this->persisted[0]['refund']);
        self::assertSame(1, $this->persisted[0]['quantity']);
    }

    public function testQuantityIsPositiveDespiteBothValuesBeingNegative(): void
    {
        // Ловушка: у возврата отрицательны и sale_amount, и seller_price,
        // их частное даёт +1. Количество обязано остаться положительным.
        $this->processor()->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->returnRow()], self::RAW_DOC_ID);

        self::assertGreaterThan(0, $this->persisted[0]['quantity']);
    }

    public function testAmountsThatDoNotMultiplyBackAreSkippedNotRounded(): void
    {
        // Зеркало регрессии из продаж. Частное 1.0005 попадало в прежний допуск
        // 0.001 и давало quantity = 1: возврат сторнировал бы себестоимость не
        // того числа единиц, что было продано.
        $row = $this->returnRow();
        $row['posting']['products'][0]['commission']['sale_amount']['amount'] = '-1000.50';
        $row['posting']['products'][0]['commission']['seller_price']['amount'] = '-1000.00';

        $this->processor()->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$row], self::RAW_DOC_ID);

        self::assertSame([], $this->persisted);
    }

    public function testSaleRowIsIgnoredByReturnsProcessor(): void
    {
        $this->processor()->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->saleRow()], self::RAW_DOC_ID);

        self::assertSame([], $this->persisted);
    }

    public function testRepeatedRunCreatesNoDuplicate(): void
    {
        $this->processor()->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->returnRow()], self::RAW_DOC_ID);
        $id = $this->persisted[0]['externalId'];

        $this->persisted = [];
        $this->processor([$id])->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->returnRow()], self::RAW_DOC_ID);

        self::assertSame([], $this->persisted);
    }

    public function testReturnLooksUpOriginalSaleByPostingNumber(): void
    {
        // Себестоимость возврата обязана сторнировать ТУ ЖЕ, что была у продажи.
        // Связь — номер отправления: он общий у продажи и её возврата, а
        // accrual_id у них разные.
        $lookedUp = null;
        $processor = $this->processor([], $lookedUp);
        $processor->processBatch(self::COMPANY_ID, MarketplaceType::OZON, [$this->returnRow()], self::RAW_DOC_ID);

        self::assertNotNull($lookedUp);
        self::assertStringContainsString('-product-0', $lookedUp);
        self::assertStringNotContainsString('-return-', $lookedUp, 'Искать надо ключ продажи, а не возврата.');
    }

    public function testProcessorClaimsOnlyByDayReturns(): void
    {
        $processor = $this->processor();

        self::assertTrue($processor->supports(StagingRecordType::RETURN, MarketplaceType::OZON, '', MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY));
        self::assertFalse($processor->supports(StagingRecordType::RETURN, MarketplaceType::OZON, '', MarketplaceRawFormat::OZON_TRANSACTION_LIST_V3));
        self::assertFalse($processor->supports(StagingRecordType::SALE, MarketplaceType::OZON, '', MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY));
    }

    /**
     * @return array<string, mixed>
     */
    private function returnRow(): array
    {
        return $this->fixture()[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function saleRow(): array
    {
        return $this->fixture()[0];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fixture(): array
    {
        $data = json_decode(
            (string) file_get_contents(__DIR__.'/../../../../Fixtures/Marketplace/Ozon/accrual_by_day_cases.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $data['accruals'];
    }

    /**
     * @param list<string> $existingIds
     */
    private function processor(array $existingIds = [], ?string &$lookedUpSaleId = null): OzonAccrualReturnsRawProcessor
    {
        $this->persisted = [];

        $company = (new \ReflectionClass(Company::class))->newInstanceWithoutConstructor();
        $this->setProperty($company, 'id', self::COMPANY_ID);

        $listing = (new \ReflectionClass(MarketplaceListing::class))->newInstanceWithoutConstructor();
        $this->setProperty($listing, 'id', 'listing-1');
        $this->setProperty($listing, 'company', $company);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($company);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof MarketplaceReturn) {
                $this->persisted[] = [
                    'externalId' => (string) $entity->getExternalReturnId(),
                    'quantity' => $entity->getQuantity(),
                    'refund' => $entity->getRefundAmount(),
                ];
            }
        });

        $listings = (new \ReflectionClass(OzonListingEnsureService::class))->newInstanceWithoutConstructor();
        $listingRepository = $this->createMock(MarketplaceListingRepository::class);
        $listingRepository->method('findListingsBySkusIndexed')->willReturnCallback(
            static fn (Company $c, MarketplaceType $m, array $skus): array => array_fill_keys($skus, $listing),
        );
        $this->setProperty($listings, 'listingRepository', $listingRepository);

        $costPrice = (new \ReflectionClass(MarketplaceCostPriceResolver::class))->newInstanceWithoutConstructor();
        $inner = $this->createMock(CostPriceResolverInterface::class);
        $inner->method('resolve')->willReturn('0.00');
        $this->setProperty($costPrice, 'costPriceResolver', $inner);

        $returnRepository = $this->createMock(MarketplaceReturnRepository::class);
        $returnRepository->method('getExistingExternalIds')->willReturn(array_fill_keys($existingIds, true));

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->method('findByMarketplaceOrderAndSku')->willReturnCallback(
            function (Company $c, MarketplaceType $m, string $orderId, string $sku) use (&$lookedUpSaleId): ?MarketplaceSale {
                $lookedUpSaleId = $orderId;

                return null;
            },
        );

        return new OzonAccrualReturnsRawProcessor(
            $em,
            $listings,
            $returnRepository,
            $saleRepository,
            $costPrice,
            new NullLogger(),
        );
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Ingestion\Facade\OzonAccrualCategoryFacade;
use App\Marketplace\Application\Processor\OzonAccrualCostsRawProcessor;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceCostExistingExternalIdsQuery;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Затраты из by-day. Услуги разбираются по ИМЕНИ из справочника
 * /v1/finance/accrual/types через фасад Ingestion: собственный каталог
 * Marketplace писался под русские названия снятого v3 и не разбирает из
 * английских кодов справочника ни одного.
 */
final class OzonAccrualCostsRawProcessorTest extends TestCase
{
    private const COMPANY_ID = 'company-1';
    private const RAW_DOC_ID = '11111111-1111-4111-8111-111111111111';

    /** @var list<array{externalId: string, code: string, amount: string}> */
    private array $persisted = [];

    public function testCommissionBecomesPositiveCostWithLegacyCategoryCode(): void
    {
        // Легаси хранит затраты положительными, смысл несут категория и
        // описание. Комиссия — не услуга со справочным type_id, поэтому её код
        // берётся тот же, что у легаси-пути, а не через фасад.
        $this->process();

        $commission = $this->find('commission');
        self::assertSame('ozon_sale_commission', $commission['code']);
        self::assertSame('1379.54', $commission['amount']);
    }

    public function testKnownServiceIsResolvedByDictionaryName(): void
    {
        $this->process();

        // type_id 32 -> Logistic -> Логистика
        $logistics = $this->find('type-32');
        self::assertSame('ozon_logistics', $logistics['code']);
        self::assertSame('118.00', $logistics['amount']);
    }

    public function testUnknownServiceStillBecomesCostInVisibleQueue(): void
    {
        // type_id 29 -> LastMileCourier, в каталоге его нет. Затрата обязана
        // появиться с категорией «Требует классификации», а не потеряться:
        // потерянная затрата занижает расходы и завышает прибыль.
        $this->process();

        $unknown = $this->find('type-29');
        self::assertStringStartsWith('ozon_unknown_', $unknown['code']);
    }

    public function testItemAndNonItemFeesAreCharged(): void
    {
        $this->process();

        // ITEM: type_id 1 -> Acquiring; NON_ITEM: type_id 96 -> AcceleratedReviewCollection
        self::assertSame('ozon_acquiring', $this->find('item-fee')['code']);
        self::assertSame('20.83', $this->find('item-fee')['amount']);
        self::assertSame('ozon_accelerated_reviews', $this->find('non-item')['code']);
        self::assertSame('512.40', $this->find('non-item')['amount']);
    }

    public function testRepeatedRunCreatesNoDuplicates(): void
    {
        $this->process();
        $ids = array_column($this->persisted, 'externalId');
        self::assertNotSame([], $ids);

        $this->persisted = [];
        $this->process(existingIds: $ids);

        self::assertSame([], $this->persisted);
    }

    public function testProcessorClaimsOnlyByDayCosts(): void
    {
        $processor = $this->processor([]);

        self::assertTrue($processor->supports(StagingRecordType::COST, MarketplaceType::OZON, '', MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY));
        self::assertFalse($processor->supports(StagingRecordType::COST, MarketplaceType::OZON, '', MarketplaceRawFormat::OZON_TRANSACTION_LIST_V3));
        self::assertFalse($processor->supports(StagingRecordType::SALE, MarketplaceType::OZON, '', MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY));
    }

    /**
     * @param list<string> $existingIds
     */
    private function process(array $existingIds = []): void
    {
        $this->processor($existingIds)->process(self::COMPANY_ID, self::RAW_DOC_ID);
    }

    /**
     * @return array{externalId: string, code: string, amount: string}
     */
    private function find(string $needle): array
    {
        foreach ($this->persisted as $row) {
            if (str_contains($row['externalId'], $needle)) {
                return $row;
            }
        }

        self::fail(sprintf('Затрата с фрагментом ключа "%s" не найдена. Есть: %s', $needle, implode(', ', array_column($this->persisted, 'externalId'))));
    }

    /**
     * @param list<string> $existingIds
     */
    private function processor(array $existingIds): OzonAccrualCostsRawProcessor
    {
        $this->persisted = [];

        $company = (new \ReflectionClass(Company::class))->newInstanceWithoutConstructor();
        $this->setProperty($company, 'id', self::COMPANY_ID);

        $document = (new \ReflectionClass(MarketplaceRawDocument::class))->newInstanceWithoutConstructor();
        $this->setProperty($document, 'id', self::RAW_DOC_ID);
        $this->setProperty($document, 'company', $company);
        $this->setProperty($document, 'rawData', $this->payload());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn (string $class): object => MarketplaceRawDocument::class === $class ? $document : $company,
        );
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof MarketplaceCost) {
                $this->persisted[] = [
                    'externalId' => (string) $entity->getExternalId(),
                    'code' => (string) $entity->getCategory()?->getCode(),
                    'amount' => $entity->getAmount(),
                ];
            }
        });

        // MarketplaceCostCategoryResolver и MarketplaceCostExistingExternalIdsQuery
        // объявлены final: собираются настоящими поверх подменённых зависимостей.
        $categoryRepository = $this->createMock(MarketplaceCostCategoryRepository::class);
        $categoryRepository->method('findOneBy')->willReturn(null);
        $categoryResolver = new MarketplaceCostCategoryResolver($categoryRepository, $em);

        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn($existingIds);
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $existingQuery = new MarketplaceCostExistingExternalIdsQuery($connection);

        return new OzonAccrualCostsRawProcessor(
            $em,
            new OzonAccrualCategoryFacade(),
            $categoryResolver,
            $existingQuery,
            new NullLogger(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $accruals = json_decode(
            (string) file_get_contents(__DIR__.'/../../../../Fixtures/Marketplace/Ozon/accrual_by_day_cases.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        $types = json_decode(
            (string) file_get_contents(__DIR__.'/../../../../Fixtures/Marketplace/Ozon/accrual_types.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        $map = [];
        foreach ($types['accrual_types'] as $type) {
            $map[(string) $type['id']] = $type['name'];
        }

        return ['accruals' => $accruals['accruals'], 'service_types' => $map];
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }
}

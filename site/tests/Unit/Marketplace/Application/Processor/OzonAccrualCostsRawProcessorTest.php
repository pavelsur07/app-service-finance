<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Ingestion\Facade\OzonAccrualCategoryFacade;
use App\Marketplace\Application\Processor\OzonAccrualCostsRawProcessor;
use App\Marketplace\Application\Service\ByDayRowReplacement;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Application\Service\OzonListingEnsureService;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceCostExistingExternalIdsQuery;
use App\Marketplace\Infrastructure\Query\MonthCloseAdvisoryLockQuery;
use App\Marketplace\Infrastructure\Query\OzonListingUpsertQuery;
use App\Marketplace\Infrastructure\Query\PreliminaryRebuildFlagQuery;
use App\Marketplace\Infrastructure\Query\UnlinkDocumentRowsQuery;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
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
    private const LISTING_ID = '22222222-2222-4222-8222-222222222222';
    private const RAW_DOC_ID = '11111111-1111-4111-8111-111111111111';

    private ?MarketplaceListing $listing = null;

    protected function setUp(): void
    {
        $listing = (new \ReflectionClass(MarketplaceListing::class))->newInstanceWithoutConstructor();
        $this->setProperty($listing, 'id', self::LISTING_ID);
        $this->listing = $listing;
    }

    /** @var list<array{externalId: string, code: string, amount: string, operationType: string|null, listingId: string|null}> */
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

    public function testCostsAlreadyOwnedByAnotherDocumentAreNotDuplicated(): void
    {
        // Строки другого документа удалением этого не сносятся, поэтому остаются
        // в выборке и после него. Заводить их второй раз нельзя.
        $this->process();
        $ids = array_column($this->persisted, 'externalId');
        self::assertNotSame([], $ids);

        $this->persisted = [];
        $this->processWithForeignCosts($ids);

        self::assertSame([], $this->persisted);
    }

    public function testRefreshRecreatesItsOwnRowsInsteadOfLeavingTheDayEmpty(): void
    {
        // Регрессия. Известные external_id читались ДО удаления, поэтому на
        // повторном прогоне цикл видел в них собственные, только что снесённые
        // строки и пропускал их как существующие: замена выходила пустой, а
        // затраты дня исчезали насовсем. Выборка идёт после удаления, и она
        // собственных строк уже не видит — значит день пересобирается целиком.
        $this->process();
        $first = $this->persisted;
        self::assertNotSame([], $first);

        // Второй прогон стартует с этими строками в базе — они принадлежат этому
        // же документу, и удаление их снимает. Мок это моделирует: до удаления
        // выборка их видит, после — уже нет.
        $this->persisted = [];
        $this->process(existingIds: array_column($first, 'externalId'));

        self::assertSame($first, $this->persisted, 'Повторный прогон обязан пересобрать те же затраты, а не оставить день пустым.');
    }

    public function testEmptyRefreshStillClearsPreviousCosts(): void
    {
        // День, из которого Ozon убрал начисления, обязан остаться без затрат.
        // Ранний выход до транзакции сохранял бы прежние строки навсегда.
        $deletes = 0;
        $processor = $this->processorWithPayload(
            ['accruals' => [], 'service_types' => []],
            $deletes,
        );

        self::assertSame(0, $processor->process(self::COMPANY_ID, self::RAW_DOC_ID));
        self::assertSame(1, $deletes, 'Пустой разбор обязан пройти через удаление прежних затрат.');
    }

    public function testPositiveCommissionIsStornoNotAnExtraExpense(): void
    {
        // Регрессия. В фикстуре два начисления комиссии: -1379.54 (удержание) и
        // +1217.62 (возврат по отменённому заказу). Обе суммы хранятся
        // положительными, поэтому вид операции несёт только operation_type.
        // Без него UnprocessedCostsQuery считает возврат обычной затратой и
        // увеличивает расходы вместо того, чтобы их уменьшить.
        $this->process();

        $byId = [];
        foreach ($this->persisted as $row) {
            $byId[$row['externalId']] = $row;
        }

        $charge = $byId['ozon-accrual-50000000001-commission-product-0'] ?? null;
        $storno = $byId['ozon-accrual-50000000002-commission-product-0'] ?? null;

        self::assertNotNull($charge);
        self::assertNotNull($storno);
        self::assertSame('charge', $charge['operationType'], 'Удержание комиссии — начисление.');
        self::assertSame('storno', $storno['operationType'], 'Возврат комиссии — сторно.');
        self::assertSame('1217.62', $storno['amount'], 'Сумма хранится положительной, знак несёт operation_type.');
    }

    public function testServiceCostsAreChargesByDefault(): void
    {
        $this->process();

        $services = array_filter(
            $this->persisted,
            static fn (array $row): bool => str_contains($row['externalId'], '-type-'),
        );

        self::assertNotSame([], $services);
        foreach ($services as $row) {
            self::assertSame('charge', $row['operationType'], $row['externalId'].' — удержание Ozon, а не сторно.');
        }
    }

    public function testPostingCostsAreBoundToTheListingOfTheirProduct(): void
    {
        // Регрессия. Затраты by-day не привязывались к листингу вообще: на проде
        // за 08.09 и 09.09 из 9638 строк привязано было 0, тогда как легаси-дни
        // держат 97–99%. Комиссия и услуги доставки относятся к конкретному
        // товару отправления и обязаны нести его листинг.
        $this->process();

        $posting = array_filter(
            $this->persisted,
            static fn (array $row): bool => str_contains($row['externalId'], '-commission-product-')
                || str_contains($row['externalId'], '-product-0-service-'),
        );

        self::assertNotSame([], $posting);
        foreach ($posting as $row) {
            self::assertSame(self::LISTING_ID, $row['listingId'], $row['externalId'].' — товарная затрата без листинга.');
        }
    }

    public function testItemFeeTakesSkuFromItsGroup(): void
    {
        // sku лежит на группе item_fees.fees[], а не на самой услуге: если брать
        // его с услуги, привязка теряется молча.
        $this->process();

        $itemFees = array_filter(
            $this->persisted,
            static fn (array $row): bool => str_contains($row['externalId'], '-item-fee-'),
        );

        self::assertNotSame([], $itemFees);
        foreach ($itemFees as $row) {
            self::assertSame(self::LISTING_ID, $row['listingId'], $row['externalId'].' — услуга по товару без листинга.');
        }
    }

    public function testNonItemFeeStaysGeneralWithoutListing(): void
    {
        // NON_ITEM по устройству ответа sku не несёт: это затрата кабинета
        // целиком. Привязать её к листингу нельзя, и выдумывать привязку нельзя.
        $this->process();

        $nonItem = array_filter(
            $this->persisted,
            static fn (array $row): bool => str_contains($row['externalId'], '-non-item'),
        );

        self::assertNotSame([], $nonItem);
        foreach ($nonItem as $row) {
            self::assertNull($row['listingId'], $row['externalId'].' — общая затрата не должна получать листинг.');
        }
    }

    public function testNonEmptyContainerFeesFailLoudlyInsteadOfBeingSkipped(): void
    {
        // За месяц выгрузки поле не было непустым ни разу, разбора для него нет.
        // Молча пропустить блок значит занизить расходы и не заметить этого.
        $payload = $this->payload();
        $payload['accruals'][0]['container_fees'] = [['type_id' => 108, 'accrued' => ['amount' => '-100.00']]];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/container_fees/');

        $this->processorWithPayload($payload)->process(self::COMPANY_ID, self::RAW_DOC_ID);
    }

    public function testLockedPeriodProducesNoCostsAtAll(): void
    {
        // Регрессия. Раньше при блокировке пропускалось только удаление, а разбор
        // шёл дальше: затрата с ранее не виденным external_id всё равно легла бы
        // в закрытый на замок месяц.
        $processor = $this->processor([], null, new \DateTimeImmutable('2026-09-30'));

        self::assertSame(0, $processor->process(self::COMPANY_ID, self::RAW_DOC_ID));
        self::assertSame([], $this->persisted);
    }

    public function testDocumentWithoutEnvelopeFailsLoudlyInsteadOfReportingZero(): void
    {
        // Документ более ранней версии загрузчика разобрать нечем: справочника
        // услуг в нём нет. Молчаливый ноль записал бы шаг затрат как успешный
        // и занизил расходы.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/refetch the day/');

        $this->processorWithPayload([['accrual_id' => 1]])->process(self::COMPANY_ID, self::RAW_DOC_ID);
    }

    public function testDeleteOfOldCostsIsInsideTheTransactionAndRollsBackOnFailure(): void
    {
        // Регрессия. Прежде прежние затраты сносил вызывающий, до этого метода:
        // DELETE ложился отдельной транзакцией и фиксировался сразу, а Messenger
        // handler в транзакцию Doctrine не оборачивает. Сбой при разборе оставлял
        // документ вовсе без затрат, и исчерпанные ретраи закрепляли потерю.
        $calls = [];

        $company = (new \ReflectionClass(Company::class))->newInstanceWithoutConstructor();
        $this->setProperty($company, 'id', self::COMPANY_ID);

        $document = (new \ReflectionClass(MarketplaceRawDocument::class))->newInstanceWithoutConstructor();
        $this->setProperty($document, 'id', self::RAW_DOC_ID);
        $this->setProperty($document, 'company', $company);
        $this->setProperty($document, 'periodFrom', new \DateTimeImmutable('2026-09-09'));
        $this->setProperty($document, 'rawData', $this->payload());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn (string $class): object => MarketplaceRawDocument::class === $class ? $document : $company,
        );
        $em->method('flush')->willThrowException(new \RuntimeException('boom'));
        $em->expects(self::once())->method('clear');

        $categoryRepository = $this->createMock(MarketplaceCostCategoryRepository::class);
        $categoryRepository->method('findOneBy')->willReturn(null);
        $categoryResolver = new MarketplaceCostCategoryResolver($categoryRepository, $em);

        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'begin';
        });
        $connection->method('executeStatement')->willReturnCallback(static function () use (&$calls): int {
            $calls[] = 'delete';

            return 1;
        });
        $connection->method('rollBack')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'rollback';
        });
        $connection->expects(self::never())->method('commit');

        $processor = new OzonAccrualCostsRawProcessor(
            $em,
            $connection,
            new OzonAccrualCategoryFacade(),
            $categoryResolver,
            new MarketplaceCostExistingExternalIdsQuery($connection),
            $this->listingEnsureService($this->listing),
            $this->rowUnlinker(),
            new NullLogger(),
        );

        try {
            $processor->process(self::COMPANY_ID, self::RAW_DOC_ID);
            self::fail('Сбой разбора обязан пробросить исключение.');
        } catch (\RuntimeException) {
            // ожидаемо
        }

        self::assertSame(['begin', 'delete', 'rollback'], $calls, 'Удаление обязано идти внутри транзакции и откатываться вместе с ней.');
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
     * Затраты, принадлежащие другому документу: удаление этого документа их не
     * трогает, поэтому выборка возвращает их и после удаления.
     *
     * @param list<string> $externalIds
     */
    private function processWithForeignCosts(array $externalIds): void
    {
        $this->persisted = [];

        $company = (new \ReflectionClass(Company::class))->newInstanceWithoutConstructor();
        $this->setProperty($company, 'id', self::COMPANY_ID);

        $document = (new \ReflectionClass(MarketplaceRawDocument::class))->newInstanceWithoutConstructor();
        $this->setProperty($document, 'id', self::RAW_DOC_ID);
        $this->setProperty($document, 'company', $company);
        $this->setProperty($document, 'periodFrom', new \DateTimeImmutable('2026-09-09'));
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
                    'operationType' => $entity->getOperationType()?->value,
                    'listingId' => $entity->getListing()?->getId(),
                ];
            }
        });

        $categoryRepository = $this->createMock(MarketplaceCostCategoryRepository::class);
        $categoryRepository->method('findOneBy')->willReturn(null);
        $categoryResolver = new MarketplaceCostCategoryResolver($categoryRepository, $em);

        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn($externalIds);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $connection->method('executeStatement')->willReturn(0);

        (new OzonAccrualCostsRawProcessor(
            $em,
            $connection,
            new OzonAccrualCategoryFacade(),
            $categoryResolver,
            new MarketplaceCostExistingExternalIdsQuery($connection),
            $this->listingEnsureService($this->listing),
            $this->rowUnlinker(),
            new NullLogger(),
        ))->process(self::COMPANY_ID, self::RAW_DOC_ID);
    }

    /**
     * @return array{externalId: string, code: string, amount: string, operationType: string|null, listingId: string|null}
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
     * @param array<array-key, mixed> $payload
     */
    private function processorWithPayload(array $payload, ?int &$deletes = null): OzonAccrualCostsRawProcessor
    {
        return $this->processor([], $payload, null, $deletes);
    }

    /**
     * @param list<string> $existingIds
     * @param array<string, mixed>|null $payloadOverride
     */
    private function processor(array $existingIds, ?array $payloadOverride = null, ?\DateTimeImmutable $lockBefore = null, ?int &$deletes = null): OzonAccrualCostsRawProcessor
    {
        $this->persisted = [];

        $company = (new \ReflectionClass(Company::class))->newInstanceWithoutConstructor();
        $this->setProperty($company, 'id', self::COMPANY_ID);

        $document = (new \ReflectionClass(MarketplaceRawDocument::class))->newInstanceWithoutConstructor();
        $this->setProperty($document, 'id', self::RAW_DOC_ID);
        $this->setProperty($document, 'company', $company);
        $this->setProperty($document, 'periodFrom', new \DateTimeImmutable('2026-09-09'));
        $this->setProperty($document, 'rawData', $payloadOverride ?? $this->payload());

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
                    'operationType' => $entity->getOperationType()?->value,
                    'listingId' => $entity->getListing()?->getId(),
                ];
            }
        });

        // MarketplaceCostCategoryResolver и MarketplaceCostExistingExternalIdsQuery
        // объявлены final: собираются настоящими поверх подменённых зависимостей.
        $categoryRepository = $this->createMock(MarketplaceCostCategoryRepository::class);
        $categoryRepository->method('findOneBy')->willReturn(null);
        $categoryResolver = new MarketplaceCostCategoryResolver($categoryRepository, $em);

        // Мок ведёт себя как база, а не как константа: удаление действительно
        // убирает строки этого документа из выборки известных external_id.
        // Без этого тест не отличил бы выборку до удаления от выборки после, а
        // разница между ними — это разница между пересобранным днём и стёртым.
        // Состояние держит объект, а не переменная по ссылке: замыкания делят
        // один и тот же экземпляр, и статанализ не сводит флаг к константе.
        /** @var \ArrayObject<string, bool> $state */
        $state = new \ArrayObject(['deleted' => false]);

        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturnCallback(
            static fn (): array => true === $state['deleted'] ? [] : $existingIds,
        );
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $connection->method('executeStatement')->willReturnCallback(static function () use (&$deletes, $state): int {
            $state['deleted'] = true;
            if (null !== $deletes) {
                ++$deletes;
            }

            return 0;
        });
        $existingQuery = new MarketplaceCostExistingExternalIdsQuery($connection);

        return new OzonAccrualCostsRawProcessor(
            $em,
            $connection,
            new OzonAccrualCategoryFacade(),
            $categoryResolver,
            $existingQuery,
            $this->listingEnsureService($this->listing),
            $this->rowUnlinker($lockBefore),
            new NullLogger(),
        );
    }

    /**
     * Заглушка снятия предварительных привязок: сам разбор от неё не зависит,
     * а её поведение проверяется в тесте Action и в собственном тесте сервиса.
     */
    private function rowUnlinker(?\DateTimeImmutable $lockBefore = null): ByDayRowReplacement
    {
        $query = (new \ReflectionClass(UnlinkDocumentRowsQuery::class))->newInstanceWithoutConstructor();
        $this->setProperty($query, 'connection', $this->createMock(Connection::class));

        $repository = $this->createMock(MarketplaceMonthCloseRepository::class);
        $repository->method('findByPeriod')->willReturn(null);

        // Блокировки периода нет: её граница проверяется отдельным тестом сервиса.
        // CompanyFacade объявлен final — собирается рефлексией.
        $companyFacade = (new \ReflectionClass(CompanyFacade::class))->newInstanceWithoutConstructor();
        $lockedCompany = $this->createMock(Company::class);
        $lockedCompany->method('getFinanceLockBefore')->willReturn($lockBefore);
        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('findById')->willReturn($lockedCompany);
        (new \ReflectionProperty($companyFacade, 'repository'))->setValue($companyFacade, $companyRepository);

        $lock = (new \ReflectionClass(MonthCloseAdvisoryLockQuery::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($lock, 'connection'))->setValue($lock, $this->createMock(Connection::class));

        $rebuildFlag = (new \ReflectionClass(PreliminaryRebuildFlagQuery::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($rebuildFlag, 'connection'))->setValue($rebuildFlag, $this->createMock(Connection::class));

        return new ByDayRowReplacement($repository, $companyFacade, $query, $lock, $rebuildFlag, new NullLogger());
    }

    /**
     * OzonListingEnsureService объявлен final: собирается рефлексией с
     * подменённым репозиторием, как в тесте процессора продаж.
     */
    private function listingEnsureService(?MarketplaceListing $listing): OzonListingEnsureService
    {
        $service = (new \ReflectionClass(OzonListingEnsureService::class))->newInstanceWithoutConstructor();

        $repository = $this->createMock(MarketplaceListingRepository::class);
        $repository->method('findListingsBySkusIndexed')->willReturnCallback(
            static fn (Company $c, MarketplaceType $m, array $skus): array => null === $listing
                ? []
                : array_fill_keys($skus, $listing),
        );
        $this->setProperty($service, 'listingRepository', $repository);
        // Ветка «листинга нет» доходит до создания недостающих, поэтому её
        // зависимости тоже подменяются: иначе тест падал бы на неинициализированном
        // свойстве вместо проверяемого поведения.
        $this->setProperty($service, 'upsertQuery', (new \ReflectionClass(OzonListingUpsertQuery::class))->newInstanceWithoutConstructor());
        $this->setProperty($service, 'entityManager', $this->createMock(EntityManagerInterface::class));

        return $service;
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

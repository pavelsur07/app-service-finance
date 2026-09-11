<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorInterface;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistryInterface;
use App\Marketplace\Application\Service\ByDayRowReplacement;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\Contract\RowClassifierInterface;
use App\Marketplace\Infrastructure\Normalizer\RowClassifierRegistryInterface;
use App\Marketplace\Infrastructure\Query\MonthCloseAdvisoryLockQuery;
use App\Marketplace\Infrastructure\Query\UnlinkDocumentRowsQuery;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use App\Marketplace\Repository\MarketplaceCostRepository;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use App\Shared\Service\AppLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ProcessMarketplaceRawDocumentActionTest extends TestCase
{
    /**
     * ByDayRowReplacement и UnlinkDocumentRowsQuery объявлены final:
     * настоящий объект собирается рефлексией с подменёнными зависимостями, как
     * это уже делается для других final-сервисов в тестах модуля.
     */
    private function createRowUnlinker(?MarketplaceMonthClose $monthClose = null, ?int &$unlinked = null, ?\DateTimeImmutable $lockBefore = null): ByDayRowReplacement
    {
        $query = (new \ReflectionClass(UnlinkDocumentRowsQuery::class))->newInstanceWithoutConstructor();
        $queryConnection = $this->createMock(Connection::class);
        $queryConnection->method('executeStatement')->willReturnCallback(static function () use (&$unlinked): int {
            if (null !== $unlinked) {
                ++$unlinked;
            }

            return 1;
        });
        (new \ReflectionProperty($query, 'connection'))->setValue($query, $queryConnection);

        $repository = $this->createMock(MarketplaceMonthCloseRepository::class);
        $repository->method('findByPeriod')->willReturn($monthClose);

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

        return new ByDayRowReplacement($repository, $companyFacade, $query, $lock, new NullLogger());
    }

    private function createCostCategoryResolver(): MarketplaceCostCategoryResolver
    {
        return new MarketplaceCostCategoryResolver(
            $this->createMock(MarketplaceCostCategoryRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
    }

    public function testThrowsWhenDocumentNotFound(): void
    {
        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn(null);

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $this->createMock(MarketplaceRawProcessorRegistryInterface::class),
            $repository,
            $this->createMock(MarketplaceSaleRepository::class),
            $this->createMock(MarketplaceReturnRepository::class),
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Raw document not found: missing-id');

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'missing-id', 'costs'));
    }

    public function testThrowsOnUnknownKind(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([['x' => 1]]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $classifier = $this->createMock(RowClassifierInterface::class);
        $classifier->method('classify')->willReturn(StagingRecordType::SALE);
        $classifierRegistry = $this->createMock(RowClassifierRegistryInterface::class);
        $classifierRegistry->method('get')->willReturn($classifier);

        $action = new ProcessMarketplaceRawDocumentAction(
            $classifierRegistry,
            $this->createMock(MarketplaceRawProcessorRegistryInterface::class),
            $repository,
            $this->createMock(MarketplaceSaleRepository::class),
            $this->createMock(MarketplaceReturnRepository::class),
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown kind "unknown"');

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'unknown'));
    }

    /**
     * DoD Stage 3: легаси-документ уходит тому же процессору, что и прежде, и
     * даёт тот же результат. Проверяется на уровне Action, а не supports():
     * тест на supports() остался бы зелёным, перестань Action извлекать или
     * передавать формат.
     */
    public function testLegacyOzonDocumentGoesToLegacyProcessorWithItsFormat(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([['x' => 1]]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);
        $document->method('getApiEndpoint')->willReturn('ozon::v3/finance/transaction/list');
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor->expects(self::once())->method('process')->with('company-1', 'doc-1')->willReturn(7);

        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry
            ->expects(self::once())
            ->method('get')
            ->with(
                StagingRecordType::COST,
                MarketplaceType::OZON,
                'costs',
                MarketplaceRawFormat::OZON_TRANSACTION_LIST_V3,
            )
            ->willReturn($processor);

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $processorRegistry,
            $repository,
            $this->createMock(MarketplaceSaleRepository::class),
            $this->createMock(MarketplaceReturnRepository::class),
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $result = $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'costs'));

        self::assertSame(7, $result->processedRows);
    }

    /**
     * Регрессия на скользящее окно. Ozon правит начисления задним числом, и окно
     * существует ровно ради этих правок. Процессор пропускает уже известный
     * external_id, поэтому без удаления прежних строк документа исправленное
     * начисление не обновилось бы, а отменённое не исчезло бы: окно ловило бы
     * правки, а таблицы оставались бы прежними.
     */
    #[DataProvider('byDayReplacementCases')]
    public function testByDayDocumentReplacesItsOwnRowsBeforeProcessing(string $kind): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn(['accruals' => []]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);
        $document->method('getApiEndpoint')->willReturn(MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $returnRepository = $this->createMock(MarketplaceReturnRepository::class);

        if ('sales' === $kind) {
            $saleRepository->expects(self::once())->method('deleteByRawDocument')
                ->with($company, MarketplaceType::OZON, 'doc-1')->willReturn(0);
            $returnRepository->expects(self::never())->method('deleteByRawDocument');
        } else {
            $returnRepository->expects(self::once())->method('deleteByRawDocument')
                ->with($company, MarketplaceType::OZON, 'doc-1')->willReturn(0);
            $saleRepository->expects(self::never())->method('deleteByRawDocument');
        }

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $this->createMock(MarketplaceRawProcessorRegistryInterface::class),
            $repository,
            $saleRepository,
            $returnRepository,
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', $kind));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function byDayReplacementCases(): iterable
    {
        yield 'sales' => ['sales'];
        yield 'returns' => ['returns'];
    }

    /**
     * Регрессия на потерю строк. Messenger не оборачивает handler в транзакцию
     * Doctrine, поэтому сбой между удалением и записью оставил бы документ вовсе
     * без продаж, а исчерпанные ретраи закрепили бы потерю.
     */
    public function testFailedReplacementRollsBackInsteadOfLeavingTheDocumentEmpty(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn(['accruals' => [['x' => 1]]]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);
        $document->method('getApiEndpoint')->willReturn(MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->expects(self::once())->method('deleteByRawDocument')->willReturn(1);

        $classifier = $this->createMock(RowClassifierInterface::class);
        $classifier->method('classify')->willReturn(StagingRecordType::SALE);
        $classifierRegistry = $this->createMock(RowClassifierRegistryInterface::class);
        $classifierRegistry->method('get')->willReturn($classifier);

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor->method('processBatch')->willThrowException(new \RuntimeException('boom'));
        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry->method('get')->willReturn($processor);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');

        $action = new ProcessMarketplaceRawDocumentAction(
            $classifierRegistry,
            $processorRegistry,
            $repository,
            $saleRepository,
            $this->createMock(MarketplaceReturnRepository::class),
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(),
            $connection,
            $this->createMock(AppLogger::class),
        );

        $this->expectException(\RuntimeException::class);

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'sales'));
    }

    /**
     * Регрессия. Замена строк не трогает те, у которых проставлен document_id, а
     * текущий месяц закрывается предварительно каждую ночь: на проде 11.09.2026
     * из 915 продаж за 08.09 было привязано 787. Без снятия предварительной
     * привязки правка Ozon по ним не доехала бы до следующего reopen, а гейт
     * свежести при этом был бы зелёным.
     */
    public function testPreliminaryLinksAreRemovedBeforeReplacement(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn(['accruals' => []]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);
        $document->method('getApiEndpoint')->willReturn(MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value);
        $document->method('getPeriodFrom')->willReturn(new \DateTimeImmutable('2026-09-08'));
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $monthClose = new MarketplaceMonthClose(
            '22222222-2222-4222-8222-222222222222',
            '19621cff-b028-45d9-9193-11f47ad9a8b2',
            MarketplaceType::OZON,
            2026,
            9,
        );
        $monthClose->setSettings(['last_close_was_preliminary' => ['sales_returns' => true]]);
        (new \ReflectionProperty($monthClose, 'stageSalesReturnsPLDocumentIds'))->setValue($monthClose, ['doc-sales']);

        $unlinked = 0;

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $this->createMock(MarketplaceRawProcessorRegistryInterface::class),
            $repository,
            $this->createMock(MarketplaceSaleRepository::class),
            $this->createMock(MarketplaceReturnRepository::class),
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker($monthClose, $unlinked),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'sales'));

        self::assertSame(1, $unlinked, 'Привязка к предварительному закрытию обязана сниматься до удаления строк.');
    }

    /**
     * Регрессия. Заблокированный период обязан остаться неизменным целиком: не
     * только прежние строки не удаляются, но и новые не появляются. Иначе
     * начисление с ранее не виденным external_id легло бы в закрытый на замок
     * месяц — причём кодом, который про блокировку уже знает.
     */
    public function testLockedPeriodIsNotTouchedAtAll(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn(['accruals' => [['x' => 1]]]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);
        $document->method('getApiEndpoint')->willReturn(MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value);
        $document->method('getPeriodFrom')->willReturn(new \DateTimeImmutable('2026-06-15'));
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->expects(self::never())->method('deleteByRawDocument');

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor->expects(self::never())->method('processBatch');
        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry->method('get')->willReturn($processor);

        $unlinked = 0;

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $processorRegistry,
            $repository,
            $saleRepository,
            $this->createMock(MarketplaceReturnRepository::class),
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(null, $unlinked, new \DateTimeImmutable('2026-06-30')),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $result = $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'sales'));

        self::assertSame(0, $result->processedRows);
        self::assertSame(0, $unlinked, 'В заблокированном периоде нельзя снимать привязку.');
    }

    /**
     * Легаси-документ снятого формата под это правило не подпадает: его строки
     * не перезагружаются, и удаление стёрло бы историю, которую нечем восстановить.
     */
    public function testLegacyDocumentRowsAreNotDeleted(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);
        $document->method('getApiEndpoint')->willReturn('ozon::v3/finance/transaction/list');
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->expects(self::never())->method('deleteByRawDocument');

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $this->createMock(MarketplaceRawProcessorRegistryInterface::class),
            $repository,
            $saleRepository,
            $this->createMock(MarketplaceReturnRepository::class),
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'sales'));
    }

    /**
     * Незнакомый непустой endpoint — это не «формат не передали», а документ,
     * про который мы ничего не знаем. Отдать его легаси-процессору значит
     * молча создать финансовые записи по чужому формату, поэтому падаем до
     * любой обработки и без ретраев.
     */
    public function testUnknownApiEndpointFailsLoudlyInsteadOfFallingBackToLegacy(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);
        $document->method('getApiEndpoint')->willReturn('ozon::v9/finance/something-new');
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry->expects(self::never())->method('get');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $processorRegistry,
            $repository,
            $this->createMock(MarketplaceSaleRepository::class),
            $this->createMock(MarketplaceReturnRepository::class),
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(),
            $connection,
            $this->createMock(AppLogger::class),
        );

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('ozon::v9/finance/something-new');

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'costs'));
    }

    public function testOrdinaryWbCostsUseProcessDirectlyWithoutReportingForceConflict(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([['x' => 1]]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::WILDBERRIES);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor
            ->expects(self::once())
            ->method('process')
            ->with('company-1', 'doc-1')
            ->willReturn(42);
        $processor
            ->expects(self::never())
            ->method('processBatch');

        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry
            ->expects(self::once())
            ->method('get')
            ->with(StagingRecordType::COST, MarketplaceType::WILDBERRIES)
            ->willReturn($processor);

        $costRepository = $this->createMock(MarketplaceCostRepository::class);
        $costRepository->expects(self::never())->method('countDocumentLinkedByRawDocument');

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $processorRegistry,
            $repository,
            $this->createMock(MarketplaceSaleRepository::class),
            $this->createMock(MarketplaceReturnRepository::class),
            $costRepository,
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $result = $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'costs'));

        self::assertSame(42, $result->processedRows);
        self::assertSame(0, $result->preservedLinkedRows);
    }

    public function testForceReprocessWbSalesCallsDeleteByRawDocument(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([['x' => 1]]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::WILDBERRIES);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->expects(self::once())
            ->method('deleteByRawDocument')
            ->with($company, MarketplaceType::WILDBERRIES, 'doc-1');

        $returnRepository = $this->createMock(MarketplaceReturnRepository::class);
        $returnRepository->expects(self::never())->method('deleteByRawDocument');

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor->expects(self::once())->method('processBatch');
        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry->method('get')->willReturn($processor);

        $classifier = $this->createMock(RowClassifierInterface::class);
        $classifier->method('classify')->willReturn(StagingRecordType::SALE);
        $classifierRegistry = $this->createMock(RowClassifierRegistryInterface::class);
        $classifierRegistry->method('get')->willReturn($classifier);

        $action = new ProcessMarketplaceRawDocumentAction(
            $classifierRegistry,
            $processorRegistry,
            $repository,
            $saleRepository,
            $returnRepository,
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-1', 'sales', true));
    }

    public function testForceReprocessWbReturnsCallsDeleteByRawDocument(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([['x' => 1]]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::WILDBERRIES);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->expects(self::never())->method('deleteByRawDocument');

        $returnRepository = $this->createMock(MarketplaceReturnRepository::class);
        $returnRepository->expects(self::once())
            ->method('deleteByRawDocument')
            ->with($company, MarketplaceType::WILDBERRIES, 'doc-2');

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor->expects(self::once())->method('processBatch');
        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry->method('get')->willReturn($processor);

        $classifier = $this->createMock(RowClassifierInterface::class);
        $classifier->method('classify')->willReturn(StagingRecordType::RETURN);
        $classifierRegistry = $this->createMock(RowClassifierRegistryInterface::class);
        $classifierRegistry->method('get')->willReturn($classifier);

        $action = new ProcessMarketplaceRawDocumentAction(
            $classifierRegistry,
            $processorRegistry,
            $repository,
            $saleRepository,
            $returnRepository,
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-2', 'returns', true));
    }

    public function testWbCostsPartialReprocessSucceedsAndReportsPreservedLinkedRows(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getMarketplace')->willReturn(MarketplaceType::WILDBERRIES);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor->expects(self::once())->method('process')->with('company-1', 'doc-costs')->willReturn(7);
        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry->expects(self::once())->method('get')->with(StagingRecordType::COST, MarketplaceType::WILDBERRIES)->willReturn($processor);

        $costRepository = $this->createMock(MarketplaceCostRepository::class);
        $costRepository->expects(self::once())
            ->method('countDocumentLinkedByRawDocument')
            ->with($company, MarketplaceType::WILDBERRIES, 'doc-costs')
            ->willReturn(2);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement');

        $logger = $this->createMock(AppLogger::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'WB raw document partially reprocessed; linked rows preserved',
                self::callback(static fn (array $context): bool => 2 === $context['preservedLinkedRows']
                    && 7 === $context['processedRows']
                    && 'costs' === $context['kind']),
            );

        $action = new ProcessMarketplaceRawDocumentAction(
            $this->createMock(RowClassifierRegistryInterface::class),
            $processorRegistry,
            $repository,
            $this->createMock(MarketplaceSaleRepository::class),
            $this->createMock(MarketplaceReturnRepository::class),
            $costRepository,
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(),
            $connection,
            $logger,
        );

        // Частичная переобработка — успех: строки обработаны, сохранённые linked rows
        // возвращены счётчиком, а не исключением.
        $result = $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-costs', 'costs', true));

        self::assertSame(7, $result->processedRows);
        self::assertSame(2, $result->preservedLinkedRows);
    }

    public function testForceReprocessOzonDoesNotCallWbDeleteByRawDocument(): void
    {
        $document = $this->createMock(MarketplaceRawDocument::class);
        $document->method('getRawData')->willReturn([]);
        $document->method('getMarketplace')->willReturn(MarketplaceType::OZON);
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn('company-1');
        $document->method('getCompany')->willReturn($company);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        $saleRepository = $this->createMock(MarketplaceSaleRepository::class);
        $saleRepository->expects(self::never())->method('deleteByRawDocument');

        $returnRepository = $this->createMock(MarketplaceReturnRepository::class);
        $returnRepository->expects(self::never())->method('deleteByRawDocument');

        $processor = $this->createMock(MarketplaceRawProcessorInterface::class);
        $processor->expects(self::never())->method('process');
        $processor->expects(self::never())->method('processBatch');

        $processorRegistry = $this->createMock(MarketplaceRawProcessorRegistryInterface::class);
        $processorRegistry->expects(self::once())->method('get')->with(StagingRecordType::SALE, MarketplaceType::OZON)->willReturn($processor);

        $classifierRegistry = $this->createMock(RowClassifierRegistryInterface::class);
        $classifierRegistry->expects(self::once())->method('get');

        $action = new ProcessMarketplaceRawDocumentAction(
            $classifierRegistry,
            $processorRegistry,
            $repository,
            $saleRepository,
            $returnRepository,
            $this->createMock(MarketplaceCostRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createCostCategoryResolver(),
            $this->createRowUnlinker(),
            $this->createMock(Connection::class),
            $this->createMock(AppLogger::class),
        );

        $action(new ProcessMarketplaceRawDocumentCommand('company-1', 'doc-3', 'sales', true));
    }
}

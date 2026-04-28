<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application;

use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Application\CloseMonthStageAction;
use App\Marketplace\Application\Command\CloseMonthStageCommand;
use App\Marketplace\Application\Command\PreflightMonthCloseCommand;
use App\Marketplace\Application\DTO\PreflightCheck;
use App\Marketplace\Application\DTO\PreflightResult;
use App\Marketplace\Application\MonthClosePreflightAction;
use App\Marketplace\Application\Source\MarketplaceDataSourceInterface;
use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\UnprocessedCostsQuery;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CloseMonthStageActionTest extends TestCase
{
    public static function preliminaryModesProvider(): array
    {
        return [
            'final-close' => [false],
            'preliminary-close' => [true],
        ];
    }

    /**
     * @dataProvider preliminaryModesProvider
     */
    public function testThrowsDomainExceptionWhenAllEntriesAreEmpty(bool $preliminary): void
    {
        $preflightAction = $this->createMock(MonthClosePreflightAction::class);
        $preflightAction
            ->expects(self::once())
            ->method('__invoke')
            ->with(self::isInstanceOf(PreflightMonthCloseCommand::class))
            ->willReturn(new PreflightResult([
                PreflightCheck::warning('costs_count', 'Затраты за период', 'Нет затрат за выбранный период'),
            ]));

        $monthCloseRepository = $this->createMock(MarketplaceMonthCloseRepository::class);
        $monthCloseRepository
            ->expects(self::once())
            ->method('findByPeriod')
            ->willReturn(null);
        $monthCloseRepository
            ->expects(self::never())
            ->method('save');

        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository
            ->expects(self::once())
            ->method('findByCompanyIdAndMarketplace')
            ->willReturn(null);

        $financeFacade = $this->createMock(FinanceFacade::class);
        $financeFacade
            ->expects(self::never())
            ->method('createPLDocument');

        $unprocessedCostsQuery = $this->createMock(UnprocessedCostsQuery::class);
        $unprocessedCostsQuery
            ->expects(self::never())
            ->method('getControlSum');

        $dataSource = $this->createMock(MarketplaceDataSourceInterface::class);
        $dataSource->method('getStage')->willReturn(CloseStage::SALES_RETURNS);
        $dataSource->method('supports')->willReturn(true);
        $dataSource->method('getSourceId')->willReturn('sales_returns');
        $dataSource->method('getUnprocessedEntries')->willReturn([]);
        $dataSource
            ->expects(self::never())
            ->method('markProcessed');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $fn): array => $fn());

        $action = new CloseMonthStageAction(
            $preflightAction,
            $monthCloseRepository,
            $connectionRepository,
            $financeFacade,
            $unprocessedCostsQuery,
            $entityManager,
            new NullLogger(),
            [$dataSource],
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Закрытие невозможно: нет строк для создания документа ОПиУ по этапу.');

        $action($this->buildCommand($preliminary));
    }

    /**
     * @dataProvider preliminaryModesProvider
     */
    public function testCreatesDocumentAndClosesStageWhenEntriesExist(bool $preliminary): void
    {
        $preflightAction = $this->createMock(MonthClosePreflightAction::class);
        $preflightAction
            ->expects(self::once())
            ->method('__invoke')
            ->with(self::isInstanceOf(PreflightMonthCloseCommand::class))
            ->willReturn(new PreflightResult([
                PreflightCheck::warning('ozon_realization', 'Реализация Ozon', 'Реализация Ozon не загружена'),
            ]));

        $savedMonthClose = null;
        $monthCloseRepository = $this->createMock(MarketplaceMonthCloseRepository::class);
        $monthCloseRepository
            ->expects(self::once())
            ->method('findByPeriod')
            ->willReturn(null);
        $monthCloseRepository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (MarketplaceMonthClose $monthClose) use (&$savedMonthClose): void {
                $savedMonthClose = $monthClose;
            });

        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository
            ->expects(self::once())
            ->method('findByCompanyIdAndMarketplace')
            ->willReturn(null);

        $financeFacade = $this->createMock(FinanceFacade::class);
        $financeFacade
            ->expects(self::once())
            ->method('createPLDocument')
            ->willReturn('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        $unprocessedCostsQuery = $this->createMock(UnprocessedCostsQuery::class);
        $unprocessedCostsQuery
            ->expects(self::never())
            ->method('getControlSum');

        $dataSource = $this->createMock(MarketplaceDataSourceInterface::class);
        $dataSource->method('getStage')->willReturn(CloseStage::SALES_RETURNS);
        $dataSource->method('supports')->willReturn(true);
        $dataSource->method('getSourceId')->willReturn('sales_returns');
        $dataSource->method('getLabel')->willReturn('Продажи и возвраты');
        $dataSource->method('getUnprocessedEntries')->willReturn([
            [
                'pl_category_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'total_amount' => '1200.50',
                'description' => 'Продажи за месяц',
                'is_negative' => false,
                'sort_order' => 10,
            ],
        ]);
        $dataSource
            ->expects(self::once())
            ->method('markProcessed')
            ->with(
                '11111111-1111-4111-8111-111111111111',
                MarketplaceType::OZON->value,
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                '2026-02-01',
                '2026-02-28',
            );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $fn): array => $fn());

        $action = new CloseMonthStageAction(
            $preflightAction,
            $monthCloseRepository,
            $connectionRepository,
            $financeFacade,
            $unprocessedCostsQuery,
            $entityManager,
            new NullLogger(),
            [$dataSource],
        );

        $result = $action($this->buildCommand($preliminary));

        self::assertSame(['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'], $result['plDocumentIds']);
        self::assertInstanceOf(MarketplaceMonthClose::class, $savedMonthClose);
        self::assertTrue($savedMonthClose->isStageClosed(CloseStage::SALES_RETURNS));
        self::assertSame(
            ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
            $savedMonthClose->getStagePLDocumentIds(CloseStage::SALES_RETURNS),
        );
    }

    private function buildCommand(bool $preliminary): CloseMonthStageCommand
    {
        return new CloseMonthStageCommand(
            companyId: '11111111-1111-4111-8111-111111111111',
            marketplace: MarketplaceType::OZON->value,
            year: 2026,
            month: 2,
            stage: CloseStage::SALES_RETURNS->value,
            actorUserId: '22222222-2222-4222-8222-222222222222',
            preliminary: $preliminary,
        );
    }
}

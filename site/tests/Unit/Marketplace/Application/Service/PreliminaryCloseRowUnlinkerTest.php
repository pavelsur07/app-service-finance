<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\PreliminaryCloseRowUnlinker;
use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\UnlinkDocumentRowsQuery;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Граница замены строк: предварительное закрытие её не блокирует, окончательное
 * блокирует.
 *
 * Замер на проде 11.09.2026, из-за которого это и понадобилось: из 915 продаж за
 * 08.09 к предварительному ОПиУ было привязано 787, из 4710 затрат — 1702.
 * Правка Ozon по ним не доезжала вовсе, а перезалив чинил бы только
 * непривязанное меньшинство.
 */
final class PreliminaryCloseRowUnlinkerTest extends TestCase
{
    private const COMPANY_ID = '19621cff-b028-45d9-9193-11f47ad9a8b2';
    private const RAW_DOC_ID = '11111111-1111-4111-8111-111111111111';
    private const DAY = '2026-09-08';

    public function testPreliminaryStageRowsAreUnlinked(): void
    {
        $captured = [];
        $unlinker = $this->unlinker($this->monthClose(preliminary: [CloseStage::COSTS->value => true]), $captured);

        $unlinked = $unlinker->unlinkCosts(self::COMPANY_ID, MarketplaceType::OZON, new \DateTimeImmutable(self::DAY), self::RAW_DOC_ID);

        self::assertSame(1, $unlinked);
        self::assertSame(['doc-costs'], $captured['documentIds'] ?? null);
        self::assertSame(self::RAW_DOC_ID, $captured['rawDocumentId'] ?? null, 'Снимать привязку можно только со строк перезагруженного дня, а не всего месяца.');
    }

    public function testFinallyClosedStageIsLeftAlone(): void
    {
        // Окончательно закрытый период правке не подлежит: его строки остаются
        // привязанными, и замена их не тронет.
        $captured = [];
        $unlinker = $this->unlinker($this->monthClose(preliminary: [CloseStage::COSTS->value => false]), $captured);

        $unlinked = $unlinker->unlinkCosts(self::COMPANY_ID, MarketplaceType::OZON, new \DateTimeImmutable(self::DAY), self::RAW_DOC_ID);

        self::assertSame(0, $unlinked);
        self::assertSame([], $captured, 'К базе обращаться не за чем, если этап закрыт окончательно.');
    }

    public function testPreliminaryFlagOfOneStageDoesNotUnlockAnother(): void
    {
        // Флаг ведётся per-stage именно затем, чтобы предварительность затрат не
        // маскировала финальное закрытие продаж того же месяца.
        $captured = [];
        $unlinker = $this->unlinker($this->monthClose(preliminary: [CloseStage::COSTS->value => true]), $captured);

        $unlinked = $unlinker->unlinkSales(self::COMPANY_ID, MarketplaceType::OZON, new \DateTimeImmutable(self::DAY), self::RAW_DOC_ID);

        self::assertSame(0, $unlinked);
        self::assertSame([], $captured);
    }

    public function testMissingMonthCloseIsNotAnError(): void
    {
        // Месяц ещё ни разу не закрывали — привязок нет, снимать нечего.
        $captured = [];
        $unlinker = $this->unlinker(null, $captured);

        self::assertSame(0, $unlinker->unlinkCosts(self::COMPANY_ID, MarketplaceType::OZON, new \DateTimeImmutable(self::DAY), self::RAW_DOC_ID));
    }

    public function testStageWithoutDocumentIdsIsSkipped(): void
    {
        $captured = [];
        $monthClose = $this->monthClose(preliminary: [CloseStage::COSTS->value => true], costDocumentIds: []);
        $unlinker = $this->unlinker($monthClose, $captured);

        self::assertSame(0, $unlinker->unlinkCosts(self::COMPANY_ID, MarketplaceType::OZON, new \DateTimeImmutable(self::DAY), self::RAW_DOC_ID));
        self::assertSame([], $captured);
    }

    /**
     * @param array<string, bool> $preliminary
     * @param list<string>|null $costDocumentIds
     */
    private function monthClose(array $preliminary, ?array $costDocumentIds = ['doc-costs']): MarketplaceMonthClose
    {
        $monthClose = new MarketplaceMonthClose(
            '22222222-2222-4222-8222-222222222222',
            self::COMPANY_ID,
            MarketplaceType::OZON,
            2026,
            9,
        );
        $monthClose->setSettings(['last_close_was_preliminary' => $preliminary]);
        (new \ReflectionProperty($monthClose, 'stageCostsPLDocumentIds'))->setValue($monthClose, $costDocumentIds);
        (new \ReflectionProperty($monthClose, 'stageSalesReturnsPLDocumentIds'))->setValue($monthClose, ['doc-sales']);

        return $monthClose;
    }

    /**
     * @param array<string, mixed> $captured
     */
    private function unlinker(?MarketplaceMonthClose $monthClose, array &$captured): PreliminaryCloseRowUnlinker
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): int {
                $captured = $params;

                return 1;
            },
        );

        $query = (new \ReflectionClass(UnlinkDocumentRowsQuery::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($query, 'connection'))->setValue($query, $connection);

        $repository = $this->createMock(MarketplaceMonthCloseRepository::class);
        $repository->method('findByPeriod')->willReturn($monthClose);

        return new PreliminaryCloseRowUnlinker($repository, $query, new NullLogger());
    }
}

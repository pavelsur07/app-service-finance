<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Marketplace\Application\Service\ByDayRowReplacement;
use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\MonthCloseStageStatus;
use App\Marketplace\Infrastructure\Query\MonthCloseAdvisoryLockQuery;
use App\Marketplace\Infrastructure\Query\UnlinkDocumentRowsQuery;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Границы замены строк перезагруженного дня.
 *
 * Замер на проде 11.09.2026, из-за которого это понадобилось: из 915 продаж за
 * 08.09 к предварительному ОПиУ было привязано 787, из 4710 затрат — 1702.
 * Правка Ozon по ним не доезжала вовсе, а перезалив чинил бы только
 * непривязанное меньшинство.
 */
final class ByDayRowReplacementTest extends TestCase
{
    private const COMPANY_ID = '19621cff-b028-45d9-9193-11f47ad9a8b2';
    private const RAW_DOC_ID = '11111111-1111-4111-8111-111111111111';
    private const MOCK_NOW = '2026-09-08 10:00:00';

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::MOCK_NOW);
    }

    public function testPreliminaryStageRowsAreUnlinkedAndReplacementAllowed(): void
    {
        $captured = [];
        $replacement = $this->service($this->monthClose([CloseStage::COSTS->value => true]), null, $captured);

        self::assertTrue($replacement->prepareCosts(self::COMPANY_ID, MarketplaceType::OZON, $this->today(), self::RAW_DOC_ID));
        self::assertSame(['doc-costs'], $captured['documentIds'] ?? null);
        self::assertSame(self::RAW_DOC_ID, $captured['rawDocumentId'] ?? null, 'Снимать привязку можно только со строк перезагруженного дня, а не всего месяца.');
    }

    public function testPastMonthIsNotReplaced(): void
    {
        // У прошлого месяца нет ночного пересбора предварительного ОПиУ: снятая
        // привязка не восстановилась бы, и документ остался бы расходиться с
        // источником. Такие месяцы остаются неизменными.
        $captured = [];
        $replacement = $this->service($this->monthClose([CloseStage::COSTS->value => true]), null, $captured);

        self::assertFalse($replacement->prepareCosts(self::COMPANY_ID, MarketplaceType::OZON, new \DateTimeImmutable('2026-08-31'), self::RAW_DOC_ID));
        self::assertSame([], $captured);
    }

    public function testPreliminaryCloseWithoutStoredDocumentIdsIsRefused(): void
    {
        // У старых и повреждённых закрытий id документов не сохранены. Снять
        // привязку нельзя: переоткрытие не найдёт, какой документ удалять, и
        // рядом с новым останется старый — двойной счёт.
        $captured = [];
        $monthClose = $this->monthClose([CloseStage::COSTS->value => true], costDocumentIds: []);
        $replacement = $this->service($monthClose, null, $captured);

        self::assertFalse($replacement->prepareCosts(self::COMPANY_ID, MarketplaceType::OZON, $this->today(), self::RAW_DOC_ID));
        self::assertSame([], $captured);
    }

    public function testFinallyClosedStageForbidsReplacementEntirely(): void
    {
        // Окончательно закрытый этап неизменен так же, как заблокированный
        // период. Разрешить замену значило бы впустить в него строку с ранее не
        // виденным external_id, которой нет в итоговом документе ОПиУ.
        $captured = [];
        $monthClose = $this->monthClose([CloseStage::COSTS->value => false], status: MonthCloseStageStatus::CLOSED);
        $replacement = $this->service($monthClose, null, $captured);

        self::assertFalse($replacement->prepareCosts(self::COMPANY_ID, MarketplaceType::OZON, $this->today(), self::RAW_DOC_ID));
        self::assertSame([], $captured);
    }

    public function testOpenStageAllowsReplacementWithoutUnlinking(): void
    {
        // Этап ещё не закрывали — привязок нет, снимать нечего, заменять можно.
        $captured = [];
        $monthClose = $this->monthClose([], status: MonthCloseStageStatus::PENDING);
        $replacement = $this->service($monthClose, null, $captured);

        self::assertTrue($replacement->prepareCosts(self::COMPANY_ID, MarketplaceType::OZON, $this->today(), self::RAW_DOC_ID));
        self::assertSame([], $captured);
    }

    public function testReopenedStageAllowsReplacement(): void
    {
        $captured = [];
        $monthClose = $this->monthClose([CloseStage::COSTS->value => false], status: MonthCloseStageStatus::REOPENED);
        $replacement = $this->service($monthClose, null, $captured);

        self::assertTrue($replacement->prepareCosts(self::COMPANY_ID, MarketplaceType::OZON, $this->today(), self::RAW_DOC_ID));
        self::assertSame([], $captured);
    }

    public function testPreliminaryFlagOfOneStageDoesNotUnlockAnother(): void
    {
        // Флаг ведётся per-stage именно затем, чтобы предварительность затрат не
        // маскировала финальное закрытие продаж того же месяца: продажи здесь
        // закрыты окончательно, и замена по ним запрещена, хотя затраты
        // предварительные.
        $captured = [];
        $replacement = $this->service($this->monthClose([CloseStage::COSTS->value => true]), null, $captured);

        self::assertFalse($replacement->prepareSales(self::COMPANY_ID, MarketplaceType::OZON, $this->today(), self::RAW_DOC_ID));
        self::assertTrue($replacement->prepareCosts(self::COMPANY_ID, MarketplaceType::OZON, $this->today(), self::RAW_DOC_ID));
        self::assertSame(['doc-costs'], $captured['documentIds'] ?? null);
    }

    public function testLockedPeriodForbidsReplacementEntirely(): void
    {
        // Ту же границу держит ReopenMonthStageAction. Заблокированный период не
        // меняется вовсе: ни привязка не снимается, ни строки не удаляются.
        $captured = [];
        $replacement = $this->service(
            $this->monthClose([CloseStage::COSTS->value => true]),
            new \DateTimeImmutable('2026-09-30'),
            $captured,
        );

        self::assertFalse($replacement->prepareCosts(self::COMPANY_ID, MarketplaceType::OZON, $this->today(), self::RAW_DOC_ID));
        self::assertSame([], $captured, 'В заблокированном периоде нельзя снимать привязку.');
    }

    public function testDayAfterLockIsStillReplaceable(): void
    {
        $captured = [];
        $replacement = $this->service(
            $this->monthClose([CloseStage::COSTS->value => true]),
            new \DateTimeImmutable('2026-07-31'),
            $captured,
        );

        self::assertTrue($replacement->prepareCosts(self::COMPANY_ID, MarketplaceType::OZON, $this->today(), self::RAW_DOC_ID));
        self::assertSame(['doc-costs'], $captured['documentIds'] ?? null);
    }

    public function testMissingMonthCloseIsNotAnError(): void
    {
        // Месяц ещё ни разу не закрывали — привязок нет, снимать нечего.
        $captured = [];
        $replacement = $this->service(null, null, $captured);

        self::assertTrue($replacement->prepareCosts(self::COMPANY_ID, MarketplaceType::OZON, $this->today(), self::RAW_DOC_ID));
        self::assertSame([], $captured);
    }

    /**
     * @param array<string, bool> $preliminary
     * @param list<string>|null $costDocumentIds
     */
    private function monthClose(
        array $preliminary,
        ?array $costDocumentIds = ['doc-costs'],
        MonthCloseStageStatus $status = MonthCloseStageStatus::CLOSED,
    ): MarketplaceMonthClose {
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
        (new \ReflectionProperty($monthClose, 'stageCostsStatus'))->setValue($monthClose, $status);
        (new \ReflectionProperty($monthClose, 'stageSalesReturnsStatus'))->setValue($monthClose, $status);

        return $monthClose;
    }

    /**
     * @param array<string, mixed> $captured
     */
    private function service(?MarketplaceMonthClose $monthClose, ?\DateTimeImmutable $lockBefore, array &$captured): ByDayRowReplacement
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

        $company = $this->createMock(Company::class);
        $company->method('getFinanceLockBefore')->willReturn($lockBefore);

        // CompanyFacade объявлен final — собирается рефлексией с подменённым репозиторием.
        $companyFacade = (new \ReflectionClass(CompanyFacade::class))->newInstanceWithoutConstructor();
        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('findById')->willReturn($company);
        (new \ReflectionProperty($companyFacade, 'repository'))->setValue($companyFacade, $companyRepository);

        $lock = (new \ReflectionClass(MonthCloseAdvisoryLockQuery::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($lock, 'connection'))->setValue($lock, $this->createMock(Connection::class));

        return new ByDayRowReplacement($repository, $companyFacade, $query, $lock, new MockClock(self::MOCK_NOW), new NullLogger());
    }
}

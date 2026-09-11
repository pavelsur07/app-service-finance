<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\MonthCloseStageStatus;
use App\Marketplace\Infrastructure\Query\PreliminaryClosedPeriodsQuery;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Запрос гоняется по настоящему PostgreSQL намеренно.
 *
 * Колонка `settings` объявлена как `json`, а не `jsonb`, и написанный сначала
 * оператор containment `@>` упал бы с «operator does not exist: json @>
 * unknown», обрушив весь ночной пересбор — включая текущий месяц. Юнит-тест с
 * моком Connection такое не ловит в принципе: он не исполняет SQL.
 */
final class PreliminaryClosedPeriodsQueryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-0000000000a1';

    public function testReturnsOnlyStagesClosedPreliminarily(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $em->getConnection()->executeStatement('DELETE FROM marketplace_month_closes');

        // Предварительно закрытые затраты — попадает.
        $this->persistMonthClose($em, 2026, 7, MonthCloseStageStatus::CLOSED, ['costs' => true]);
        // Окончательно закрытые оба этапа — не попадает.
        $this->persistMonthClose($em, 2026, 8, MonthCloseStageStatus::CLOSED, ['costs' => false, 'sales_returns' => false]);
        // Переоткрытый этап с оставшимся флагом — не попадает: иначе пересбор
        // закрыл бы обратно период, который человек открыл ради правок.
        $this->persistMonthClose($em, 2026, 9, MonthCloseStageStatus::REOPENED, ['costs' => true]);
        // Ни разу не закрывали — не попадает.
        $this->persistMonthClose($em, 2026, 10, MonthCloseStageStatus::PENDING, []);
        // Другой маркетплейс — не попадает: исторические строки умеет отвязывать
        // только разбор Ozon by-day, и переоткрывать чужие давние закрытия
        // каждую ночь не за что.
        $this->persistMonthClose($em, 2026, 6, MonthCloseStageStatus::CLOSED, ['costs' => true], MarketplaceType::WILDBERRIES);

        $em->flush();

        $query = self::getContainer()->get(PreliminaryClosedPeriodsQuery::class);

        $rows = array_values(array_filter(
            $query->execute(),
            static fn (array $row): bool => self::COMPANY_ID === $row['company_id'],
        ));

        $periods = array_map(
            static fn (array $row): string => sprintf('%d-%02d', $row['year'], $row['month']),
            $rows,
        );

        self::assertSame(['2026-07'], $periods);
        self::assertSame(['costs'], $rows[0]['stages'], 'Возвращаться должен точный список предварительно закрытых этапов.');
    }

    /**
     * @param array<string, bool> $preliminary
     */
    private function persistMonthClose(
        EntityManagerInterface $em,
        int $year,
        int $month,
        MonthCloseStageStatus $status,
        array $preliminary,
        MarketplaceType $marketplace = MarketplaceType::OZON,
    ): void {
        $monthClose = new MarketplaceMonthClose(
            \Ramsey\Uuid\Uuid::uuid7()->toString(),
            self::COMPANY_ID,
            $marketplace,
            $year,
            $month,
        );
        $monthClose->setSettings(['last_close_was_preliminary' => $preliminary]);
        (new \ReflectionProperty($monthClose, 'stageCostsStatus'))->setValue($monthClose, $status);
        (new \ReflectionProperty($monthClose, 'stageSalesReturnsStatus'))->setValue($monthClose, MonthCloseStageStatus::PENDING);

        $em->persist($monthClose);
    }
}

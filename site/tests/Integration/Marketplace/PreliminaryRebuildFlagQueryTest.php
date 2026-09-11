<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\PreliminaryRebuildFlagQuery;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Отметка живёт в колонке `settings` типа `json`, а `jsonb_set` и `#-` есть
 * только у `jsonb`: без приведения типа оба запроса упали бы прямо в проде.
 * Юнит-тест с моком Connection такое не ловит — он не исполняет SQL.
 */
final class PreliminaryRebuildFlagQueryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-0000000000c1';

    public function testMarkAndClearRoundTrip(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            'DELETE FROM marketplace_month_closes WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );

        $monthClose = new MarketplaceMonthClose(
            Uuid::uuid7()->toString(),
            self::COMPANY_ID,
            MarketplaceType::OZON,
            2026,
            7,
        );
        $monthClose->setSettings(['last_close_was_preliminary' => ['costs' => true]]);
        $em->persist($monthClose);
        $em->flush();
        $em->clear();

        $query = self::getContainer()->get(PreliminaryRebuildFlagQuery::class);

        $affected = $query->mark(self::COMPANY_ID, MarketplaceType::OZON, 2026, 7, CloseStage::COSTS);
        self::assertSame(1, $affected, 'UPDATE обязан найти строку периода.');
        self::assertSame('true', $this->flag($em, 'costs'));
        // Соседний этап не задет.
        self::assertNull($this->flag($em, 'sales_returns'));
        // Прежние настройки не потеряны.
        self::assertSame('true', $this->preliminaryFlag($em, 'costs'));

        $query->clear(self::COMPANY_ID, MarketplaceType::OZON, 2026, 7, CloseStage::COSTS);
        self::assertNull($this->flag($em, 'costs'));
        self::assertSame('true', $this->preliminaryFlag($em, 'costs'));
    }

    private function flag(EntityManagerInterface $em, string $stage): ?string
    {
        // Имя этапа подставляется литералом, а не параметром: у оператора `->>`
        // нетипизированный параметр PostgreSQL может принять за индекс массива и
        // вернуть NULL. Значение приходит из enum, так что подстановка безопасна.
        $value = $em->getConnection()->fetchOne(
            sprintf("SELECT settings->'needs_preliminary_rebuild'->>'%s' FROM marketplace_month_closes WHERE company_id = :c", $stage),
            ['c' => self::COMPANY_ID],
        );

        return false === $value || null === $value ? null : (string) $value;
    }

    private function preliminaryFlag(EntityManagerInterface $em, string $stage): ?string
    {
        $value = $em->getConnection()->fetchOne(
            sprintf("SELECT settings->'last_close_was_preliminary'->>'%s' FROM marketplace_month_closes WHERE company_id = :c", $stage),
            ['c' => self::COMPANY_ID],
        );

        return false === $value || null === $value ? null : (string) $value;
    }
}

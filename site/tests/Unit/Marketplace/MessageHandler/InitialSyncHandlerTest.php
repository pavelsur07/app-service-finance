<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\MarketplaceWeekPartitionService;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\InitialSyncMessage;
use App\Marketplace\MessageHandler\InitialSyncHandler;
use App\Marketplace\Service\Integration\MarketplaceAdapterInterface;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class InitialSyncHandlerTest extends TestCase
{
    private const COMPANY_ID    = '11111111-1111-1111-1111-111111111111';
    private const CONNECTION_ID = '22222222-2222-2222-2222-222222222222';

    /**
     * Сценарий: первая партия split-недели уже отгружена,
     * следующая партия = вторая половина (2026-03-30..03-31).
     * Handler не должен пересчитывать nextDateTo через 'sunday this week' —
     * это сломало бы границу месяца. Должен взять nextDateTo как есть и
     * посчитать партию-после-следующей с понедельника 2026-04-01.
     */
    public function testNextBatchPreservesMonthBoundarySplit(): void
    {
        // Тест зависит от $today — должен быть >= 2026-04-05, иначе afterPartitions обрежется.
        if (new \DateTimeImmutable('today') < new \DateTimeImmutable('2026-04-05')) {
            self::markTestSkipped('Test scenario requires today >= 2026-04-05');
        }

        $company    = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $connection = new MarketplaceConnection(
            self::CONNECTION_ID,
            $company,
            MarketplaceType::OZON,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static function (string $class, string $id) use ($company, $connection) {
            if ($class === Company::class && $id === self::COMPANY_ID) {
                return $company;
            }
            if ($class === MarketplaceConnection::class && $id === self::CONNECTION_ID) {
                return $connection;
            }

            return null;
        });

        $adapter = $this->createMock(MarketplaceAdapterInterface::class);
        $adapter->method('getMarketplaceType')->willReturn(MarketplaceType::OZON->value);
        $adapter->method('getApiEndpointName')->willReturn('test/endpoint');
        // Возвращаем непустой массив чтобы handler сохранил MarketplaceRawDocument
        // и дошёл до dispatch следующей партии.
        $adapter->method('fetchRawReport')->willReturn([['ok' => 1]]);

        $registry = new MarketplaceAdapterRegistry([$adapter]);

        $captured  = null;
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$captured): Envelope {
                $captured = $message;

                return new Envelope($message);
            });

        $handler = new InitialSyncHandler(
            $em,
            $registry,
            $messageBus,
            new NullLogger(),
            new MarketplaceWeekPartitionService(),
        );

        // Текущая партия = 2026-03-23 .. 2026-03-29 (полная неделя Пн-Вс).
        // Следующая партия (split) = 2026-03-30 .. 2026-03-31 — первая половина
        // недели 30.03–05.04, разрезанной по границе месяца.
        $message = new InitialSyncMessage(
            companyId:    self::COMPANY_ID,
            connectionId: self::CONNECTION_ID,
            marketplace:  MarketplaceType::OZON->value,
            dateFrom:     '2026-03-23',
            dateTo:       '2026-03-29',
            nextDateFrom: '2026-03-30',
            nextDateTo:   '2026-03-31',
        );

        $handler($message);

        self::assertInstanceOf(InitialSyncMessage::class, $captured);
        self::assertSame(self::COMPANY_ID, $captured->companyId);
        self::assertSame(self::CONNECTION_ID, $captured->connectionId);
        self::assertSame(MarketplaceType::OZON->value, $captured->marketplace);

        // Текущая партия следующего сообщения = вторая половина split-недели как есть.
        self::assertSame('2026-03-30', $captured->dateFrom);
        self::assertSame('2026-03-31', $captured->dateTo);

        // Партия-после-следующей = вторая половина той же исходной недели
        // (понедельник нового месяца → воскресенье).
        self::assertSame('2026-04-01 00:00:00', $captured->nextDateFrom);
        self::assertSame('2026-04-05 23:59:59', $captured->nextDateTo);
    }
}

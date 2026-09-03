<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Repository;

use App\Ingestion\Entity\IngestOrderStatusEvent;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Repository\IngestOrderStatusEventRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class IngestOrderStatusEventRepositoryTest extends IntegrationTestCase
{
    private IngestOrderStatusEventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IngestOrderStatusEventRepository::class);
    }

    /**
     * При РАВНОМ времени наблюдения история идёт в порядке ПРИМЕНЕНИЯ.
     *
     * Порядок применения задаёт очерёдность захвата блокировки заказа, а не
     * идентификатор сырья: сырьё получает свой UUID при загрузке, задолго до
     * разбора и другим процессом. Пока история сортировалась по `rawRecordId`,
     * два наблюдения одной микросекунды могли вернуться в обратном порядке —
     * цепочка `previousStatus` не сходилась, а последним в истории оказывался
     * не тот переход, который стоит в заказе.
     */
    public function testEventsWithTheSameInstantKeepTheOrderTheyWereApplied(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $orderId = Uuid::uuid7()->toString();
        $observedAt = new \DateTimeImmutable('2026-09-01 10:00:00.123456');

        // Идентификаторы сырья намеренно убывающие: сортировка по ним дала бы
        // порядок, ОБРАТНЫЙ порядку применения.
        $firstRawRecordId = '0192f0c2-0000-7000-8000-0000000000ff';
        $secondRawRecordId = '0192f0c2-0000-7000-8000-000000000011';

        $first = new IngestOrderStatusEvent(
            companyId: $companyId,
            orderId: $orderId,
            rawStatus: 'delivering',
            status: IngestOrderStatus::SHIPPED,
            observedAt: $observedAt,
            previousStatus: IngestOrderStatus::ORDERED,
            rawRecordId: $firstRawRecordId,
        );
        $this->em->persist($first);
        $this->em->flush();

        $second = new IngestOrderStatusEvent(
            companyId: $companyId,
            orderId: $orderId,
            rawStatus: 'delivered',
            status: IngestOrderStatus::DELIVERED,
            observedAt: $observedAt,
            previousStatus: IngestOrderStatus::SHIPPED,
            rawRecordId: $secondRawRecordId,
        );
        $this->em->persist($second);
        $this->em->flush();

        $history = $this->repository->findByOrder($companyId, $orderId);

        self::assertSame(
            [IngestOrderStatus::SHIPPED, IngestOrderStatus::DELIVERED],
            array_map(static fn (IngestOrderStatusEvent $e): IngestOrderStatus => $e->getStatus(), $history),
        );

        // Цепочка обязана сходиться: предыдущий статус второго события — это
        // статус первого.
        self::assertSame(IngestOrderStatus::SHIPPED, $history[1]->getPreviousStatus());
    }
}

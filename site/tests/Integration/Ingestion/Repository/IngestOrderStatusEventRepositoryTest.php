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
     * Порядок применения задаёт номер, выданный счётчиком ЗАКАЗА под его
     * блокировкой. Ни идентификатор сырья, ни идентификатор события для этого
     * не годятся: сырьё получает UUID при загрузке, задолго до разбора и
     * другим процессом, а UUID v7 события упорядочен лишь по миллисекунде — и
     * два процесса, взявшие блокировку внутри одной миллисекунды, могли дать
     * обратный порядок. Тогда цепочка `previousStatus` не сходится, а
     * последним в истории оказывается не тот переход, который стоит в заказе.
     *
     * Идентификаторы здесь подобраны так, чтобы ОБА прежних тай-брейка дали
     * порядок, обратный порядку применения.
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

        // Идентификаторы событий тоже убывающие: сортировка по `id` дала бы
        // тот же обратный порядок, что и сортировка по сырью.
        $firstEventId = '0192f0c2-1111-7000-8000-0000000000ff';
        $secondEventId = '0192f0c2-1111-7000-8000-000000000011';

        $first = new IngestOrderStatusEvent(
            companyId: $companyId,
            orderId: $orderId,
            rawStatus: 'delivering',
            status: IngestOrderStatus::SHIPPED,
            observedAt: $observedAt,
            previousStatus: IngestOrderStatus::ORDERED,
            rawRecordId: $firstRawRecordId,
            recordedSeq: 1,
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
            recordedSeq: 2,
        );
        $this->em->persist($second);
        $this->em->flush();

        // Идентификаторы переставляются УЖЕ ПОСЛЕ записи: UUID v7 растёт по
        // времени создания, поэтому естественный порядок совпал бы с порядком
        // применения и тест не отличил бы одно от другого.
        $this->connection->executeStatement(
            'UPDATE ingest_order_status_events SET id = :new WHERE id = :old',
            ['new' => $firstEventId, 'old' => $first->getId()],
        );
        $this->connection->executeStatement(
            'UPDATE ingest_order_status_events SET id = :new WHERE id = :old',
            ['new' => $secondEventId, 'old' => $second->getId()],
        );
        $this->em->clear();

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

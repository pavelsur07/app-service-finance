<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Entity;

use App\Ingestion\Entity\IngestOrderStatusEvent;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Tests\Builders\Ingestion\IngestOrderBuilder;
use PHPUnit\Framework\TestCase;

final class IngestOrderTest extends TestCase
{
    public function testNewerObservationMovesStatusForward(): void
    {
        $order = IngestOrderBuilder::anOrder()
            ->withStatus(IngestOrderStatus::SHIPPED, 'delivering')
            ->statusObservedAt(new \DateTimeImmutable('2026-09-01 10:00:00'))
            ->build();

        $applied = $order->observeStatus('delivered', IngestOrderStatus::DELIVERED, new \DateTimeImmutable('2026-09-01 12:00:00'));

        self::assertTrue($applied);
        self::assertSame(IngestOrderStatus::DELIVERED, $order->getStatus());
        self::assertSame('delivered', $order->getRawStatus());
    }

    /**
     * Маркетплейсы отдают устаревшее состояние: перезапрос окна может вернуть
     * снимок старше уже виденного. Текущее состояние заказа назад не едет —
     * иначе доставленный заказ снова стал бы «в пути».
     */
    public function testOlderObservationDoesNotMoveStatusBackwards(): void
    {
        $order = IngestOrderBuilder::anOrder()
            ->withStatus(IngestOrderStatus::DELIVERED, 'delivered')
            ->statusObservedAt(new \DateTimeImmutable('2026-09-01 12:00:00'))
            ->build();

        $applied = $order->observeStatus('delivering', IngestOrderStatus::SHIPPED, new \DateTimeImmutable('2026-09-01 10:00:00'));

        self::assertFalse($applied);
        self::assertSame(IngestOrderStatus::DELIVERED, $order->getStatus());
        self::assertSame('delivered', $order->getRawStatus());
    }

    /**
     * Журнал переходов правится только добавлением строк. Держать это на
     * договорённости нельзя — проверяем машинно.
     */
    public function testStatusEventHasNoPublicMutators(): void
    {
        $reflection = new \ReflectionClass(IngestOrderStatusEvent::class);

        $mutators = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            if (str_starts_with($method->getName(), 'get')) {
                continue;
            }
            $mutators[] = $method->getName();
        }

        self::assertSame([], $mutators, 'IngestOrderStatusEvent — append-only, публичных мутаторов быть не должно.');
    }

    /**
     * NULL означает «авторитетного снимка ещё не было», и первый снимок
     * принимается любым: заказ мог быть создан частичным наблюдением, которое
     * снимком не является. Заказы, заведённые до появления колонки, к этой
     * ветке не относятся — им отметка проставлена обратным заполнением в
     * миграции.
     */
    public function testFirstSnapshotIsAcceptedRegardlessOfStatusWatermark(): void
    {
        $order = IngestOrderBuilder::anOrder()
            ->statusObservedAt(new \DateTimeImmutable('2026-09-01T12:00:00+00:00'))
            ->build();

        // Свежесозданный заказ отметки снимка не несёт: билдер её не задаёт,
        // а частичное наблюдение снимком не является.
        self::assertTrue($order->acceptSnapshot(new \DateTimeImmutable('2026-09-01T10:00:00+00:00')));

        $recorded = $order->getSnapshotObservedAt();
        self::assertNotNull($recorded);
        self::assertSame('2026-09-01T10:00:00+00:00', $recorded->format(\DATE_ATOM));
    }

    /**
     * А вот дальше порядок соблюдается: устаревший снимок не переписывает
     * более свежий.
     */
    public function testStaleSnapshotIsRejectedOnceTheWatermarkExists(): void
    {
        $order = IngestOrderBuilder::anOrder()->build();
        $order->acceptSnapshot(new \DateTimeImmutable('2026-09-01T12:00:00+00:00'));

        self::assertFalse($order->acceptSnapshot(new \DateTimeImmutable('2026-09-01T11:00:00+00:00')));
        self::assertTrue($order->acceptSnapshot(new \DateTimeImmutable('2026-09-01T13:00:00+00:00')));
    }
}

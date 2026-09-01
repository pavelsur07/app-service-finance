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
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Entity;

use App\Ingestion\Entity\IngestOrder;
use App\Ingestion\Entity\IngestOrderStatusEvent;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Shared\Infrastructure\Doctrine\MicrosecondDateTimeImmutableType;
use App\Tests\Builders\Ingestion\IngestOrderBuilder;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Сущности ссылаются на микросекундный тип СТРОКОЙ: класс живёт в
     * Shared\Infrastructure, а `Infrastructure/` чужого модуля закрыт. Строка
     * и зарегистрированное имя типа разъехаться молча не должны — тогда
     * Doctrine вернулась бы к секундной точности, и наблюдения внутри одной
     * секунды снова стали бы неразличимы.
     *
     * @param class-string $entity
     */
    #[DataProvider('microsecondColumnProvider')]
    public function testObservationColumnsUseTheRegisteredMicrosecondType(string $entity, string $property): void
    {
        $attributes = (new \ReflectionProperty($entity, $property))->getAttributes(Column::class);
        self::assertCount(1, $attributes);

        self::assertSame(
            MicrosecondDateTimeImmutableType::NAME,
            $attributes[0]->newInstance()->type,
            sprintf('%s::$%s обязана храниться с микросекундами.', $entity, $property),
        );
    }

    /**
     * @return iterable<string, array{entity: class-string, property: string}>
     */
    public static function microsecondColumnProvider(): iterable
    {
        yield 'order status watermark' => ['entity' => IngestOrder::class, 'property' => 'statusObservedAt'];
        yield 'order snapshot watermark' => ['entity' => IngestOrder::class, 'property' => 'snapshotObservedAt'];
        yield 'order partial watermark' => ['entity' => IngestOrder::class, 'property' => 'partialObservedAt'];
        yield 'order refresh attempt' => ['entity' => IngestOrder::class, 'property' => 'statusRefreshAttemptedAt'];
        yield 'journal observation' => ['entity' => IngestOrderStatusEvent::class, 'property' => 'observedAt'];
        yield 'raw record fetch time' => ['entity' => IngestRawRecord::class, 'property' => 'fetchedAt'];
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
            // `get*` и `is*` — чтение. Проверка именно на мутаторы: append-only
            // запрещает изменение записи, а не булев геттер.
            if (str_starts_with($method->getName(), 'get') || str_starts_with($method->getName(), 'is')) {
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

    /**
     * Отметка изменения не едет назад НИ ПОСЛЕ ОДНОГО мутатора.
     *
     * Часы прогона инжектируются и могут опережать системные — так и работает
     * `AdvancingClock` в интеграционных тестах. Достаточно одного мутатора,
     * пишущего «системное сейчас» напрямую, чтобы отметка, поставленная от
     * инжектированных часов, откатилась, и выборка «что изменилось после
     * момента X» перестала видеть только что обновлённый заказ.
     *
     * Проверяются оба пути наблюдения: сам статус и слияние атрибутов.
     */
    public function testUpdatedAtNeverGoesBackwardsAfterAnyMutator(): void
    {
        $order = IngestOrderBuilder::anOrder()->build();

        // Часы прогона впереди системных: ровно то, что делает AdvancingClock.
        $ahead = new \DateTimeImmutable('+1 hour');
        $order->markRefreshAttempted($ahead);

        self::assertSame($ahead->format('U.u'), $order->getUpdatedAt()->format('U.u'));

        $order->observeStatus(
            'delivered',
            IngestOrderStatus::DELIVERED,
            new \DateTimeImmutable('+2 hours'),
            null,
            null,
        );
        self::assertGreaterThanOrEqual(
            $ahead,
            $order->getUpdatedAt(),
            'Наблюдение статуса не имеет права откатить отметку изменения.',
        );

        $order->mergeAttributes(['warehouse' => 'новый склад']);
        self::assertGreaterThanOrEqual(
            $ahead,
            $order->getUpdatedAt(),
            'Слияние атрибутов — тоже мутатор, и правило для него то же.',
        );

        $order->stopRefreshing(new \DateTimeImmutable('-1 hour'));
        self::assertGreaterThanOrEqual(
            $ahead,
            $order->getUpdatedAt(),
            'Даже операция, датированная прошлым, отметку назад не двигает.',
        );
    }
}

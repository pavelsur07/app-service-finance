<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Domain\TenantOwnedInterface;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\IngestOrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Заказ (отправление) маркетплейса.
 *
 * Естественный ключ — `externalId`, а не идентификатор заказа маркетплейса:
 * у Ozon один `order_id` порождает несколько отправлений (в выгрузке
 * 100 отправлений на 89 заказов), и именно отправление живёт своей жизнью
 * по статусам. Для WB `externalId` — это `rid`/`srid`, общий ключ двух
 * потоков API; числовой идентификатор лежит отдельно в `externalOrderId`,
 * он нужен для запроса статусов.
 */
#[ORM\Entity(repositoryClass: IngestOrderRepository::class)]
#[ORM\Table(name: 'ingest_orders')]
// connection_ref входит в ключ: posting_number уникален в пределах кабинета
// продавца, а не глобально. Без него два кабинета Ozon одной компании слились
// бы в одну запись, и статусы с позициями одного подключения затирали бы
// другое.
#[ORM\UniqueConstraint(name: 'uniq_ingest_order_external', columns: ['company_id', 'source', 'connection_ref', 'external_id'])]
#[ORM\Index(name: 'idx_ingest_order_company_status_ordered', columns: ['company_id', 'status', 'ordered_at'])]
#[ORM\Index(name: 'idx_ingest_order_company_connection', columns: ['company_id', 'connection_ref'])]
class IngestOrder implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $connectionRef;

    #[ORM\Column(type: Types::STRING, length: 255, options: ['default' => ''])]
    private string $shopRef;

    #[ORM\Column(type: Types::STRING, enumType: IngestSource::class)]
    private IngestSource $source;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: IngestOrderScheme::class)]
    private IngestOrderScheme $scheme;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $externalId;

    /** Числовой идентификатор заказа маркетплейса; у WB нужен для /orders/status. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $externalOrderId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $orderedAt;

    /** Дословная строка маркетплейса — доказательство при разборе. */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $rawStatus;

    /**
     * Уточнение статуса Ozon. В нормализацию не участвует: `posting_on_way_to_city`
     * и `posting_in_pickup_point` оба лежат под одним `delivering`.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $rawSubstatus = null;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: IngestOrderStatus::class)]
    private IngestOrderStatus $status;

    /**
     * Когда статус наблюдался в последний раз.
     *
     * NULL означает «статуса ещё никто не сообщал»: заказ мог быть заведён
     * наблюдением, которое о статусе молчит (поток изменений WB без отмены,
     * ответ `/api/v3/orders` без строки `/orders/status`). Ставить сюда
     * отметку такого наблюдения значило бы закрыть дорогу первому настоящему
     * статусу, если тот окажется старше по времени скачивания.
     */
    // Тип по ЗАРЕГИСТРИРОВАННОМУ имени, а не по классу: класс живёт в
    // Shared\Infrastructure, а `Infrastructure/` чужого модуля закрыт.
    // Регистрация — config/packages/doctrine.yaml, dbal.types.
    #[ORM\Column(type: 'datetime_immutable_us', nullable: true)]
    private ?\DateTimeImmutable $statusObservedAt;

    /**
     * Когда наблюдался последний ПОЛНЫЙ снимок заказа. Отдельно от
     * statusObservedAt — см. {@see acceptSnapshot()}.
     */
    #[ORM\Column(type: 'datetime_immutable_us', nullable: true)]
    private ?\DateTimeImmutable $snapshotObservedAt = null;

    /**
     * Когда наблюдалось последнее ЧАСТИЧНОЕ наблюдение.
     *
     * Третья отметка нужна потому, что частичный поток обновляет своё:
     * атрибуты, уточнение схемы, недостающие позиции. Привязать это к
     * статусной отметке нельзя — частичное наблюдение статуса может не
     * нести вовсе, и тогда все его непротиворечивые данные терялись бы.
     */
    #[ORM\Column(type: 'datetime_immutable_us', nullable: true)]
    private ?\DateTimeImmutable $partialObservedAt = null;

    /** Указатель на raw, из которого получено последнее наблюдение. */
    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $lastRawRecordId = null;

    /**
     * Момент, когда заказ перестали опрашивать как безнадёжно зависший.
     * Не терминальный статус: это наше решение, а не сообщение маркетплейса.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $refreshStoppedAt = null;

    /**
     * Момент ПОПЫТКИ перепроса — независимо от того, чем она кончилась.
     *
     * Отдельно от `statusObservedAt`, потому что попытка бывает без
     * наблюдения: 404, ответ без поля статуса, отсутствие заказа в успешном
     * ответе WB. Планировать очередь по времени наблюдения нельзя — такие
     * заказы отметку не двигают, а сортировка стабильна, поэтому они вечно
     * занимали бы начало лимита и остальные заказы кабинета не опрашивались
     * бы никогда.
     */
    #[ORM\Column(type: 'datetime_immutable_us', nullable: true)]
    private ?\DateTimeImmutable $statusRefreshAttemptedAt = null;

    /**
     * Сколько событий журнала уже записано ЭТОМУ заказу.
     *
     * Счётчик заказа, а не события, потому что монотонность нужна ровно в
     * пределах одного заказа и обеспечивается его же блокировкой: журнал
     * пишется только под `PESSIMISTIC_WRITE` на эту строку, поэтому два
     * процесса получают номера по очереди, а не одновременно.
     *
     * Нужен, чтобы у истории был НАСТОЯЩИЙ порядок применения. Сортировать
     * события с равным `observedAt` по `id` — не то же самое: UUID v7
     * упорядочен по миллисекунде, и два процесса, взявшие блокировку внутри
     * одной миллисекунды, могли дать порядок, обратный порядку применения.
     * Тогда цепочка `previousStatus` не сходится, а последним в истории
     * оказывается не тот переход, который стоит в заказе.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $statusEventSeq = 0;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $attributes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed>|null $attributes
     */
    public function __construct(
        string $companyId,
        string $connectionRef,
        string $shopRef,
        IngestSource $source,
        IngestOrderScheme $scheme,
        string $externalId,
        \DateTimeImmutable $orderedAt,
        string $rawStatus,
        IngestOrderStatus $status,
        ?\DateTimeImmutable $statusObservedAt,
        ?string $externalOrderId = null,
        ?string $rawSubstatus = null,
        ?string $lastRawRecordId = null,
        ?array $attributes = null,
    ) {
        Assert::uuid($companyId);
        Assert::notEmpty($connectionRef);
        Assert::notEmpty($externalId);
        Assert::notEmpty($rawStatus);

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->connectionRef = $connectionRef;
        $this->shopRef = $shopRef;
        $this->source = $source;
        $this->scheme = $scheme;
        $this->externalId = $externalId;
        $this->orderedAt = $orderedAt;
        $this->rawStatus = $rawStatus;
        $this->status = $status;
        $this->statusObservedAt = $statusObservedAt;
        $this->externalOrderId = $externalOrderId;
        $this->rawSubstatus = $rawSubstatus;
        $this->lastRawRecordId = $lastRawRecordId;
        $this->attributes = $attributes;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Статус двигается только вперёд по времени наблюдения.
     *
     * Маркетплейсы отдают устаревшее состояние: перезапрос окна может вернуть
     * снимок старше того, что мы уже видели. Событие такого наблюдения
     * фиксируется отдельно и всегда — это факт, — но текущее состояние заказа
     * назад не откатывается.
     *
     * @return bool применилось ли наблюдение
     */
    public function observeStatus(
        string $rawStatus,
        IngestOrderStatus $status,
        \DateTimeImmutable $observedAt,
        ?string $rawSubstatus = null,
        ?string $rawRecordId = null,
    ): bool {
        // NULL — статуса ещё не было, поэтому первое настоящее наблюдение
        // принимается независимо от того, насколько оно старое.
        if (null !== $this->statusObservedAt && $observedAt < $this->statusObservedAt) {
            return false;
        }

        $this->rawStatus = $rawStatus;
        $this->status = $status;
        $this->statusObservedAt = $observedAt;
        $this->rawSubstatus = $rawSubstatus ?? $this->rawSubstatus;
        $this->lastRawRecordId = $rawRecordId ?? $this->lastRawRecordId;
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }

    /**
     * Принять ПОЛНЫЙ снимок заказа.
     *
     * Свежесть снимка отделена от свежести статуса намеренно. Потоки приходят
     * вперемешку: у WB частичное наблюдение из statistics может быть скачано
     * позже, а разобрано раньше полного снимка из marketplace. Если бы состав
     * и цена применялись по той же отметке, что и статус, более позднее
     * частичное наблюдение навсегда закрыло бы дорогу авторитетному снимку —
     * заказ остался бы без номера, валюты и с ценой другой семантики.
     *
     * Сравнение идёт с отметкой ПОСЛЕДНЕГО полного снимка, поэтому статус
     * при этом назад не едет: за него отвечает {@see observeStatus()}.
     *
     * @return bool применять ли снимок
     */
    public function acceptSnapshot(\DateTimeImmutable $observedAt): bool
    {
        // NULL означает «авторитетного снимка ещё не было» — и такой снимок
        // принимается любым. Это не дыра: заказ мог быть создан ЧАСТИЧНЫМ
        // наблюдением, которое снимком не является, и запретить ему принять
        // первый полный снимок значило бы вернуть дефект, ради которого
        // отметки и разделены.
        //
        // Заказы, заведённые до появления колонки, к этой ветке не относятся:
        // им отметка проставлена обратным заполнением в миграции
        // Version20260902130000, потому что до этой стадии все наблюдения были
        // полными снимками и пользовались status_observed_at.
        if (null !== $this->snapshotObservedAt && $observedAt < $this->snapshotObservedAt) {
            return false;
        }

        $this->snapshotObservedAt = $observedAt;
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }

    /**
     * Схему задаёт только принятый авторитетный снимок.
     *
     * Заказ мог быть создан частичным наблюдением, которое схемы не знало и
     * оставило {@see IngestOrderScheme::UNKNOWN}. Поток, который видит заказ
     * целиком, обязан это исправить — иначе схема навсегда зависела бы от
     * того, кто пришёл первым.
     */
    public function applyScheme(IngestOrderScheme $scheme): void
    {
        if ($this->scheme === $scheme) {
            return;
        }

        $this->scheme = $scheme;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Принять ЧАСТИЧНОЕ наблюдение: атрибуты, уточнение схемы, добавление
     * недостающих позиций. Со статусом не связано — см. докблок поля.
     */
    public function acceptPartialObservation(\DateTimeImmutable $observedAt): bool
    {
        if (null !== $this->partialObservedAt && $observedAt < $this->partialObservedAt) {
            return false;
        }

        $this->partialObservedAt = $observedAt;
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }

    /**
     * Попытка перепроса состоялась — независимо от её исхода.
     */
    public function markRefreshAttempted(\DateTimeImmutable $at): void
    {
        $this->statusRefreshAttemptedAt = $at;
        // Одна операция — одни часы. Отметка попытки И есть «сейчас» этой
        // записи, и читать рядом системное время значило бы завести вторую
        // шкалу: при замороженных часах в тестах или сдвиге системного времени
        // updatedAt разошёлся бы с фактическим временем попытки.
        $this->updatedAt = $at;
    }

    public function getStatusRefreshAttemptedAt(): ?\DateTimeImmutable
    {
        return $this->statusRefreshAttemptedAt;
    }

    /**
     * Выдать следующий номер события журнала.
     *
     * Вызывается ТОЛЬКО под блокировкой этой строки — иначе два процесса
     * прочитали бы одно значение и выдали бы один номер дважды.
     */
    public function nextStatusEventSequence(): int
    {
        return ++$this->statusEventSeq;
    }

    public function getStatusEventSeq(): int
    {
        return $this->statusEventSeq;
    }

    public function stopRefreshing(\DateTimeImmutable $at): void
    {
        $this->refreshStoppedAt = $at;
        // Одна операция — одни часы, как и в markRefreshAttempted(): момент
        // остановки И есть «сейчас» этой записи, а системное время рядом с
        // переданным завело бы вторую шкалу.
        $this->updatedAt = $at;
    }

    public function setExternalOrderId(?string $externalOrderId): void
    {
        $this->externalOrderId = $externalOrderId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed>|null $attributes
     */
    public function mergeAttributes(?array $attributes): void
    {
        if (null === $attributes || [] === $attributes) {
            return;
        }

        $this->attributes = array_merge($this->attributes ?? [], $attributes);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getConnectionRef(): string
    {
        return $this->connectionRef;
    }

    public function getShopRef(): string
    {
        return $this->shopRef;
    }

    public function getSource(): IngestSource
    {
        return $this->source;
    }

    public function getScheme(): IngestOrderScheme
    {
        return $this->scheme;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getExternalOrderId(): ?string
    {
        return $this->externalOrderId;
    }

    public function getOrderedAt(): \DateTimeImmutable
    {
        return $this->orderedAt;
    }

    public function getRawStatus(): string
    {
        return $this->rawStatus;
    }

    public function getRawSubstatus(): ?string
    {
        return $this->rawSubstatus;
    }

    public function getStatus(): IngestOrderStatus
    {
        return $this->status;
    }

    public function getStatusObservedAt(): ?\DateTimeImmutable
    {
        return $this->statusObservedAt;
    }

    public function getPartialObservedAt(): ?\DateTimeImmutable
    {
        return $this->partialObservedAt;
    }

    public function getLastRawRecordId(): ?string
    {
        return $this->lastRawRecordId;
    }

    public function getSnapshotObservedAt(): ?\DateTimeImmutable
    {
        return $this->snapshotObservedAt;
    }

    public function getRefreshStoppedAt(): ?\DateTimeImmutable
    {
        return $this->refreshStoppedAt;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAttributes(): ?array
    {
        return $this->attributes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

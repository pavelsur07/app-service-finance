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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $statusObservedAt;

    /**
     * Когда наблюдался последний ПОЛНЫЙ снимок заказа. Отдельно от
     * statusObservedAt — см. {@see acceptSnapshot()}.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $snapshotObservedAt = null;

    /** Указатель на raw, из которого получено последнее наблюдение. */
    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $lastRawRecordId = null;

    /**
     * Момент, когда заказ перестали опрашивать как безнадёжно зависший.
     * Не терминальный статус: это наше решение, а не сообщение маркетплейса.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $refreshStoppedAt = null;

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
        \DateTimeImmutable $statusObservedAt,
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
        if ($observedAt < $this->statusObservedAt) {
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

    public function stopRefreshing(\DateTimeImmutable $at): void
    {
        $this->refreshStoppedAt = $at;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getStatusObservedAt(): \DateTimeImmutable
    {
        return $this->statusObservedAt;
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

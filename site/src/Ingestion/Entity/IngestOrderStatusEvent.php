<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Domain\TenantOwnedInterface;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Repository\IngestOrderStatusEventRepository;
use App\Shared\Infrastructure\Doctrine\MicrosecondDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Наблюдение смены статуса заказа. Append-only.
 *
 * У сущности намеренно НЕТ публичных мутаторов: журнал переходов правится
 * только добавлением строк. Это проверяется тестом, а не держится на
 * договорённости.
 *
 * `rawStatus` хранится на самом событии, а не только на заказе: благодаря
 * этому расширение словаря статусов пересчитывает нормализованные значения
 * по истории, без повторной перекачки сырья с S3.
 */
#[ORM\Entity(repositoryClass: IngestOrderStatusEventRepository::class)]
#[ORM\Table(name: 'ingest_order_status_events')]
// Наблюдение одного и того же статуса из одного и того же сырья — одно
// событие, а не новое при каждой перенормализации. Без этого повторный прогон
// старого raw бесконечно дописывал бы копии: устаревшее наблюдение не двигает
// статус заказа, поэтому «статус отличается» остаётся истиной навсегда.
// Порядок колонок подобран под запрос дедупа, который фильтрует по
// (company_id, raw_record_id): при порядке с order_id посередине B-tree не мог
// бы использовать префикс, и каждая нормализация просматривала бы все события
// компании. Кортеж уникальности от перестановки не меняется.
#[ORM\UniqueConstraint(name: 'uniq_ingest_order_status_event_observation', columns: ['company_id', 'raw_record_id', 'order_id', 'raw_status'])]
#[ORM\Index(name: 'idx_ingest_order_status_event_order', columns: ['order_id', 'observed_at'])]
#[ORM\Index(name: 'idx_ingest_order_status_event_company', columns: ['company_id', 'observed_at'])]
class IngestOrderStatusEvent implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $orderId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $rawStatus;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: IngestOrderStatus::class)]
    private IngestOrderStatus $status;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: IngestOrderStatus::class, nullable: true)]
    private ?IngestOrderStatus $previousStatus = null;

    #[ORM\Column(type: MicrosecondDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $observedAt;

    /** Указатель на сырьё-доказательство; после retention может стать неразрешимым. */
    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $rawRecordId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $companyId,
        string $orderId,
        string $rawStatus,
        IngestOrderStatus $status,
        \DateTimeImmutable $observedAt,
        ?IngestOrderStatus $previousStatus = null,
        ?string $rawRecordId = null,
    ) {
        Assert::uuid($companyId);
        Assert::uuid($orderId);
        Assert::notEmpty($rawStatus);

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->orderId = $orderId;
        $this->rawStatus = $rawStatus;
        $this->status = $status;
        $this->observedAt = $observedAt;
        $this->previousStatus = $previousStatus;
        $this->rawRecordId = $rawRecordId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getRawStatus(): string
    {
        return $this->rawStatus;
    }

    public function getStatus(): IngestOrderStatus
    {
        return $this->status;
    }

    public function getPreviousStatus(): ?IngestOrderStatus
    {
        return $this->previousStatus;
    }

    public function getObservedAt(): \DateTimeImmutable
    {
        return $this->observedAt;
    }

    public function getRawRecordId(): ?string
    {
        return $this->rawRecordId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

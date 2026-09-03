<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Domain\TenantOwnedInterface;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: IngestRawRecordRepository::class)]
#[ORM\Table(name: 'ingest_raw_records')]
#[ORM\Index(columns: ['company_id', 'source', 'resource_type', 'fetched_at'], name: 'idx_ingest_raw_company_source_resource_fetched')]
#[ORM\Index(columns: ['normalization_status', 'fetched_at'], name: 'idx_ingest_raw_normalization_status_fetched')]
#[ORM\UniqueConstraint(name: 'uniq_ingest_raw_company_source_resource_external_hash', columns: ['company_id', 'source', 'resource_type', 'external_id', 'hash'])]
class IngestRawRecord implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $connectionRef;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $shopRef;

    #[ORM\Column(type: Types::STRING, length: 64, enumType: IngestSource::class)]
    private IngestSource $source;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $resourceType;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $externalId;

    #[ORM\Column(type: Types::STRING, length: 1024)]
    private string $storagePath;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $hash;

    #[ORM\Column(type: Types::INTEGER)]
    private int $byteSize;

    /**
     * Момент СКАЧИВАНИЯ — он же момент наблюдения для нормализации, поэтому
     * микросекунды здесь обязаны переживать запись: усечённые до секунды, они
     * делают два наблюдения внутри одной секунды неразличимыми, и более
     * старое побеждает более новое.
     */
    // Имя типа, а не класс: см. IngestOrder. Регистрация — doctrine.yaml.
    #[ORM\Column(type: 'datetime_immutable_us')]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $syncJobId;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: RawNormalizationStatus::class)]
    private RawNormalizationStatus $normalizationStatus = RawNormalizationStatus::PENDING;

    /**
     * Когда полезная нагрузка удалена по политике хранения.
     *
     * Строка при этом остаётся: дорого стоит объект в хранилище, а не сотня
     * байт метаданных. Указатели на сырьё продолжают разрешаться, чтение
     * отвечает внятной ошибкой вместо сбоя хранилища, а дедупу есть что
     * обновлять. Если та же выгрузка приедет снова, объект восстановится и
     * отметка снимется.
     */
    #[ORM\Column(type: 'datetime_immutable_us', nullable: true)]
    private ?\DateTimeImmutable $payloadPrunedAt = null;

    /**
     * Когда объект действительно удалён из хранилища.
     *
     * Отдельно от решения: хранилище не транзакционно, и коммит решения обязан
     * произойти ДО обращения к нему. Иначе падение между удалением и коммитом
     * откатило бы решение при уже уничтоженных данных. Пара отметок делает
     * незавершённое состояние видимым, а работу — повторяемой.
     */
    #[ORM\Column(type: 'datetime_immutable_us', nullable: true)]
    private ?\DateTimeImmutable $payloadDeletedAt = null;

    /**
     * Когда попытку удаления предпринимали в последний раз.
     *
     * Очередь незавершённых удалений сортируется по ней, иначе голодает:
     * она всегда брала бы самые старые решения, а неудачная попытка ключ
     * сортировки не меняет — `limit` неустранимых объектов навсегда закрыл бы
     * дорогу остальным.
     */
    #[ORM\Column(type: 'datetime_immutable_us', nullable: true)]
    private ?\DateTimeImmutable $payloadDeletionAttemptedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        string $connectionRef,
        string $shopRef,
        IngestSource $source,
        string $resourceType,
        string $externalId,
        string $storagePath,
        string $hash,
        int $byteSize,
        \DateTimeImmutable $fetchedAt,
        string $syncJobId,
    ) {
        Assert::uuid($companyId);
        Assert::notEmpty($connectionRef);
        Assert::notEmpty($shopRef);
        Assert::notEmpty($resourceType);
        Assert::notEmpty($externalId);
        Assert::notEmpty($storagePath);
        Assert::length($hash, 64);
        Assert::natural($byteSize);
        Assert::notEmpty($syncJobId);

        $now = new \DateTimeImmutable();

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->connectionRef = $connectionRef;
        $this->shopRef = $shopRef;
        $this->source = $source;
        $this->resourceType = $resourceType;
        $this->externalId = $externalId;
        $this->storagePath = $storagePath;
        $this->hash = $hash;
        $this->byteSize = $byteSize;
        $this->fetchedAt = $fetchedAt;
        $this->lastSeenAt = $now;
        $this->syncJobId = $syncJobId;
        $this->createdAt = $now;
        $this->updatedAt = $now;
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

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getSyncJobId(): string
    {
        return $this->syncJobId;
    }

    public function getNormalizationStatus(): RawNormalizationStatus
    {
        return $this->normalizationStatus;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markSeen(?\DateTimeImmutable $seenAt = null): void
    {
        $this->lastSeenAt = $seenAt ?? new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Решение удалить нагрузку. Коммитится ДО обращения к хранилищу.
     */
    public function markPayloadPruned(\DateTimeImmutable $at): void
    {
        $this->payloadPrunedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * Объект действительно удалён.
     */
    public function markPayloadDeleted(\DateTimeImmutable $at): void
    {
        $this->payloadDeletedAt = $at;
        $this->payloadDeletionAttemptedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * Попытку предпринимали — независимо от исхода.
     */
    public function markPayloadDeletionAttempted(\DateTimeImmutable $at): void
    {
        $this->payloadDeletionAttemptedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * Нагрузка вернулась — отметка снимается.
     *
     * Иначе запись утверждала бы, что объекта нет, тогда как он снова на
     * месте, и чтение отвечало бы ошибкой на существующих данных.
     */
    public function markPayloadRestored(): void
    {
        if (null === $this->payloadPrunedAt) {
            return;
        }

        $this->payloadPrunedAt = null;
        $this->payloadDeletedAt = null;
        $this->payloadDeletionAttemptedAt = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPayloadPrunedAt(): ?\DateTimeImmutable
    {
        return $this->payloadPrunedAt;
    }

    public function getPayloadDeletedAt(): ?\DateTimeImmutable
    {
        return $this->payloadDeletedAt;
    }

    public function getPayloadDeletionAttemptedAt(): ?\DateTimeImmutable
    {
        return $this->payloadDeletionAttemptedAt;
    }

    public function markNormalizationDone(): void
    {
        $this->normalizationStatus = RawNormalizationStatus::DONE;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markNormalizationPending(): void
    {
        if (RawNormalizationStatus::DONE === $this->normalizationStatus) {
            throw new \DomainException('Done raw record cannot be reset to pending.');
        }

        $this->normalizationStatus = RawNormalizationStatus::PENDING;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markNormalizationSkipped(): void
    {
        $this->normalizationStatus = RawNormalizationStatus::SKIPPED;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markNormalizationFailed(): void
    {
        $this->normalizationStatus = RawNormalizationStatus::FAILED;
        $this->updatedAt = new \DateTimeImmutable();
    }
}

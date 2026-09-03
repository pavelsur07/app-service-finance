<?php

declare(strict_types=1);

namespace App\Tests\Builders\Ingestion;

use App\Ingestion\Entity\IngestOrder;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use Ramsey\Uuid\Uuid;

final class IngestOrderBuilder
{
    private string $companyId;
    private string $connectionRef = 'connection-1';
    private string $shopRef = 'shop-main';
    private IngestSource $source = IngestSource::OZON;
    private IngestOrderScheme $scheme = IngestOrderScheme::FBO;
    private string $externalId = '00001-0001-1';
    private \DateTimeImmutable $orderedAt;
    private string $rawStatus = 'delivering';
    private IngestOrderStatus $status = IngestOrderStatus::SHIPPED;
    private \DateTimeImmutable $statusObservedAt;

    /** @var array<string, mixed>|null */
    private ?array $attributes = null;

    private ?string $externalOrderId = null;

    private ?\DateTimeImmutable $refreshAttemptedAt = null;

    /**
     * Заказ без сырья не существует: он всегда создаётся нормализацией.
     * Билдер повторяет это, иначе тесты проверяли бы недостижимое состояние.
     */
    private ?string $lastRawRecordId;

    private function __construct()
    {
        $this->lastRawRecordId = Uuid::uuid7()->toString();
        $this->companyId = Uuid::uuid7()->toString();
        $this->orderedAt = new \DateTimeImmutable('-2 days');
        $this->statusObservedAt = new \DateTimeImmutable('-1 hour');
    }

    public static function anOrder(): self
    {
        return new self();
    }

    public function forCompany(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withSource(IngestSource $source): self
    {
        $clone = clone $this;
        $clone->source = $source;

        return $clone;
    }

    public function withScheme(IngestOrderScheme $scheme): self
    {
        $clone = clone $this;
        $clone->scheme = $scheme;

        return $clone;
    }

    public function withConnectionRef(string $connectionRef): self
    {
        $clone = clone $this;
        $clone->connectionRef = $connectionRef;

        return $clone;
    }

    public function withExternalId(string $externalId): self
    {
        $clone = clone $this;
        $clone->externalId = $externalId;

        return $clone;
    }

    public function withStatus(IngestOrderStatus $status, string $rawStatus = 'raw'): self
    {
        $clone = clone $this;
        $clone->status = $status;
        $clone->rawStatus = $rawStatus;

        return $clone;
    }

    public function orderedAt(\DateTimeImmutable $orderedAt): self
    {
        $clone = clone $this;
        $clone->orderedAt = $orderedAt;

        return $clone;
    }

    public function statusObservedAt(\DateTimeImmutable $observedAt): self
    {
        $clone = clone $this;
        $clone->statusObservedAt = $observedAt;

        return $clone;
    }

    public function refreshAttemptedAt(?\DateTimeImmutable $attemptedAt): self
    {
        $clone = clone $this;
        $clone->refreshAttemptedAt = $attemptedAt;

        return $clone;
    }

    public function withExternalOrderId(?string $externalOrderId): self
    {
        $clone = clone $this;
        $clone->externalOrderId = $externalOrderId;

        return $clone;
    }

    public function withLastRawRecordId(?string $rawRecordId): self
    {
        $clone = clone $this;
        $clone->lastRawRecordId = $rawRecordId;

        return $clone;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = $attributes;

        return $clone;
    }

    public function build(): IngestOrder
    {
        $order = new IngestOrder(
            companyId: $this->companyId,
            connectionRef: $this->connectionRef,
            shopRef: $this->shopRef,
            source: $this->source,
            scheme: $this->scheme,
            externalId: $this->externalId,
            orderedAt: $this->orderedAt,
            rawStatus: $this->rawStatus,
            status: $this->status,
            statusObservedAt: $this->statusObservedAt,
            attributes: $this->attributes,
            lastRawRecordId: $this->lastRawRecordId,
            externalOrderId: $this->externalOrderId,
        );

        if (null !== $this->refreshAttemptedAt) {
            $order->markRefreshAttempted($this->refreshAttemptedAt);
        }

        return $order;
    }
}

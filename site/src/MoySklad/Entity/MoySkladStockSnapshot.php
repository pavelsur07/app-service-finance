<?php

declare(strict_types=1);

namespace App\MoySklad\Entity;

use App\MoySklad\Infrastructure\Repository\MoySkladStockSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MoySkladStockSnapshotRepository::class)]
#[ORM\Table(name: 'moysklad_stock_snapshots')]
#[ORM\Index(columns: ['company_id', 'connection_id', 'completed_at'], name: 'idx_moysklad_stock_snapshots_completed', options: ['where' => "status = 'completed'"])]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_stock_snapshots_tenant_id', columns: ['company_id', 'connection_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_stock_snapshots_building', columns: ['connection_id'], options: ['where' => "status = 'building'"])]
class MoySkladStockSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $connectionId;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $status = 'building';

    #[ORM\Column(type: 'datetime_immutable_utc_ms', precision: 3)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable_utc_ms', precision: 3, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(string $id, string $companyId, string $connectionId, \DateTimeImmutable $startedAt)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        $this->id = strtolower($id);
        $this->companyId = strtolower($companyId);
        $this->connectionId = strtolower($connectionId);
        $this->startedAt = $startedAt;
    }

    public function complete(\DateTimeImmutable $completedAt): void
    {
        $this->finish('completed', $completedAt);
    }

    public function fail(\DateTimeImmutable $completedAt): void
    {
        $this->finish('failed', $completedAt);
    }

    private function finish(string $status, \DateTimeImmutable $completedAt): void
    {
        if ('building' !== $this->status) {
            throw new \LogicException('Stock snapshot has already finished.');
        }
        if ($completedAt < $this->startedAt) {
            throw new \InvalidArgumentException('Completion time cannot precede start time.');
        }
        $this->status = $status;
        $this->completedAt = $completedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getConnectionId(): string
    {
        return $this->connectionId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
}

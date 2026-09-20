<?php

declare(strict_types=1);

namespace App\MoySklad\Entity;

use App\MoySklad\Infrastructure\Repository\MoySkladSyncCursorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MoySkladSyncCursorRepository::class)]
#[ORM\Table(name: 'moysklad_sync_cursors')]
#[ORM\Index(columns: ['company_id', 'connection_id'], name: 'idx_moysklad_sync_cursors_company_connection')]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_sync_cursors_connection_type', columns: ['connection_id', 'entity_type'])]
class MoySkladSyncCursor
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $connectionId;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $entityType;

    #[ORM\Column(type: 'datetime_immutable_utc_us', precision: 6, nullable: true)]
    private ?\DateTimeImmutable $lastCompletedAt = null;

    public function __construct(string $id, string $companyId, string $connectionId, string $entityType)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::notEmpty(trim($entityType));
        $this->id = strtolower($id);
        $this->companyId = strtolower($companyId);
        $this->connectionId = strtolower($connectionId);
        $this->entityType = $entityType;
    }

    public function completeAt(\DateTimeImmutable $completedAt): void
    {
        $this->lastCompletedAt = $completedAt;
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

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getLastCompletedAt(): ?\DateTimeImmutable
    {
        return $this->lastCompletedAt;
    }
}

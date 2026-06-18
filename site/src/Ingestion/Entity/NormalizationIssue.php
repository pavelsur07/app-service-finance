<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Domain\TenantOwnedInterface;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Repository\NormalizationIssueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: NormalizationIssueRepository::class)]
#[ORM\Table(name: 'ingest_normalization_issues')]
#[ORM\Index(name: 'idx_norm_issue_company_kind_resolved', columns: ['company_id', 'kind', 'resolved_at'])]
#[ORM\Index(name: 'idx_norm_issue_company_raw', columns: ['company_id', 'raw_record_id'])]
class NormalizationIssue implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $rawRecordId;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $operationGroupId;

    #[ORM\Column(type: Types::STRING, length: 64, enumType: NormalizationIssueKind::class)]
    private NormalizationIssueKind $kind;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $details;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $companyId,
        string $rawRecordId,
        ?string $operationGroupId,
        NormalizationIssueKind $kind,
        array $details,
    ) {
        Assert::uuid($companyId);
        Assert::uuid($rawRecordId);

        if (null !== $operationGroupId) {
            Assert::uuid($operationGroupId);
        }

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->rawRecordId = $rawRecordId;
        $this->operationGroupId = $operationGroupId;
        $this->kind = $kind;
        $this->details = $details;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function markResolved(?\DateTimeImmutable $at = null): void
    {
        if (null !== $this->resolvedAt) {
            return;
        }

        $this->resolvedAt = $at ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getRawRecordId(): string
    {
        return $this->rawRecordId;
    }

    public function getOperationGroupId(): ?string
    {
        return $this->operationGroupId;
    }

    public function getKind(): NormalizationIssueKind
    {
        return $this->kind;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

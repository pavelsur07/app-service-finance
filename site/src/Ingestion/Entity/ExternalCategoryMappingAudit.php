<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'ingest_external_category_mapping_audit')]
#[ORM\Index(name: 'idx_ingest_ext_category_mapping_audit_category', columns: ['external_category_id', 'created_at'])]
#[ORM\Index(name: 'idx_ingest_ext_category_mapping_audit_company', columns: ['company_id', 'created_at'])]
class ExternalCategoryMappingAudit
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ExternalCategory::class)]
    #[ORM\JoinColumn(name: 'external_category_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ExternalCategory $externalCategory;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $companyId;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $scope;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $action;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $oldValues;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $newValues;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $updatedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed> $newValues
     */
    public function __construct(
        ExternalCategory $externalCategory,
        string $scope,
        string $action,
        ?array $oldValues,
        array $newValues,
        ?string $companyId = null,
        ?string $updatedBy = null,
    ) {
        Assert::notEmpty($scope);
        Assert::notEmpty($action);
        if (null !== $companyId) {
            Assert::uuid($companyId);
        }
        if (null !== $updatedBy) {
            Assert::uuid($updatedBy);
        }

        $this->id = Uuid::uuid7()->toString();
        $this->externalCategory = $externalCategory;
        $this->companyId = $companyId;
        $this->scope = $scope;
        $this->action = $action;
        $this->oldValues = $oldValues;
        $this->newValues = $newValues;
        $this->updatedBy = $updatedBy;
        $this->createdAt = new \DateTimeImmutable();
    }
}

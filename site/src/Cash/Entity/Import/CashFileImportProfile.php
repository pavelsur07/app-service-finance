<?php

namespace App\Cash\Entity\Import;

use App\Cash\Repository\Import\CashFileImportProfileRepository;
use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CashFileImportProfileRepository::class)]
#[ORM\Table(name: 'cash_file_import_profile')]
#[ORM\Index(name: 'idx_cash_file_import_profile_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_cash_file_import_profile_company_type', columns: ['company_id', 'type'])]
#[ORM\HasLifecycleCallbacks]
class CashFileImportProfile
{
    public const TYPE_CASH_TRANSACTION = 'cash_transaction';

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: null)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 64)]
    private string $type = self::TYPE_CASH_TRANSACTION;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $mapping = [];

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $options
     */
    public function __construct(
        string $id,
        Company $company,
        string $name,
        array $mapping = [],
        array $options = [],
        string $type = self::TYPE_CASH_TRANSACTION,
    ) {
        $this->id = $id;
        $this->company = $company;
        $this->name = $name;
        $this->mapping = $mapping;
        $this->options = $options;
        $this->type = $type;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): void
    {
        $this->company = $company;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMapping(): array
    {
        return $this->mapping;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    public function setMapping(array $mapping): void
    {
        $this->mapping = $mapping;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function setTimestampsOnCreate(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function setTimestampsOnUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

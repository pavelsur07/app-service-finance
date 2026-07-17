<?php

declare(strict_types=1);

namespace App\Company\Entity;

use App\Company\Enum\FinancialResponsibilityCenterStatus;
use App\Company\Repository\FinancialResponsibilityCenterRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: FinancialResponsibilityCenterRepository::class)]
#[ORM\Table(name: 'financial_responsibility_centers')]
#[ORM\UniqueConstraint(name: 'uniq_frc_company_code', columns: ['company_id', 'code'])]
#[ORM\Index(name: 'idx_frc_company_status_sort', columns: ['company_id', 'status', 'sort'])]
class FinancialResponsibilityCenter
{
    public const CODE_GENERAL = 'CFO_GENERAL';
    public const NAME_GENERAL = 'Общий';

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $code;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sort = 0;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: FinancialResponsibilityCenterStatus::class)]
    private FinancialResponsibilityCenterStatus $status = FinancialResponsibilityCenterStatus::ACTIVE;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $companyId, string $code, string $name)
    {
        Assert::uuid($companyId);
        Assert::regex($code, '/^[A-Z][A-Z0-9_]*$/');
        Assert::maxLength($code, 64);

        $name = \trim($name);
        Assert::notEmpty($name);
        Assert::maxLength($name, 255);

        $now = new \DateTimeImmutable();
        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->code = $code;
        $this->name = $name;
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $name = \trim($name);
        Assert::notEmpty($name);
        Assert::maxLength($name, 255);

        if ($this->isSystem() && self::NAME_GENERAL !== $name) {
            throw new \DomainException('Системный ЦФО нельзя переименовать.');
        }

        if ($this->name === $name) {
            return;
        }

        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSort(): int
    {
        return $this->sort;
    }

    public function setSort(int $sort): void
    {
        if ($this->sort === $sort) {
            return;
        }

        $this->sort = $sort;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getStatus(): FinancialResponsibilityCenterStatus
    {
        return $this->status;
    }

    public function archive(): void
    {
        if ($this->isSystem()) {
            throw new \DomainException('Системный ЦФО нельзя архивировать.');
        }

        if (FinancialResponsibilityCenterStatus::ARCHIVED === $this->status) {
            return;
        }

        $this->status = FinancialResponsibilityCenterStatus::ARCHIVED;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function restore(): void
    {
        if (FinancialResponsibilityCenterStatus::ACTIVE === $this->status) {
            return;
        }

        $this->status = FinancialResponsibilityCenterStatus::ACTIVE;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isSystem(): bool
    {
        return self::CODE_GENERAL === $this->code;
    }

    public function getVersion(): int
    {
        return $this->version;
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

<?php

declare(strict_types=1);

namespace App\Company\Entity;

use App\Company\Repository\FinancialResponsibilityCenterProjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: FinancialResponsibilityCenterProjectRepository::class)]
#[ORM\Table(name: 'financial_responsibility_center_projects')]
#[ORM\UniqueConstraint(name: 'uniq_frc_project_pair', columns: ['project_direction_id', 'responsibility_center_id'])]
#[ORM\Index(name: 'idx_frc_project_company_center', columns: ['company_id', 'responsibility_center_id'])]
#[ORM\Index(name: 'idx_frc_project_company_project', columns: ['company_id', 'project_direction_id'])]
class FinancialResponsibilityCenterProject
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: ProjectDirection::class)]
    #[ORM\JoinColumn(name: 'project_direction_id', nullable: false, onDelete: 'RESTRICT')]
    private ProjectDirection $projectDirection;

    #[ORM\ManyToOne(targetEntity: FinancialResponsibilityCenter::class)]
    #[ORM\JoinColumn(name: 'responsibility_center_id', nullable: false, onDelete: 'RESTRICT')]
    private FinancialResponsibilityCenter $responsibilityCenter;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $companyId,
        ProjectDirection $projectDirection,
        FinancialResponsibilityCenter $responsibilityCenter,
    ) {
        Assert::uuid($companyId);
        Assert::same($companyId, $projectDirection->getCompany()->getId(), 'Проект принадлежит другой компании.');
        Assert::same($companyId, $responsibilityCenter->getCompanyId(), 'ЦФО принадлежит другой компании.');

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->projectDirection = $projectDirection;
        $this->responsibilityCenter = $responsibilityCenter;
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

    public function getProjectDirection(): ProjectDirection
    {
        return $this->projectDirection;
    }

    public function getResponsibilityCenter(): FinancialResponsibilityCenter
    {
        return $this->responsibilityCenter;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

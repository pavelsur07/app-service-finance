<?php

declare(strict_types=1);

namespace App\Company\Entity;

use App\Company\Repository\ConsolidationMembershipRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Компания в группе консолидации. У головной компании своя строка с headCompanyId = companyId,
 * поэтому PK по company_id держит все инварианты группы: компания не входит в две группы,
 * головная не участник чужой группы, участник не головная. FK на companies — в миграции.
 */
#[ORM\Entity(repositoryClass: ConsolidationMembershipRepository::class)]
#[ORM\Table(name: 'company_consolidation_member')]
#[ORM\Index(name: 'idx_company_consolidation_member_head', columns: ['head_company_id'])]
class ConsolidationMembership
{
    #[ORM\Id]
    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\Column(name: 'head_company_id', type: 'guid')]
    private string $headCompanyId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $companyId, string $headCompanyId, \DateTimeImmutable $now)
    {
        Assert::uuid($companyId);
        Assert::uuid($headCompanyId);
        $this->companyId = $companyId;
        $this->headCompanyId = $headCompanyId;
        $this->createdAt = $now;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getHeadCompanyId(): string
    {
        return $this->headCompanyId;
    }

    public function isHead(): bool
    {
        return $this->companyId === $this->headCompanyId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

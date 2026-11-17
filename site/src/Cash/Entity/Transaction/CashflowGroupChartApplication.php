<?php

declare(strict_types=1);

namespace App\Cash\Entity\Transaction;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Применение синхронизации дерева ДДС с эталоном группы, которое что-то изменило.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cashflow_group_chart_applications')]
#[ORM\Index(name: 'idx_cashflow_group_chart_applications_company', columns: ['company_id', 'applied_at'])]
class CashflowGroupChartApplication
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\Column(length: 8)]
    private string $source;

    #[ORM\Column(name: 'head_company_id', type: 'guid', nullable: true)]
    private ?string $headCompanyId;

    #[ORM\Column(name: 'bound_count', type: 'integer')]
    private int $boundCount;

    #[ORM\Column(name: 'created_count', type: 'integer')]
    private int $createdCount;

    #[ORM\Column(name: 'conflict_count', type: 'integer')]
    private int $conflictCount;

    #[ORM\Column(name: 'head_codes_count', type: 'integer')]
    private int $headCodesCount;

    #[ORM\Column(name: 'applied_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $appliedAt;

    public function __construct(
        string $companyId,
        string $source,
        ?string $headCompanyId,
        int $boundCount,
        int $createdCount,
        int $conflictCount,
        int $headCodesCount,
        \DateTimeImmutable $appliedAt,
        ?string $id = null,
    ) {
        Assert::uuid($companyId);
        Assert::oneOf($source, ['core', 'head']);
        Assert::nullOrUuid($headCompanyId);
        Assert::greaterThan($boundCount + $createdCount + $headCodesCount, 0, 'Записывается только применение, которое что-то изменило.');
        if (null !== $id) {
            Assert::uuid($id);
        }

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->source = $source;
        $this->headCompanyId = $headCompanyId;
        $this->boundCount = $boundCount;
        $this->createdCount = $createdCount;
        $this->conflictCount = $conflictCount;
        $this->headCodesCount = $headCodesCount;
        $this->appliedAt = $appliedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }
}

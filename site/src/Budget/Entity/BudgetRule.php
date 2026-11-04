<?php

declare(strict_types=1);

namespace App\Budget\Entity;

use App\Budget\Domain\BudgetFillMath;
use App\Budget\Repository\BudgetRuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Правило «X % от строки-базы» для листа плана в паре «проект × ЦФО» варианта (задача pl-budget,
 * часть 4). Суммы статьи по месяцам считает BudgetRuleCalculator и пишет в budget_lines с
 * источником `rule`; статьи с правилом в базу не входят.
 *
 * Статья, база, проект и ЦФО — строковые ссылки без внешних ключей (как у BudgetLine). Пишется
 * через DBAL под блокировкой варианта (SetBudgetRuleAction); сущность — для схемы, чтения и тестов.
 */
#[ORM\Entity(repositoryClass: BudgetRuleRepository::class)]
#[ORM\Table(name: 'budget_rules')]
#[ORM\UniqueConstraint(name: 'uniq_budget_rules_target', columns: ['version_id', 'pl_category_id', 'project_direction_id', 'responsibility_center_id'])]
#[ORM\Index(name: 'idx_budget_rules_company', columns: ['company_id'])]
class BudgetRule
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: BudgetVersion::class)]
    #[ORM\JoinColumn(name: 'version_id', nullable: false, onDelete: 'CASCADE')]
    private BudgetVersion $version;

    #[ORM\Column(name: 'pl_category_id', type: 'guid')]
    private string $plCategoryId;

    #[ORM\Column(name: 'project_direction_id', type: 'guid')]
    private string $projectDirectionId;

    #[ORM\Column(name: 'responsibility_center_id', type: 'guid')]
    private string $responsibilityCenterId;

    #[ORM\Column(name: 'base_category_id', type: 'guid')]
    private string $baseCategoryId;

    /** Процент 0–1000, два знака после точки. */
    #[ORM\Column(type: 'decimal', precision: 6, scale: 2)]
    private string $percent;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        BudgetVersion $version,
        string $plCategoryId,
        string $projectDirectionId,
        string $responsibilityCenterId,
        string $baseCategoryId,
        string $percent,
        \DateTimeImmutable $now,
    ) {
        Assert::uuid($id);
        Assert::uuid($plCategoryId);
        Assert::uuid($projectDirectionId);
        Assert::uuid($responsibilityCenterId);
        Assert::uuid($baseCategoryId);
        Assert::notSame($plCategoryId, $baseCategoryId, 'Статья не может считаться от самой себя.');
        Assert::true(BudgetFillMath::isPercent($percent), 'Процент — число от 0 до 1000, до двух знаков после точки.');

        $this->id = $id;
        $this->companyId = $version->getCompanyId();
        $this->version = $version;
        $this->plCategoryId = $plCategoryId;
        $this->projectDirectionId = $projectDirectionId;
        $this->responsibilityCenterId = $responsibilityCenterId;
        $this->baseCategoryId = $baseCategoryId;
        $this->percent = $percent;
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

    public function getVersion(): BudgetVersion
    {
        return $this->version;
    }

    public function getPlCategoryId(): string
    {
        return $this->plCategoryId;
    }

    public function getProjectDirectionId(): string
    {
        return $this->projectDirectionId;
    }

    public function getResponsibilityCenterId(): string
    {
        return $this->responsibilityCenterId;
    }

    public function getBaseCategoryId(): string
    {
        return $this->baseCategoryId;
    }

    public function getPercent(): string
    {
        return $this->percent;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Repository\MarketplaceCostPLMappingRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Маппинг категории затрат маркетплейса к категории ОПиУ.
 *
 * include_in_pl = false — затрата исключается из расчёта ОПиУ
 * (например: общие затраты на рекламу когда есть детализация по SKU).
 *
 * Один к одному: одна категория затрат → один маппинг.
 */
#[ORM\Entity(repositoryClass: MarketplaceCostPLMappingRepository::class)]
#[ORM\Table(name: 'marketplace_cost_pl_mappings')]
#[ORM\UniqueConstraint(
    name: 'uniq_cost_pl_mapping',
    columns: ['company_id', 'cost_category_id'],
)]
#[ORM\Index(columns: ['company_id'], name: 'idx_cost_pl_mapping_company')]
class MarketplaceCostPLMapping
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: MarketplaceCostCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MarketplaceCostCategory $costCategory;

    /**
     * UUID категории ОПиУ из модуля Finance.
     * Nullable — категория может быть без маппинга (тогда не попадает в ОПиУ).
     */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $plCategoryId;

    /**
     * Включать ли в расчёт ОПиУ.
     * false — исключить даже если plCategoryId заполнен.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $includeInPl = true;

    /** Затраты всегда отрицательные в ОПиУ */
    #[ORM\Column(type: 'boolean')]
    private bool $isNegative = true;

    #[ORM\Column(type: 'smallint')]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $companyId,
        MarketplaceCostCategory $costCategory,
        ?string $plCategoryId,
        bool $includeInPl = true,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);

        $this->id           = $id;
        $this->companyId    = $companyId;
        $this->costCategory = $costCategory;
        $this->plCategoryId = $plCategoryId;
        $this->includeInPl  = $includeInPl;
        $this->createdAt    = new \DateTimeImmutable();
        $this->updatedAt    = new \DateTimeImmutable();
    }

    public function update(?string $plCategoryId, bool $includeInPl, int $sortOrder): void
    {
        $this->plCategoryId = $plCategoryId;
        $this->includeInPl  = $includeInPl;
        $this->sortOrder    = $sortOrder;
        $this->updatedAt    = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getCostCategory(): MarketplaceCostCategory { return $this->costCategory; }
    public function getPlCategoryId(): ?string { return $this->plCategoryId; }
    public function isIncludeInPl(): bool { return $this->includeInPl; }
    public function isNegative(): bool { return $this->isNegative; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}

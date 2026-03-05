<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Company\Entity\Company;
use App\Entity\ProjectDirection;
use App\Entity\PLCategory;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceSaleMappingRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Маппинг: какое поле продажи/возврата → в какую категорию ОПиУ.
 *
 * Одна продажа может создать несколько строк ОПиУ:
 *   marketplace=WB, operationType=sale, amountSource=SALE_GROSS      → PLCategory "Выкупы без СПП"
 *   marketplace=WB, operationType=sale, amountSource=SALE_REVENUE    → PLCategory "Выручка с СПП"
 *   marketplace=WB, operationType=sale, amountSource=SALE_COST_PRICE → PLCategory "Себестоимость"
 *
 * isNegative — инвертирует знак суммы (для расходных строк и возвратов).
 */
#[ORM\Entity(repositoryClass: MarketplaceSaleMappingRepository::class)]
#[ORM\Table(name: 'marketplace_sale_mappings')]
#[ORM\UniqueConstraint(
    name: 'uniq_sale_mapping',
    columns: ['company_id', 'marketplace', 'operation_type', 'amount_source', 'pl_category_id']
)]
#[ORM\Index(columns: ['company_id', 'marketplace', 'operation_type'], name: 'idx_mapping_lookup')]
class MarketplaceSaleMapping
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    /**
     * Тип операции: 'sale' или 'return'.
     * Соответствует AmountSource::getOperationType().
     */
    #[ORM\Column(type: 'string', length: 10)]
    private string $operationType;

    #[ORM\Column(type: 'string', enumType: AmountSource::class)]
    private AmountSource $amountSource;

    #[ORM\ManyToOne(targetEntity: PLCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PLCategory $plCategory;

    #[ORM\ManyToOne(targetEntity: ProjectDirection::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProjectDirection $projectDirection = null;

    /**
     * Инвертировать знак суммы.
     *
     * true → сумма будет отрицательной (расходы, возвраты).
     * false → сумма будет положительной (доходы).
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isNegative = false;

    /**
     * Шаблон описания для строки ОПиУ.
     * Пример: "Выручка с СПП — WB", "Себестоимость — Ozon"
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $descriptionTemplate = null;

    /**
     * Порядок сортировки строк в документе ОПиУ.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        Company $company,
        MarketplaceType $marketplace,
        AmountSource $amountSource,
        PLCategory $plCategory,
    ) {
        Assert::uuid($id);
        Assert::inArray($amountSource->getOperationType(), ['sale', 'return']);

        $this->id = $id;
        $this->company = $company;
        $this->marketplace = $marketplace;
        $this->amountSource = $amountSource;
        $this->operationType = $amountSource->getOperationType();
        $this->plCategory = $plCategory;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    // === Getters ===

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getOperationType(): string
    {
        return $this->operationType;
    }

    public function getAmountSource(): AmountSource
    {
        return $this->amountSource;
    }

    public function getPlCategory(): PLCategory
    {
        return $this->plCategory;
    }

    public function getProjectDirection(): ?ProjectDirection
    {
        return $this->projectDirection;
    }

    public function isNegative(): bool
    {
        return $this->isNegative;
    }

    public function getDescriptionTemplate(): ?string
    {
        return $this->descriptionTemplate;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    // === Setters ===

    public function setPlCategory(PLCategory $plCategory): self
    {
        $this->plCategory = $plCategory;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function setProjectDirection(?ProjectDirection $projectDirection): self
    {
        $this->projectDirection = $projectDirection;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function setIsNegative(bool $isNegative): self
    {
        $this->isNegative = $isNegative;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function setDescriptionTemplate(?string $descriptionTemplate): self
    {
        $this->descriptionTemplate = $descriptionTemplate;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}

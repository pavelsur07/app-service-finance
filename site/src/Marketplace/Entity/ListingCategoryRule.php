<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Правило «категория площадки → категория листингов компании». Ключ площадки —
 * `marketplace_listings.marketplace_data->>'category_key'` (`ozon:<description_category_id>:<type_id>`,
 * `wb:<subjectID>`). Пишется `ListingCategoryRuleQuery::upsert()`, применяется `apply()`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_listing_category_rules')]
#[ORM\UniqueConstraint(name: 'uniq_listing_category_rule_key', columns: ['company_id', 'source_key'])]
class ListingCategoryRule
{
    public const SOURCE_KEY_PATTERN = '/^(ozon:\d+:\d+|wb:\d+)$/';

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\Column(name: 'source_key', length: 64)]
    private string $sourceKey;

    #[ORM\ManyToOne(targetEntity: MarketplaceListingCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: false, onDelete: 'CASCADE')]
    private MarketplaceListingCategory $category;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(MarketplaceListingCategory $category, string $sourceKey, ?string $id = null)
    {
        Assert::regex($sourceKey, self::SOURCE_KEY_PATTERN);
        if (null !== $id) {
            Assert::uuid($id);
        }

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->companyId = $category->getCompanyId();
        $this->sourceKey = $sourceKey;
        $this->category = $category;
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

    public function getSourceKey(): string
    {
        return $this->sourceKey;
    }

    public function getCategory(): MarketplaceListingCategory
    {
        return $this->category;
    }
}

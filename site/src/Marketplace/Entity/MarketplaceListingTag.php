<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'marketplace_listing_tags')]
#[ORM\UniqueConstraint(name: 'uniq_listing_tag_company_slug', columns: ['company_id', 'slug'])]
class MarketplaceListingTag
{
    public const NAME_MAX_LENGTH = 50;

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $slug;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, string $companyId, string $name)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);

        $this->id = $id;
        $this->companyId = $companyId;
        $this->createdAt = new \DateTimeImmutable();

        $this->applyName($name);
    }

    /**
     * Единая нормализация: «Зима», « зима » и «ЗИМА» дают один и тот же slug,
     * а уникальный индекс (company_id, slug) не даёт завести дубли.
     */
    public static function slugify(string $name): string
    {
        return \mb_strtolower(trim($name));
    }

    public function rename(string $name): void
    {
        $this->applyName($name);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function applyName(string $name): void
    {
        $normalized = trim($name);

        Assert::stringNotEmpty($normalized, 'Tag name must not be empty.');
        Assert::maxLength(
            $normalized,
            self::NAME_MAX_LENGTH,
            sprintf('Tag name must not exceed %d characters.', self::NAME_MAX_LENGTH),
        );

        $this->name = $normalized;
        $this->slug = self::slugify($normalized);
    }
}

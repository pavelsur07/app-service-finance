<?php

declare(strict_types=1);

namespace App\Finance\Entity;

use App\Company\Enum\BusinessProfile;
use App\Finance\Enum\PLTemplateApplicationSource;
use App\Finance\Repository\PLTemplateApplicationRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Запись о применении стартового шаблона ОПиУ, которое создало статьи у компании. По первой записи
 * известно происхождение дерева: шаблон другого профиля к нему не применяется.
 */
#[ORM\Entity(repositoryClass: PLTemplateApplicationRepository::class)]
#[ORM\Table(name: 'pl_template_applications')]
#[ORM\Index(name: 'idx_pl_template_applications_company', columns: ['company_id', 'applied_at'])]
class PLTemplateApplication
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(length: 32, enumType: BusinessProfile::class)]
    private BusinessProfile $profile;

    #[ORM\Column(type: 'integer')]
    private int $templateVersion;

    #[ORM\Column(type: 'integer')]
    private int $createdCount;

    #[ORM\Column(length: 16, enumType: PLTemplateApplicationSource::class)]
    private PLTemplateApplicationSource $source;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $appliedAt;

    public function __construct(
        string $companyId,
        BusinessProfile $profile,
        int $templateVersion,
        int $createdCount,
        PLTemplateApplicationSource $source,
        \DateTimeImmutable $appliedAt,
        ?string $id = null,
    ) {
        Assert::uuid($companyId);
        Assert::greaterThan($createdCount, 0, 'Записывается только применение, которое создало статьи.');
        if (null !== $id) {
            Assert::uuid($id);
        }

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->profile = $profile;
        $this->templateVersion = $templateVersion;
        $this->createdCount = $createdCount;
        $this->source = $source;
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

    public function getProfile(): BusinessProfile
    {
        return $this->profile;
    }

    public function getTemplateVersion(): int
    {
        return $this->templateVersion;
    }

    public function getCreatedCount(): int
    {
        return $this->createdCount;
    }

    public function getSource(): PLTemplateApplicationSource
    {
        return $this->source;
    }

    public function getAppliedAt(): \DateTimeImmutable
    {
        return $this->appliedAt;
    }
}

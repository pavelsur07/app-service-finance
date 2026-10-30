<?php

declare(strict_types=1);

namespace App\Cash\Entity\Transaction;

use App\Cash\Repository\Transaction\CashAiCategorizationSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Настройки ИИ-категоризации компании. Нет строки — автоприменение выключено.
 */
#[ORM\Entity(repositoryClass: CashAiCategorizationSettingRepository::class)]
#[ORM\Table(name: 'cash_ai_categorization_setting')]
class CashAiCategorizationSetting
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $autoApply = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $companyId, \DateTimeImmutable $createdAt)
    {
        Assert::uuid($companyId);
        $this->companyId = $companyId;
        $this->updatedAt = $createdAt;
    }

    public function changeAutoApply(bool $enabled, \DateTimeImmutable $at): void
    {
        if ($this->autoApply === $enabled) {
            return;
        }
        $this->autoApply = $enabled;
        $this->updatedAt = $at;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function isAutoApply(): bool
    {
        return $this->autoApply;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

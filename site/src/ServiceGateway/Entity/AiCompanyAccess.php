<?php

declare(strict_types=1);

namespace App\ServiceGateway\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Компания подключена к ИИ-функциям: есть строка — включено, нет — выключено.
 * Включает и выключает администратор (`/admin/users`, `SetAiCompanyAccessAction`), читает
 * `AiServiceAccess` — обе стороны через DBAL; общий выключатель — `AI_SERVICE_ENABLED`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_company_access')]
#[ORM\UniqueConstraint(name: 'uq_ai_company_access_company', columns: ['company_id'])]
class AiCompanyAccess
{
    public const DEFAULT_MONTHLY_LIMIT_RUB = '500.00';
    public const DEFAULT_LOOKBACK_DAYS = 90;
    public const LOOKBACK_CHOICES = [7, 30, 90];

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $enabledAt;

    /** Администратор, включивший доступ; null — перенос из env-списка. */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $enabledByUserId;

    /** Лимит расходов ИИ компании за календарный месяц, ₽. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => 500])]
    private string $monthlyLimitRub = self::DEFAULT_MONTHLY_LIMIT_RUB;

    /** Окно операций, которые уходят в ИИ: 7, 30 или 90 дней. */
    #[ORM\Column(type: 'smallint', options: ['default' => 90])]
    private int $lookbackDays = self::DEFAULT_LOOKBACK_DAYS;

    public function __construct(
        string $companyId,
        \DateTimeImmutable $enabledAt,
        ?string $enabledByUserId,
        ?string $id = null,
    ) {
        $id ??= Uuid::uuid7()->toString();
        Assert::uuid($id);
        Assert::uuid($companyId);
        if (null !== $enabledByUserId) {
            Assert::uuid($enabledByUserId);
        }

        $this->id = $id;
        $this->companyId = strtolower($companyId);
        $this->enabledAt = $enabledAt;
        $this->enabledByUserId = $enabledByUserId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getEnabledAt(): \DateTimeImmutable
    {
        return $this->enabledAt;
    }

    public function getEnabledByUserId(): ?string
    {
        return $this->enabledByUserId;
    }
}

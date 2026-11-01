<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Enum\TariffCode;
use App\Billing\Repository\TariffRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Тариф справочника. Строки создаёт миграция; публичные тарифы видны клиентам,
 * скрытые назначает только администратор. Бессрочный тариф («Друг») не имеет даты окончания.
 */
#[ORM\Entity(repositoryClass: TariffRepository::class)]
#[ORM\Table(name: 'billing_tariffs')]
#[ORM\UniqueConstraint(name: 'uniq_billing_tariffs_code', columns: ['code'])]
class Tariff
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(length: 32, enumType: TariffCode::class)]
    private TariffCode $code;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(name: 'is_public')]
    private bool $public;

    #[ORM\Column(name: 'is_perpetual')]
    private bool $perpetual;

    #[ORM\Column(name: 'is_active')]
    private bool $active;

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        TariffCode $code,
        string $name,
        bool $public,
        bool $perpetual,
        int $sortOrder,
        \DateTimeImmutable $now,
        ?string $id = null,
    ) {
        if (null !== $id) {
            Assert::uuid($id);
        }
        Assert::stringNotEmpty(trim($name), 'Название тарифа обязательно.');

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->code = $code;
        $this->name = trim($name);
        $this->public = $public;
        $this->perpetual = $perpetual;
        $this->active = true;
        $this->sortOrder = $sortOrder;
        $this->createdAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCode(): TariffCode
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function isPerpetual(): bool
    {
        return $this->perpetual;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
}

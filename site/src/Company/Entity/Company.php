<?php

declare(strict_types=1);

namespace App\Company\Entity;

use App\Company\Enum\BusinessProfile;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: '`companies`')]
#[ORM\UniqueConstraint(name: 'uniq_companies_public_id', columns: ['public_id'])]
class Company
{
    /** Срок между запросом удаления и окончательной очисткой данных компании. */
    public const DELETION_GRACE_DAYS = 30;

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\Column(name: 'public_id', type: 'integer', insertable: false, updatable: false, options: ['default' => "nextval('companies_public_id_seq'::regclass)"], generated: 'INSERT')]
    private int $publicId;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $inn = null;

    #[ORM\ManyToOne(inversedBy: 'companies')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $financeLockBefore = null;

    #[ORM\Column(length: 32, enumType: BusinessProfile::class, options: ['default' => 'MARKETPLACE'])]
    private BusinessProfile $businessProfile = BusinessProfile::DEFAULT;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'minimum_balance_')]
    private Money $minimumBalance;

    /** Когда владелец запросил удаление; null — компания работает. */
    #[ORM\Column(name: 'deletion_requested_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletionRequestedAt = null;

    /** Кто запросил удаление (id пользователя) — для журнала и разбора. */
    #[ORM\Column(name: 'deletion_requested_by', type: 'guid', nullable: true)]
    private ?string $deletionRequestedBy = null;

    public function __construct(string $id, User $user)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->user = $user;
        $this->minimumBalance = Money::fromMinor(0, 'RUB');
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getPublicId(): ?int
    {
        return $this->publicId ?? null;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getInn(): ?string
    {
        return $this->inn;
    }

    public function setInn(?string $inn): self
    {
        $this->inn = $inn;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getFinanceLockBefore(): ?\DateTimeImmutable
    {
        return $this->financeLockBefore;
    }

    public function setFinanceLockBefore(?\DateTimeImmutable $date): self
    {
        $this->financeLockBefore = $date ? $date->setTime(0, 0) : null;

        return $this;
    }

    public function getBusinessProfile(): BusinessProfile
    {
        return $this->businessProfile;
    }

    public function setBusinessProfile(BusinessProfile $businessProfile): self
    {
        $this->businessProfile = $businessProfile;

        return $this;
    }

    public function getMinimumBalance(): Money
    {
        return $this->minimumBalance;
    }

    public function setMinimumBalance(Money $minimumBalance): self
    {
        if ($minimumBalance->isNegative()) {
            throw new \DomainException('Минимальный остаток не может быть отрицательным.');
        }

        $this->minimumBalance = $minimumBalance;

        return $this;
    }

    /**
     * Помечает компанию к удалению. Повторный запрос ничего не меняет: дата и срок
     * очистки считаются от первого запроса.
     *
     * @return bool true — пометка поставлена сейчас, false — уже стояла
     */
    public function requestDeletion(\DateTimeImmutable $now, string $requestedByUserId): bool
    {
        Assert::uuid($requestedByUserId);

        if (null !== $this->deletionRequestedAt) {
            return false;
        }

        $this->deletionRequestedAt = $now;
        $this->deletionRequestedBy = $requestedByUserId;

        return true;
    }

    /**
     * Снимает пометку удаления.
     *
     * @return bool true — пометка снята сейчас, false — её не было
     */
    public function cancelDeletion(): bool
    {
        if (null === $this->deletionRequestedAt) {
            return false;
        }

        $this->deletionRequestedAt = null;
        $this->deletionRequestedBy = null;

        return true;
    }

    public function isDeletionRequested(): bool
    {
        return null !== $this->deletionRequestedAt;
    }

    public function getDeletionRequestedAt(): ?\DateTimeImmutable
    {
        return $this->deletionRequestedAt;
    }

    public function getDeletionRequestedBy(): ?string
    {
        return $this->deletionRequestedBy;
    }

    /** С этого момента данные компании удаляются окончательно. */
    public function getDeletionDueAt(): ?\DateTimeImmutable
    {
        return $this->deletionRequestedAt?->modify(sprintf('+%d days', self::DELETION_GRACE_DAYS));
    }
}

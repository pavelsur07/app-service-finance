<?php

declare(strict_types=1);

namespace App\Company\Entity;

use App\Company\Domain\ValueObject\CounterpartyName;
use App\Company\Enum\CounterpartyType;
use App\Company\Repository\CounterpartyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Webmozart\Assert\Assert as WebAssert;

#[ORM\Entity(repositoryClass: CounterpartyRepository::class)]
#[ORM\Table(name: '`counterparty`')]
#[ORM\Index(name: 'idx_counterparty_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_counterparty_company_inn', columns: ['company_id', 'inn'])]
#[ORM\Index(name: 'idx_counterparty_company_name_core', columns: ['company_id', 'name_core'])]
class Counterparty
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    /**
     * Артефакт разбора названия, не правовой статус: только для того, чтобы
     * «ООО "Ромашка"» и «"Ромашка" ООО» давали одинаковый nameCore.
     * Авторитетные источники статуса — CounterpartyType и длина ИНН.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $legalFormHint = null;

    /**
     * Нормализованное название для поиска и сравнения. Логически обязательно, но
     * остаётся nullable до backfill существующих строк: типизированное `string`
     * упало бы на гидрации ещё не пересчитанной записи.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nameCore = null;

    #[ORM\Column(length: 12, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{10}(\d{2})?$/')]
    private ?string $inn = null;

    #[ORM\Column(length: 9, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{9}$/')]
    private ?string $kpp = null;

    #[ORM\Column(enumType: CounterpartyType::class)]
    private CounterpartyType $type;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'boolean')]
    private bool $isArchived = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $averageDelayDays = null;

    #[ORM\Column(type: 'integer', options: ['default' => 100])]
    private int $reliabilityScore = 100;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastScoredAt = null;

    public function __construct(string $id, Company $company, CounterpartyName $name, CounterpartyType $type)
    {
        WebAssert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->type = $type;
        $this->applyName($name);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    /**
     * Контрагент не меняет компанию никогда: сеттера нет намеренно (вектор IDOR).
     */
    public function belongsToCompany(string $companyId): bool
    {
        return $this->company->getId() === $companyId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(CounterpartyName $name): void
    {
        $this->applyName($name);
        $this->touch();
    }

    /**
     * Пересчёт производных полей у существующей записи (backfill).
     *
     * Ни `name`, ни `updatedAt` не меняются: это не правка пользователя. Переименовать
     * этим методом нельзя — переданное значение обязано соответствовать текущему name.
     */
    public function refreshNormalizedName(CounterpartyName $name): void
    {
        if (!$name->isDisplayOf($this->name)) {
            throw new \InvalidArgumentException('Пересчёт производных полей не может менять название.');
        }

        $this->applyDerivedName($name);
    }

    public function getLegalFormHint(): ?string
    {
        return $this->legalFormHint;
    }

    public function getNameCore(): ?string
    {
        return $this->nameCore;
    }

    /**
     * ИНН из 10 знаков = юрлицо, поэтому разобранный «ИП» означает ошибку парсера
     * названия. Вызывается после установки названия и ИНН.
     */
    public function hasInconsistentLegalFormHint(): bool
    {
        return 'ИП' === $this->legalFormHint
            && null !== $this->inn
            && 10 === strlen($this->inn);
    }

    /**
     * Сбрасывает подсказку ОПФ, не затрагивая название: «исправлять» name нельзя.
     */
    public function clearLegalFormHint(): void
    {
        $this->legalFormHint = null;
        $this->touch();
    }

    public function getInn(): ?string
    {
        return $this->inn;
    }

    public function getKpp(): ?string
    {
        return $this->kpp;
    }

    public function hasTaxId(): bool
    {
        return null !== $this->inn;
    }

    public function assignTaxIds(?string $inn, ?string $kpp): void
    {
        if (null === $inn && null !== $kpp) {
            throw new \InvalidArgumentException('КПП не может быть задан без ИНН.');
        }

        $this->inn = $inn;
        $this->kpp = $kpp;
        $this->touch();
    }

    public function getType(): CounterpartyType
    {
        return $this->type;
    }

    public function setType(CounterpartyType $type): self
    {
        $this->type = $type;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function archive(): void
    {
        $this->isArchived = true;
        $this->touch();
    }

    public function restore(): void
    {
        $this->isArchived = false;
        $this->touch();
    }

    public function getAverageDelayDays(): ?int
    {
        return $this->averageDelayDays;
    }

    public function setAverageDelayDays(?int $averageDelayDays): self
    {
        $this->averageDelayDays = $averageDelayDays;

        return $this;
    }

    public function getReliabilityScore(): int
    {
        return $this->reliabilityScore;
    }

    public function setReliabilityScore(int $reliabilityScore): self
    {
        $this->reliabilityScore = $reliabilityScore;

        return $this;
    }

    public function getLastScoredAt(): ?\DateTimeImmutable
    {
        return $this->lastScoredAt;
    }

    public function setLastScoredAt(?\DateTimeImmutable $lastScoredAt): self
    {
        $this->lastScoredAt = $lastScoredAt;

        return $this;
    }

    /**
     * Инвариант: name, legalFormHint и nameCore записываются только вместе и
     * только из нормализованного значения.
     */
    private function applyName(CounterpartyName $name): void
    {
        $this->name = $name->display;
        $this->applyDerivedName($name);
    }

    private function applyDerivedName(CounterpartyName $name): void
    {
        $this->legalFormHint = $name->legalFormHint;
        $this->nameCore = $name->core;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

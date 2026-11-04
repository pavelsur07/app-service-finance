<?php

declare(strict_types=1);

namespace App\Budget\Entity;

use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\InvalidBudgetVersionTransitionException;
use App\Budget\Repository\BudgetVersionRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Вариант бюджета ОПиУ компании на календарный год. Редактируется только черновик; утверждённый на
 * год — не больше одного (частичный unique `uniq_budget_versions_approved_year`, миграция).
 */
#[ORM\Entity(repositoryClass: BudgetVersionRepository::class)]
#[ORM\Table(name: 'budget_versions')]
#[ORM\Index(name: 'idx_budget_versions_company_year', columns: ['company_id', 'year'])]
class BudgetVersion
{
    public const MIN_YEAR = 2000;
    public const MAX_YEAR = 2100;
    public const NAME_MAX_LENGTH = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'smallint')]
    private int $year;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column(length: 16, enumType: BudgetVersionStatus::class)]
    private BudgetVersionStatus $status = BudgetVersionStatus::DRAFT;

    /** Вариант, копией которого создан этот; ссылка справочная, без внешнего ключа. */
    #[ORM\Column(name: 'copied_from_id', type: 'guid', nullable: true)]
    private ?string $copiedFromId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $companyId, int $year, string $name, \DateTimeImmutable $now, ?string $copiedFromId = null)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::range($year, self::MIN_YEAR, self::MAX_YEAR, 'Год бюджета — от 2000 до 2100.');
        if (null !== $copiedFromId) {
            Assert::uuid($copiedFromId);
        }

        $this->id = $id;
        $this->companyId = $companyId;
        $this->year = $year;
        $this->name = self::normalizeName($name);
        $this->copiedFromId = $copiedFromId;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function normalizeName(string $name): string
    {
        $name = trim($name);
        Assert::stringNotEmpty($name, 'Укажите название варианта.');
        Assert::maxLength($name, self::NAME_MAX_LENGTH, 'Название варианта — не длиннее 120 символов.');

        return $name;
    }

    public function approve(\DateTimeImmutable $now): void
    {
        $this->changeStatus(BudgetVersionStatus::APPROVED, 'утвердить', $now);
    }

    /**
     * Из утверждённого (правка) или из архива (восстановление).
     */
    public function returnToDraft(\DateTimeImmutable $now): void
    {
        $this->changeStatus(BudgetVersionStatus::DRAFT, 'вернуть в черновик', $now);
    }

    /**
     * Только черновик: утверждённый сначала возвращают в черновик (это решение владельца).
     */
    public function archive(\DateTimeImmutable $now): void
    {
        $this->changeStatus(BudgetVersionStatus::ARCHIVED, 'архивировать', $now);
    }

    public function isEditable(): bool
    {
        return BudgetVersionStatus::DRAFT === $this->status;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): BudgetVersionStatus
    {
        return $this->status;
    }

    public function getCopiedFromId(): ?string
    {
        return $this->copiedFromId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function changeStatus(BudgetVersionStatus $status, string $action, \DateTimeImmutable $now): void
    {
        if (!$this->status->allowsTransitionTo($status)) {
            throw new InvalidBudgetVersionTransitionException($this->status, $action);
        }
        $this->status = $status;
        $this->updatedAt = $now;
    }
}

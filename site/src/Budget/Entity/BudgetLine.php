<?php

declare(strict_types=1);

namespace App\Budget\Entity;

use App\Budget\Domain\BudgetAmount;
use App\Budget\Enum\BudgetLineSource;
use App\Budget\Repository\BudgetLineRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Плановая сумма ячейки: вариант × листовая статья ОПиУ × месяц × проект × ЦФО.
 *
 * Статья, проект и ЦФО — строковые ссылки на чужие модули без внешних ключей: удаление статьи в
 * Finance не падает на плане, строки удалённой статьи в отчёт не попадают. Пишется пачкой через
 * DBAL (`SaveBudgetLinesAction`, upsert по `uniq_budget_lines_cell`); сущность — для чтения и тестов.
 */
#[ORM\Entity(repositoryClass: BudgetLineRepository::class)]
#[ORM\Table(name: 'budget_lines')]
#[ORM\UniqueConstraint(name: 'uniq_budget_lines_cell', columns: ['version_id', 'pl_category_id', 'month', 'project_direction_id', 'responsibility_center_id'])]
#[ORM\Index(name: 'idx_budget_lines_company', columns: ['company_id'])]
class BudgetLine
{
    public const COMMENT_MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: BudgetVersion::class)]
    #[ORM\JoinColumn(name: 'version_id', nullable: false, onDelete: 'CASCADE')]
    private BudgetVersion $version;

    #[ORM\Column(name: 'pl_category_id', type: 'guid')]
    private string $plCategoryId;

    /** Первое число месяца. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $month;

    #[ORM\Column(name: 'project_direction_id', type: 'guid')]
    private string $projectDirectionId;

    #[ORM\Column(name: 'responsibility_center_id', type: 'guid')]
    private string $responsibilityCenterId;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 16, enumType: BudgetLineSource::class)]
    private BudgetLineSource $source;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        BudgetVersion $version,
        string $plCategoryId,
        \DateTimeImmutable $month,
        string $projectDirectionId,
        string $responsibilityCenterId,
        string $amount,
        BudgetLineSource $source,
        ?string $comment,
        \DateTimeImmutable $now,
    ) {
        Assert::uuid($id);
        Assert::uuid($plCategoryId);
        Assert::uuid($projectDirectionId);
        Assert::uuid($responsibilityCenterId);
        Assert::same((int) $month->format('Y'), $version->getYear(), 'Месяц вне года варианта.');
        Assert::true(BudgetAmount::isValid($amount), 'Сумма — число с точкой, до двух знаков после неё.');

        $this->id = $id;
        $this->companyId = $version->getCompanyId();
        $this->version = $version;
        $this->plCategoryId = $plCategoryId;
        $this->month = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), 1)->setTime(0, 0);
        $this->projectDirectionId = $projectDirectionId;
        $this->responsibilityCenterId = $responsibilityCenterId;
        $this->amount = $amount;
        $this->source = $source;
        $this->comment = self::normalizeComment($comment);
        $this->updatedAt = $now;
    }

    public static function normalizeComment(?string $comment): ?string
    {
        $comment = null === $comment ? '' : trim($comment);
        if ('' === $comment) {
            return null;
        }
        Assert::maxLength($comment, self::COMMENT_MAX_LENGTH, 'Комментарий — не длиннее 500 символов.');

        return $comment;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getVersion(): BudgetVersion
    {
        return $this->version;
    }

    public function getPlCategoryId(): string
    {
        return $this->plCategoryId;
    }

    public function getMonth(): \DateTimeImmutable
    {
        return $this->month;
    }

    public function getProjectDirectionId(): string
    {
        return $this->projectDirectionId;
    }

    public function getResponsibilityCenterId(): string
    {
        return $this->responsibilityCenterId;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getSource(): BudgetLineSource
    {
        return $this->source;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

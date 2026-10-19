<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Enum\OzonAccrualTypeGroupStatus;
use App\Ingestion\Repository\OzonAccrualTypeGroupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Группа Ozon для типа начисления, выведенная из отчёта кабинета «Детализация
 * начислений»: строки отчёта связаны с by-day по дате, SKU и сумме.
 *
 * Группа типа у Ozon одна для всех продавцов, поэтому строка глобальная;
 * `evidenceCompanyId` — чей отчёт её подтвердил (для разбора, не для доступа).
 * Сам отчёт не хранится — только агрегаты голосования.
 */
#[ORM\Entity(repositoryClass: OzonAccrualTypeGroupRepository::class)]
#[ORM\Table(name: 'ingest_ozon_accrual_type_groups')]
#[ORM\UniqueConstraint(name: 'uniq_ingest_ozon_accrual_type_group_type_id', columns: ['type_id'])]
class OzonAccrualTypeGroup
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $typeId;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $groupCode;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: OzonAccrualTypeGroupStatus::class)]
    private OzonAccrualTypeGroupStatus $status;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $xlsxTypeName;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $xlsxGroupLabel;

    #[ORM\Column(type: Types::INTEGER)]
    private int $votes;

    #[ORM\Column(type: Types::INTEGER)]
    private int $rowsTotal;

    #[ORM\Column(type: Types::GUID)]
    private string $evidenceCompanyId;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $disputedGroupCode = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $disputedVotes = null;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $disputedCompanyId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $disputedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        int $typeId,
        ?string $groupCode,
        OzonAccrualTypeGroupStatus $status,
        string $xlsxTypeName,
        string $xlsxGroupLabel,
        int $votes,
        int $rowsTotal,
        string $evidenceCompanyId,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        ?string $id = null,
    ) {
        Assert::greaterThan($typeId, 0);
        if (null !== $id) {
            Assert::uuid($id);
        }

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->typeId = $typeId;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->apply($groupCode, $status, $xlsxTypeName, $xlsxGroupLabel, $votes, $rowsTotal, $evidenceCompanyId, $periodFrom, $periodTo);
    }

    /**
     * Новое свидетельство заменяет прежнее целиком.
     *
     * @return bool сменилась ли подтверждённая группа
     */
    public function replaceEvidence(
        ?string $groupCode,
        OzonAccrualTypeGroupStatus $status,
        string $xlsxTypeName,
        string $xlsxGroupLabel,
        int $votes,
        int $rowsTotal,
        string $evidenceCompanyId,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
    ): bool {
        $previous = $this->confirmedGroupCode();
        $this->apply($groupCode, $status, $xlsxTypeName, $xlsxGroupLabel, $votes, $rowsTotal, $evidenceCompanyId, $periodFrom, $periodTo);
        $changed = $previous !== $this->confirmedGroupCode();
        // Повторное подтверждение той же группы отчётом другой компании не решает
        // открытый спор: решение за админом.
        if ($changed) {
            $this->clearDispute();
        }
        $this->updatedAt = new \DateTimeImmutable();

        return $changed;
    }

    /**
     * Отчёт подтвердил другую группу, чем уже подтверждённая. Таблица общая для
     * всех компаний, поэтому молча не перезаписываем: прежняя группа действует,
     * спор ждёт решения админа.
     */
    public function recordDispute(string $groupCode, int $votes, string $companyId): void
    {
        Assert::uuid($companyId);
        Assert::greaterThan($votes, 0);

        $this->disputedGroupCode = $groupCode;
        $this->disputedVotes = $votes;
        $this->disputedCompanyId = $companyId;
        $this->disputedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->disputedAt;
    }

    /**
     * Админ принял группу из спорного отчёта.
     */
    public function acceptDispute(): void
    {
        Assert::true($this->isDisputed(), 'No dispute to accept.');

        $this->groupCode = $this->disputedGroupCode;
        $this->status = OzonAccrualTypeGroupStatus::CONFIRMED;
        // Доказательство — голоса спорного отчёта; строки и период прежнего отчёта
        // к ним не относятся, поэтому строки = голоса, а метки отчёта помечены.
        $this->votes = (int) $this->disputedVotes;
        $this->rowsTotal = $this->votes;
        $this->xlsxGroupLabel = 'принято админом из спорного отчёта';
        $this->evidenceCompanyId = (string) $this->disputedCompanyId;
        $this->clearDispute();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Админ оставил прежнюю группу.
     */
    public function dismissDispute(): void
    {
        Assert::true($this->isDisputed(), 'No dispute to dismiss.');

        $this->clearDispute();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isDisputed(): bool
    {
        return null !== $this->disputedGroupCode;
    }

    public function getDisputedGroupCode(): ?string
    {
        return $this->disputedGroupCode;
    }

    private function clearDispute(): void
    {
        $this->disputedGroupCode = null;
        $this->disputedVotes = null;
        $this->disputedCompanyId = null;
        $this->disputedAt = null;
    }

    public function confirmedGroupCode(): ?string
    {
        return OzonAccrualTypeGroupStatus::CONFIRMED === $this->status ? $this->groupCode : null;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function getGroupCode(): ?string
    {
        return $this->groupCode;
    }

    public function getStatus(): OzonAccrualTypeGroupStatus
    {
        return $this->status;
    }

    public function getXlsxTypeName(): string
    {
        return $this->xlsxTypeName;
    }

    public function getXlsxGroupLabel(): string
    {
        return $this->xlsxGroupLabel;
    }

    public function getVotes(): int
    {
        return $this->votes;
    }

    public function getRowsTotal(): int
    {
        return $this->rowsTotal;
    }

    private function apply(
        ?string $groupCode,
        OzonAccrualTypeGroupStatus $status,
        string $xlsxTypeName,
        string $xlsxGroupLabel,
        int $votes,
        int $rowsTotal,
        string $evidenceCompanyId,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
    ): void {
        Assert::uuid($evidenceCompanyId);
        Assert::notEmpty(trim($xlsxTypeName));
        Assert::notEmpty(trim($xlsxGroupLabel));
        Assert::range($votes, 0, $rowsTotal);
        Assert::greaterThan($rowsTotal, 0);
        Assert::false($periodTo < $periodFrom, 'Evidence period end is before its start.');
        Assert::false(OzonAccrualTypeGroupStatus::CONFIRMED === $status && null === $groupCode, 'Confirmed group needs a group code.');

        $this->groupCode = $groupCode;
        $this->status = $status;
        $this->xlsxTypeName = mb_substr(trim($xlsxTypeName), 0, 255);
        $this->xlsxGroupLabel = mb_substr(trim($xlsxGroupLabel), 0, 255);
        $this->votes = $votes;
        $this->rowsTotal = $rowsTotal;
        $this->evidenceCompanyId = $evidenceCompanyId;
        $this->periodFrom = $periodFrom;
        $this->periodTo = $periodTo;
    }
}

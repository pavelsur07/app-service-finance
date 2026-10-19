<?php

declare(strict_types=1);

namespace App\Tests\Builders\Ingestion;

use App\Ingestion\Entity\OzonAccrualTypeGroup;
use App\Ingestion\Enum\OzonAccrualTypeGroupStatus;
use Ramsey\Uuid\Uuid;

final class OzonAccrualTypeGroupBuilder
{
    private int $typeId = 76;
    private ?string $groupCode = 'partners';
    private OzonAccrualTypeGroupStatus $status = OzonAccrualTypeGroupStatus::CONFIRMED;
    private int $votes = 3;
    private int $rowsTotal = 3;
    private string $evidenceCompanyId;

    public function __construct()
    {
        $this->evidenceCompanyId = Uuid::uuid7()->toString();
    }

    public static function aTypeGroup(): self
    {
        return new self();
    }

    public function withTypeId(int $typeId): self
    {
        $this->typeId = $typeId;

        return $this;
    }

    public function withGroupCode(?string $groupCode): self
    {
        $this->groupCode = $groupCode;

        return $this;
    }

    public function withStatus(OzonAccrualTypeGroupStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function build(): OzonAccrualTypeGroup
    {
        return new OzonAccrualTypeGroup(
            typeId: $this->typeId,
            groupCode: $this->groupCode,
            status: $this->status,
            xlsxTypeName: 'Тип из отчёта',
            xlsxGroupLabel: 'Группа из отчёта',
            votes: $this->votes,
            rowsTotal: $this->rowsTotal,
            evidenceCompanyId: $this->evidenceCompanyId,
            periodFrom: new \DateTimeImmutable('2026-08-01'),
            periodTo: new \DateTimeImmutable('2026-08-31'),
        );
    }
}

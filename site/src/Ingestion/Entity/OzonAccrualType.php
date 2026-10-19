<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Repository\OzonAccrualTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Тип начисления из справочника Ozon `/v1/finance/accrual/types`.
 *
 * Справочник у Ozon один на всех продавцов: у всех кабинетов в один день
 * приходит версия с одинаковым hash. Поэтому у сущности нет `companyId` —
 * это справочные данные Ozon, а не данные компании.
 *
 * `versionFetchedAt` — время загрузки версии, из которой строка применена
 * последний раз. По нему синхронизация отличает более старую версию (её
 * разбирают позже новой — повтор сообщения, ручной прогон) и не даёт ей
 * откатить название или пометить тип пропавшим.
 */
#[ORM\Entity(repositoryClass: OzonAccrualTypeRepository::class)]
#[ORM\Table(name: 'ingest_ozon_accrual_types')]
#[ORM\UniqueConstraint(name: 'uniq_ingest_ozon_accrual_type_type_id', columns: ['type_id'])]
class OzonAccrualType
{
    public const NAME_MAX_LENGTH = 255;
    public const DESCRIPTION_MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $typeId;

    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: self::DESCRIPTION_MAX_LENGTH, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $removedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $versionFetchedAt;

    #[ORM\Column(type: Types::GUID)]
    private string $lastRawRecordId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        int $typeId,
        string $name,
        ?string $description,
        string $rawRecordId,
        \DateTimeImmutable $versionFetchedAt,
        ?string $id = null,
    ) {
        Assert::greaterThan($typeId, 0);
        Assert::uuid($rawRecordId);
        if (null !== $id) {
            Assert::uuid($id);
        }

        $now = new \DateTimeImmutable();

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->typeId = $typeId;
        $this->name = self::normalizeName($name);
        $this->description = self::normalizeDescription($description);
        $this->firstSeenAt = $versionFetchedAt;
        $this->versionFetchedAt = $versionFetchedAt;
        $this->lastRawRecordId = $rawRecordId;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Применяет строку более новой (или той же) версии справочника.
     *
     * @return bool изменились ли название, описание или признак пропажи
     */
    public function applyVersion(
        string $name,
        ?string $description,
        string $rawRecordId,
        \DateTimeImmutable $versionFetchedAt,
    ): bool {
        Assert::uuid($rawRecordId);
        Assert::false(
            $versionFetchedAt < $this->versionFetchedAt,
            sprintf('Ozon accrual type %d: version %s is older than applied %s.', $this->typeId, $versionFetchedAt->format(\DATE_ATOM), $this->versionFetchedAt->format(\DATE_ATOM)),
        );

        $name = self::normalizeName($name);
        $description = self::normalizeDescription($description);
        $changed = $name !== $this->name
            || $description !== $this->description
            || null !== $this->removedAt;

        $this->versionFetchedAt = $versionFetchedAt;
        if (!$changed) {
            return false;
        }

        $this->name = $name;
        $this->description = $description;
        $this->removedAt = null;
        $this->lastRawRecordId = $rawRecordId;
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }

    /**
     * Тип отсутствует в более новой версии справочника. Строку не удаляем:
     * по ней размечены транзакции прошлых периодов.
     */
    public function markRemoved(string $rawRecordId, \DateTimeImmutable $versionFetchedAt): bool
    {
        Assert::uuid($rawRecordId);
        if (null !== $this->removedAt || $versionFetchedAt < $this->versionFetchedAt) {
            return false;
        }

        $this->removedAt = $versionFetchedAt;
        $this->versionFetchedAt = $versionFetchedAt;
        $this->lastRawRecordId = $rawRecordId;
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getRemovedAt(): ?\DateTimeImmutable
    {
        return $this->removedAt;
    }

    public function isRemoved(): bool
    {
        return null !== $this->removedAt;
    }

    public function getVersionFetchedAt(): \DateTimeImmutable
    {
        return $this->versionFetchedAt;
    }

    public function getLastRawRecordId(): string
    {
        return $this->lastRawRecordId;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        Assert::notEmpty($name);
        Assert::maxLength($name, self::NAME_MAX_LENGTH);

        return $name;
    }

    private static function normalizeDescription(?string $description): ?string
    {
        $description = trim((string) $description);
        if ('' === $description) {
            return null;
        }

        return mb_substr($description, 0, self::DESCRIPTION_MAX_LENGTH);
    }
}

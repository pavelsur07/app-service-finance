<?php

declare(strict_types=1);

namespace App\MoySklad\Entity;

use App\MoySklad\Domain\CounterpartySnapshot;
use App\MoySklad\Infrastructure\Repository\MoySkladCounterpartyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MoySkladCounterpartyRepository::class)]
#[ORM\Table(name: 'moysklad_counterparties')]
#[ORM\Index(columns: ['company_id', 'connection_id'], name: 'idx_moysklad_counterparties_company_connection')]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_counterparties_connection_external', columns: ['connection_id', 'external_id'])]
class MoySkladCounterparty
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::GUID)]
    private string $connectionId;

    #[ORM\Column(type: Types::GUID)]
    private string $externalId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $companyType;

    #[ORM\Column(type: Types::STRING, length: 4096, nullable: true)]
    private ?string $legalTitle;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $inn;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $kpp;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $ogrn;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $ogrnip;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $legalAddress;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $archived;

    #[ORM\Column(type: 'datetime_immutable_utc_ms', precision: 3)]
    private \DateTimeImmutable $sourceUpdatedAt;

    #[ORM\Column(type: 'datetime_immutable_utc_ms', precision: 3)]
    private \DateTimeImmutable $loadedAt;

    public function __construct(string $id, string $companyId, string $connectionId, CounterpartySnapshot $snapshot, \DateTimeImmutable $loadedAt)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        $this->id = strtolower($id);
        $this->companyId = strtolower($companyId);
        $this->connectionId = strtolower($connectionId);
        $this->externalId = strtolower($snapshot->externalId);
        $this->replaceSnapshot($snapshot, $loadedAt);
    }

    public function applySnapshot(CounterpartySnapshot $snapshot, \DateTimeImmutable $loadedAt): bool
    {
        if ($this->externalId !== strtolower($snapshot->externalId)) {
            throw new \LogicException('Counterparty external ID cannot be changed.');
        }

        $changed = $this->name !== $snapshot->name
            || $this->companyType !== $snapshot->companyType
            || $this->legalTitle !== $snapshot->legalTitle
            || $this->inn !== $snapshot->inn
            || $this->kpp !== $snapshot->kpp
            || $this->ogrn !== $snapshot->ogrn
            || $this->ogrnip !== $snapshot->ogrnip
            || $this->legalAddress !== $snapshot->legalAddress
            || $this->archived !== $snapshot->archived
            || $this->sourceUpdatedAt != $snapshot->sourceUpdatedAt;

        $this->replaceSnapshot($snapshot, $loadedAt);

        return $changed;
    }

    private function replaceSnapshot(CounterpartySnapshot $snapshot, \DateTimeImmutable $loadedAt): void
    {
        $this->name = $snapshot->name;
        $this->companyType = $snapshot->companyType;
        $this->legalTitle = $snapshot->legalTitle;
        $this->inn = $snapshot->inn;
        $this->kpp = $snapshot->kpp;
        $this->ogrn = $snapshot->ogrn;
        $this->ogrnip = $snapshot->ogrnip;
        $this->legalAddress = $snapshot->legalAddress;
        $this->archived = $snapshot->archived;
        $this->sourceUpdatedAt = $snapshot->sourceUpdatedAt;
        $this->loadedAt = $loadedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getConnectionId(): string
    {
        return $this->connectionId;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCompanyType(): string
    {
        return $this->companyType;
    }

    public function getLegalTitle(): ?string
    {
        return $this->legalTitle;
    }

    public function getInn(): ?string
    {
        return $this->inn;
    }

    public function getKpp(): ?string
    {
        return $this->kpp;
    }

    public function getOgrn(): ?string
    {
        return $this->ogrn;
    }

    public function getOgrnip(): ?string
    {
        return $this->ogrnip;
    }

    public function getLegalAddress(): ?string
    {
        return $this->legalAddress;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function getSourceUpdatedAt(): \DateTimeImmutable
    {
        return $this->sourceUpdatedAt;
    }

    public function getLoadedAt(): \DateTimeImmutable
    {
        return $this->loadedAt;
    }
}

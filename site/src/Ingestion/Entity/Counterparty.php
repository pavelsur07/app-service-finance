<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Domain\TenantOwnedInterface;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\CounterpartyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: CounterpartyRepository::class)]
#[ORM\Table(name: 'ingest_counterparties')]
#[ORM\UniqueConstraint(name: 'uniq_counterparty_natural', columns: ['company_id', 'source', 'external_key'])]
class Counterparty implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::STRING, length: 64, enumType: IngestSource::class)]
    private IngestSource $source;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $externalKey;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $name;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $companyId, IngestSource $source, string $externalKey, string $name)
    {
        Assert::uuid($companyId);
        Assert::notEmpty($externalKey);
        Assert::notEmpty($name);

        $now = new \DateTimeImmutable();

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->source = $source;
        $this->externalKey = $externalKey;
        $this->name = $name;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function rename(string $name): void
    {
        Assert::notEmpty($name);

        if ($this->name === $name) {
            return;
        }

        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getSource(): IngestSource
    {
        return $this->source;
    }

    public function getExternalKey(): string
    {
        return $this->externalKey;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

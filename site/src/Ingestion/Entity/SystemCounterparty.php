<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\SystemCounterpartyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: SystemCounterpartyRepository::class)]
#[ORM\Table(name: 'system_counterparties')]
#[ORM\UniqueConstraint(name: 'uniq_system_counterparties_source', columns: ['source'])]
class SystemCounterparty
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 64, enumType: IngestSource::class)]
    private IngestSource $source;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $inn;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, IngestSource $source, string $name, ?string $inn = null)
    {
        Assert::uuid($id);
        Assert::notEmpty($name);

        $this->id = $id;
        $this->source = $source;
        $this->name = $name;
        $this->inn = $inn;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSource(): IngestSource
    {
        return $this->source;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getInn(): ?string
    {
        return $this->inn;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

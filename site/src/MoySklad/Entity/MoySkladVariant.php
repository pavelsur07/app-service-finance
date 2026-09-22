<?php

declare(strict_types=1);

namespace App\MoySklad\Entity;

use App\MoySklad\Domain\VariantSnapshot;
use App\MoySklad\Infrastructure\Repository\MoySkladVariantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MoySkladVariantRepository::class)]
#[ORM\Table(name: 'moysklad_variants')]
#[ORM\Index(columns: ['company_id', 'connection_id', 'product_external_id'], name: 'idx_moysklad_variants_product')]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_variants_connection_external', columns: ['connection_id', 'external_id'])]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_variants_tenant_external', columns: ['company_id', 'connection_id', 'external_id'])]
class MoySkladVariant
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

    #[ORM\Column(type: Types::GUID)]
    private string $productExternalId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $externalCode;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $code;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $article;

    /** @var list<array{id: string, name: string, value: string}> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $characteristics;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $archived;

    #[ORM\Column(type: 'datetime_immutable_utc_ms', precision: 3)]
    private \DateTimeImmutable $sourceUpdatedAt;

    #[ORM\Column(type: 'datetime_immutable_utc_ms', precision: 3)]
    private \DateTimeImmutable $loadedAt;

    public function __construct(string $id, string $companyId, string $connectionId, VariantSnapshot $snapshot, \DateTimeImmutable $loadedAt)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::uuid($snapshot->externalId);
        Assert::uuid($snapshot->productExternalId);
        $this->id = strtolower($id);
        $this->companyId = strtolower($companyId);
        $this->connectionId = strtolower($connectionId);
        $this->externalId = strtolower($snapshot->externalId);
        $this->replaceSnapshot($snapshot, $loadedAt);
    }

    public function applySnapshot(VariantSnapshot $snapshot, \DateTimeImmutable $loadedAt): bool
    {
        Assert::uuid($snapshot->productExternalId);
        if ($this->externalId !== strtolower($snapshot->externalId)) {
            throw new \LogicException('Variant external ID cannot be changed.');
        }
        $changed = $this->productExternalId !== strtolower($snapshot->productExternalId) || $this->name !== $snapshot->name
            || $this->externalCode !== $snapshot->externalCode || $this->code !== $snapshot->code
            || $this->article !== $snapshot->article || $this->characteristics !== $snapshot->characteristics
            || $this->archived !== $snapshot->archived || $this->sourceUpdatedAt != $snapshot->sourceUpdatedAt;
        $this->replaceSnapshot($snapshot, $loadedAt);

        return $changed;
    }

    private function replaceSnapshot(VariantSnapshot $snapshot, \DateTimeImmutable $loadedAt): void
    {
        $this->productExternalId = strtolower($snapshot->productExternalId);
        $this->name = $snapshot->name;
        $this->externalCode = $snapshot->externalCode;
        $this->code = $snapshot->code;
        $this->article = $snapshot->article;
        $this->characteristics = $snapshot->characteristics;
        $this->archived = $snapshot->archived;
        $this->sourceUpdatedAt = $snapshot->sourceUpdatedAt;
        $this->loadedAt = $loadedAt;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getProductExternalId(): string
    {
        return $this->productExternalId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getConnectionId(): string
    {
        return $this->connectionId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<array{id: string, name: string, value: string}> */
    public function getCharacteristics(): array
    {
        return $this->characteristics;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }
}

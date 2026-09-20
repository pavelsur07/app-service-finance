<?php

declare(strict_types=1);

namespace App\MoySklad\Entity;

use App\MoySklad\Domain\ProductSnapshot;
use App\MoySklad\Infrastructure\Repository\MoySkladProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MoySkladProductRepository::class)]
#[ORM\Table(name: 'moysklad_products')]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_products_connection_external', columns: ['connection_id', 'external_id'])]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_products_tenant_external', columns: ['company_id', 'connection_id', 'external_id'])]
class MoySkladProduct
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

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $externalCode;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $code;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $article;

    #[ORM\Column(type: Types::INTEGER)]
    private int $variantsCount;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $archived;

    #[ORM\Column(type: 'datetime_immutable_utc_ms', precision: 3)]
    private \DateTimeImmutable $sourceUpdatedAt;

    #[ORM\Column(type: 'datetime_immutable_utc_ms', precision: 3)]
    private \DateTimeImmutable $loadedAt;

    public function __construct(string $id, string $companyId, string $connectionId, ProductSnapshot $snapshot, \DateTimeImmutable $loadedAt)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::uuid($snapshot->externalId);
        $this->id = strtolower($id);
        $this->companyId = strtolower($companyId);
        $this->connectionId = strtolower($connectionId);
        $this->externalId = strtolower($snapshot->externalId);
        $this->replaceSnapshot($snapshot, $loadedAt);
    }

    public function applySnapshot(ProductSnapshot $snapshot, \DateTimeImmutable $loadedAt): bool
    {
        if ($this->externalId !== strtolower($snapshot->externalId)) {
            throw new \LogicException('Product external ID cannot be changed.');
        }
        $changed = $this->name !== $snapshot->name || $this->externalCode !== $snapshot->externalCode
            || $this->code !== $snapshot->code || $this->article !== $snapshot->article
            || $this->variantsCount !== $snapshot->variantsCount || $this->archived !== $snapshot->archived
            || $this->sourceUpdatedAt != $snapshot->sourceUpdatedAt;
        $this->replaceSnapshot($snapshot, $loadedAt);

        return $changed;
    }

    private function replaceSnapshot(ProductSnapshot $snapshot, \DateTimeImmutable $loadedAt): void
    {
        $this->name = $snapshot->name;
        $this->externalCode = $snapshot->externalCode;
        $this->code = $snapshot->code;
        $this->article = $snapshot->article;
        $this->variantsCount = $snapshot->variantsCount;
        $this->archived = $snapshot->archived;
        $this->sourceUpdatedAt = $snapshot->sourceUpdatedAt;
        $this->loadedAt = $loadedAt;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
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

    public function getVariantsCount(): int
    {
        return $this->variantsCount;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function getLoadedAt(): \DateTimeImmutable
    {
        return $this->loadedAt;
    }
}

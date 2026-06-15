<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Domain\TenantOwnedInterface;
use App\Ingestion\Infrastructure\Doctrine\EncryptedJsonType;
use App\Ingestion\Infrastructure\Security\PlaintextSecretCodec;
use App\Ingestion\Repository\IngestionCredentialRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: IngestionCredentialRepository::class)]
#[ORM\Table(name: 'ingestion_credentials')]
#[ORM\Index(columns: ['company_id'], name: 'idx_ingestion_credentials_company')]
#[ORM\Index(columns: ['connection_ref'], name: 'idx_ingestion_credentials_connection_ref')]
#[ORM\UniqueConstraint(name: 'uniq_ingestion_credentials_company_ref_type', columns: ['company_id', 'connection_ref', 'type'])]
class IngestionCredential implements TenantOwnedInterface
{
    public const TYPE_API_CREDENTIALS = 'api_credentials';

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $connectionRef;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $type;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: EncryptedJsonType::NAME)]
    private array $payload;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => PlaintextSecretCodec::KEY_VERSION])]
    private int $keyVersion = PlaintextSecretCodec::KEY_VERSION;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $companyId,
        string $connectionRef,
        array $payload,
        string $type = self::TYPE_API_CREDENTIALS,
        int $keyVersion = PlaintextSecretCodec::KEY_VERSION,
        ?\DateTimeImmutable $expiresAt = null,
    ) {
        Assert::uuid($companyId);
        Assert::notEmpty($connectionRef);
        Assert::notEmpty($type);

        $now = new \DateTimeImmutable();

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->connectionRef = $connectionRef;
        $this->type = $type;
        $this->payload = $payload;
        $this->keyVersion = $keyVersion;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getConnectionRef(): string
    {
        return $this->connectionRef;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getKeyVersion(): int
    {
        return $this->keyVersion;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function replacePayload(array $payload, int $keyVersion, ?\DateTimeImmutable $expiresAt = null): void
    {
        $this->payload = $payload;
        $this->keyVersion = $keyVersion;
        $this->expiresAt = $expiresAt;
        $this->updatedAt = new \DateTimeImmutable();
    }
}

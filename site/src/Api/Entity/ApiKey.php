<?php

declare(strict_types=1);

namespace App\Api\Entity;

use App\Api\Domain\ApiKeyName;
use App\Api\Domain\ApiKeySecret;
use App\Api\Repository\ApiKeyRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Serializer\Attribute\Ignore;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'api_keys')]
#[ORM\UniqueConstraint(name: 'uniq_api_keys_identifier', columns: ['public_identifier'])]
#[ORM\Index(name: 'idx_api_keys_company_created', columns: ['company_id', 'created_at'])]
class ApiKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Version]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    public function __construct(
        #[ORM\Column(type: 'guid')]
        private string $companyId,
        string $name,
        #[ORM\Column(length: 32)]
        private string $publicIdentifier,
        #[Ignore]
        #[ORM\Column(length: 64)]
        #[\SensitiveParameter]
        private string $secretHash,
        #[ORM\Column(type: 'guid')]
        private string $createdBy,
        \DateTimeImmutable $now,
    ) {
        Assert::uuid($companyId);
        Assert::uuid($createdBy);
        $this->id = Uuid::uuid7()->toString();
        $this->name = ApiKeyName::normalize($name);
        $this->createdAt = $now->setTimezone(new \DateTimeZone('UTC'));
        $this->createdAt = $this->createdAt->setTime((int) $this->createdAt->format('H'), (int) $this->createdAt->format('i'), (int) $this->createdAt->format('s'));
        $this->expiresAt = $this->createdAt->add(new \DateInterval('P90D'));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPublicIdentifier(): string
    {
        return $this->publicIdentifier;
    }

    public function getCreatedBy(): string
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function rename(string $name): void
    {
        $this->name = ApiKeyName::normalize($name);
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && $now < $this->expiresAt;
    }

    public function matchesSecret(#[\SensitiveParameter] string $secret): bool
    {
        return hash_equals($this->secretHash, ApiKeySecret::hash($secret));
    }

    /** @return array{id: string, companyId: string} */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'companyId' => $this->companyId];
    }
}

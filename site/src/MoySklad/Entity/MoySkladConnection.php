<?php

declare(strict_types=1);

namespace App\MoySklad\Entity;

use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MoySkladConnectionWriteRepository::class)]
#[ORM\Table(name: 'moysklad_connections')]
#[ORM\Index(columns: ['company_id', 'is_active'], name: 'idx_moysklad_connections_company_active')]
#[ORM\Index(columns: ['company_id', 'name'], name: 'idx_moysklad_connections_company_name')]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_connections_company_name', columns: ['company_id', 'name'])]
#[ORM\UniqueConstraint(name: 'uniq_moysklad_connections_account', columns: ['account_id'])]
class MoySkladConnection
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255)]
    private string $baseUrl;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $login = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $accessToken = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $accountId = null;

    #[ORM\Column(type: 'string', length: 32, enumType: ConnectionCheckStatus::class, options: ['default' => 'unverified'])]
    private ConnectionCheckStatus $checkStatus = ConnectionCheckStatus::UNVERIFIED;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSuccessfulCheckAt = null;

    #[ORM\Version]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $accessTokenEncrypted = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $refreshTokenEncrypted = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $tokenExpiresAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $companyId, string $name, string $baseUrl)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);

        $this->id = $id;
        $this->companyId = $companyId;
        $this->setName($name);
        $this->setBaseUrl($baseUrl);
        $this->createdAt = new \DateTimeImmutable();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $name = trim($name);
        if ('' == $name) {
            throw new \InvalidArgumentException('Connection name cannot be empty.');
        }

        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = trim($baseUrl);
        $this->touch();

        return $this;
    }

    public function getLogin(): ?string
    {
        return $this->login;
    }

    public function setLogin(?string $login): self
    {
        $this->login = null !== $login ? trim($login) : null;
        $this->touch();

        return $this;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken): self
    {
        $this->accessToken = $accessToken;
        $this->touch();

        return $this;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): self
    {
        $this->refreshToken = $refreshToken;
        $this->touch();

        return $this;
    }

    public function getTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?\DateTimeImmutable $tokenExpiresAt): self
    {
        $this->tokenExpiresAt = $tokenExpiresAt;
        $this->touch();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        $this->touch();

        return $this;
    }

    public function getLastSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function setLastSyncAt(?\DateTimeImmutable $lastSyncAt): self
    {
        $this->lastSyncAt = $lastSyncAt;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getAccountId(): ?string
    {
        return $this->accountId;
    }

    public function bindAccount(string $accountId): void
    {
        Assert::uuid($accountId);
        $accountId = strtolower($accountId);
        if (null !== $this->accountId && $this->accountId !== $accountId) {
            throw new \LogicException('Connection account cannot be changed.');
        }

        $this->accountId = $accountId;
        $this->touch();
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function isVerified(): bool
    {
        return null !== $this->accountId;
    }

    public function getCheckStatus(): ConnectionCheckStatus
    {
        return $this->checkStatus;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function getLastSuccessfulCheckAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessfulCheckAt;
    }

    public function recordCheck(ConnectionCheckStatus $status, \DateTimeImmutable $checkedAt): void
    {
        $this->checkStatus = $status;
        $this->lastCheckedAt = $checkedAt;
        if (ConnectionCheckStatus::CONNECTED === $status) {
            $this->lastSuccessfulCheckAt = $checkedAt;
        }
        $this->touch();
    }

    public function getAccessTokenEncrypted(): ?string
    {
        return $this->accessTokenEncrypted;
    }

    public function setAccessTokenEncrypted(?string $payload): self
    {
        $this->accessTokenEncrypted = $payload;
        $this->touch();

        return $this;
    }

    public function getRefreshTokenEncrypted(): ?string
    {
        return $this->refreshTokenEncrypted;
    }

    public function setRefreshTokenEncrypted(?string $payload): self
    {
        $this->refreshTokenEncrypted = $payload;
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

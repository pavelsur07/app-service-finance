<?php

declare(strict_types=1);

namespace App\BankSync\Entity;

use App\BankSync\Enum\BankCode;
use App\BankSync\Repository\BankConnectionRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Подключение компании к API банка. Учётные данные — только шифротекст
 * (`BankCredentialsCodec`); открытого токена в сущности нет.
 */
#[ORM\Entity(repositoryClass: BankConnectionRepository::class)]
#[ORM\Table(name: 'bank_sync_connection')]
#[ORM\Index(name: 'idx_bank_sync_connection_company_bank', columns: ['company_id', 'bank_code'])]
class BankConnection
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(length: 32, enumType: BankCode::class)]
    private BankCode $bankCode;

    #[ORM\Column(length: 255)]
    private string $baseUrl;

    #[ORM\Column(type: 'text')]
    private string $credentialsEncrypted;

    #[ORM\Column(length: 32)]
    private string $credentialsKeyVersion;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        BankCode $bankCode,
        string $baseUrl,
        string $credentialsEncrypted,
        string $credentialsKeyVersion,
        bool $isActive = true,
        ?string $id = null,
    ) {
        if (null !== $id) {
            Assert::uuid($id);
        }
        Assert::uuid($companyId);

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->bankCode = $bankCode;
        $this->changeSettings($baseUrl, $isActive);
        $this->replaceCredentials($credentialsEncrypted, $credentialsKeyVersion);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function changeSettings(string $baseUrl, bool $isActive): void
    {
        Assert::startsWith($baseUrl, 'https://', 'Адрес API банка должен начинаться с https://.');
        Assert::maxLength($baseUrl, 255);

        $this->baseUrl = $baseUrl;
        $this->isActive = $isActive;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function replaceCredentials(string $credentialsEncrypted, string $credentialsKeyVersion): void
    {
        Assert::notEmpty($credentialsEncrypted);
        Assert::notEmpty($credentialsKeyVersion);

        $this->credentialsEncrypted = $credentialsEncrypted;
        $this->credentialsKeyVersion = $credentialsKeyVersion;
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

    public function getBankCode(): BankCode
    {
        return $this->bankCode;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getCredentialsEncrypted(): string
    {
        return $this->credentialsEncrypted;
    }

    public function getCredentialsKeyVersion(): string
    {
        return $this->credentialsKeyVersion;
    }

    public function isActive(): bool
    {
        return $this->isActive;
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

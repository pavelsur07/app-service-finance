<?php

declare(strict_types=1);

namespace App\BankSync\Entity;

use App\BankSync\Enum\BankCode;
use App\BankSync\Enum\BankConnectionStatus;
use App\BankSync\Exception\BankConnectionStatusTransitionException;
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

    #[ORM\Column(length: 16, enumType: BankConnectionStatus::class)]
    private BankConnectionStatus $status;

    /** Машинная причина последнего перехода (`refresh_rejected`, `token_rejected`…), без данных банка. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $statusReason = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $statusChangedAt;

    /**
     * Идентификатор пользователя у банка, который дал согласие (`sub` из `id_token`):
     * псевдоним без ФИО. По нему подключение находится в ответе банка о согласиях.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalSubject = null;

    /** Последний день действия согласия по данным банка; null — ещё не сверялся. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $consentExpiresAt = null;

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
        ?string $id = null,
    ) {
        if (null !== $id) {
            Assert::uuid($id);
        }
        Assert::uuid($companyId);

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->bankCode = $bankCode;
        $this->changeBaseUrl($baseUrl);
        $this->replaceCredentials($credentialsEncrypted, $credentialsKeyVersion);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->status = BankConnectionStatus::ACTIVE;
        $this->statusChangedAt = $this->createdAt;
    }

    public function changeBaseUrl(string $baseUrl): void
    {
        Assert::startsWith($baseUrl, 'https://', 'Адрес API банка должен начинаться с https://.');
        Assert::maxLength($baseUrl, 255);

        $this->baseUrl = $baseUrl;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Те же права доступа, новые токены (обновление OAuth): статус не меняется.
     */
    public function replaceCredentials(string $credentialsEncrypted, string $credentialsKeyVersion): void
    {
        Assert::notEmpty($credentialsEncrypted);
        Assert::notEmpty($credentialsKeyVersion);

        $this->credentialsEncrypted = $credentialsEncrypted;
        $this->credentialsKeyVersion = $credentialsKeyVersion;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Новый вход клиента (новое согласие или новый токен): из любого статуса в `ACTIVE`.
     * Срок прежнего согласия больше не действует — новый придёт со сверкой согласий.
     *
     * @param string|null $externalSubject `sub` вошедшего пользователя; null — банк его не
     *                                     сообщает (статический токен)
     */
    public function reauthorize(string $credentialsEncrypted, string $credentialsKeyVersion, ?string $externalSubject = null): void
    {
        $this->replaceCredentials($credentialsEncrypted, $credentialsKeyVersion);
        $this->recordAuthorizedSubject($externalSubject);
        $this->changeStatus(BankConnectionStatus::ACTIVE, null);
    }

    /**
     * Вход в новое подключение: кто дал согласие. Срок согласия — со сверкой.
     */
    public function recordAuthorizedSubject(?string $externalSubject): void
    {
        if (null !== $externalSubject) {
            Assert::notEmpty($externalSubject);
            Assert::maxLength($externalSubject, 255);
        }
        $this->externalSubject = $externalSubject;
        $this->consentExpiresAt = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Срок действующего согласия по данным банка (ночная сверка).
     */
    public function updateConsentExpiresAt(\DateTimeImmutable $consentExpiresAt): void
    {
        $day = $consentExpiresAt->setTime(0, 0);
        if ($this->consentExpiresAt == $day) {
            return;
        }
        $this->consentExpiresAt = $day;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return bool false — подключение уже ждало повторного входа (warning не повторяется)
     */
    public function markNeedsReauth(string $reason): bool
    {
        if (BankConnectionStatus::NEEDS_REAUTH === $this->status) {
            return false;
        }
        $this->assertTransition([BankConnectionStatus::ACTIVE], BankConnectionStatus::NEEDS_REAUTH);
        $this->changeStatus(BankConnectionStatus::NEEDS_REAUTH, $reason);

        return true;
    }

    public function markRevoked(string $reason): void
    {
        $this->assertTransition(
            [BankConnectionStatus::ACTIVE, BankConnectionStatus::NEEDS_REAUTH, BankConnectionStatus::DISABLED],
            BankConnectionStatus::REVOKED,
        );
        $this->changeStatus(BankConnectionStatus::REVOKED, $reason);
    }

    public function disable(): void
    {
        $this->assertTransition([BankConnectionStatus::ACTIVE, BankConnectionStatus::NEEDS_REAUTH], BankConnectionStatus::DISABLED);
        $this->changeStatus(BankConnectionStatus::DISABLED, null);
    }

    /**
     * Только из `DISABLED`: отозванное или отвергнутое банком подключение включается
     * новым входом (`reauthorize()`), а не флажком.
     */
    public function enable(): void
    {
        $this->assertTransition([BankConnectionStatus::DISABLED], BankConnectionStatus::ACTIVE);
        $this->changeStatus(BankConnectionStatus::ACTIVE, null);
    }

    public function canSync(): bool
    {
        return BankConnectionStatus::ACTIVE === $this->status;
    }

    /**
     * @param list<BankConnectionStatus> $allowedFrom
     */
    private function assertTransition(array $allowedFrom, BankConnectionStatus $to): void
    {
        if (!\in_array($this->status, $allowedFrom, true)) {
            throw new BankConnectionStatusTransitionException($this->status, $to);
        }
    }

    private function changeStatus(BankConnectionStatus $status, ?string $reason): void
    {
        if (null !== $reason) {
            Assert::maxLength($reason, 64);
        }
        $this->status = $status;
        $this->statusReason = $reason;
        $this->statusChangedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->statusChangedAt;
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

    public function getStatus(): BankConnectionStatus
    {
        return $this->status;
    }

    public function getStatusReason(): ?string
    {
        return $this->statusReason;
    }

    public function getStatusChangedAt(): \DateTimeImmutable
    {
        return $this->statusChangedAt;
    }

    public function getExternalSubject(): ?string
    {
        return $this->externalSubject;
    }

    public function getConsentExpiresAt(): ?\DateTimeImmutable
    {
        return $this->consentExpiresAt;
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

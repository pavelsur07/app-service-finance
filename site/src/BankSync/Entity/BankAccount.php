<?php

declare(strict_types=1);

namespace App\BankSync\Entity;

use App\BankSync\Repository\BankAccountRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Счёт клиента в банке, увиденный через подключение, и его привязка к денежному счёту Cash
 * (`moneyAccountId`; null — «не привязан», выписка не загружается).
 *
 * Строки создаёт и обновляет загрузка (`BankAccountCatalog`, upsert по
 * `(company_id, connection_id, account_number)`); пропавший из ответа банка счёт не
 * удаляется — привязка и курсор переживают временный сбой банка. Уникальность
 * `(company_id, money_account_id)` — частичный индекс в миграции: один денежный счёт
 * получает выписку только из одного счёта банка.
 */
#[ORM\Entity(repositoryClass: BankAccountRepository::class)]
#[ORM\Table(name: 'bank_sync_account')]
#[ORM\UniqueConstraint(name: 'uniq_bank_sync_account_number', columns: ['company_id', 'connection_id', 'account_number'])]
#[ORM\Index(name: 'idx_bank_sync_account_connection', columns: ['connection_id'])]
#[ORM\Index(name: 'idx_bank_sync_account_money_account', columns: ['money_account_id'])]
class BankAccount
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'guid')]
    private string $connectionId;

    #[ORM\Column(length: 64)]
    private string $accountNumber;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $bic = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $description = null;

    /** Идентификатор счёта у банка (у Альфы `accountId`). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $externalId = null;

    /** Банк разрешает операции по счёту (`Enabled`). */
    #[ORM\Column(type: 'boolean')]
    private bool $enabledInBank = true;

    /** Счёт был в последнем ответе банка; false — пропал, не загружается. */
    #[ORM\Column(type: 'boolean')]
    private bool $presentInBank = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $moneyAccountId = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $linkedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        string $connectionId,
        string $accountNumber,
        string $currency,
        \DateTimeImmutable $seenAt,
        ?string $id = null,
    ) {
        if (null !== $id) {
            Assert::uuid($id);
        }
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::stringNotEmpty($accountNumber);
        Assert::maxLength($accountNumber, 64);
        Assert::regex($currency, '/^[A-Z]{3}$/');

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->connectionId = $connectionId;
        $this->accountNumber = $accountNumber;
        $this->currency = $currency;
        $this->lastSeenAt = $seenAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Банк вернул счёт в списке: обновляем то, что банк может поменять. Номер — ключ строки
     * и не меняется; смену валюты загрузка увидит при сверке с денежным счётом.
     */
    public function observeInBank(
        string $currency,
        bool $enabled,
        ?string $bic,
        ?string $description,
        ?string $externalId,
        \DateTimeImmutable $seenAt,
    ): void {
        Assert::regex($currency, '/^[A-Z]{3}$/');

        $this->currency = $currency;
        $this->enabledInBank = $enabled;
        $this->bic = null === $bic ? null : mb_substr($bic, 0, 20);
        $this->description = null === $description ? null : mb_substr($description, 0, 128);
        $this->externalId = null === $externalId ? null : mb_substr($externalId, 0, 64);
        $this->presentInBank = true;
        $this->lastSeenAt = $seenAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Счёта нет в последнем ответе банка: не загружаем, но привязку и курсор не трогаем —
     * вернётся в следующем ответе, если пропал из-за сбоя банка.
     */
    public function markMissingFromBank(): void
    {
        if (!$this->presentInBank) {
            return;
        }

        $this->presentInBank = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Привязка к денежному счёту той же компании. Валюту и принадлежность компании
     * проверяет вызывающий через `CashFacade` — Entity Cash не знает.
     */
    public function linkTo(string $moneyAccountId): void
    {
        Assert::uuid($moneyAccountId);
        if ($this->moneyAccountId === $moneyAccountId) {
            return;
        }

        $this->moneyAccountId = $moneyAccountId;
        $this->linkedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->linkedAt;
    }

    public function unlink(): void
    {
        if (null === $this->moneyAccountId) {
            return;
        }

        $this->moneyAccountId = null;
        $this->linkedAt = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Загрузка берёт только привязанный счёт, который банк вернул в последнем ответе и
     * по которому разрешены операции.
     */
    public function isLoadable(): bool
    {
        return null !== $this->moneyAccountId && $this->enabledInBank && $this->presentInBank;
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

    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBic(): ?string
    {
        return $this->bic;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function isEnabledInBank(): bool
    {
        return $this->enabledInBank;
    }

    public function isPresentInBank(): bool
    {
        return $this->presentInBank;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getMoneyAccountId(): ?string
    {
        return $this->moneyAccountId;
    }

    public function getLinkedAt(): ?\DateTimeImmutable
    {
        return $this->linkedAt;
    }
}

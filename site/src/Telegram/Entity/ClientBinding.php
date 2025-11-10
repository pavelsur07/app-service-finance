<?php

namespace App\Telegram\Entity;

use App\Entity\Company;
use App\Entity\MoneyAccount;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'client_bindings')]
#[ORM\UniqueConstraint(
    name: 'uniq_client_binding_company_bot_user',
    columns: ['company_id', 'bot_id', 'telegram_user_id']
)]
class ClientBinding
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: TelegramBot::class)]
    #[ORM\JoinColumn(name: 'bot_id', nullable: false, onDelete: 'CASCADE')]
    private TelegramBot $bot;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'telegram_user_id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $telegramUser;

    // Default money account for this TG user within the company
    #[ORM\ManyToOne(targetEntity: MoneyAccount::class)]
    #[ORM\JoinColumn(name: 'money_account_id', nullable: true, onDelete: 'SET NULL')]
    private ?MoneyAccount $moneyAccount = null;

    // Optional ISO currency (e.g. RUB, USD). If null — take account/company default.
    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    private ?string $defaultCurrency = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Company $company, TelegramBot $bot, TelegramUser $telegramUser)
    {
        $this->company = $company;
        $this->bot = $bot;
        $this->telegramUser = $telegramUser;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->status = self::STATUS_ACTIVE;
    }

    public function setMoneyAccount(?MoneyAccount $account): void
    {
        $this->moneyAccount = $account;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function block(): void
    {
        $this->status = self::STATUS_BLOCKED;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function activate(): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // getters/setters ...
}

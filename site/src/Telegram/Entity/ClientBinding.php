<?php

namespace App\Telegram\Entity;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Company\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: '`client_bindings`')]
#[ORM\UniqueConstraint(
    name: 'uniq_client_binding_company_bot_user',
    columns: ['company_id', 'bot_id', 'telegram_user_id']
)]
class ClientBinding
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';

    // Уникальный идентификатор привязки (UUID строкой)
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    // Компания, в рамках которой действует привязка
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    // Бот, через которого ведется взаимодействие
    #[ORM\ManyToOne(targetEntity: TelegramBot::class)]
    #[ORM\JoinColumn(name: 'bot_id', nullable: false, onDelete: 'CASCADE')]
    private TelegramBot $bot;

    // Пользователь Telegram, к которому относится привязка
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'telegram_user_id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $telegramUser;

    // Базовый денежный счет клиента (может отсутствовать)
    #[ORM\ManyToOne(targetEntity: MoneyAccount::class)]
    #[ORM\JoinColumn(name: 'money_account_id', nullable: true, onDelete: 'SET NULL')]
    private ?MoneyAccount $moneyAccount = null;

    // Валюта по умолчанию (ISO-код), если задана
    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    private ?string $defaultCurrency = null;

    // Статус привязки
    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_ACTIVE;

    // Дата создания записи
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    // Дата последнего изменения записи
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Company $company, TelegramBot $bot, TelegramUser $telegramUser)
    {
        Assert::uuid($id);

        $this->id = $id;
        $this->company = $company;
        $this->bot = $bot;
        $this->telegramUser = $telegramUser;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->status = self::STATUS_ACTIVE;
    }

    // Возвращает идентификатор привязки
    public function getId(): ?string
    {
        return $this->id;
    }

    // Возвращает компанию
    public function getCompany(): Company
    {
        return $this->company;
    }

    // Обновляет компанию
    public function setCompany(Company $company): self
    {
        $this->company = $company;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // Возвращает бота
    public function getBot(): TelegramBot
    {
        return $this->bot;
    }

    // Обновляет бота
    public function setBot(TelegramBot $bot): self
    {
        $this->bot = $bot;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // Возвращает пользователя Telegram
    public function getTelegramUser(): TelegramUser
    {
        return $this->telegramUser;
    }

    // Обновляет пользователя Telegram
    public function setTelegramUser(TelegramUser $telegramUser): self
    {
        $this->telegramUser = $telegramUser;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // Устанавливает денежный счет
    public function setMoneyAccount(?MoneyAccount $account): void
    {
        $this->moneyAccount = $account;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Возвращает денежный счет
    public function getMoneyAccount(): ?MoneyAccount
    {
        return $this->moneyAccount;
    }

    // Возвращает валюту по умолчанию
    public function getDefaultCurrency(): ?string
    {
        return $this->defaultCurrency;
    }

    // Устанавливает валюту по умолчанию
    public function setDefaultCurrency(?string $defaultCurrency): self
    {
        $this->defaultCurrency = $defaultCurrency;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // Возвращает статус привязки
    public function getStatus(): string
    {
        return $this->status;
    }

    // Переводит привязку в заблокированный статус
    public function block(): void
    {
        $this->status = self::STATUS_BLOCKED;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Активирует привязку
    public function activate(): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Возвращает дату создания
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // Возвращает дату последнего изменения
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

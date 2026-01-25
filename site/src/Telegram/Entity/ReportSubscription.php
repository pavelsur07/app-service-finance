<?php

namespace App\Telegram\Entity;

use App\Company\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: '`report_subscriptions`')]
#[ORM\UniqueConstraint(
    name: 'uniq_report_subscrib_company_user',
    columns: ['company_id', 'telegram_user_id']
)]
class ReportSubscription
{
    public const PERIOD_DAILY = 'daily';
    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';

    // Уникальный идентификатор подписки (UUID хранится строкой)
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    // Компания, для которой настроена подписка
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    // Пользователь Telegram, который получает отчеты
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'telegram_user_id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $telegramUser;

    // Периодичность отправки (daily/weekly/monthly)
    #[ORM\Column(type: 'string', length: 16)]
    private string $periodicity;

    // Локальное время отправки в формате HH:MM (хранится строкой для MVP)
    #[ORM\Column(type: 'string', length: 5)]
    private string $sendAtLocalTime;

    // Часовой пояс (IANA), может быть не задан
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $timezone = null;

    // Битовая маска выбранных метрик
    #[ORM\Column(type: 'integer')]
    private int $metricsMask = 0;

    // Флаг активности подписки
    #[ORM\Column(type: 'boolean')]
    private bool $isEnabled = true;

    // Дата создания записи
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    // Дата последнего обновления записи
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Company $company, TelegramUser $telegramUser, string $periodicity, string $sendAtLocalTime)
    {
        Assert::uuid($id);

        $this->id = $id;
        $this->company = $company;
        $this->telegramUser = $telegramUser;
        $this->periodicity = $periodicity;
        $this->sendAtLocalTime = $sendAtLocalTime;
        $this->isEnabled = true;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    // Включает подписку
    public function enable(): void
    {
        $this->isEnabled = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Отключает подписку
    public function disable(): void
    {
        $this->isEnabled = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Устанавливает маску метрик
    public function setMetricsMask(int $mask): void
    {
        $this->metricsMask = $mask;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Устанавливает часовой пояс
    public function setTimezone(?string $tz): void
    {
        $this->timezone = $tz;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Возвращает UUID подписки
    public function getId(): ?string
    {
        return $this->id;
    }

    // Возвращает компанию
    public function getCompany(): Company
    {
        return $this->company;
    }

    // Устанавливает компанию
    public function setCompany(Company $company): self
    {
        $this->company = $company;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // Возвращает пользователя Telegram
    public function getTelegramUser(): TelegramUser
    {
        return $this->telegramUser;
    }

    // Устанавливает пользователя Telegram
    public function setTelegramUser(TelegramUser $telegramUser): self
    {
        $this->telegramUser = $telegramUser;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // Возвращает периодичность
    public function getPeriodicity(): string
    {
        return $this->periodicity;
    }

    // Устанавливает периодичность
    public function setPeriodicity(string $periodicity): self
    {
        $this->periodicity = $periodicity;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // Возвращает локальное время отправки
    public function getSendAtLocalTime(): string
    {
        return $this->sendAtLocalTime;
    }

    // Устанавливает локальное время отправки
    public function setSendAtLocalTime(string $sendAtLocalTime): self
    {
        $this->sendAtLocalTime = $sendAtLocalTime;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // Возвращает часовой пояс
    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    // Возвращает маску метрик
    public function getMetricsMask(): int
    {
        return $this->metricsMask;
    }

    // Возвращает статус активности
    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    // Возвращает дату создания
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // Возвращает дату последнего обновления
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

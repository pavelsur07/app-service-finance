<?php

namespace App\Telegram\Entity;

use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

use App\Telegram\Repository\TelegramBotRepository;

#[ORM\Entity(repositoryClass: TelegramBotRepository::class)]
#[ORM\Table(name: '`telegram_bots`')]
#[ORM\UniqueConstraint(name: 'uniq_telegram_bot_token', columns: ['token'])]
class TelegramBot
{
    // Уникальный идентификатор бота (UUID хранится строкой)
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    // Токен, полученный от @BotFather
    #[ORM\Column(type: 'string', length: 255)]
    private string $token;

    // Публичное имя пользователя бота в Telegram
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $username = null;

    // URL текущего вебхука
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $webhookUrl = null;

    // Флаг активности бота
    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    // Дата создания записи
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    // Дата последнего изменения записи
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $token)
    {
        Assert::uuid($id);

        $this->id = $id;
        $this->token = $token;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->isActive = true;
    }

    // Возвращает UUID бота
    public function getId(): ?string
    {
        return $this->id;
    }

    // Возвращает токен бота
    public function getToken(): string
    {
        return $this->token;
    }

    // Обновляет токен бота
    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    // Возвращает username бота
    public function getUsername(): ?string
    {
        return $this->username;
    }

    // Устанавливает username бота
    public function setUsername(?string $username): self
    {
        $this->username = $username;

        return $this;
    }

    // Возвращает URL вебхука
    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    // Устанавливает URL вебхука
    public function setWebhookUrl(?string $webhookUrl): self
    {
        $this->webhookUrl = $webhookUrl;

        return $this;
    }

    // Проверяет, активен ли бот
    public function isActive(): bool
    {
        return $this->isActive;
    }

    // Переключает активность бота
    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
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

    // Обновляет дату последнего изменения
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}

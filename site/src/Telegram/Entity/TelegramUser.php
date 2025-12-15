<?php

namespace App\Telegram\Entity;

use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: '`telegram_users`')]
#[ORM\UniqueConstraint(name: 'uniq_telegram_users_tg_user_id', columns: ['tg_user_id'])]
class TelegramUser
{
    // Уникальный идентификатор пользователя (UUID хранится строкой)
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    // Идентификатор пользователя Telegram (хранится строкой для безопасности)
    #[ORM\Column(name: 'tg_user_id', type: 'string', length: 32)]
    private string $tgUserId;

    // Никнейм пользователя в Telegram
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $username = null;

    // Имя пользователя
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $firstName = null;

    // Фамилия пользователя
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $lastName = null;

    // Телефон пользователя
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $phone = null;

    // Дата создания записи
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    // Дата последнего изменения записи
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $tgUserId)
    {
        Assert::uuid($id);

        $this->id = $id;
        $this->tgUserId = $tgUserId;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    // Обновляет дату последнего взаимодействия
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Возвращает UUID записи
    public function getId(): ?string
    {
        return $this->id;
    }

    // Возвращает идентификатор пользователя в Telegram
    public function getTgUserId(): string
    {
        return $this->tgUserId;
    }

    // Устанавливает идентификатор пользователя в Telegram
    public function setTgUserId(string $tgUserId): self
    {
        $this->tgUserId = $tgUserId;

        return $this;
    }

    // Возвращает username
    public function getUsername(): ?string
    {
        return $this->username;
    }

    // Устанавливает username
    public function setUsername(?string $username): self
    {
        $this->username = $username;

        return $this;
    }

    // Возвращает имя
    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    // Устанавливает имя
    public function setFirstName(?string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    // Возвращает фамилию
    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    // Устанавливает фамилию
    public function setLastName(?string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    // Возвращает телефон
    public function getPhone(): ?string
    {
        return $this->phone;
    }

    // Устанавливает телефон
    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

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

<?php

namespace App\Telegram\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'telegram_users')]
#[ORM\UniqueConstraint(name: 'uniq_telegram_users_tg_user_id', columns: ['tg_user_id'])]
class TelegramUser
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    // Telegram's numeric user id (fit in signed 64-bit). Store as string to be safe.
    #[ORM\Column(name: 'tg_user_id', type: 'string', length: 32)]
    private string $tgUserId;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $tgUserId)
    {
        $this->tgUserId = $tgUserId;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // getters/setters ...
}

<?php

namespace App\Telegram\Entity;

use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'telegram_bots')]
#[ORM\UniqueConstraint(name: 'uniq_telegram_bot_token', columns: ['token'])]
class TelegramBot
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    // Bot token from @BotFather (keep securely)
    #[ORM\Column(type: 'string', length: 255)]
    private string $token;

    // Optional username like my_fin_bot
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $webhookUrl = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Company $company, string $token)
    {
        $this->company = $company;
        $this->token = $token;
        $this->createdAt = new \DateTimeImmutable();
        $this->isActive = true;
    }

    // getters/setters ...
}

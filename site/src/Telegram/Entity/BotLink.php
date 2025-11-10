<?php

namespace App\Telegram\Entity;

use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bot_links')]
#[ORM\UniqueConstraint(name: 'uniq_bot_links_token', columns: ['token'])]
class BotLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: TelegramBot::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TelegramBot $bot;

    // Signed short-lived token for /start deep-link
    #[ORM\Column(type: 'string', length: 255)]
    private string $token;

    // e.g. 'finance'
    #[ORM\Column(type: 'string', length: 64)]
    private string $scope;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Company $company, TelegramBot $bot, string $token, string $scope, \DateTimeImmutable $expiresAt)
    {
        $this->company = $company;
        $this->bot = $bot;
        $this->token = $token;
        $this->scope = $scope;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }

    // getters/setters ...
}

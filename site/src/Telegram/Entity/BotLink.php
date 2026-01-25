<?php

namespace App\Telegram\Entity;

use App\Company\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: '`bot_links`')]
#[ORM\UniqueConstraint(name: 'uniq_bot_links_token', columns: ['token'])]
class BotLink
{
    // Уникальный идентификатор записи (UUID хранится строкой)
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    // Компания, для которой сформирована ссылка
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    // Бот, через которого будет работать ссылка
    #[ORM\ManyToOne(targetEntity: TelegramBot::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TelegramBot $bot;

    // Подписанный токен для deeplink /start
    #[ORM\Column(type: 'string', length: 255)]
    private string $token;

    // Контекст применения ссылки (например, finance)
    #[ORM\Column(type: 'string', length: 64)]
    private string $scope;

    // Время истечения действия ссылки
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    // Время использования ссылки, если уже активирована
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    // Дата создания записи
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    // Дата последнего изменения записи
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        Company $company,
        TelegramBot $bot,
        string $token,
        string $scope,
        \DateTimeImmutable $expiresAt,
    ) {
        Assert::uuid($id);

        $this->id = $id;
        $this->company = $company;
        $this->bot = $bot;
        $this->token = $token;
        $this->scope = $scope;
        $this->expiresAt = $expiresAt;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    // Отмечает ссылку как использованную
    public function markUsed(): void
    {
        $now = new \DateTimeImmutable();
        $this->usedAt = $now;
        $this->updatedAt = $now;
    }

    // Возвращает идентификатор
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

    // Возвращает бота
    public function getBot(): TelegramBot
    {
        return $this->bot;
    }

    // Устанавливает бота
    public function setBot(TelegramBot $bot): self
    {
        $this->bot = $bot;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // Возвращает токен
    public function getToken(): string
    {
        return $this->token;
    }

    // Обновляет токен
    public function setToken(string $token): self
    {
        $this->token = $token;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // Возвращает область действия
    public function getScope(): string
    {
        return $this->scope;
    }

    // Обновляет область действия
    public function setScope(string $scope): self
    {
        $this->scope = $scope;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // Возвращает дату истечения
    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    // Обновляет дату истечения
    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    // Возвращает дату использования или null
    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
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

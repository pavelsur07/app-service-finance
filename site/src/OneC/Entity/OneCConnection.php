<?php

declare(strict_types=1);

namespace App\OneC\Entity;

use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'onec_connection')]
#[ORM\UniqueConstraint(name: 'onec_connection_token_hash_key', columns: ['token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_onec_owner_base', columns: ['owner_id', 'base_id'])]
class OneCConnection
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public private(set) string $id;

    #[ORM\Column(type: 'guid', nullable: true)]
    public private(set) ?string $baseId = null;

    #[ORM\Column(length: 16)]
    public private(set) string $status = 'pending';

    /** @var array<string, string> */
    #[ORM\Column(type: 'jsonb')]
    public private(set) array $versions = [];

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $lastHandshakeAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $lastSuccessAt = null;

    public function __construct(
        string $id,
        #[ORM\Column(type: 'guid')]
        public private(set) string $ownerId,
        #[ORM\Column(length: 255)]
        public private(set) string $name,
        #[ORM\Column(length: 64)]
        private string $tokenHash,
    ) {
        Assert::uuid($id);
        Assert::uuid($ownerId);
        $this->id = $id;
    }

    /** @param array<string, string> $versions */
    public function handshake(string $baseId, array $versions, bool $supported, \DateTimeImmutable $now): void
    {
        if (null !== $this->baseId && $this->baseId !== $baseId) {
            throw new \DomainException('Подключение уже привязано к другой базе.', 409);
        }
        $this->baseId = $baseId;
        $this->versions = $versions;
        $this->status = $supported ? 'connected' : 'error';
        $this->lastHandshakeAt = $now;
    }

    /** Прежний токен перестаёт действовать; отключённое подключение снова ждёт рукопожатия. */
    public function reissueToken(string $tokenHash): void
    {
        $this->tokenHash = $tokenHash;
        if ('disabled' === $this->status) {
            $this->status = 'pending';
        }
    }

    /** Отключение = отзыв: хэш никому не известного токена, старый токен получает 401. */
    public function disable(): void
    {
        $this->tokenHash = hash('sha256', random_bytes(32));
        $this->status = 'disabled';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Ждёт рукопожатия',
            'connected' => 'Подключено',
            'error' => 'Версия не поддерживается',
            'disabled' => 'Отключено',
            default => $this->status,
        };
    }

    /** Подключено, но частей не было больше суток. */
    public function isStale(\DateTimeImmutable $now): bool
    {
        return 'connected' === $this->status && (null === $this->lastSuccessAt || $this->lastSuccessAt < $now->modify('-1 day'));
    }
}

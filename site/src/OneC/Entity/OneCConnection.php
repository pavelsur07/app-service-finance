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
}

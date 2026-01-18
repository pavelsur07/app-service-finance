<?php

declare(strict_types=1);

namespace App\Tests\Builders\Company;

use App\Entity\User;
use InvalidArgumentException;

final class UserBuilder
{
    public const DEFAULT_USER_ID = '22222222-2222-2222-2222-222222222222';
    public const DEFAULT_EMAIL = 'user+1@example.test';
    public const DEFAULT_PASSWORD_HASH = 'password-hash';
    public const DEFAULT_CREATED_AT = '2024-01-01 00:00:00+00:00';

    private const ALLOWED_ROLES = [
        'ROLE_USER',
        'ROLE_ADMIN',
        'ROLE_SUPER_ADMIN',
        'ROLE_COMPANY_USER',
        'ROLE_COMPANY_OWNER',
        'ROLE_MANAGER',
    ];

    private string $id;
    private string $email;
    private string $passwordHash;
    private \DateTimeImmutable $createdAt;
    /** @var list<string> */
    private array $roles;

    private function __construct()
    {
        $this->id = self::DEFAULT_USER_ID;
        $this->email = self::DEFAULT_EMAIL;
        $this->passwordHash = self::DEFAULT_PASSWORD_HASH;
        $this->createdAt = new \DateTimeImmutable(self::DEFAULT_CREATED_AT);
        $this->roles = ['ROLE_COMPANY_OWNER'];
    }

    public static function aUser(): self
    {
        return new self();
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->email = sprintf('user+%d@example.test', $index);

        return $clone;
    }

    public function withEmail(string $email): self
    {
        $clone = clone $this;
        $clone->email = $email;

        return $clone;
    }

    public function withPasswordHash(string $passwordHash): self
    {
        $clone = clone $this;
        $clone->passwordHash = $passwordHash;

        return $clone;
    }

    /**
     * @param list<string> $roles
     */
    public function withRoles(array $roles): self
    {
        $unknownRoles = array_diff($roles, self::ALLOWED_ROLES);
        if ([] !== $unknownRoles) {
            throw new InvalidArgumentException(sprintf('Unsupported roles: %s', implode(', ', $unknownRoles)));
        }

        $clone = clone $this;
        $clone->roles = $roles;

        return $clone;
    }

    public function asCompanyOwner(): self
    {
        $clone = clone $this;
        $clone->roles = ['ROLE_COMPANY_OWNER'];

        return $clone;
    }

    public function withCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $clone = clone $this;
        $clone->createdAt = $createdAt;

        return $clone;
    }

    public function build(): User
    {
        $user = new User($this->id, $this->createdAt);
        $user->setEmail($this->email);
        $user->setPassword($this->passwordHash);
        $user->setRoles($this->roles);

        return $user;
    }
}

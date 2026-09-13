<?php

declare(strict_types=1);

namespace App\Tests\Builders\Api;

use App\Api\Domain\ApiKeySecret;
use App\Api\Entity\ApiKey;

final class ApiKeyBuilder
{
    private string $companyId = '11111111-1111-4111-8111-111111111111';
    private string $secret = 'test-only-secret';
    private ?\DateTimeImmutable $now = null;

    public static function aKey(): self
    {
        return new self();
    }

    public function withCompanyId(string $id): self
    {
        $clone = clone $this;
        $clone->companyId = $id;

        return $clone;
    }

    public function withSecret(string $secret): self
    {
        $clone = clone $this;
        $clone->secret = $secret;

        return $clone;
    }

    public function createdAt(\DateTimeImmutable $now): self
    {
        $clone = clone $this;
        $clone->now = $now;

        return $clone;
    }

    public function build(): ApiKey
    {
        return new ApiKey($this->companyId, 'Test key', bin2hex(random_bytes(16)), ApiKeySecret::hash($this->secret), '22222222-2222-4222-8222-222222222222', $this->now ?? new \DateTimeImmutable('2026-09-13T10:00:00Z'));
    }
}

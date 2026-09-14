<?php

declare(strict_types=1);

namespace App\Tests\Builders\Balance;

use App\Balance\Entity\BalanceAuditEvent;

final class BalanceAuditEventBuilder
{
    private string $companyId = '22222222-2222-4222-8222-222222222222';
    private ?string $authorId = '33333333-3333-4333-8333-333333333333';
    private string $objectId = '33333333-3333-4333-8333-333333333333';

    public static function aBalanceAuditEvent(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withAuthorId(?string $authorId): self
    {
        $clone = clone $this;
        $clone->authorId = $authorId;

        return $clone;
    }

    public function withObjectId(string $objectId): self
    {
        $clone = clone $this;
        $clone->objectId = $objectId;

        return $clone;
    }

    public function build(): BalanceAuditEvent
    {
        return new BalanceAuditEvent($this->companyId, 'account', $this->objectId, 'created', $this->authorId, []);
    }
}

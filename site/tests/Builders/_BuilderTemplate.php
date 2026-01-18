<?php

declare(strict_types=1);

namespace App\Tests\Builders;

use App\Entity\Entity;

final class _BuilderTemplate
{
    private string $name = 'Default Name';
    private int $amount = 100;

    private function __construct()
    {
    }

    public static function aEntity(): self
    {
        return new self();
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withAmount(int $amount): self
    {
        $clone = clone $this;
        $clone->amount = $amount;

        return $clone;
    }

    public function build(): Entity
    {
        // persist/flush/clear выполняются в тестах, не в Builder
        return new Entity($this->name, $this->amount);
    }
}

<?php

namespace App\Cash\Application\Service;

final class AutoRuleDispatchGuard
{
    private int $depth = 0;

    public function isSuppressed(): bool
    {
        return $this->depth > 0;
    }

    public function suppress(callable $operation): mixed
    {
        ++$this->depth;

        try {
            return $operation();
        } finally {
            --$this->depth;
        }
    }
}

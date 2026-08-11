<?php

declare(strict_types=1);

namespace App\Company\Security;

enum AccessLevel: string
{
    case NONE = 'none';
    case READ = 'read';
    case WRITE = 'write';

    public function atLeast(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::NONE => 0,
            self::READ => 1,
            self::WRITE => 2,
        };
    }
}

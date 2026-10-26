<?php

declare(strict_types=1);

namespace App\Shared\Security\ValueObject;

final readonly class KeyVersionCount
{
    /**
     * @param string|null $keyVersion null — значение хранится без шифрования
     */
    public function __construct(
        public string $module,
        public string $field,
        public ?string $keyVersion,
        public int $count,
    ) {
    }
}

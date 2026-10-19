<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

/**
 * Вариант селекта «Кабинет» на /admin/users.
 */
final readonly class CabinetChoiceView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $inn,
    ) {
    }
}

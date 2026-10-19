<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

/**
 * Кабинет пользователя в списке /admin/users: собственный (owner) или по активному членству.
 */
final readonly class UserCabinetView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $inn,
        public bool $owner,
    ) {
    }
}

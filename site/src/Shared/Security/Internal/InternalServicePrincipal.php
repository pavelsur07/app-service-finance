<?php

declare(strict_types=1);

namespace App\Shared\Security\Internal;

use Symfony\Component\Security\Core\User\UserInterface;

/** Сервис из `services/`, прошедший внутреннюю авторизацию. Без компании и пользователя. */
final readonly class InternalServicePrincipal implements UserInterface
{
    public const ROLE = 'ROLE_INTERNAL_SERVICE';

    public function getUserIdentifier(): string
    {
        return 'internal-service';
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function eraseCredentials(): void
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Company\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Голосует по атрибутам module.<group>.read|write, делегируя ModuleAccessResolver.
 * Security/AuthorizationChecker сюда не инжектим, чтобы не получить рекурсию voter'ов.
 */
final class ModuleAccessVoter extends Voter
{
    public function __construct(
        private readonly ModuleAccessResolver $resolver,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return ModuleAccess::isModuleAttribute($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $parsed = ModuleAccess::parse($attribute);
        if (null === $parsed) {
            return false;
        }

        [$module, $level] = $parsed;

        return $this->resolver->allows($module, $level);
    }
}

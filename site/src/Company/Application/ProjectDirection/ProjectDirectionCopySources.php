<?php

declare(strict_types=1);

namespace App\Company\Application\ProjectDirection;

use App\Company\Entity\User;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Company\Security\ModuleAccessResolver;

final readonly class ProjectDirectionCopySources
{
    public function __construct(
        private CompanyRepository $companies,
        private CompanyMemberRepository $members,
        private ModuleAccessResolver $access,
    ) {
    }

    /** @return array<string, string> */
    public function forUser(User $user, string $targetId): array
    {
        $userId = (string) $user->getId();
        $memberships = [];
        foreach ($this->members->findActiveByUserId($userId) as $member) {
            $memberships[(string) $member->getCompany()->getId()] = $member;
        }

        $sources = [];
        foreach ($this->companies->findAccessibleByUserId($userId) as $company) {
            $id = (string) $company->getId();
            if ($id !== $targetId && $this->access->allowsForCompany($user, $company, $memberships[$id] ?? null, Module::FINANCE, AccessLevel::READ)) {
                $sources[$id] = (string) $company->getName();
            }
        }

        return $sources;
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Service;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyMemberRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Service\ResetInterface;

class ActiveCompanyService implements ResetInterface
{
    private ?Company $activeCompany = null;
    private mixed $activeCompanyKey = null;
    private ?string $activeUserKey = null;
    private bool $membershipResolved = false;
    private ?CompanyMember $activeMembership = null;
    private mixed $membershipKey = null;
    private ?string $membershipUserKey = null;

    public function __construct(
        private RequestStack $requestStack,
        private CompanyRepository $companyRepository,
        private CompanyMemberRepository $companyMemberRepository,
        private Security $security,
    ) {
    }

    public function getActiveCompany(): Company
    {
        $session = $this->requestStack->getSession();
        $id = $session->get('active_company_id');
        $user = $this->security->getUser();

        if (null === $user) {
            throw new NotFoundHttpException();
        }

        // Мемоизация на запрос с ключом (пользователь, active_company_id из сессии):
        // смена любого из них посреди запроса (логин, legacy-переключение компании)
        // инвалидирует кэш. Кэш-хит не обходится без проверки пользователя.
        $userKey = (string) $user->getId();
        if (null !== $this->activeCompany
            && $this->activeCompanyKey === $id
            && $this->activeUserKey === $userKey
        ) {
            return $this->activeCompany;
        }

        if ($id) {
            $company = $this->companyRepository->find($id);
            if ($company && $company->getUser() === $user) {
                return $this->memoizeCompany($company, $userKey);
            }
            if ($company) {
                $membership = $this->companyMemberRepository->findActiveOneByCompanyAndUser($company, $user);
                if (null !== $membership) {
                    // Найденное членство сразу кэшируем, чтобы getActiveMembership() не дублировал запрос.
                    return $this->memoizeCompany($company, $userKey, $membership);
                }
            }
        }

        $company = $this->companyRepository->findOneBy(['user' => $user])
            ?? $this->companyMemberRepository->findFirstActiveCompanyForUser($user);
        if (!$company) {
            throw new NotFoundHttpException();
        }

        $session->set('active_company_id', $company->getId());

        return $this->memoizeCompany($company, $userKey);
    }

    /**
     * Активное членство текущего пользователя в активной компании или null.
     * Никогда не бросает: нет пользователя/сессии/компании — null.
     */
    public function getActiveMembership(): ?CompanyMember
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        try {
            $company = $this->getActiveCompany();
        } catch (NotFoundHttpException|SessionNotFoundException) {
            return null;
        }

        $userKey = (string) $user->getId();
        if ($this->membershipResolved
            && $this->membershipKey === $this->activeCompanyKey
            && $this->membershipUserKey === $userKey
        ) {
            return $this->activeMembership;
        }

        $this->membershipResolved = true;
        $this->membershipKey = $this->activeCompanyKey;
        $this->membershipUserKey = $userKey;

        return $this->activeMembership = $this->companyMemberRepository->findActiveOneByCompanyAndUser($company, $user);
    }

    public function reset(): void
    {
        $this->activeCompany = null;
        $this->activeCompanyKey = null;
        $this->activeUserKey = null;
        $this->membershipResolved = false;
        $this->activeMembership = null;
        $this->membershipKey = null;
        $this->membershipUserKey = null;
    }

    private function memoizeCompany(Company $company, string $userKey, ?CompanyMember $membership = null): Company
    {
        $this->activeCompany = $company;
        $this->activeCompanyKey = $this->requestStack->getSession()->get('active_company_id');
        $this->activeUserKey = $userKey;
        // Если членство найдено вместе с компанией — кэшируем и его; иначе сбрасываем.
        $this->membershipResolved = null !== $membership;
        $this->activeMembership = $membership;
        $this->membershipKey = $this->activeCompanyKey;
        $this->membershipUserKey = $userKey;

        return $company;
    }
}

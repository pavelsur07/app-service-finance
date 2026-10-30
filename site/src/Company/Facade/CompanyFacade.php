<?php

declare(strict_types=1);

namespace App\Company\Facade;

use App\Company\Application\CreateCompanyOwnerAccountAction;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Enum\BusinessProfile;
use App\Company\Exception\CompanyNotFoundException;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Repository\CounterpartyRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Company\Repository\UserRepository;
use Ramsey\Uuid\Uuid;

/**
 * Публичный контракт модуля Company для других модулей.
 */
final class CompanyFacade
{
    // Must match companies_public_id_seq in Version20260913090000.
    private const MIN_PUBLIC_ID = 100_000;

    public function __construct(
        private readonly CompanyRepository $repository,
        private readonly CreateCompanyOwnerAccountAction $accountCreator,
        private readonly CounterpartyRepository $counterpartyRepository,
        private readonly CompanyMemberRepository $companyMemberRepository,
        private readonly ProjectDirectionRepository $projectDirectionRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function findById(string $companyId): ?Company
    {
        return $this->repository->findById($companyId);
    }

    /**
     * Профиль бизнеса компании — по нему модули выбирают стартовые шаблоны.
     *
     * @throws CompanyNotFoundException
     */
    public function getBusinessProfile(string $companyId): BusinessProfile
    {
        return ($this->repository->findById($companyId) ?? throw new CompanyNotFoundException($companyId))->getBusinessProfile();
    }

    /**
     * Global public identity lookup; this does not grant access to company data.
     */
    public function findByPublicId(int $publicId): ?Company
    {
        return $publicId < self::MIN_PUBLIC_ID ? null : $this->repository->findByPublicId($publicId);
    }

    /** @return list<array{id: string, label: string}> */
    public function listMembers(string $companyId): array
    {
        $company = $this->repository->findById($companyId);
        if (null === $company) {
            return [];
        }
        $choices = [];
        $owner = $company->getUser();
        if (null !== $owner && null !== $owner->getId()) {
            $choices[$owner->getId()] = ['id' => $owner->getId(), 'label' => (string) $owner->getEmail()];
        }
        foreach ($this->companyMemberRepository->findActiveUserChoicesByCompany($companyId) as $choice) {
            $choices[$choice['id']] = $choice;
        }

        return array_values($choices);
    }

    /**
     * Fresh financial rights, optionally serialized with membership/role changes.
     * Lock order for callers: company first, then module-specific resources.
     *
     * @return array{owner:bool,read:bool,write:bool}
     */
    public function financialAccess(string $companyId, string $userId, bool $lock = false): array
    {
        if (!Uuid::isValid($companyId) || !Uuid::isValid($userId)) {
            return ['owner' => false, 'read' => false, 'write' => false];
        }

        return $this->repository->financialAccess($companyId, $userId, $lock);
    }

    public function isOwner(string $companyId, string $userId): bool
    {
        if (!Uuid::isValid($companyId) || !Uuid::isValid($userId)) {
            return false;
        }

        return $this->repository->findById($companyId)?->getUser()?->getId() === $userId;
    }

    /**
     * Создаёт пользователя-владельца, компанию и активного CompanyMember OWNER.
     */
    public function createOwnerAccount(string $email, string $plainPassword, string $companyName, BusinessProfile $businessProfile = BusinessProfile::DEFAULT): Company
    {
        $user = new User(Uuid::uuid7()->toString());
        $user->setEmail($email);

        return ($this->accountCreator)($user, $plainPassword, $companyName, businessProfile: $businessProfile);
    }

    /**
     * Возвращает список ID всех активных компаний в системе.
     * Используется воркерами и CLI-командами других модулей.
     *
     * @return list<string>
     */
    public function getAllActiveCompanyIds(): array
    {
        return $this->repository->getAllActiveCompanyIds();
    }

    /**
     * Возвращает компании по списку ID как простые DTO-массивы,
     * чтобы вызывающий модуль не тянул Entity.
     *
     * @param list<string> $companyIds
     *
     * @return list<array{id: string, name: string}>
     */
    public function getCompaniesByIds(array $companyIds): array
    {
        return $this->repository->findByIds($companyIds);
    }

    /**
     * Контрагент строго в рамках компании — защита от обращения к чужим данным по id.
     */
    public function findCounterpartyByIdAndCompany(string $counterpartyId, string $companyId): ?Counterparty
    {
        if (!Uuid::isValid($counterpartyId) || !Uuid::isValid($companyId)) {
            return null;
        }

        return $this->counterpartyRepository->findOneByIdAndCompany($counterpartyId, $companyId);
    }

    public function findProjectDirectionByIdAndCompany(string $projectDirectionId, string $companyId): ?ProjectDirection
    {
        if (!Uuid::isValid($projectDirectionId) || !Uuid::isValid($companyId)) {
            return null;
        }

        return $this->projectDirectionRepository->findOneByIdAndCompanyId($projectDirectionId, $companyId);
    }

    /**
     * Компании, доступные пользователю: которыми он владеет, либо в которых
     * состоит активным участником. Используется для выбора компании при
     * межкомпанийных операциях (например, импорт справочников).
     *
     * @return list<array{id: string, name: string}>
     */
    public function listAccessibleCompaniesForUser(string $userId): array
    {
        return array_map(
            static fn (Company $company): array => [
                'id' => (string) $company->getId(),
                'name' => (string) $company->getName(),
            ],
            $this->accessibleCompaniesForUser($userId),
        );
    }

    /**
     * Владелец компании либо активный CompanyMember — единственные, у кого
     * есть доступ к данным компании.
     */
    public function userHasAccess(string $companyId, string $userId): bool
    {
        foreach ($this->accessibleCompaniesForUser($userId) as $company) {
            if ($company->getId() === $companyId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Пользователь с таким id существует (без проверки доступа к компании).
     */
    public function userExists(string $userId): bool
    {
        return Uuid::isValid($userId) && null !== $this->userRepository->find($userId);
    }

    /**
     * Проекты компании для пикера в формах, связанных с сущностью (legacy-ассоциации
     * `ProjectDirection`): `$treeOrder` — обход дерева, иначе по названию.
     *
     * @return list<ProjectDirection>
     */
    public function listProjectDirectionEntities(string $companyId, bool $treeOrder): array
    {
        $company = $this->findById($companyId);
        if (null === $company) {
            return [];
        }

        /** @var list<ProjectDirection> $projects */
        $projects = array_values($treeOrder
            ? $this->projectDirectionRepository->findTreeByCompany($company)
            : $this->projectDirectionRepository->findBy(['company' => $company], ['name' => 'ASC']));

        return $projects;
    }

    /**
     * Системный проект «Основной» компании — для документов, которым проект не указан.
     */
    public function findDefaultProjectDirection(string $companyId): ?ProjectDirection
    {
        $company = $this->findById($companyId);

        return null === $company ? null : $this->projectDirectionRepository->findDefaultForCompany($company);
    }

    /**
     * @return list<Company>
     */
    private function accessibleCompaniesForUser(string $userId): array
    {
        $owned = $this->repository->findByUserId($userId);
        $memberCompanies = array_map(
            static fn (CompanyMember $member): Company => $member->getCompany(),
            $this->companyMemberRepository->findActiveByUserId($userId),
        );

        $byId = [];
        foreach ([...$owned, ...$memberCompanies] as $company) {
            $byId[$company->getId()] = $company;
        }

        return array_values($byId);
    }
}

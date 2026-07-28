<?php

declare(strict_types=1);

namespace App\Company\Facade;

use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\User;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CounterpartyRepository;
use App\Company\Service\CompanyOwnerAccountCreator;
use Ramsey\Uuid\Uuid;

/**
 * Публичный контракт модуля Company для других модулей.
 */
final class CompanyFacade
{
    public function __construct(
        private readonly CompanyRepository $repository,
        private readonly CompanyOwnerAccountCreator $accountCreator,
        private readonly CounterpartyRepository $counterpartyRepository,
    ) {
    }

    public function findById(string $companyId): ?Company
    {
        return $this->repository->findById($companyId);
    }

    /**
     * Глобальный справочный поиск без ограничения по компании.
     * Предназначен только для read-only MCP-инструмента company_find_by_name.
     */
    public function resolveIdByName(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('Укажите название компании.');
        }

        $ids = $this->repository->findIdsByExactName($name);

        return match (\count($ids)) {
            0 => throw new \DomainException('Компания с таким названием не найдена.'),
            1 => $ids[0],
            default => throw new \DomainException('Найдено несколько компаний с таким названием.'),
        };
    }

    /**
     * Создаёт пользователя-владельца, компанию и активного CompanyMember OWNER.
     */
    public function createOwnerAccount(string $email, string $plainPassword, string $companyName): Company
    {
        $user = new User(Uuid::uuid7()->toString());
        $user->setEmail($email);

        return $this->accountCreator->create($user, $plainPassword, $companyName);
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

        return $this->counterpartyRepository->findOneBy([
            'id' => $counterpartyId,
            'company' => $companyId,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Company\Facade;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use Doctrine\DBAL\Connection;

/**
 * Публичный контракт модуля Company для других модулей.
 */
final class CompanyFacade
{
    public function __construct(
        private CompanyRepository $repository
    ) {}


    public function findById(string $companyId): ?Company
    {
        return $this->repository->findById($companyId);
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
}

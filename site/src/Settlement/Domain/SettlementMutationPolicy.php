<?php

declare(strict_types=1);

namespace App\Settlement\Domain;

use App\Company\Facade\CompanyFacade;
use App\Settlement\Entity\SettlementBook;
use App\Settlement\Repository\SettlementRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class SettlementMutationPolicy
{
    public function __construct(private CompanyFacade $companies, private SettlementRepository $repository)
    {
    }

    public function requireWrite(string $companyId, string $actorUserId): void
    {
        if (!$this->companies->financialAccess($companyId, $actorUserId, true)['write']) {
            throw new AccessDeniedException();
        }
    }

    public function lock(string $companyId, string $actorUserId): SettlementBook
    {
        $this->requireWrite($companyId, $actorUserId);

        return $this->repository->getBookForUpdate($companyId);
    }

    public function assertDate(string $companyId, SettlementBook $book, \DateTimeImmutable $date): void
    {
        $day = $date->format('Y-m-d');
        $lock = $this->companies->getFinanceLockBefore($companyId);
        if ($day < $book->getStartDate()->format('Y-m-d') || $day > (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d') || (null !== $lock && $day < $lock->format('Y-m-d'))) {
            throw new \DomainException('Дата вне открытого периода учёта.');
        }
    }
}

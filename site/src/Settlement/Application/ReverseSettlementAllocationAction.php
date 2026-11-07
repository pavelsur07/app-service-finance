<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Company\Facade\CompanyFacade;
use App\Settlement\Application\DTO\ReverseSettlementInput;
use App\Settlement\Application\DTO\SettlementResult;
use App\Settlement\Repository\SettlementRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Read-only dispatch wrapper; ReverseSettlementDocumentAction owns the sole write transaction. */
final readonly class ReverseSettlementAllocationAction
{
    public function __construct(private CompanyFacade $companies, private SettlementRepository $repository, private ReverseSettlementDocumentAction $reverse)
    {
    }

    public function __invoke(string $companyId, string $actorUserId, string $allocationId, ReverseSettlementInput $input): SettlementResult
    {
        if (!$this->companies->financialAccess($companyId, $actorUserId)['write']) {
            throw new AccessDeniedException();
        }
        $allocation = $this->repository->getAllocation($companyId, $allocationId);

        return ($this->reverse)($companyId, $actorUserId, $allocation->getDocumentId(), $input);
    }
}

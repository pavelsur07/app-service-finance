<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Cash\Application\DTO\CashSettlementSource;
use App\Cash\Facade\CashFacade;
use App\Finance\Application\DTO\FinanceSettlementSource;
use App\Finance\Facade\FinanceFacade;
use App\Settlement\Domain\SettlementMutationPolicy;
use App\Settlement\Enum\SettlementSourceType;
use Doctrine\ORM\EntityManagerInterface;

/** Existing snapshot Facades require a transaction even for a form preview. */
final readonly class PreviewSettlementSourceAction
{
    public function __construct(private EntityManagerInterface $em, private SettlementMutationPolicy $policy, private CashFacade $cash, private FinanceFacade $finance)
    {
    }

    public function __invoke(string $companyId, string $actorUserId, SettlementSourceType $type, string $id): CashSettlementSource|FinanceSettlementSource
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $type, $id): CashSettlementSource|FinanceSettlementSource {
            $this->policy->requireWrite($companyId, $actorUserId);

            return match ($type) {
                SettlementSourceType::CASH => $this->cash->getSettlementSourceForUpdate($companyId, $id),
                SettlementSourceType::FINANCE => $this->finance->getSettlementSourceForUpdate($companyId, $id),
                default => throw new \DomainException('Неизвестный источник.'),
            };
        });
    }
}

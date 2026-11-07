<?php

declare(strict_types=1);

namespace App\Cash\Application\Service;

use App\Cash\Domain\Event\CashSettlementSourceChanging;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class CashSettlementSourceLifecycle
{
    public function __construct(private EntityManagerInterface $em, private CashTransactionRepository $repository, private EventDispatcherInterface $events)
    {
    }

    public function lock(CashTransaction $transaction): void
    {
        $companyId = (string) $transaction->getCompany()->getId();
        $this->repository->getSettlementSourceForUpdate($companyId, (string) $transaction->getId());
        $this->em->refresh($transaction);
    }

    /** Call after lock and before applying changes; no flush/commit. */
    public function changing(CashTransaction $transaction, bool $materialChange): void
    {
        $this->events->dispatch(new CashSettlementSourceChanging((string) $transaction->getCompany()->getId(), (string) $transaction->getId(), $materialChange));
    }
}

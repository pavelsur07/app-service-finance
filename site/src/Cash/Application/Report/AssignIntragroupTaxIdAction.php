<?php

declare(strict_types=1);

namespace App\Cash\Application\Report;

use App\Cash\Exception\IntragroupDecisionException;
use App\Company\Facade\CounterpartyFacade;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class AssignIntragroupTaxIdAction
{
    public function __construct(
        private EntityManagerInterface $em,
        private IntragroupGroupAccess $access,
        private IntragroupTaxIds $suggestions,
        private CounterpartyFacade $counterparties,
    ) {
    }

    public function __invoke(string $activeCompanyId, string $userId, string $companyId, string $counterpartyId, string $peerCompanyId): void
    {
        $this->em->wrapInTransaction(function () use ($activeCompanyId, $userId, $companyId, $counterpartyId, $peerCompanyId): void {
            $members = $this->access->lockedMembers($activeCompanyId, $userId);
            if (!\in_array($companyId, $members, true) || !\in_array($peerCompanyId, $members, true) || !Uuid::isValid($counterpartyId)) {
                throw new IntragroupDecisionException('Контрагент и встречная компания должны входить в выбранную группу.');
            }
            $candidate = $this->suggestions->forAssignment($members, $companyId, $counterpartyId, $peerCompanyId);
            if (null === $candidate || !$this->counterparties->assignMissingInn($companyId, $counterpartyId, $candidate['name'], $candidate['inn'])) {
                throw new IntragroupDecisionException('Предложение ИНН устарело. Обновите сверку.');
            }
        });
    }
}

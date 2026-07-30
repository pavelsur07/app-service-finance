<?php

declare(strict_types=1);

namespace App\Company\Facade;

use App\Company\Entity\Counterparty;
use App\Company\Facade\DTO\CounterpartyChoiceDTO;
use App\Company\Repository\CounterpartyRepository;
use Ramsey\Uuid\Uuid;

/**
 * Единственная точка доступа соседних модулей к справочнику контрагентов для форм.
 *
 * Каждый метод принимает companyId: изоляция по компании не опциональна.
 */
final class CounterpartyFacade
{
    public function __construct(private readonly CounterpartyRepository $repository)
    {
    }

    /**
     * Варианты выбора для формы: архивные не предлагаются, но уже выбранный архивный
     * остаётся в списке — иначе правка старой записи молча потеряет ссылку.
     *
     * @return list<CounterpartyChoiceDTO>
     */
    public function getSelectable(string $companyId, ?string $keepId = null): array
    {
        if (!Uuid::isValid($companyId)) {
            return [];
        }

        $keepId = null !== $keepId && Uuid::isValid($keepId) ? $keepId : null;

        return array_map(
            static fn (Counterparty $counterparty): CounterpartyChoiceDTO => new CounterpartyChoiceDTO(
                $counterparty->getId(),
                $counterparty->getName(),
                $counterparty->getInn(),
                $counterparty->getKpp(),
                $counterparty->isArchived(),
            ),
            $this->repository->findSelectableByCompany($companyId, $keepId),
        );
    }

    /**
     * Контрагент строго в рамках компании — для форм, которым нужна сама сущность.
     */
    public function findEntityByIdAndCompany(string $counterpartyId, string $companyId): ?Counterparty
    {
        if (!Uuid::isValid($counterpartyId) || !Uuid::isValid($companyId)) {
            return null;
        }

        return $this->repository->findOneByIdAndCompany($counterpartyId, $companyId);
    }
}

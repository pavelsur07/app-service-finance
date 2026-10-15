<?php

declare(strict_types=1);

namespace App\Company\Facade;

use App\Company\Domain\ValueObject\CounterpartyName;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Enum\CounterpartyType;
use App\Company\Facade\DTO\CounterpartyChoiceDTO;
use App\Company\Repository\CounterpartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Единственная точка доступа соседних модулей к справочнику контрагентов для форм.
 *
 * Каждый метод принимает companyId: изоляция по компании не опциональна.
 */
final class CounterpartyFacade
{
    public function __construct(
        private readonly CounterpartyRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
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
                $counterparty->getDisplayName(),
                $counterparty->getType(),
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

    /**
     * ИНН контрагентов компании из списка, которые уже заведены (как хранятся).
     *
     * @param list<string> $inns
     *
     * @return list<string>
     */
    public function findExistingInns(string $companyId, array $inns): array
    {
        if (!Uuid::isValid($companyId) || [] === $inns) {
            return [];
        }

        $existing = [];
        foreach ($this->repository->findBy(['company' => $companyId, 'inn' => $inns]) as $counterparty) {
            $inn = $counterparty->getInn();
            if (null !== $inn) {
                $existing[] = $inn;
            }
        }

        return $existing;
    }

    /**
     * Контрагент компании по ИНН; если его нет — создаётся с этим именем и ИНН.
     * Новый контрагент только persist(): flush — за вызывающим Action/Handler.
     */
    public function findOrCreateByInn(Company $company, string $inn, CounterpartyName $name, CounterpartyType $type): Counterparty
    {
        $existing = $this->repository->findOneBy(['company' => $company, 'inn' => $inn]);
        if ($existing instanceof Counterparty) {
            return $existing;
        }

        $counterparty = new Counterparty(Uuid::uuid7()->toString(), $company, $name, $type);
        $counterparty->assignTaxIds($inn, null);
        $this->entityManager->persist($counterparty);

        return $counterparty;
    }

    /**
     * Контрагент компании по нормализованному названию (ядро + подсказка ОПФ); если
     * его нет — создаётся. Новый контрагент только persist().
     */
    public function findOrCreateByNormalizedName(Company $company, CounterpartyName $name, CounterpartyType $type): Counterparty
    {
        $existing = $this->repository->findOneByNormalizedName((string) $company->getId(), $name->core, $name->legalFormHint);
        if ($existing instanceof Counterparty) {
            return $existing;
        }

        $counterparty = new Counterparty(Uuid::uuid7()->toString(), $company, $name, $type);
        $this->entityManager->persist($counterparty);

        return $counterparty;
    }
}

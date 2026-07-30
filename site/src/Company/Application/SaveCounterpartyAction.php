<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Application\DTO\CounterpartyFormData;
use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Enum\CounterpartyType;
use App\Company\Exception\CounterpartyInnAlreadyExistsException;
use App\Company\Repository\CounterpartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Создание и изменение контрагента справочника.
 *
 * Создание и изменение отличаются только исключением самой записи из проверки
 * уникальности ИНН, поэтому это один Action, а не два почти одинаковых.
 */
final class SaveCounterpartyAction
{
    public function __construct(
        private readonly CounterpartyNameNormalizer $normalizer,
        private readonly CounterpartyRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Company $company, CounterpartyFormData $data, ?Counterparty $counterparty = null): Counterparty
    {
        $name = $this->normalizer->normalize((string) $data->name);
        $inn = $this->normalizeTaxId($data->inn);
        $kpp = $this->normalizeTaxId($data->kpp);
        $type = $data->type ?? CounterpartyType::LEGAL_ENTITY;

        if (null !== $inn) {
            $existing = $this->repository->findOneByInn($company->getId(), $inn, $counterparty?->getId());
            if (null !== $existing) {
                throw new CounterpartyInnAlreadyExistsException($inn);
            }
        }

        if (null === $counterparty) {
            $counterparty = new Counterparty(Uuid::uuid7()->toString(), $company, $name, $type);
            $this->em->persist($counterparty);
        } else {
            $counterparty->rename($name);
            $counterparty->setType($type);
        }

        $counterparty->assignTaxIds($inn, $kpp);

        if ($counterparty->hasInconsistentLegalFormHint()) {
            // Ожидаемое условие: ошибка разбора названия, не инцидент.
            $this->logger->warning('Counterparty legal form hint conflicts with INN length, hint dropped.', [
                'counterpartyId' => $counterparty->getId(),
                'companyId' => $company->getId(),
                'legalFormHint' => $counterparty->getLegalFormHint(),
            ]);
            $counterparty->clearLegalFormHint();
        }

        $this->em->flush();

        return $counterparty;
    }

    private function normalizeTaxId(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}

<?php

namespace App\Service\Bank1C;

use App\DTO\CashTransactionDTO;
use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Enum\CashDirection;
use App\Enum\CounterpartyType;
use App\Repository\CashTransactionRepository;
use App\Repository\CounterpartyRepository;
use App\Service\Bank1C\Dto\Bank1CImportResult;
use App\Service\Bank1C\Dto\Bank1CDocument;
use App\Service\CashTransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

class Bank1CImportService
{
    public function __construct(
        private Bank1CStatementParser $parser,
        private CashTransactionService $txService,
        private CounterpartyRepository $cpRepo,
        private CashTransactionRepository $txRepo,
        private EntityManagerInterface $em
    ) {
    }

    public function import(Company $company, MoneyAccount $account, string $raw, ?string $filename = null): Bank1CImportResult
    {
        $result = new Bank1CImportResult();
        $statement = $this->parser->parse($raw);
        foreach ($statement->documents as $doc) {
            $result->total++;
            try {
                $direction = $this->determineDirection($account, $doc);
                $dateStr = $this->determineDate($direction, $doc);
                $counterparty = $this->resolveCounterparty($company, $direction, $doc);

                $externalId = hash('sha256', implode('|', [
                    $doc->type,
                    $doc->number,
                    $doc->date,
                    $doc->amount,
                    $doc->payerAccount,
                    $doc->payeeAccount,
                ]));

                if ($this->txRepo->findOneBy([
                    'company' => $company,
                    'moneyAccount' => $account,
                    'externalId' => $externalId,
                ])) {
                    $result->duplicates++;
                    continue;
                }

                $dto = new CashTransactionDTO();
                $dto->companyId = $company->getId();
                $dto->moneyAccountId = $account->getId();
                $dto->direction = $direction;
                $dto->amount = $doc->amount ?? '0';
                $dto->currency = $account->getCurrency();
                $dto->occurredAt = new \DateTimeImmutable($dateStr);
                $dto->description = $doc->purpose;
                $dto->externalId = $externalId;
                if ($counterparty) {
                    $dto->counterpartyId = $counterparty->getId();
                }
                $this->txService->add($dto);
                $result->created++;
                if (count($result->samples) < 10) {
                    $result->samples[] = [
                        'date' => $dateStr,
                        'direction' => $direction->value,
                        'amount' => $doc->amount ?? '0',
                        'counterparty' => $counterparty?->getName(),
                        'purpose' => $doc->purpose ?? '',
                    ];
                }
            } catch (\Throwable $e) {
                $result->errors[] = ($doc->number ?? '?') . ': ' . $e->getMessage();
            }
        }
        return $result;
    }

    private function determineDirection(MoneyAccount $account, Bank1CDocument $doc): CashDirection
    {
        $acc = $account->getAccountNumber();
        if ($doc->payerAccount === $acc && $doc->payeeAccount === $acc) {
            return CashDirection::OUTFLOW;
        }
        if ($doc->payerAccount === $acc) {
            return CashDirection::OUTFLOW;
        }
        return CashDirection::INFLOW;
    }

    private function determineDate(CashDirection $direction, Bank1CDocument $doc): string
    {
        if ($direction === CashDirection::INFLOW) {
            return $doc->dateCredited ?: ($doc->date ?: 'today');
        }
        return $doc->dateDebited ?: ($doc->date ?: 'today');
    }

    private function resolveCounterparty(Company $company, CashDirection $direction, Bank1CDocument $doc): ?Counterparty
    {
        $name = $direction === CashDirection::INFLOW ? $doc->payerName : $doc->payeeName;
        $inn = $direction === CashDirection::INFLOW ? $doc->payerInn : $doc->payeeInn;
        if (!$name && !$inn) {
            return null;
        }
        $counterparty = null;
        if ($inn) {
            $counterparty = $this->cpRepo->findOneBy(['company' => $company, 'inn' => $inn]);
        }
        if (!$counterparty && $name) {
            $counterparty = $this->cpRepo->findOneBy(['company' => $company, 'name' => $name]);
        }
        if (!$counterparty && $name) {
            $type = $this->guessCounterpartyType($name, $inn);
            $counterparty = new Counterparty(Uuid::uuid4()->toString(), $company, $name, $type);
            if ($inn) {
                $counterparty->setInn($inn);
            }
            $this->em->persist($counterparty);
            $this->em->flush();
        }
        return $counterparty;
    }

    private function guessCounterpartyType(string $name, ?string $inn): CounterpartyType
    {
        if ($inn && strlen($inn) === 12) {
            return CounterpartyType::INDIVIDUAL_ENTREPRENEUR;
        }
        if (str_starts_with(mb_strtoupper($name), 'ИП')) {
            return CounterpartyType::INDIVIDUAL_ENTREPRENEUR;
        }
        return CounterpartyType::LEGAL_ENTITY;
    }
}

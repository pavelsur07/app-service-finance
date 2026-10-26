<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\DTO\BankStatementDayInput;
use App\Cash\Application\DTO\BankStatementDayResult;
use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Exception\StatementImportOutsideTransactionException;
use App\Cash\Exception\StatementImportTargetNotFoundException;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Company\Entity\ProjectDirection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Строки банковской выписки за день → операции ДДС и пересчёт остатков счёта.
 *
 * Своей транзакции не открывает: строки, остатки и курсор загрузки вызывающего коммитятся
 * вместе в его `wrapInTransaction()`. `flush()` внутри неё нужен, потому что пересчёт
 * остатков читает строки из БД. Порядок блокировок: блокировка импорта счёта (сессионная, у
 * вызывающего) → транзакция дня → блокировка пересчёта счёта.
 */
final readonly class ImportBankStatementDayAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MoneyAccountRepository $moneyAccountRepository,
        private CashTransactionRepository $cashTransactionRepository,
        private CashTransactionResponsibilityCenterResolver $responsibilityCenterResolver,
        private AccountBalanceService $accountBalanceService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $moneyAccountId,
        string $source,
        BankStatementDayInput $day,
    ): BankStatementDayResult {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new StatementImportOutsideTransactionException();
        }

        $account = $this->moneyAccountRepository->findOneByIdAndCompanyId($moneyAccountId, $companyId)
            ?? throw StatementImportTargetNotFoundException::moneyAccount($moneyAccountId);
        $company = $account->getCompany();

        $externalIds = array_map(static fn ($line): string => $line->externalId, $day->lines);
        $alreadyImported = array_fill_keys(
            $this->cashTransactionRepository->findImportedExternalIds($companyId, $source, $externalIds),
            true,
        );

        $responsibility = $this->responsibilityCenterResolver->resolveForCreate($companyId, null, null);
        $project = $this->entityManager->getReference(ProjectDirection::class, $responsibility->projectDirectionId);

        $created = 0;
        $duplicates = 0;
        $errors = 0;
        $firstDate = null;

        foreach ($day->lines as $line) {
            if (isset($alreadyImported[$line->externalId])) {
                ++$duplicates;
                continue;
            }
            $alreadyImported[$line->externalId] = true;

            $currency = null !== $line->currency ? strtoupper($line->currency) : $account->getCurrency();
            if ($currency !== $account->getCurrency()) {
                ++$errors;
                continue;
            }

            $transaction = new CashTransaction(
                Uuid::uuid7()->toString(),
                $company,
                $account,
                $line->isInflow ? CashDirection::INFLOW : CashDirection::OUTFLOW,
                $line->amount,
                $currency,
                $line->occurredAt,
            );
            $transaction
                ->setExternalId($line->externalId)
                ->setImportSource($source)
                ->setProjectDirection($project)
                ->setResponsibilityCenterId($responsibility->responsibilityCenterId)
                ->setDescription($line->description);
            $this->entityManager->persist($transaction);

            ++$created;
            if (null === $firstDate || $line->occurredAt < $firstDate) {
                $firstDate = $line->occurredAt;
            }
        }

        if ($errors > 0) {
            $this->logger->warning('Bank statement lines skipped: currency differs from the account', [
                'company' => $companyId,
                'money_account_id' => $moneyAccountId,
                'source' => $source,
                'account_currency' => $account->getCurrency(),
                'skipped' => $errors,
            ]);
        }

        if (null !== $firstDate) {
            $this->entityManager->flush();
            $this->accountBalanceService->recalculateDailyRange(
                $company,
                $account,
                $firstDate,
                new \DateTimeImmutable('today'),
            );
        }

        return new BankStatementDayResult($created, $duplicates, $errors);
    }
}

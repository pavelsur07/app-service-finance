<?php

declare(strict_types=1);

namespace App\Cash\Facade;

use App\Cash\Application\AddCashTransactionAction;
use App\Cash\Application\CashTransferLifecycleAction;
use App\Cash\Application\CreateCashTransferAction;
use App\Cash\Application\DTO\BankStatementDayInput;
use App\Cash\Application\DTO\BankStatementDayResult;
use App\Cash\Application\DTO\CashCategoryAmount;
use App\Cash\Application\DTO\CashDailyAmount;
use App\Cash\Application\DTO\CashflowCategoryNode;
use App\Cash\Application\DTO\CashflowReportRow;
use App\Cash\Application\DTO\CashflowReportRowFilter;
use App\Cash\Application\DTO\CashStatementLine;
use App\Cash\Application\DTO\CreateCashTransactionCommand;
use App\Cash\Application\DTO\CreateCashTransactionResult;
use App\Cash\Application\DTO\CreateCashTransferCommand;
use App\Cash\Application\DTO\CreateCashTransferResult;
use App\Cash\Application\DTO\MoneyAccountDailyBalanceRow;
use App\Cash\Application\DTO\MoneyAccountOpeningBalance;
use App\Cash\Application\DTO\MoneyAccountSummary;
use App\Cash\Application\DTO\StatementImportAccount;
use App\Cash\Application\DTO\StatementImportSummary;
use App\Cash\Application\DTO\StatementLinkCandidate;
use App\Cash\Application\ImportBankStatementDayAction;
use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Exception\StatementImportOutsideTransactionException;
use App\Cash\Exception\StatementImportTargetNotFoundException;
use App\Cash\Infrastructure\Lock\CashImportAccountLock;
use App\Cash\Infrastructure\Lock\CashImportLogLease;
use App\Cash\Infrastructure\Query\CashDashboardQuery;
use App\Cash\Infrastructure\Query\CashReportQuery;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Accounts\MoneyFundMovementRepository;
use App\Cash\Repository\Import\ImportLogRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceProvider;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ramsey\Uuid\Uuid;

final readonly class CashFacade
{
    public function __construct(
        private AddCashTransactionAction $addCashTransaction,
        private CashTransactionRepository $cashTransactionRepository,
        private CashflowCategoryRepository $cashflowCategoryRepository,
        private MoneyAccountRepository $moneyAccountRepository,
        private CompanyFacade $companyFacade,
        private CreateCashTransferAction $createCashTransfer,
        private CashTransferLifecycleAction $cashTransferLifecycle,
        private CashDashboardQuery $dashboardQuery,
        private AccountBalanceProvider $accountBalanceProvider,
        private MoneyFundMovementRepository $moneyFundMovementRepository,
        private CashReportQuery $reportQuery,
        private DailyBalanceRecalculator $dailyBalanceRecalculator,
        private ImportBankStatementDayAction $importBankStatementDay,
        private CashImportAccountLock $importAccountLock,
        private CashImportLogLease $importLogLease,
        private ImportLogger $importLogger,
        private ImportLogRepository $importLogRepository,
    ) {
    }

    /**
     * Денежный счёт компании по id — цель импорта выписки по привязке счёта банка.
     * Чужой, несуществующий или неактивный — null: загрузка пропускает счёт.
     */
    public function getStatementImportAccount(string $companyId, string $moneyAccountId): ?StatementImportAccount
    {
        $account = $this->findMoneyAccount($companyId, $moneyAccountId);

        return null === $account || !$account->isActive()
            ? null
            : new StatementImportAccount((string) $account->getId(), $account->getCurrency());
    }

    /**
     * Активные денежные счета компании в валюте — кандидаты для привязки счёта банка.
     *
     * @return list<StatementLinkCandidate>
     */
    public function listStatementLinkCandidates(string $companyId, string $currency): array
    {
        $accounts = $this->moneyAccountRepository->findByFilters(
            $this->requireCompany($companyId),
            null,
            [$currency],
            true,
            null,
            ['name' => \SortDirection::Ascending],
        );

        return array_values(array_map(static function (MoneyAccount $account): StatementLinkCandidate {
            $digits = preg_replace('/\D+/', '', (string) $account->getAccountNumber());

            return new StatementLinkCandidate(
                (string) $account->getId(),
                $account->getName(),
                $account->getCurrency(),
                '' === $digits ? null : $digits,
            );
        }, $accounts));
    }

    /**
     * Отметить, что выписка в счёт приходит из банка по API. Только внутри транзакции
     * вызывающего: счёт перечитывается под `FOR UPDATE` (в памяти мог остаться прежний
     * признак), коммитит вызывающий (BankSync, в транзакции привязки).
     *
     * @throws StatementImportTargetNotFoundException счёт не принадлежит компании
     */
    public function markBankApiStatementSource(string $companyId, string $moneyAccountId, string $bankCode): void
    {
        $account = $this->lockMoneyAccount($companyId, $moneyAccountId)
            ?? throw StatementImportTargetNotFoundException::moneyAccount($moneyAccountId);

        $account->markBankApiSource($bankCode);
    }

    /**
     * Снять отметку «выписка из банка по API»; чужой или исчезнувший счёт — без изменений.
     * Как `markBankApiStatementSource()`: внутри транзакции, под `FOR UPDATE`, коммитит
     * вызывающий.
     */
    public function clearBankApiStatementSource(string $companyId, string $moneyAccountId): void
    {
        $this->lockMoneyAccount($companyId, $moneyAccountId)?->clearBankApiSource();
    }

    /**
     * Сессионная блокировка импорта в счёт — та же, что у файлового импорта и 1С. Держится
     * до `releaseAccountStatementImportLock()` или обрыва соединения.
     */
    public function tryLockAccountForStatementImport(string $companyId, string $moneyAccountId): bool
    {
        return $this->importAccountLock->tryAcquire($companyId, $moneyAccountId);
    }

    public function releaseAccountStatementImportLock(string $companyId, string $moneyAccountId): void
    {
        $this->importAccountLock->release($companyId, $moneyAccountId);
    }

    /**
     * Открывает журнал импорта и берёт его lease: пока загрузка идёт, ремонт и ночной гейт
     * не считают журнал брошенным. Коммитит вызывающий; lease снимает
     * `finishStatementImportLog()` или `abandonStatementImportLog()`.
     */
    public function startStatementImportLog(string $companyId, string $source): string
    {
        $importLog = $this->importLogger->start($this->requireCompany($companyId), $source, false, null, null);
        $this->importLogLease->acquire($importLog->getId());

        return $importLog->getId();
    }

    /**
     * Счётчики и итог в журнал, `finished_at`, снятие lease. Коммитит вызывающий.
     */
    public function finishStatementImportLog(string $companyId, string $importLogId, StatementImportSummary $summary): void
    {
        try {
            $importLog = $this->importLogRepository->findOneByIdAndCompanyId($importLogId, $companyId)
                ?? throw StatementImportTargetNotFoundException::importLog($importLogId);

            $importLog->setCreatedCount($summary->created);
            $importLog->setSkippedDuplicates($summary->skippedDuplicates);
            $importLog->setErrorsCount($summary->errors + (null !== $summary->failure ? 1 : 0));
            $importLog->setFileName(json_encode(array_filter([
                'accounts_found' => $summary->accountsFound,
                'accounts_matched' => $summary->accountsMatched,
                'transactions_created' => $summary->created,
                'transactions_skipped_duplicates' => $summary->skippedDuplicates,
                'error' => $summary->failure,
            ], static fn (mixed $value): bool => null !== $value), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
            $this->importLogger->finish($importLog);
        } finally {
            $this->importLogLease->release($importLogId);
        }
    }

    /**
     * Только снимает lease журнала, когда записать итог нельзя (EntityManager закрыт сбоем).
     */
    public function abandonStatementImportLog(string $importLogId): void
    {
        $this->importLogLease->release($importLogId);
    }

    /**
     * Строки выписки за день → операции ДДС, пересчёт остатков счёта. Только внутри
     * транзакции вызывающего (`wrapInTransaction()`): коммитит он, вместе со своим курсором.
     *
     * @throws StatementImportOutsideTransactionException транзакция не открыта
     * @throws StatementImportTargetNotFoundException счёт не принадлежит компании
     */
    public function importBankStatementDay(
        string $companyId,
        string $moneyAccountId,
        string $source,
        BankStatementDayInput $day,
    ): BankStatementDayResult {
        return ($this->importBankStatementDay)($companyId, $moneyAccountId, $source, $day);
    }

    public function createTransfer(CreateCashTransferCommand $command): CreateCashTransferResult
    {
        return ($this->createCashTransfer)($command);
    }

    public function deleteTransfer(
        string $companyId,
        string $transferId,
        ?string $actorUserId = null,
        ?string $reason = null,
    ): void {
        $this->cashTransferLifecycle->delete($companyId, $transferId, $actorUserId, $reason);
    }

    public function restoreTransfer(string $companyId, string $transferId, ?string $actorUserId = null): void
    {
        $this->cashTransferLifecycle->restore($companyId, $transferId, $actorUserId);
    }

    /**
     * Ключи — UUID счетов, PHP их в int не превращает; приведение нужно для типа контракта.
     *
     * @param array<int|string, string> $byAccountId
     *
     * @return array<string, string>
     */
    private function stringKeys(array $byAccountId): array
    {
        $result = [];
        foreach ($byAccountId as $accountId => $value) {
            $result[(string) $accountId] = $value;
        }

        return $result;
    }

    private function findMoneyAccount(string $companyId, string $moneyAccountId): ?MoneyAccount
    {
        if (!Uuid::isValid($companyId) || !Uuid::isValid($moneyAccountId)) {
            return null;
        }

        return $this->moneyAccountRepository->findOneByIdAndCompanyId($moneyAccountId, $companyId);
    }

    private function lockMoneyAccount(string $companyId, string $moneyAccountId): ?MoneyAccount
    {
        if (!Uuid::isValid($companyId) || !Uuid::isValid($moneyAccountId)) {
            return null;
        }

        return $this->moneyAccountRepository->lockOneByIdAndCompanyId($moneyAccountId, $companyId);
    }

    private function requireCompany(string $companyId): Company
    {
        $company = $this->companyFacade->findById($companyId);
        if (null === $company) {
            throw new \DomainException(sprintf('Компания %s не найдена.', $companyId));
        }

        return $company;
    }

    public function createTransaction(CreateCashTransactionCommand $command): CreateCashTransactionResult
    {
        $dto = new CashTransactionDTO();
        $dto->companyId = $command->companyId;
        $dto->moneyAccountId = $command->moneyAccountId;
        $dto->direction = $command->direction;
        $dto->amount = $command->amount;
        $dto->currency = $command->currency;
        $dto->occurredAt = $command->occurredAt;
        $dto->description = $command->description;
        $dto->counterpartyId = $command->counterpartyId;
        $dto->cashflowCategoryId = $command->cashflowCategoryId;
        $dto->projectDirectionId = $command->projectDirectionId;
        $dto->importSource = $command->importSource;
        $dto->externalId = $command->externalId;
        $dto->dedupeHash = $command->dedupeHash;
        $dto->rawData = $command->rawData;
        $dto->responsibilityCenterId = $command->responsibilityCenterId;

        $existing = $this->findExistingByImport($command);
        if (null !== $existing) {
            return new CreateCashTransactionResult((string) $existing->getId(), false, true);
        }

        try {
            $transaction = ($this->addCashTransaction)($dto);

            return new CreateCashTransactionResult((string) $transaction->getId(), true, false);
        } catch (UniqueConstraintViolationException $e) {
            if (null === $command->importSource || null === $command->externalId) {
                throw $e;
            }

            $existingId = $this->cashTransactionRepository->findAnyIdByCompanyImportSourceExternalIdDbal(
                $command->companyId,
                $command->importSource,
                $command->externalId,
            );

            if (null !== $existingId) {
                return new CreateCashTransactionResult($existingId, false, true);
            }

            throw $e;
        }
    }

    /**
     * Последняя активность ДДС компании: max(updatedAt транзакций, occurredAt движений фондов).
     */
    public function getLastCashActivityAt(string $companyId): ?\DateTimeImmutable
    {
        $company = $this->requireCompany($companyId);
        $timestamps = array_filter([
            $this->cashTransactionRepository->maxUpdatedAtForCompany($company),
            $this->moneyFundMovementRepository->maxUpdatedAtForCompany($company),
        ]);

        return [] === $timestamps ? null : max($timestamps);
    }

    /**
     * Id активных счетов компании в валюте, по названию счёта.
     *
     * @return list<string>
     */
    public function listActiveMoneyAccountIds(string $companyId, string $currency): array
    {
        $accounts = $this->moneyAccountRepository->findByFilters(
            $this->requireCompany($companyId),
            null,
            [$currency],
            true,
            null,
            ['name' => \SortDirection::Ascending],
        );

        return array_values(array_map(static fn (MoneyAccount $account): string => (string) $account->getId(), $accounts));
    }

    /**
     * Сумма остатков счетов на конец дня, decimal string с двумя знаками.
     *
     * @param list<string> $accountIds
     */
    public function sumClosingBalancesUpToDate(string $companyId, \DateTimeImmutable $date, array $accountIds): string
    {
        $balances = $this->accountBalanceProvider->getClosingBalancesUpToDate($this->requireCompany($companyId), $date, $accountIds);

        $sum = '0.00';
        foreach ($balances as $balance) {
            $sum = bcadd($sum, (string) $balance, 2);
        }

        return $sum;
    }

    /**
     * Остаток всех фондов компании в валюте на конец дня, в минорных единицах.
     */
    public function sumFundBalancesMinorUpToDate(string $companyId, \DateTimeImmutable $date, string $currency): int
    {
        $this->requireCompany($companyId);

        return $this->dashboardQuery->sumFundBalancesMinorUpToDate($companyId, $date, $currency);
    }

    public function sumInflowExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): string
    {
        $this->requireCompany($companyId);

        return $this->dashboardQuery->sumInflowExcludingTransfers($companyId, $from, $to, $currency);
    }

    /**
     * @return list<CashDailyAmount>
     */
    public function sumInflowByDayExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): array
    {
        $this->requireCompany($companyId);

        return $this->dashboardQuery->sumInflowByDayExcludingTransfers($companyId, $from, $to, $currency);
    }

    /**
     * Сумма расходов со знаком, как она хранится.
     */
    public function sumOutflowExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): string
    {
        $this->requireCompany($companyId);

        return $this->dashboardQuery->sumOutflowExcludingTransfers($companyId, $from, $to, $currency);
    }

    /**
     * @return list<CashDailyAmount> суммы со знаком, как они хранятся
     */
    public function sumOutflowByDayExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): array
    {
        $this->requireCompany($companyId);

        return $this->dashboardQuery->sumOutflowByDayExcludingTransfers($companyId, $from, $to, $currency);
    }

    public function sumCapexOutflowExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): string
    {
        $this->requireCompany($companyId);

        return $this->dashboardQuery->sumCapexOutflowExcludingTransfers($companyId, $from, $to, $currency);
    }

    /**
     * @return list<CashCategoryAmount>
     */
    public function sumOutflowByCategoryExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): array
    {
        $this->requireCompany($companyId);

        return $this->dashboardQuery->sumOutflowByCategoryExcludingTransfers($companyId, $from, $to, $currency);
    }

    /**
     * @return array{OPERATING: string, INVESTING: string, FINANCING: string}
     */
    public function sumNetByFlowKindExcludingTransfers(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency): array
    {
        $this->requireCompany($companyId);

        return $this->dashboardQuery->sumNetByFlowKindExcludingTransfers($companyId, $from, $to, $currency);
    }

    /**
     * Денежные счета компании для отчётов. `$typeFirst` — порядок «тип, название»,
     * иначе по названию.
     *
     * @return list<MoneyAccountSummary>
     */
    public function listMoneyAccounts(string $companyId, bool $activeOnly, ?string $currency = null, bool $typeFirst = false): array
    {
        $accounts = $this->moneyAccountRepository->findByFilters(
            $this->requireCompany($companyId),
            null,
            null === $currency ? null : [$currency],
            $activeOnly ? true : null,
            null,
            $typeFirst
                ? ['type' => \SortDirection::Ascending, 'name' => \SortDirection::Ascending]
                : ['name' => \SortDirection::Ascending],
        );

        return array_values(array_map(static fn (MoneyAccount $account): MoneyAccountSummary => new MoneyAccountSummary(
            (string) $account->getId(),
            $account->getName(),
            $account->getType(),
            $account->getCurrency(),
            $account->getOpeningBalance(),
            $account->getOpeningBalanceDate(),
            $account->getCurrentBalance(),
        ), $accounts));
    }

    /**
     * Остаток каждого счёта на начало дня: входящий остаток этого дня либо исходящий
     * последнего дня до него. Счета без строк регистра в ответ не попадают.
     *
     * @param list<string> $accountIds
     *
     * @return array<string, string> accountId => остаток
     */
    public function getOpeningBalancesUpToDate(string $companyId, \DateTimeImmutable $date, array $accountIds): array
    {
        return $this->stringKeys($this->accountBalanceProvider->getOpeningBalancesUpToDate($this->requireCompany($companyId), $date, $accountIds));
    }

    /**
     * Остаток каждого счёта на конец дня. Счета без строк регистра в ответ не попадают.
     *
     * @param list<string> $accountIds
     *
     * @return array<string, string> accountId => остаток
     */
    public function getClosingBalancesUpToDate(string $companyId, \DateTimeImmutable $date, array $accountIds): array
    {
        return $this->stringKeys($this->accountBalanceProvider->getClosingBalancesUpToDate($this->requireCompany($companyId), $date, $accountIds));
    }

    /**
     * Обороты счетов за период в валюте счёта, включая переводы.
     *
     * @param list<string> $accountIds
     *
     * @return array<string, array{inflow: string, outflow: string}>
     */
    public function sumTurnoversByAccount(string $companyId, array $accountIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->cashTransactionRepository->sumByAccountAndPeriod($this->requireCompany($companyId), $accountIds, $from, $to);
    }

    /**
     * Валовые обороты по строкам разбивки без переводов и технических статей; с
     * `$flowKind` — только этот вид потока без «нераспределённого».
     *
     * @return array{inflow: string, outflow: string}
     */
    public function sumGrossTurnoverExcludingTransfers(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $currency,
        ?CashflowFlowKind $flowKind,
    ): array {
        return $this->cashTransactionRepository->sumGrossTurnoverByPeriodExcludeTransfers(
            $this->requireCompany($companyId),
            $from,
            $to,
            $currency,
            $flowKind,
        );
    }

    /**
     * @param list<string> $accountIds пустой — все счета компании
     *
     * @return list<MoneyAccountDailyBalanceRow>
     */
    public function listDailyBalances(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds): array
    {
        $this->requireCompany($companyId);

        return $this->reportQuery->listDailyBalances($companyId, $from, $to, $accountIds);
    }

    /**
     * @return list<CashStatementLine>
     */
    public function listStatementLines(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $accountId,
        ?string $categoryId,
    ): array {
        $this->requireCompany($companyId);

        return $this->reportQuery->listStatementLines($companyId, $from, $to, $accountId, $categoryId);
    }

    /**
     * Пересчитывает регистр ежедневных остатков всех счетов компании за период.
     */
    public function recalculateDailyBalances(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $this->dailyBalanceRecalculator->recalcRange($this->requireCompany($companyId), $from, $to, null);
    }

    /**
     * Все статьи ДДС компании в порядке обхода дерева (корень, затем его потомки
     * по `sort`). Узел из `children` — тот же объект, что и в списке.
     *
     * @return list<CashflowCategoryNode>
     */
    public function getCashflowCategoryTree(string $companyId): array
    {
        $categories = $this->cashflowCategoryRepository->findTreeByCompany($this->requireCompany($companyId));

        /** @var array<string, CashflowCategoryNode> $nodes */
        $nodes = [];
        $toNode = static function (CashflowCategory $category) use (&$toNode, &$nodes): CashflowCategoryNode {
            $id = (string) $category->getId();
            if (isset($nodes[$id])) {
                return $nodes[$id];
            }

            $children = [];
            foreach ($category->getChildren() as $child) {
                $children[] = $toNode($child);
            }

            return $nodes[$id] = new CashflowCategoryNode(
                $id,
                $category->getName(),
                $category->getLevel(),
                null === $category->getParent() ? null : (string) $category->getParent()->getId(),
                $category->getFlowKind(),
                $category->getSystemCode(),
                $children,
            );
        };

        return array_values(array_map($toNode, $categories));
    }

    /**
     * Строки отчёта ДДС (строки разбивки) за период с фильтрами.
     *
     * @return list<CashflowReportRow>
     */
    public function listCashflowReportRows(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, CashflowReportRowFilter $filter): array
    {
        $this->requireCompany($companyId);

        return $this->reportQuery->listCashflowReportRows($companyId, $from, $to, $filter);
    }

    /**
     * Входящие остатки счетов на начало дня для отчёта ДДС: все счета компании
     * либо, с `$currency`, счета этой валюты по названию.
     *
     * @return list<MoneyAccountOpeningBalance>
     */
    public function getReportOpeningBalances(string $companyId, \DateTimeImmutable $date, ?string $currency = null): array
    {
        $company = $this->requireCompany($companyId);
        $accounts = null !== $currency
            ? $this->moneyAccountRepository->findByFilters($company, null, [$currency], null, null, ['name' => \SortDirection::Ascending])
            : $this->moneyAccountRepository->findBy(['company' => $company]);

        return $this->reportQuery->listOpeningBalancesAtDate($companyId, $date, array_values($accounts));
    }

    private function findExistingByImport(CreateCashTransactionCommand $command): ?CashTransaction
    {
        if (null === $command->importSource || null === $command->externalId) {
            return null;
        }

        return $this->cashTransactionRepository->findAnyByCompanyImportSourceExternalId(
            $command->companyId,
            $command->importSource,
            $command->externalId,
        );
    }
}

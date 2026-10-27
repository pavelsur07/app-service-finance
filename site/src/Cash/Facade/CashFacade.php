<?php

declare(strict_types=1);

namespace App\Cash\Facade;

use App\Cash\Application\AddCashTransactionAction;
use App\Cash\Application\CashTransferLifecycleAction;
use App\Cash\Application\CreateCashTransferAction;
use App\Cash\Application\DTO\AutoRuleConditionInput;
use App\Cash\Application\DTO\AutoRuleInput;
use App\Cash\Application\DTO\BankStatementDayInput;
use App\Cash\Application\DTO\BankStatementDayResult;
use App\Cash\Application\DTO\CashCategoryAmount;
use App\Cash\Application\DTO\CashCategoryTurnover;
use App\Cash\Application\DTO\CashDailyAmount;
use App\Cash\Application\DTO\CashflowCategoryInput;
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
use App\Cash\Application\DTO\PlannedPaymentSummary;
use App\Cash\Application\DTO\StatementImportAccount;
use App\Cash\Application\DTO\StatementImportSummary;
use App\Cash\Application\DTO\StatementLinkCandidate;
use App\Cash\Application\ImportBankStatementDayAction;
use App\Cash\Application\SaveCashflowCategoryAction;
use App\Cash\Application\SaveCashTransactionAutoRuleAction;
use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Exception\StatementImportOutsideTransactionException;
use App\Cash\Exception\StatementImportTargetNotFoundException;
use App\Cash\Infrastructure\Lock\CashImportAccountLock;
use App\Cash\Infrastructure\Lock\CashImportLogLease;
use App\Cash\Infrastructure\Query\CashDashboardQuery;
use App\Cash\Infrastructure\Query\CashReportQuery;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Accounts\MoneyFundMovementRepository;
use App\Cash\Repository\Import\ImportLogRepository;
use App\Cash\Repository\PaymentPlan\PaymentPlanRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceProvider;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Facade\CompanyFacade;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final readonly class CashFacade
{
    public const MAX_PER_PAGE = 200;

    public function __construct(
        private AddCashTransactionAction $addCashTransaction,
        private CashTransactionRepository $cashTransactionRepository,
        private CashflowCategoryRepository $cashflowCategoryRepository,
        private CashTransactionAutoRuleRepository $autoRuleRepository,
        private MoneyAccountRepository $moneyAccountRepository,
        private CompanyFacade $companyFacade,
        private SaveCashflowCategoryAction $saveCategory,
        private SaveCashTransactionAutoRuleAction $saveAutoRule,
        private CreateCashTransferAction $createCashTransfer,
        private CashTransferLifecycleAction $cashTransferLifecycle,
        private CashDashboardQuery $dashboardQuery,
        private AccountBalanceProvider $accountBalanceProvider,
        private MoneyFundMovementRepository $moneyFundMovementRepository,
        private CashReportQuery $reportQuery,
        private DailyBalanceRecalculator $dailyBalanceRecalculator,
        private PaymentPlanRepository $paymentPlanRepository,
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
     * Отметить, что выписка в счёт приходит из банка по API. Только `persist`-изменение:
     * коммитит вызывающий (BankSync, в транзакции привязки).
     *
     * @throws StatementImportTargetNotFoundException счёт не принадлежит компании
     */
    public function markBankApiStatementSource(string $companyId, string $moneyAccountId, string $bankCode): void
    {
        $account = $this->findMoneyAccount($companyId, $moneyAccountId)
            ?? throw StatementImportTargetNotFoundException::moneyAccount($moneyAccountId);

        $account->markBankApiSource($bankCode);
    }

    /**
     * Снять отметку «выписка из банка по API»; чужой или исчезнувший счёт — без изменений.
     * Коммитит вызывающий.
     */
    public function clearBankApiStatementSource(string $companyId, string $moneyAccountId): void
    {
        $this->findMoneyAccount($companyId, $moneyAccountId)?->clearBankApiSource();
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
     * @param array{
     *     dateFrom?: ?string, dateTo?: ?string, accountId?: ?string, categoryId?: ?string,
     *     counterpartyId?: ?string, direction?: ?string, amountMin?: ?string, amountMax?: ?string, q?: ?string
     * } $filters
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function listTransactions(string $companyId, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $company = $this->requireCompany($companyId);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $pager = $this->cashTransactionRepository->paginateByCompanyWithFilters(
            $company,
            $filters + [
                'dateFrom' => null, 'dateTo' => null, 'accountId' => null, 'categoryId' => null,
                'counterpartyId' => null, 'direction' => null, 'amountMin' => null, 'amountMax' => null, 'q' => null,
            ],
            max(1, $page),
            $perPage,
        );

        $items = [];
        foreach ($pager->getCurrentPageResults() as $transaction) {
            $items[] = $this->serializeTransaction($transaction);
        }

        return [
            'items' => $items,
            'total' => $pager->getNbResults(),
            'page' => $pager->getCurrentPage(),
            'pages' => $pager->getNbPages(),
            'per_page' => $pager->getMaxPerPage(),
        ];
    }

    /**
     * Плоское дерево статей ДДС компании, отсортированное как в интерфейсе.
     *
     * @return list<array<string, mixed>>
     */
    public function listCashflowCategories(string $companyId): array
    {
        $company = $this->requireCompany($companyId);

        return array_map(
            static fn (CashflowCategory $category): array => [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'level' => $category->getLevel(),
                'parentId' => $category->getParent()?->getId(),
                'status' => $category->getStatus()->value,
                'flowKind' => $category->getFlowKind()->value,
                'sort' => $category->getSort(),
                'isSystem' => $category->isSystem(),
            ],
            $this->cashflowCategoryRepository->findTreeByCompany($company),
        );
    }

    /**
     * @return string id созданной или изменённой статьи
     */
    public function upsertCashflowCategory(string $companyId, CashflowCategoryInput $input): string
    {
        $company = $this->requireCompany($companyId);

        if (null === $input->id) {
            if (null === $input->name || '' === trim($input->name)) {
                throw new \DomainException('Для новой статьи укажите name.');
            }

            $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        } else {
            $category = $this->requireCategory($input->id, $company);
        }

        if (null !== $input->name) {
            $category->setName($input->name);
        }
        if ($input->parentIdProvided || null !== $input->parentId) {
            $category->setParent(null === $input->parentId ? null : $this->requireCategory($input->parentId, $company));
        }
        if (null !== $input->description) {
            $category->setDescription($input->description);
        }
        if (null !== $input->status) {
            $category->setStatus($input->status);
        }
        if (null !== $input->sort) {
            $category->setSort($input->sort);
        }
        if (null !== $input->flowKind) {
            $category->setFlowKind($input->flowKind);
        }

        ($this->saveCategory)($category);

        return (string) $category->getId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAutoRules(string $companyId): array
    {
        $company = $this->requireCompany($companyId);

        return array_map(
            static fn (CashTransactionAutoRule $rule): array => [
                'id' => $rule->getId(),
                'name' => $rule->getName(),
                'action' => $rule->getAction()->value,
                'operationType' => $rule->getOperationType()->value,
                'priority' => $rule->getPriority(),
                'isActive' => $rule->isActive(),
                'category' => null === $rule->getCashflowCategory() ? null : [
                    'id' => $rule->getCashflowCategory()->getId(),
                    'name' => $rule->getCashflowCategory()->getName(),
                ],
                'counterpartyId' => $rule->getCounterparty()?->getId(),
                'conditions' => array_map(
                    static fn (CashTransactionAutoRuleCondition $condition): array => [
                        'field' => $condition->getField()->value,
                        'operator' => $condition->getOperator()->value,
                        'value' => $condition->getValue(),
                        'valueTo' => $condition->getValueTo(),
                        'counterpartyId' => $condition->getCounterparty()?->getId(),
                        'moneyAccountId' => $condition->getMoneyAccount()?->getId(),
                    ],
                    $rule->getConditions()->toArray(),
                ),
            ],
            $this->autoRuleRepository->findByCompany($company, null, null, null),
        );
    }

    /**
     * @return string id созданного или изменённого автоправила
     */
    public function upsertAutoRule(string $companyId, AutoRuleInput $input, ?string $actorUserId = null): string
    {
        $company = $this->requireCompany($companyId);

        if (null === $input->id) {
            if (null === $input->name || '' === trim($input->name)) {
                throw new \DomainException('Для нового автоправила укажите name.');
            }
            if (null === $input->cashflowCategoryId) {
                throw new \DomainException('Для нового автоправила укажите cashflowCategoryId.');
            }
            if (null === $input->conditions || [] === $input->conditions) {
                throw new \DomainException('Для нового автоправила укажите хотя бы одно условие.');
            }

            $rule = new CashTransactionAutoRule(
                Uuid::uuid4()->toString(),
                $company,
                $input->name,
                $input->action ?? CashTransactionAutoRuleAction::FILL,
                $input->operationType ?? CashTransactionAutoRuleOperationType::ANY,
                createdByUserId: $actorUserId,
            );
        } else {
            $rule = $this->autoRuleRepository->findOneByIdAndCompanyId($input->id, (string) $company->getId());
            if (null === $rule) {
                throw new \DomainException(sprintf('Автоправило %s не найдено.', $input->id));
            }

            if (null !== $input->name) {
                $rule->setName($input->name);
            }
            if (null !== $input->action) {
                $rule->setAction($input->action);
            }
            if (null !== $input->operationType) {
                $rule->setOperationType($input->operationType);
            }
        }

        if (null !== $input->cashflowCategoryId) {
            $rule->setCashflowCategory($this->requireCategory($input->cashflowCategoryId, $company));
        }
        if (null !== $input->counterpartyId) {
            $rule->setCounterparty($this->requireCounterparty($input->counterpartyId, $company));
        }
        if (null !== $input->priority) {
            $rule->setPriority($input->priority);
        }
        if (null !== $input->isActive) {
            $rule->setIsActive($input->isActive);
        }
        if (null !== $input->conditions) {
            $this->replaceConditions($rule, $input->conditions, $company);
        }

        ($this->saveAutoRule)(
            $rule,
            $actorUserId,
            $rule->getProjectDirection()?->getId(),
            $rule->getResponsibilityCenterId(),
        );

        return (string) $rule->getId();
    }

    /**
     * @param list<AutoRuleConditionInput> $conditions
     */
    private function replaceConditions(CashTransactionAutoRule $rule, array $conditions, Company $company): void
    {
        foreach ($rule->getConditions()->toArray() as $existing) {
            $rule->removeCondition($existing);
        }

        foreach ($conditions as $input) {
            $counterparty = null;
            if (CashTransactionAutoRuleConditionField::COUNTERPARTY === $input->field) {
                if (null === $input->counterpartyId) {
                    throw new \DomainException('Для условия по контрагенту укажите counterpartyId.');
                }
                $counterparty = $this->requireCounterparty($input->counterpartyId, $company);
            }

            $moneyAccount = null;
            if (CashTransactionAutoRuleConditionField::MONEY_ACCOUNT === $input->field) {
                if (null === $input->moneyAccountId) {
                    throw new \DomainException('Для условия по счёту укажите moneyAccountId.');
                }
                $moneyAccount = $this->moneyAccountRepository->findOneBy([
                    'id' => $input->moneyAccountId,
                    'company' => $company,
                ]);
                if (null === $moneyAccount) {
                    throw new \DomainException(sprintf('Денежный счёт %s не найден.', $input->moneyAccountId));
                }
            }

            $rule->addCondition(new CashTransactionAutoRuleCondition(
                Uuid::uuid4()->toString(),
                $rule,
                $input->field,
                $input->operator,
                $input->value,
                $input->valueTo,
                $counterparty,
                $moneyAccount,
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransaction(CashTransaction $transaction): array
    {
        // getSplits() отдаёт снимок коллекции, поэтому берём его один раз.
        $splits = $transaction->getSplits()->toArray();
        $singleCategory = $transaction->getSingleSplitCategory();

        return [
            'id' => $transaction->getId(),
            'occurredAt' => $transaction->getOccurredAt()->format('Y-m-d'),
            'direction' => $transaction->getDirection()->value,
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrency(),
            'description' => $transaction->getDescription(),
            'isTransfer' => $transaction->isTransfer(),
            'account' => [
                'id' => $transaction->getMoneyAccount()->getId(),
                'name' => $transaction->getMoneyAccount()->getName(),
            ],
            'counterparty' => null === $transaction->getCounterparty() ? null : [
                'id' => $transaction->getCounterparty()->getId(),
                'name' => $transaction->getCounterparty()->getName(),
            ],
            // Контракт расширен: транзакция может относиться к нескольким категориям,
            // поэтому появился массив splits с суммой каждой строки. Поле category
            // сохранено для обратной совместимости и заполняется, только когда строка
            // одна — иначе оно молча врало бы одной категорией из нескольких.
            'category' => null === $singleCategory ? null : [
                'id' => $singleCategory->getId(),
                'name' => $singleCategory->getName(),
            ],
            'splits' => array_map(
                static fn (CashTransactionSplit $split): array => [
                    'categoryId' => $split->getCashflowCategory()->getId(),
                    'categoryName' => $split->getCashflowCategory()->getName(),
                    'amount' => $split->getAmount(),
                ],
                $splits,
            ),
        ];
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

    private function requireCompany(string $companyId): Company
    {
        $company = $this->companyFacade->findById($companyId);
        if (null === $company) {
            throw new \DomainException(sprintf('Компания %s не найдена.', $companyId));
        }

        return $company;
    }

    private function requireCategory(string $categoryId, Company $company): CashflowCategory
    {
        $category = $this->cashflowCategoryRepository->findOneBy(['id' => $categoryId, 'company' => $company]);
        if (null === $category) {
            throw new \DomainException(sprintf('Статья ДДС %s не найдена.', $categoryId));
        }

        return $category;
    }

    private function requireCounterparty(string $counterpartyId, Company $company): Counterparty
    {
        $counterparty = $this->companyFacade->findCounterpartyByIdAndCompany(
            $counterpartyId,
            (string) $company->getId(),
        );
        if (null === $counterparty) {
            throw new \DomainException(sprintf('Контрагент %s не найден.', $counterpartyId));
        }

        return $counterparty;
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

    /**
     * Поступления и выбытия по названию статьи. Удалённые операции и переводы входят
     * (так считает AI-агент ДДС, см. «Находки» плана Cash).
     *
     * @return list<CashCategoryTurnover>
     */
    public function sumTurnoverByCategoryNameIncludingDeleted(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $this->requireCompany($companyId);

        return $this->reportQuery->sumTurnoverByCategoryNameIncludingDeleted($companyId, $from, $to);
    }

    /**
     * Чистый поток по дням. Удалённые операции и переводы входят.
     *
     * @return list<CashDailyAmount>
     */
    public function sumNetByDayIncludingDeleted(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $this->requireCompany($companyId);

        return $this->reportQuery->sumNetByDayIncludingDeleted($companyId, $from, $to);
    }

    /**
     * Поступления и выбытия за период. Удалённые операции и переводы входят.
     *
     * @return array{inflow: string, outflow: string}
     */
    public function sumTurnoverIncludingDeleted(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $this->requireCompany($companyId);

        return $this->reportQuery->sumTurnoverIncludingDeleted($companyId, $from, $to);
    }

    /**
     * Плановые платежи (PLANNED, APPROVED) с датой в периоде, по дате.
     *
     * @return list<PlannedPaymentSummary>
     */
    public function listPlannedPayments(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $plans = $this->paymentPlanRepository->findPlannedByCompanyAndPeriod($this->requireCompany($companyId), $from, $to);

        return array_map(static function (PaymentPlan $plan): PlannedPaymentSummary {
            $plannedAt = $plan->getPlannedAt();
            Assert::notNull($plannedAt);

            return new PlannedPaymentSummary(
                $plannedAt,
                $plan->getAmount(),
                $plan->getCashflowCategory()->getName(),
                $plan->getCounterparty()?->getName(),
            );
        }, $plans);
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

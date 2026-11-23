<?php

declare(strict_types=1);

namespace App\Cash\Application\PaymentIntake;

use App\Cash\Application\AddCashTransactionAction;
use App\Cash\Application\DTO\CashTransactionDTO;
use App\Cash\Application\DTO\PaymentIntake\CreateMcpCashDraft;
use App\Cash\Application\DTO\PaymentIntake\McpCashDraftResult;
use App\Cash\Application\DTO\PaymentIntake\PaymentIntakeConfirmation;
use App\Cash\Application\DTO\PaymentIntake\PaymentIntakeDraftView;
use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Application\Service\Category\CashflowCategoryTreeBuilder;
use App\Cash\Application\Service\Transaction\CashTransactionService;
use App\Cash\Domain\Event\CashTransactionsChanged;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\PaymentIntake\PaymentIntakeDraft;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Exception\McpCashDraftException;
use App\Cash\Exception\PaymentIntakeAccessDeniedException;
use App\Cash\Infrastructure\Query\PaymentIntakeDuplicateQuery;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\PaymentIntake\PaymentIntakeDraftRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Facade\CompanyFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Черновики платежей MCP: создание, подтверждение в ДДС, отмена и повторная отправка события после commit. */
final readonly class PaymentIntakeAction
{
    public function __construct(
        private EntityManagerInterface $em,
        private PaymentIntakeDraftRepository $drafts,
        private CompanyFacade $companies,
        private CashTransactionService $transactions,
        private CashTransactionResponsibilityCenterResolver $responsibilityResolver,
        private AddCashTransactionAction $addTransaction,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
        private PaymentIntakeDuplicateQuery $duplicates,
        private ClockInterface $clock,
        private MoneyAccountDailyBalanceRepository $balances,
        private CashflowCategoryRepository $categoryRepository,
    ) {
    }

    public function createMcpDraft(CreateMcpCashDraft $command): McpCashDraftResult
    {
        if (!Uuid::isValid($command->connectionId) || !Uuid::isValid($command->moneyAccountId)
            || (null !== $command->categoryId && !Uuid::isValid($command->categoryId))
            || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\\/-]{0,127}$/D', $command->externalId)
            || 1 !== preg_match('/^(?:0|[1-9][0-9]{0,15})\.[0-9]{2}$/D', $command->amount)
            || '0.00' === $command->amount || mb_strlen($command->description) > CashTransaction::DESCRIPTION_MAX_LENGTH
            || !mb_check_encoding($command->description, 'UTF-8') || str_contains($command->description, "\0")
            || (null !== $command->currency && null === FiatCurrency::tryFrom($command->currency))) {
            throw new McpCashDraftException('invalid_input', 'Проверьте параметры платежа.');
        }

        $draft = $this->em->wrapInTransaction(function () use ($command): PaymentIntakeDraft {
            $this->authorize($command->companyId, $command->actorUserId, true);
            $connectionId = strtolower($command->connectionId);
            $source = 'mcp:'.strtolower($command->companyId).':'.$connectionId.':'.$command->externalId;
            $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:source, 0))', ['source' => $source]);
            $existing = $this->drafts->getMcpByExternalId($command->companyId, $connectionId, $command->externalId);
            if (null !== $existing) {
                if ($existing->getActorUserId() !== $command->actorUserId) {
                    throw new McpCashDraftException('reference_unavailable', 'Черновик недоступен.');
                }
                if ($existing->getSourceRevision() !== self::mcpFingerprint($command, $command->currency ?? $existing->getCurrency())) {
                    throw new McpCashDraftException('idempotency_conflict', 'Внешний ID уже связан с другим платежом.');
                }

                return $existing;
            }

            if (null !== $command->categoryId
                && !$this->mcpCategoryMatches($command->companyId, strtolower($command->categoryId), $command->direction)) {
                throw new McpCashDraftException('reference_unavailable', 'Счёт или статья ДДС недоступны.');
            }
            try {
                $account = $this->transactions->resolveAccount($command->moneyAccountId, $command->companyId);
            } catch (\DomainException) {
                throw new McpCashDraftException('reference_unavailable', 'Счёт или статья ДДС недоступны.');
            }
            $this->balances->acquireRecalculationLock($account);
            $this->em->refresh($account);
            if (!$account->isActive()) {
                throw new McpCashDraftException('reference_unavailable', 'Счёт или статья ДДС недоступны.');
            }
            $currency = $command->currency ?? $account->getCurrency();
            if (null === FiatCurrency::tryFrom($currency) || $currency !== $account->getCurrency()) {
                throw new McpCashDraftException('invalid_input', 'Укажите поддерживаемую валюту счёта.');
            }
            $periodLock = $this->companies->getFinanceLockBefore($command->companyId);
            if (null !== $periodLock && $command->date->format('Y-m-d') < $periodLock->format('Y-m-d')) {
                throw new McpCashDraftException('period_closed', 'Период платежа закрыт.');
            }
            $draft = PaymentIntakeDraft::fromMcp($command, $currency, self::mcpFingerprint($command, $currency), $this->clock->now());
            $this->em->persist($draft);

            return $draft;
        });

        return new McpCashDraftResult($draft->getId(), match ($draft->getStatus()) {
            'confirmed', 'cancelled' => $draft->getStatus(),
            default => 'awaiting_confirmation',
        });
    }

    private static function mcpFingerprint(CreateMcpCashDraft $command, string $currency): string
    {
        return hash('sha256', json_encode([
            strtolower($command->moneyAccountId),
            $command->date->format('Y-m-d'),
            $command->direction->value,
            $command->amount,
            $currency,
            null === $command->categoryId ? null : strtolower($command->categoryId),
            $command->description,
        ], \JSON_THROW_ON_ERROR));
    }

    private function mcpCategoryMatches(string $companyId, string $categoryId, CashDirection $direction): bool
    {
        $this->categoryRepository->lockTree($companyId);
        $ancestry = $this->categoryRepository->findActiveLeafAncestry($companyId, $categoryId);
        if ([] === $ancestry) {
            return false;
        }
        foreach ($ancestry as $category) {
            $flow = CashflowCategoryTreeBuilder::declaredFlow($category['systemCode'], $category['operationType']);
            if (null !== $flow) {
                return 'both' === $flow || (CashDirection::INFLOW === $direction ? 'in' : 'out') === $flow;
            }
        }

        return true;
    }

    /** @return list<array{id: string, externalId: string, receivedAt: string}> */
    public function listMcpDrafts(string $companyId, string $actorUserId, ?string $beforeId, bool $closed): array
    {
        $this->authorize($companyId, $actorUserId);

        return $this->drafts->listMcpDrafts($companyId, $actorUserId, $beforeId, $closed);
    }

    public function getMcpDraft(string $companyId, string $actorUserId, string $draftId): PaymentIntakeDraftView
    {
        $this->authorize($companyId, $actorUserId);

        return $this->view($this->owned($companyId, $actorUserId, $draftId));
    }

    public function confirmMcp(string $companyId, string $actorUserId, string $draftId, int $expectedRevision, bool $paidConfirmed): PaymentIntakeConfirmation
    {
        if (!$paidConfirmed) {
            throw new \DomainException('Подтвердите факт оплаты.');
        }
        $draft = $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $draftId, $expectedRevision): PaymentIntakeDraft {
            $this->authorize($companyId, $actorUserId, true);
            $draft = $this->owned($companyId, $actorUserId, $draftId, lock: true);
            $itemIds = [(string) $draft->getItems()[0]['id']];
            if ($draft->confirmationMatches($expectedRevision, $itemIds)) {
                return $draft;
            }
            $draft->confirmMcpPayment($expectedRevision, $this->clock->now());
            $items = $draft->selectedForConfirmation($expectedRevision, $itemIds);
            if (null !== $items[0]['category_id']
                && !$this->mcpCategoryMatches($companyId, $items[0]['category_id'], CashDirection::from(strtoupper($items[0]['direction'])))) {
                throw new \DomainException('Статья недоступна или не подходит направлению платежа.');
            }
            $ids = [];
            $account = $this->lockedAccount($draft);
            $periodLock = $this->companies->getFinanceLockBefore($draft->getCompanyId());
            foreach ($items as $item) {
                $this->validateReferences($draft, $item, $account);
                if (!\is_string($item['amount']) || 1 !== preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,2})?$/D', $item['amount']) || bccomp($item['amount'], '0', 2) <= 0
                    || !\in_array($item['direction'], ['inflow', 'outflow'], true) || !\is_string($item['date']) || !\is_string($item['currency'])) {
                    throw new \DomainException('Укажите положительную сумму, направление, дату и валюту.');
                }
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $item['date'], new \DateTimeZone($draft->getTimezone()));
                if (false === $date || $date->format('Y-m-d') !== $item['date']) {
                    throw new \DomainException('Укажите корректную дату платежа.');
                }
                if (null !== $periodLock && $date->format('Y-m-d') < $periodLock->format('Y-m-d')) {
                    throw new \DomainException('Период платежа закрыт.');
                }
                $dto = new CashTransactionDTO();
                $dto->companyId = $draft->getCompanyId();
                $dto->moneyAccountId = $draft->getMoneyAccountId();
                $dto->direction = CashDirection::from(strtoupper($item['direction']));
                $dto->amount = $item['amount'];
                $dto->currency = $item['currency'];
                $dto->occurredAt = $date;
                $dto->description = $item['description'];
                $dto->cashflowCategoryId = $item['category_id'];
                $dto->projectDirectionId = $item['project_id'];
                $dto->responsibilityCenterId = $item['cfo_id'];
                $dto->idempotencyKey = $item['transaction_id'];
                $dto->importSource = 'mcp';
                $dto->externalId = $draft->getId().'/'.$item['id'];
                $dto->rawData = ['draft_id' => $draft->getId(), 'item_id' => $item['id'], 'revision' => $draft->getRevision(), 'provenance' => $item['provenance']];
                $tx = $this->addTransaction->createInCurrentTransaction($dto);
                $ids[] = (string) $tx->getId();
            }
            $draft->confirm($expectedRevision, $itemIds, $ids);

            return $draft;
        });
        $this->deliverCashEvent($draft);

        return new PaymentIntakeConfirmation($draft->getId(), $draft->getTransactionIds());
    }

    public function cancelMcp(string $companyId, string $actorUserId, string $draftId, int $expectedRevision): void
    {
        $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $draftId, $expectedRevision): void {
            $this->authorize($companyId, $actorUserId, true);
            $this->owned($companyId, $actorUserId, $draftId, lock: true)->cancel($expectedRevision);
        });
    }

    /** @return list<string> */
    public function pendingEventCompanyIds(): array
    {
        return $this->drafts->getPendingCashEventCompanyIds();
    }

    /** Повторно отправляет CashTransactionsChanged по уже закоммиченным подтверждениям; платежи не меняет. */
    public function recoverEvents(string $companyId, bool $failOnError = false): int
    {
        $count = 0;
        $afterId = null;
        do {
            $batch = $this->drafts->getPendingCashEvents($companyId, $afterId);
            foreach ($batch as $draft) {
                $afterId = $draft->getId();
                $this->deliverCashEvent($draft, $failOnError);
                if (!$draft->isCashEventPending()) {
                    ++$count;
                }
            }
        } while (100 === \count($batch));

        return $count;
    }

    private function deliverCashEvent(PaymentIntakeDraft $draft, bool $failOnError = false): void
    {
        if (!$draft->isCashEventPending()) {
            return;
        }
        try {
            $this->events->dispatch(new CashTransactionsChanged($draft->getCompanyId()));
        } catch (\Throwable $exception) {
            if ($failOnError) {
                throw $exception;
            }
            $this->logger->warning('Payment intake cash event remains pending after confirmation.', ['companyId' => $draft->getCompanyId(), 'draftId' => $draft->getId(), 'exceptionClass' => $exception::class]);

            return;
        }
        $this->em->wrapInTransaction(function () use ($draft): void {
            $this->drafts->getForCompany($draft->getCompanyId(), $draft->getId(), true)?->acknowledgeCashEvent();
        });
    }

    private function lockedAccount(PaymentIntakeDraft $draft): MoneyAccount
    {
        $account = $this->transactions->resolveAccount($draft->getMoneyAccountId(), $draft->getCompanyId());
        $this->balances->acquireRecalculationLock($account);
        $this->em->refresh($account);
        if (!$account->isActive()) {
            throw new \DomainException('Выбранный счёт недоступен.');
        }

        return $account;
    }

    /** @param array<string, mixed> $item */
    private function validateReferences(PaymentIntakeDraft $draft, array $item, MoneyAccount $account): void
    {
        $companyId = $draft->getCompanyId();
        if (null !== $item['currency']) {
            $this->transactions->resolveCurrencyForAccount($item['currency'], $account);
        }
        if (null !== $item['project_id'] || null !== $item['cfo_id']) {
            $this->responsibilityResolver->resolveForCreate($companyId, $item['project_id'], $item['cfo_id']);
        }
    }

    private function authorize(string $companyId, string $actorUserId, bool $lock = false): void
    {
        if (!$this->companies->financialAccess($companyId, $actorUserId, $lock)['write']) {
            throw new PaymentIntakeAccessDeniedException();
        }
    }

    /** Только MCP-черновики: прежние черновики ИИ-разбора недоступны до удаления миграцией. */
    private function owned(string $companyId, string $actorUserId, string $draftId, bool $lock = false): PaymentIntakeDraft
    {
        if (!Uuid::isValid($draftId)) {
            throw new \DomainException('Черновик не найден.');
        }
        $draft = $this->drafts->getForCompany($companyId, $draftId, $lock);
        if (null === $draft || $draft->getActorUserId() !== $actorUserId || !$draft->isMcp()) {
            throw new \DomainException('Черновик не найден.');
        }

        return $draft;
    }

    private function view(PaymentIntakeDraft $draft): PaymentIntakeDraftView
    {
        $items = $draft->getItems();
        $matches = $this->duplicates->findMatches($draft->getCompanyId(), $draft->getMoneyAccountId(), $items);
        $groups = [];
        foreach ($items as $item) {
            if (null !== $item['amount'] && null !== $item['date'] && null !== $item['direction']) {
                $key = json_encode([$item['date'], $item['amount'], $item['currency'], $item['direction']], \JSON_THROW_ON_ERROR);
                $groups[$key] = ($groups[$key] ?? 0) + 1;
            }
        }
        foreach ($items as &$item) {
            $item['possible_duplicate_ids'] = $matches[$item['id']] ?? [];
            $item['warnings'] = [];
            if ([] !== $item['possible_duplicate_ids']) {
                $item['warnings'][] = 'Возможный повтор: в этой кассе уже есть платежи с той же датой, суммой, валютой и направлением. Проверьте перед подтверждением.';
            }
            $key = json_encode([$item['date'], $item['amount'], $item['currency'], $item['direction']], \JSON_THROW_ON_ERROR);
            if (($groups[$key] ?? 0) > 1) {
                $item['warnings'][] = 'В черновике есть похожая строка с той же датой, суммой, валютой и направлением. Каждая выбранная строка будет сохранена отдельно.';
            }
        }
        unset($item);

        return new PaymentIntakeDraftView(
            $draft->getId(), $draft->getCompanyId(), $draft->getActorUserId(), $draft->getMoneyAccountId(),
            $draft->getRevision(), $draft->getStatus(), $items,
            mcpExternalId: $draft->getMcpExternalId(),
            confirmedAt: $draft->getConfirmedAt(),
            confirmedTransactionId: $draft->getTransactionIds()[0] ?? null,
            mcpConnectionId: $draft->getMcpConnectionId(),
            receivedAt: $draft->getReceivedAt(),
        );
    }
}

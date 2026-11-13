<?php

declare(strict_types=1);

namespace App\Cash\Application\PaymentIntake;

use App\Cash\Application\AddCashTransactionAction;
use App\Cash\Application\DTO\CashTransactionDTO;
use App\Cash\Application\DTO\PaymentIntake\AmendPaymentIntakeDraftCommand;
use App\Cash\Application\DTO\PaymentIntake\ConfirmPaymentIntakeDraftCommand;
use App\Cash\Application\DTO\PaymentIntake\PaymentIntakeConfirmation;
use App\Cash\Application\DTO\PaymentIntake\PaymentIntakeDraftView;
use App\Cash\Application\DTO\PaymentIntake\RequestPaymentIntakeCommand;
use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Application\Service\Transaction\CashTransactionService;
use App\Cash\Domain\Event\CashTransactionsChanged;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\PaymentIntake\PaymentIntakeDraft;
use App\Cash\Entity\PaymentIntake\PaymentIntakeReplyReceipt;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use App\Cash\Infrastructure\Query\PaymentIntakeDuplicateQuery;
use App\Cash\Repository\PaymentIntake\PaymentIntakeDraftRepository;
use App\Cash\Repository\PaymentIntake\PaymentIntakeReplyReceiptRepository;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** All writes own the transaction; neither the model nor Telegram owns ledger semantics. */
final readonly class PaymentIntakeAction
{
    public const TASK_TYPE = 'parse_payment_message';

    public function __construct(
        private EntityManagerInterface $em,
        private PaymentIntakeDraftRepository $drafts,
        private CompanyFacade $companies,
        private FinancialResponsibilityCenterFacade $centers,
        private CashTransactionService $transactions,
        private CashTransactionResponsibilityCenterResolver $responsibilityResolver,
        private CashCategorizationQuery $categories,
        private AiTaskFacadeInterface $ai,
        private AddCashTransactionAction $addTransaction,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
        private PaymentIntakeDuplicateQuery $duplicates,
        private ClockInterface $clock,
        private PaymentIntakeReplyReceiptRepository $replyReceipts,
    ) {
    }

    public function request(RequestPaymentIntakeCommand $command): PaymentIntakeDraftView
    {
        $source = self::sourceKey($command->botId, $command->chatId, $command->messageId);
        $draft = $this->em->wrapInTransaction(function () use ($command, $source): PaymentIntakeDraft {
            $this->authorize($command->companyId, $command->actorUserId, true);
            // Global source identity is serialized independently of the selected company.
            $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:source, 0))', ['source' => 'payment-intake:'.$source]);
            $existing = $this->drafts->getBySource($source);
            if (null !== $existing) {
                if ($existing->getCompanyId() !== $command->companyId || $existing->getActorUserId() !== $command->actorUserId) {
                    throw new \DomainException('Сообщение уже связано с другой компанией или пользователем.');
                }

                return $existing;
            }
            $account = $this->transactions->resolveAccount($command->moneyAccountId, $command->companyId);
            if (!$account->isActive()) {
                throw new \DomainException('Выбранный счёт недоступен.');
            }
            if (!$this->ai->isEnabledForCompany($command->companyId)) {
                throw new \DomainException('Разбор платежей ИИ недоступен для компании.');
            }
            $draft = new PaymentIntakeDraft($command->companyId, $command->actorUserId, $command->moneyAccountId, $source, $command->sourceRevision, $command->text, $command->receivedAt, $command->timezone, $account->getCurrency());
            $this->queue($draft);
            $this->em->persist($draft);

            return $draft;
        });
        $this->dispatch($draft);

        return $this->view($draft);
    }

    public function get(string $companyId, string $actorUserId, string $draftId): PaymentIntakeDraftView
    {
        $this->authorize($companyId, $actorUserId);
        $draft = $this->owned($companyId, $actorUserId, $draftId);

        return $this->view($draft);
    }

    public function getBySource(string $botId, string $chatId, string $messageId, string $actorUserId): ?PaymentIntakeDraftView
    {
        $draft = $this->drafts->getBySource(self::sourceKey($botId, $chatId, $messageId));
        if (null === $draft) {
            return null;
        }
        $this->authorize($draft->getCompanyId(), $actorUserId);
        if ($draft->getActorUserId() !== $actorUserId) {
            throw new \DomainException('Черновик не найден.');
        }

        return $this->view($draft);
    }

    /** Identity metadata only: safe even when the former actor binding no longer exists. */
    public function hasSource(string $botId, string $chatId, string $messageId): bool
    {
        try {
            $sourceKey = self::sourceKey($botId, $chatId, $messageId);
        } catch (\DomainException) {
            return false;
        }

        return $this->drafts->hasSource($sourceKey)
            || $this->replyReceipts->hasReceipt(hash('sha256', 'telegram:'.$botId.':'.$chatId.':'.$messageId));
    }

    public function amend(AmendPaymentIntakeDraftCommand $command): PaymentIntakeDraftView
    {
        $replyKey = $command->replyIdempotencyKey;
        if (null !== $replyKey && ('' === $replyKey || \strlen($replyKey) > 255 || 1 !== \count($command->items) || null !== $command->text || null !== $command->sourceRevision || $command->retry)) {
            throw new \DomainException('Ответ должен исправлять одну строку отдельным действием.');
        }
        $receiptKey = null === $replyKey ? null : hash('sha256', $replyKey);
        $alreadyProcessed = false;
        $draft = $this->em->wrapInTransaction(function () use ($command, $receiptKey, &$alreadyProcessed): PaymentIntakeDraft {
            $this->authorize($command->companyId, $command->actorUserId, true);
            if (null !== $receiptKey) {
                $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:reply, 0))', ['reply' => 'payment-intake-reply:'.$receiptKey]);
                if ($this->replyReceipts->hasReceipt($receiptKey)) {
                    $alreadyProcessed = true;

                    return $this->owned($command->companyId, $command->actorUserId, $command->draftId, true);
                }
            }
            $draft = $this->owned($command->companyId, $command->actorUserId, $command->draftId, true);
            $changed = $draft->amend($command->expectedRevision, $command->items, $command->text, $command->sourceRevision, $command->questions, $command->retry);
            if ($changed && ($command->retry || null !== $command->text)) {
                $this->queue($draft);
            }
            if ($changed && !$draft->isDispatchPending()) {
                [$account, $eligible] = $this->validationContext($draft);
                foreach ($draft->getItems() as $item) {
                    if (isset($command->items[$item['id']]) && !($item['excluded'] ?? false)) {
                        $this->validateReferences($draft, $item, $account, $eligible);
                    }
                }
            }

            if ($changed && null !== $receiptKey) {
                $itemId = array_key_first($command->items);
                \assert(\is_string($itemId));
                $this->em->persist(new PaymentIntakeReplyReceipt($receiptKey, $draft, $itemId, $command->expectedRevision, $this->clock->now()));
            }

            return $draft;
        });
        if ($alreadyProcessed) {
            throw new \DomainException('Этот ответ уже обработан. Обновите карточку черновика.');
        }
        $this->dispatch($draft);

        return $this->view($draft);
    }

    public function confirm(ConfirmPaymentIntakeDraftCommand $command): PaymentIntakeConfirmation
    {
        $draft = $this->em->wrapInTransaction(function () use ($command): PaymentIntakeDraft {
            $this->authorize($command->companyId, $command->actorUserId, true);
            $draft = $this->owned($command->companyId, $command->actorUserId, $command->draftId, true);
            if ($draft->confirmationMatches($command->expectedRevision, $command->itemIds)) {
                return $draft;
            }
            $items = $draft->selectedForConfirmation($command->expectedRevision, $command->itemIds);
            $ids = [];
            [$account, $eligible] = $this->validationContext($draft);
            $periodLock = $this->companies->getFinanceLockBefore($draft->getCompanyId());
            foreach ($items as $item) {
                $this->validateReferences($draft, $item, $account, $eligible);
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
                $dto->importSource = 'telegram_payment_intake';
                $dto->externalId = $draft->getId().'/'.$item['id'];
                $dto->rawData = ['draft_id' => $draft->getId(), 'item_id' => $item['id'], 'revision' => $draft->getRevision(), 'provenance' => $item['provenance']];
                $tx = $this->addTransaction->createInCurrentTransaction($dto);
                $ids[] = (string) $tx->getId();
            }
            $draft->confirm($command->expectedRevision, $command->itemIds, $ids);

            return $draft;
        });
        $this->deliverCashEvent($draft);

        return new PaymentIntakeConfirmation($draft->getId(), $draft->getTransactionIds());
    }

    public function cancel(string $companyId, string $actorUserId, string $draftId, int $expectedRevision): void
    {
        $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $draftId, $expectedRevision): void {
            $this->authorize($companyId, $actorUserId, true);
            $this->owned($companyId, $actorUserId, $draftId, true)->cancel($expectedRevision);
        });
    }

    /** Re-dispatch durable pending intent with the same attempt key; never creates a draft. */
    public function recover(string $companyId): int
    {
        $count = 0;
        $afterId = null;
        do {
            $batch = $this->drafts->getRecoverable($companyId, $this->clock->now(), 100, $afterId);
            foreach ($batch as $draft) {
                $afterId = $draft->getId();
                $count += $this->recoverOne($draft);
            }
        } while (100 === \count($batch));

        return $count;
    }

    /** Interactive recovery is bounded to the authenticated actor's selected draft. */
    public function recoverDraft(string $companyId, string $actorUserId, string $draftId): int
    {
        $this->authorize($companyId, $actorUserId);

        return $this->recoverOne($this->owned($companyId, $actorUserId, $draftId));
    }

    private function recoverOne(PaymentIntakeDraft $draft): int
    {
        $companyId = $draft->getCompanyId();
        $dispatchPending = $draft->isDispatchPending();
        $eventPending = $draft->isCashEventPending();
        $redispatched = false;
        try {
            if ($dispatchPending) {
                $this->dispatch($draft);
            }
            if ($eventPending) {
                $this->deliverCashEvent($draft);
            }
            if (!$dispatchPending && $draft->needsRedispatch($this->clock->now())) {
                $redispatched = $this->redispatchQueued($draft);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Payment intake recovery remains pending.', ['companyId' => $companyId, 'draftId' => $draft->getId(), 'exceptionClass' => $exception::class]);
        }
        if (!$this->em->isOpen()) {
            throw new \RuntimeException('Payment intake recovery requires a fresh EntityManager after a database failure.');
        }
        $current = $this->drafts->getForCompany($companyId, $draft->getId());

        return $redispatched || (null !== $current && (($dispatchPending && !$current->isDispatchPending()) || ($eventPending && !$current->isCashEventPending()))) ? 1 : 0;
    }

    private function redispatchQueued(PaymentIntakeDraft $draft): bool
    {
        $claimed = $this->em->wrapInTransaction(function () use ($draft): ?PaymentIntakeDraft {
            if (!$this->companies->financialAccess($draft->getCompanyId(), $draft->getActorUserId(), true)['write']) {
                return null;
            }
            $current = $this->drafts->getForCompany($draft->getCompanyId(), $draft->getId(), true);
            if (null === $current || !$this->ai->isEnabledForCompany($current->getCompanyId()) || !$current->reserveRedispatch($this->clock->now())) {
                return null;
            }

            return $current;
        });

        return null !== $claimed && $this->dispatch($claimed, true);
    }

    private function queue(PaymentIntakeDraft $draft): void
    {
        $key = $this->taskKey($draft);
        $taskId = $this->ai->taskIdFor($draft->getCompanyId(), self::TASK_TYPE, $key);
        // The same spent-budget policy as Cash categorization, for new attempts only.
        // Previously admitted attempts keep their identity during transport recovery.
        if ($this->ai->budgetFor($draft->getCompanyId())->exceeded()) {
            $draft->queue($taskId);
            $draft->fail($taskId, 'budget_exceeded');

            return;
        }
        $categories = [];
        foreach ($this->categories->findLeafCategories($draft->getCompanyId()) as $category) {
            $categories[] = ['id' => $category->id, 'name' => mb_substr($category->path, -500), 'direction' => null === $category->operationType ? 'any' : strtolower($category->operationType)];
        }
        $projects = array_map(static fn ($project): array => ['id' => $project->id, 'name' => mb_substr($project->name, 0, 500)], $this->companies->listProjectDirections($draft->getCompanyId()));
        $centers = array_map(static fn ($center): array => ['id' => $center->id, 'name' => mb_substr($center->name, 0, 500)], $this->centers->getActiveChoices($draft->getCompanyId()));
        $pairs = array_map(static fn ($pair): array => ['project_id' => $pair->projectDirectionId, 'cfo_id' => $pair->responsibilityCenterId], $this->centers->getActivePairs($draft->getCompanyId()));
        if (\count($categories) > 1000 || \count($projects) > 1000 || \count($centers) > 1000 || \count($pairs) > 10000) {
            throw new \DomainException('Справочник превышает допустимый размер разбора.');
        }
        $included = array_values(array_filter($draft->getItems(), static fn (array $item): bool => !($item['excluded'] ?? false)));
        if (\count($included) > 50) {
            throw new \DomainException('В черновике больше 50 включённых строк. Исключите несопоставленные повторы или разделите сообщение перед новым разбором.');
        }
        $draft->queue($taskId, [
            'text' => $draft->getText(), 'reference_at' => $draft->getReceivedAt()->format(\DATE_ATOM), 'timezone' => $draft->getTimezone(), 'currency' => $draft->getCurrency(),
            'categories' => $categories, 'projects' => $projects, 'cfos' => $centers, 'allowed_pairs' => $pairs, 'draft_items' => array_map(static fn (array $item): array => ['ref' => $item['id'], 'description' => $item['description']], $included),
        ]);
    }

    private function dispatch(PaymentIntakeDraft $draft, bool $queuedRetry = false): bool
    {
        if ((!$queuedRetry && !$draft->isDispatchPending()) || null === $draft->getTaskId()) {
            return false;
        }
        $requestedTaskId = $draft->getTaskId();
        if (!$this->companies->financialAccess($draft->getCompanyId(), $draft->getActorUserId())['write']) {
            $this->em->wrapInTransaction(function () use ($draft, $requestedTaskId): void {
                $this->drafts->getForCompany($draft->getCompanyId(), $draft->getId(), true)?->fail($requestedTaskId, 'access_revoked');
            });

            return false;
        }
        $taskId = $this->ai->requestTask($draft->getCompanyId(), self::TASK_TYPE, $draft->getPayload(), $this->taskKey($draft), $draft->getActorUserId());
        if (null === $taskId && $queuedRetry) {
            return false;
        }
        $this->em->wrapInTransaction(function () use ($draft, $taskId, $requestedTaskId): void {
            $locked = $this->drafts->getForCompany($draft->getCompanyId(), $draft->getId(), true);
            if (null === $locked) {
                return;
            }
            if (null === $taskId) {
                $locked->fail($requestedTaskId, 'service_disabled');
            } else {
                $locked->markDispatched($taskId, $this->clock->now());
            }
        });

        return null !== $taskId;
    }

    private function deliverCashEvent(PaymentIntakeDraft $draft): void
    {
        if (!$draft->isCashEventPending()) {
            return;
        }
        try {
            $this->events->dispatch(new CashTransactionsChanged($draft->getCompanyId()));
        } catch (\Throwable $exception) {
            $this->logger->warning('Payment intake cash event remains pending after confirmation.', ['companyId' => $draft->getCompanyId(), 'draftId' => $draft->getId(), 'exceptionClass' => $exception::class]);

            return;
        }
        $this->em->wrapInTransaction(function () use ($draft): void {
            $this->drafts->getForCompany($draft->getCompanyId(), $draft->getId(), true)?->acknowledgeCashEvent();
        });
    }

    /**
     * @return array{MoneyAccount, array<string, ?string>}
     */
    private function validationContext(PaymentIntakeDraft $draft): array
    {
        $account = $this->transactions->resolveAccount($draft->getMoneyAccountId(), $draft->getCompanyId());
        $this->em->refresh($account);
        if (!$account->isActive()) {
            throw new \DomainException('Выбранный счёт недоступен.');
        }
        $eligible = array_column(array_map(static fn ($category): array => ['id' => $category->id, 'direction' => null === $category->operationType ? null : strtolower($category->operationType)], $this->categories->findLeafCategories($draft->getCompanyId())), 'direction', 'id');

        return [$account, $eligible];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, ?string> $eligible
     */
    private function validateReferences(PaymentIntakeDraft $draft, array $item, MoneyAccount $account, array $eligible): void
    {
        $companyId = $draft->getCompanyId();
        if (null !== $item['currency']) {
            $this->transactions->resolveCurrencyForAccount($item['currency'], $account);
        }
        if (null !== $item['category_id']) {
            if (!\array_key_exists($item['category_id'], $eligible) || (null !== $eligible[$item['category_id']] && $eligible[$item['category_id']] !== $item['direction'])) {
                throw new \DomainException('Статья недоступна или не подходит направлению платежа.');
            }
        }
        if (null !== $item['project_id'] || null !== $item['cfo_id']) {
            $this->responsibilityResolver->resolveForCreate($companyId, $item['project_id'], $item['cfo_id']);
        }
    }

    private function authorize(string $companyId, string $actorUserId, bool $lock = false): void
    {
        if (!$this->companies->financialAccess($companyId, $actorUserId, $lock)['write']) {
            throw new \DomainException('Нет права записи платежей компании.');
        }
    }

    private function owned(string $companyId, string $actorUserId, string $draftId, bool $lock = false): PaymentIntakeDraft
    {
        if (!Uuid::isValid($draftId)) {
            throw new \DomainException('Черновик не найден.');
        }
        $draft = $this->drafts->getForCompany($companyId, $draftId, $lock);
        if (null === $draft || $draft->getActorUserId() !== $actorUserId) {
            throw new \DomainException('Черновик не найден.');
        }

        return $draft;
    }

    private function taskKey(PaymentIntakeDraft $draft): string
    {
        return $draft->getId().'/revision/'.$draft->getRevision().'/attempt/'.$draft->getAttempt();
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

        return new PaymentIntakeDraftView($draft->getId(), $draft->getCompanyId(), $draft->getActorUserId(), $draft->getMoneyAccountId(), $draft->getRevision(), $draft->getStatus(), $draft->getTaskId(), $items, $draft->getQuestions(), $draft->getErrorCode());
    }

    private static function sourceKey(string $botId, string $chatId, string $messageId): string
    {
        if (!Uuid::isValid($botId) && 1 !== preg_match('/^[0-9]{1,30}$/D', $botId)) {
            throw new \DomainException('Некорректный бот Telegram.');
        }
        foreach ([$chatId, $messageId] as $part) {
            if (1 !== preg_match('/^-?[0-9]{1,30}$/D', $part)) {
                throw new \DomainException('Некорректный источник Telegram.');
            }
        }

        return $botId.'/'.$chatId.'/'.$messageId;
    }
}

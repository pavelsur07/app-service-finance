<?php

declare(strict_types=1);

namespace App\Cash\Application\Service;

use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashCategorySuggestionDecisionOutcome as Outcome;
use App\Cash\Enum\Transaction\CashCategorySuggestionStatus;
use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Exception\CashCategorySuggestionNotFoundException;
use App\Cash\Exception\CashCategorySuggestionStateException;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use App\Cash\Repository\Transaction\CashCategorySuggestionRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Transaction\CashTransactionService;
use App\Cash\Service\Transaction\CashTransactionSplitSynchronizer;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Проставить статью операции из ИИ-подсказки: «Принять» человеком или автоприменение.
 *
 * Только `persist`: вызывается внутри транзакции точки входа. Порядок блокировок —
 * подсказка (`FOR UPDATE`, статус проверяется после захвата), затем операция. Статья
 * ставится, только если операция всё ещё без статьи, — статью человека подсказка не
 * перезаписывает никогда (ТЗ §6); иначе подсказка устаревает.
 *
 * Человек: разбивка `manual`, закрытый период и перевод — ошибка для показа. Автоприменение:
 * разбивка `auto` и своя запись истории с `aiSuggestion` — по ней
 * `CashTransactionAutoRuleProvenanceResolver` считает статью авто-назначенной, и её
 * перезаписывают автоправила в режиме `replace_auto_assigned` и человек; закрытый период и
 * перевод — подсказка устаревает, решать некому. Общую запись `AuditLogSubscriber` вызывающий
 * подавляет (`AutoRuleDispatchGuard::suppress`), иначе она перекрыла бы эту.
 */
final readonly class CashCategorySuggestionApplier
{
    public function __construct(
        private CashCategorySuggestionRepository $suggestions,
        private CashTransactionRepository $transactions,
        private CashflowCategoryRepository $categories,
        private CashCategorizationQuery $query,
        private CashTransactionService $transactionService,
        private CashTransactionSplitSynchronizer $splitSynchronizer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param string|null $userId автор «Принять»; null — автоприменение
     *
     * @return Outcome|null null — автоприменению нечего делать: подсказку уже решил человек
     *                      (без исключения — оно закрыло бы EntityManager транзакции)
     */
    public function apply(string $companyId, string $suggestionId, ?string $userId, \DateTimeImmutable $now): ?Outcome
    {
        $suggestion = $this->suggestions->findOneForCompanyForUpdate($companyId, $suggestionId);
        if (null === $suggestion || CashCategorySuggestionStatus::SUGGESTED !== $suggestion->getStatus()) {
            if (null === $userId) {
                return null;
            }
            throw null === $suggestion ? CashCategorySuggestionNotFoundException::forId($suggestionId) : CashCategorySuggestionStateException::fromStatus($suggestion->getStatus());
        }

        $transaction = $this->transactions->findOneByIdAndCompanyIdForUpdate($suggestion->getTransactionId(), $companyId);
        if (null === $transaction || [] === $this->query->uncategorizedAmong($companyId, [$suggestion->getTransactionId()])) {
            $suggestion->markStale('categorized', $now);

            return Outcome::STALE;
        }

        $category = null === $suggestion->getCategoryId()
            ? null
            : $this->categories->findOneByIdAndCompanyId($suggestion->getCategoryId(), $companyId);
        if (null === $category
            || CashflowCategoryStatus::ACTIVE !== $category->getStatus()
            || $category->isSystem()
            || !$category->getChildren()->isEmpty()) {
            $suggestion->markStale('category_unavailable', $now);

            return Outcome::STALE;
        }

        try {
            $this->transactionService->assertStandaloneTransaction($transaction);
            $this->transactionService->assertNotLockedForCompany($transaction->getCompany(), $transaction->getOccurredAt());
        } catch (\DomainException $e) {
            if (null !== $userId) {
                throw $e;
            }
            $suggestion->markStale('not_applicable', $now);

            return Outcome::STALE;
        }

        $before = $transaction->getCashflowCategory()?->getId();
        $transaction->setCashflowCategory($category);
        if (null === $userId) {
            $this->recordAutoApplied($transaction, $suggestion, $before);
        }
        $this->splitSynchronizer->sync($transaction, null === $userId ? CashTransactionSplitSource::AUTO : CashTransactionSplitSource::MANUAL);
        $this->decide($suggestion, $userId, $now);

        return Outcome::ACCEPTED;
    }

    private function recordAutoApplied(CashTransaction $transaction, CashCategorySuggestion $suggestion, ?string $before): void
    {
        $this->entityManager->persist(new AuditLog(
            (string) $transaction->getCompany()->getId(),
            CashTransaction::class,
            (string) $transaction->getId(),
            AuditLogAction::UPDATE,
            [
                'mode' => 'ai_auto_apply',
                'aiSuggestion' => ['cashflowCategory' => ['id' => $suggestion->getId()]],
                'changes' => ['cashflowCategory' => ['before' => $before, 'after' => $transaction->getCashflowCategory()?->getId()]],
            ],
        ));
    }

    private function decide(CashCategorySuggestion $suggestion, ?string $userId, \DateTimeImmutable $now): void
    {
        if (null === $userId) {
            $suggestion->autoApply($now);
        } else {
            $suggestion->accept($userId, $now);
        }
    }
}

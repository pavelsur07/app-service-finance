<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Domain\Event\CashTransactionsChanged;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webmozart\Assert\Assert;

/**
 * «Принять» ИИ-подсказку: проставить статью так же, как ручная правка (закрытый период,
 * перевод, разбивка с источником MANUAL — решение человека), и записать решение.
 *
 * Статья ставится, только если операция всё ещё без статьи: статью, выбранную человеком,
 * подсказка не перезаписывает никогда (ТЗ §6). Иначе подсказка помечается устаревшей.
 * Строка операции берётся FOR UPDATE: параллельная ручная правка не проскочит между
 * проверкой и записью.
 */
final class AcceptCashCategorySuggestionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashCategorySuggestionRepository $suggestions,
        private readonly CashTransactionRepository $transactions,
        private readonly CashflowCategoryRepository $categories,
        private readonly CashCategorizationQuery $query,
        private readonly CashTransactionService $transactionService,
        private readonly CashTransactionSplitSynchronizer $splitSynchronizer,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $companyId, string $suggestionId, string $userId): Outcome
    {
        Assert::uuid($companyId);
        Assert::uuid($userId);

        /** @var Outcome $outcome */
        $outcome = $this->entityManager->wrapInTransaction(function () use ($companyId, $suggestionId, $userId): Outcome {
            $suggestion = $this->suggestions->findOneForCompany($companyId, $suggestionId)
                ?? throw CashCategorySuggestionNotFoundException::forId($suggestionId);
            if (CashCategorySuggestionStatus::SUGGESTED !== $suggestion->getStatus()) {
                throw CashCategorySuggestionStateException::fromStatus($suggestion->getStatus());
            }
            $now = $this->clock->now();

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

            $this->transactionService->assertStandaloneTransaction($transaction);
            $this->transactionService->assertNotLockedForCompany($transaction->getCompany(), $transaction->getOccurredAt());

            $transaction->setCashflowCategory($category);
            $this->splitSynchronizer->sync($transaction, CashTransactionSplitSource::MANUAL);
            $suggestion->accept($userId, $now);

            return Outcome::ACCEPTED;
        });

        if (Outcome::ACCEPTED === $outcome) {
            $this->eventDispatcher->dispatch(new CashTransactionsChanged($companyId));
        }

        return $outcome;
    }
}

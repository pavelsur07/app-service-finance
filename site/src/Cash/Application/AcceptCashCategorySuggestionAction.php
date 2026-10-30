<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\Service\CashCategorySuggestionApplier;
use App\Cash\Domain\Event\CashTransactionsChanged;
use App\Cash\Enum\Transaction\CashCategorySuggestionDecisionOutcome as Outcome;
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
 * Логика и блокировки — `CashCategorySuggestionApplier`, общая с автоприменением.
 */
final class AcceptCashCategorySuggestionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashCategorySuggestionApplier $applier,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $companyId, string $suggestionId, string $userId): Outcome
    {
        Assert::uuid($companyId);
        Assert::uuid($userId);

        // С автором применитель либо решает, либо бросает исключение — null не возвращается.
        /** @var Outcome $outcome */
        $outcome = $this->entityManager->wrapInTransaction(
            fn (): ?Outcome => $this->applier->apply($companyId, $suggestionId, $userId, $this->clock->now()),
        );

        if (Outcome::ACCEPTED === $outcome) {
            $this->eventDispatcher->dispatch(new CashTransactionsChanged($companyId));
        }

        return $outcome;
    }
}

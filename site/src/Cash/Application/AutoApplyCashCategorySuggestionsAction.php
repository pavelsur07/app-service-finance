<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\DTO\CashAiAutoApplyResult;
use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Application\Service\CashCategorySuggestionApplier;
use App\Cash\Domain\Event\CashTransactionsChanged;
use App\Cash\Domain\Policy\CashAiAutoApplyPolicy;
use App\Cash\Enum\Transaction\CashCategorySuggestionDecisionOutcome as Outcome;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use App\Cash\Repository\Transaction\CashAiCategorizationSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webmozart\Assert\Assert;

/**
 * Автоприменение уверенных ИИ-подсказок статей ДДС (docs/tasks/ai/stages/stage-2.md, 2.3).
 *
 * Ворота компании — `CashAiAutoApplyPolicy`: флаг и точность людей по уверенным подсказкам.
 * Каждая подсказка — своя транзакция с той же логикой, что «Принять»
 * (`CashCategorySuggestionApplier`): сбой одной не откатывает остальные, а параллельное
 * решение человека побеждает (подсказка уже не `suggested`). Событие — один раз после
 * всех commit.
 */
final class AutoApplyCashCategorySuggestionsAction
{
    private const PER_RUN = 200;

    public function __construct(
        private readonly CashAiCategorizationSettingRepository $settings,
        private readonly CashCategorizationQuery $query,
        private readonly CashCategorySuggestionApplier $applier,
        private readonly AutoRuleDispatchGuard $dispatchGuard,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId): CashAiAutoApplyResult
    {
        Assert::uuid($companyId);

        $gate = $this->query->autoApplyGateStats($companyId, CashAiAutoApplyPolicy::MIN_CONFIDENCE);
        $closed = CashAiAutoApplyPolicy::gateClosedReason($this->settings->isAutoApplyEnabled($companyId), $gate);
        if (null !== $closed) {
            return new CashAiAutoApplyResult($closed, $gate);
        }

        $applied = 0;
        $stale = 0;
        foreach ($this->query->findAutoApplicable($companyId, CashAiAutoApplyPolicy::MIN_CONFIDENCE, self::PER_RUN) as $suggestionId) {
            // Историю пишет applier (с `aiSuggestion`); общая запись подписчика перекрыла бы её.
            /** @var Outcome|null $outcome */
            $outcome = $this->dispatchGuard->suppress(fn (): mixed => $this->entityManager->wrapInTransaction(
                fn (): ?Outcome => $this->applier->apply($companyId, $suggestionId, null, $this->clock->now()),
            ));
            // null — человек решил между отбором и блокировкой, его решение остаётся.
            match ($outcome) {
                Outcome::ACCEPTED => ++$applied,
                Outcome::STALE => ++$stale,
                default => null,
            };
        }

        if ($applied > 0) {
            $this->eventDispatcher->dispatch(new CashTransactionsChanged($companyId));
        }
        $this->logger->info('AI category suggestions auto-applied.', [
            'companyId' => $companyId,
            'applied' => $applied,
            'stale' => $stale,
        ]);

        return new CashAiAutoApplyResult(null, $gate, $applied, $stale);
    }
}

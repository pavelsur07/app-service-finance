<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Cash\Service\Transaction\CashTransactionSplitSynchronizer;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Ручное применение автоправила к одной транзакции ДДС.
 *
 * Блокировка транзакции держится от чтения до flush: параллельное применение
 * (воркер автоправил, двойной клик) иначе вставило бы ту же строку разбивки
 * дважды — GlitchTip #414.
 */
final class ApplyAutoRuleToTransactionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionRepository $transactions,
        private readonly CashTransactionAutoRuleService $autoRuleService,
        private readonly AutoRuleDispatchGuard $dispatchGuard,
        private readonly AuditContextProvider $auditContextProvider,
        private readonly CashTransactionSplitSynchronizer $splitSynchronizer,
    ) {
    }

    /**
     * @return array<string, bool|string>|null тело JSON-ответа; null — транзакции нет у компании
     */
    public function __invoke(string $companyId, string $transactionId, string $requestedRuleId): ?array
    {
        return $this->entityManager->wrapInTransaction(function () use ($companyId, $transactionId, $requestedRuleId): ?array {
            $t = $this->transactions->findOneByIdAndCompanyIdForUpdate($transactionId, $companyId);
            if (!$t instanceof CashTransaction) {
                // До этапа 5.1 здесь бросался 404 внутри транзакции: ORM откатывал её и
                // закрывал EntityManager. Теперь пустая транзакция коммитится, 404 бросает
                // контроллер — до загрузки транзакции UnitOfWork пуст, писать нечего.
                return null;
            }

            $skipReason = $this->autoRuleService->getSkipReason($t);
            if (null !== $skipReason) {
                return [
                    'ok' => false,
                    'changed' => false,
                    'reason' => $skipReason->value,
                    'message' => $skipReason->label(),
                ];
            }

            $match = $this->autoRuleService->match($t);
            if (!$match->hasWinners() && $match->hasConflict()) {
                return [
                    'ok' => false,
                    'changed' => false,
                    'reason' => 'conflict',
                    'message' => 'Все найденные поля конфликтуют и не будут изменены',
                ];
            }

            $rule = $match->rule;
            if (!$rule || ('' !== $requestedRuleId && !$match->hasWinnerId($requestedRuleId))) {
                return ['ok' => false, 'message' => 'Подходящее правило не найдено'];
            }

            $applicationPlan = $this->autoRuleService->applyRule($rule, $t, $match);
            $changed = $applicationPlan?->hasChanges() ?? false;
            if ($changed) {
                $this->entityManager->persist(new AuditLog(
                    (string) $t->getCompany()->getId(),
                    CashTransaction::class,
                    (string) $t->getId(),
                    AuditLogAction::UPDATE,
                    $applicationPlan->auditDiff(Uuid::uuid7()->toString()),
                    $this->auditContextProvider->getActorUserId(),
                ));
                $this->dispatchGuard->suppress(
                    function () use ($t): void {
                        $this->splitSynchronizer->sync($t, CashTransactionSplitSource::AUTO);
                        $this->entityManager->flush();
                    },
                    $applicationPlan,
                );
            }

            return [
                'ok' => true,
                'changed' => $changed,
                'ruleName' => $rule->getName(),
                'action' => $rule->getAction()->value,
            ];
        });
    }
}

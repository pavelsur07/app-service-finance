<?php

namespace App\Cash\MessageHandler;

use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Application\Service\CashTransactionAutoRuleProvenanceResolver;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Message\ApplyAutoRulesForTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Cash\Service\Transaction\CashTransactionSplitSynchronizer;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ApplyAutoRulesForTransactionHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionRepository $transactionRepository,
        private readonly CashTransactionAutoRuleService $autoRuleService,
        private readonly CashTransactionSplitSynchronizer $splitSynchronizer,
        private readonly CashflowSystemCategoryService $cashflowSystemCategoryService,
        private readonly AutoRuleDispatchGuard $dispatchGuard,
        private readonly CashTransactionAutoRuleProvenanceResolver $provenanceResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyAutoRulesForTransaction $message): void
    {
        $correlationId = $message->correlationId ?? Uuid::uuid7()->toString();
        $transaction = $this->transactionRepository->findOneByIdAndCompanyId(
            $message->transactionId,
            $message->companyId,
        );

        if (!$transaction instanceof CashTransaction) {
            $this->logger->warning('Cash auto rules: transaction not found', [
                'transactionId' => $message->transactionId,
                'companyId' => $message->companyId,
                'correlationId' => $correlationId,
                'createdAt' => $message->createdAt->format(\DATE_ATOM),
                'mode' => $message->mode->value,
            ]);

            return;
        }

        $skipReason = $this->autoRuleService->getSkipReason($transaction);
        if (null !== $skipReason) {
            $this->logger->info('Cash auto rules skipped', [
                'transactionId' => $transaction->getId(),
                'companyId' => $message->companyId,
                'correlationId' => $correlationId,
                'messageCreatedAt' => $message->createdAt->format(\DATE_ATOM),
                'reason' => $skipReason->value,
                'mode' => $message->mode->value,
            ]);

            $this->entityManager->clear(CashTransaction::class);

            return;
        }

        $match = $this->autoRuleService->match($transaction);
        $rule = $match->rule;
        $provenance = $message->mode->replacesAutoAssigned() && $match->hasWinners()
            ? $this->provenanceResolver->resolve($transaction)
            : null;
        $applicationPlan = $this->autoRuleService->applyMatch(
            $transaction,
            $match,
            $message->mode,
            $provenance,
        );
        $changed = $applicationPlan?->hasChanges() ?? false;
        $ruleId = null;
        $ruleName = null;

        if ($match->hasConflict()) {
            $this->logger->warning('Cash auto rules: conflict detected', [
                'transactionId' => $transaction->getId(),
                'companyId' => $message->companyId,
                'correlationId' => $correlationId,
                'ruleIds' => array_map(
                    static fn (CashTransactionAutoRule $conflictingRule): string => (string) $conflictingRule->getId(),
                    $match->conflictingRules,
                ),
                'fields' => array_keys($match->conflicts),
                'mode' => $message->mode->value,
            ]);
        }

        if (null !== $rule) {
            $ruleId = $rule->getId();
            $ruleName = $rule->getName();
        }

        if (null === $transaction->getCashflowCategory()) {
            $unallocatedCategory = $this->cashflowSystemCategoryService->getOrCreateUnallocated($transaction->getCompany());
            $transaction->setCashflowCategory($unallocatedCategory);
            $changed = true;
        }

        if (null !== $applicationPlan && $applicationPlan->hasChanges()) {
            $this->entityManager->persist(new AuditLog(
                (string) $transaction->getCompany()->getId(),
                CashTransaction::class,
                (string) $transaction->getId(),
                AuditLogAction::UPDATE,
                $applicationPlan->auditDiff($correlationId, $message->mode),
                $message->initiatedByUserId,
            ));
        }

        if ($changed) {
            $this->dispatchGuard->suppress(
                function () use ($transaction): void {
                    // Строки зеркалят колонку в том же flush: иначе между записью категории
                    // и появлением строки остаётся окно, в котором они расходятся.
                    $this->splitSynchronizer->sync($transaction, CashTransactionSplitSource::AUTO);
                    $this->entityManager->flush();
                },
                $applicationPlan,
            );
        }

        $this->logger->info('Cash auto rules applied', [
            'transactionId' => $transaction->getId(),
            'companyId' => $message->companyId,
            'correlationId' => $correlationId,
            'messageCreatedAt' => $message->createdAt->format(\DATE_ATOM),
            'changed' => $changed,
            'conflict' => $match->hasConflict(),
            'ruleId' => $ruleId,
            'ruleName' => $ruleName,
            'mode' => $message->mode->value,
            'initiatedByUserId' => $message->initiatedByUserId,
        ]);

        $this->entityManager->clear(CashTransaction::class);
    }
}

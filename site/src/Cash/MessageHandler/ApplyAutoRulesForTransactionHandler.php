<?php

namespace App\Cash\MessageHandler;

use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Message\ApplyAutoRulesForTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ApplyAutoRulesForTransactionHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionRepository $transactionRepository,
        private readonly CashTransactionAutoRuleService $autoRuleService,
        private readonly CashflowSystemCategoryService $cashflowSystemCategoryService,
        private readonly AutoRuleDispatchGuard $dispatchGuard,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyAutoRulesForTransaction $message): void
    {
        $transaction = $this->transactionRepository->findOneByIdAndCompanyId(
            $message->transactionId,
            $message->companyId,
        );

        if (!$transaction instanceof CashTransaction) {
            $this->logger->warning('Cash auto rules: transaction not found', [
                'transactionId' => $message->transactionId,
                'companyId' => $message->companyId,
                'createdAt' => $message->createdAt->format(\DATE_ATOM),
            ]);

            return;
        }

        $skipReason = $this->autoRuleService->getSkipReason($transaction);
        if (null !== $skipReason) {
            $this->logger->info('Cash auto rules skipped', [
                'transactionId' => $transaction->getId(),
                'companyId' => $message->companyId,
                'messageCreatedAt' => $message->createdAt->format(\DATE_ATOM),
                'reason' => $skipReason->value,
            ]);

            $this->entityManager->clear(CashTransaction::class);

            return;
        }

        $match = $this->autoRuleService->match($transaction);
        $rule = $match->rule;
        $changed = false;
        $ruleId = null;
        $ruleName = null;

        if ($match->hasConflict()) {
            $this->logger->warning('Cash auto rules: conflict detected', [
                'transactionId' => $transaction->getId(),
                'companyId' => $message->companyId,
                'ruleIds' => array_map(
                    static fn (CashTransactionAutoRule $conflictingRule): string => (string) $conflictingRule->getId(),
                    $match->conflictingRules,
                ),
            ]);
        }

        if (null !== $rule) {
            $changed = $this->autoRuleService->applyRule($rule, $transaction, $match);
            $ruleId = $rule->getId();
            $ruleName = $rule->getName();
        }

        if (null === $transaction->getCashflowCategory()) {
            $unallocatedCategory = $this->cashflowSystemCategoryService->getOrCreateUnallocated($transaction->getCompany());
            $transaction->setCashflowCategory($unallocatedCategory);
            $changed = true;
        }

        if ($changed) {
            $this->dispatchGuard->suppress(fn () => $this->entityManager->flush());
        }

        $this->logger->info('Cash auto rules applied', [
            'transactionId' => $transaction->getId(),
            'companyId' => $message->companyId,
            'messageCreatedAt' => $message->createdAt->format(\DATE_ATOM),
            'changed' => $changed,
            'conflict' => $match->hasConflict(),
            'ruleId' => $ruleId,
            'ruleName' => $ruleName,
        ]);

        $this->entityManager->clear(CashTransaction::class);
    }
}

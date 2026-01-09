<?php

namespace App\MessageHandler;

use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Transaction\CashTransactionAutoRuleService;
use App\Entity\CashTransaction;
use App\Message\ApplyAutoRulesForTransaction;
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
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyAutoRulesForTransaction $message): void
    {
        $transaction = $this->transactionRepository->find($message->transactionId);

        if (!$transaction instanceof CashTransaction) {
            $this->logger->warning('Cash auto rules: transaction not found', [
                'transactionId' => $message->transactionId,
                'companyId' => $message->companyId,
                'createdAt' => $message->createdAt->format(\DATE_ATOM),
            ]);

            return;
        }

        $transactionCompanyId = $transaction->getCompany()->getId();
        if (null === $transactionCompanyId || $transactionCompanyId !== $message->companyId) {
            $this->logger->warning('Cash auto rules: company mismatch', [
                'transactionId' => $message->transactionId,
                'expectedCompanyId' => $message->companyId,
                'actualCompanyId' => $transactionCompanyId,
                'createdAt' => $message->createdAt->format(\DATE_ATOM),
            ]);

            $this->entityManager->clear(CashTransaction::class);

            return;
        }

        $rule = $this->autoRuleService->findMatchingRule($transaction);
        $changed = false;
        $ruleId = null;
        $ruleName = null;

        if (null !== $rule) {
            $changed = $this->autoRuleService->applyRule($rule, $transaction);
            $ruleId = $rule->getId();
            $ruleName = $rule->getName();
        }

        $this->logger->info('Cash auto rules applied', [
            'transactionId' => $transaction->getId(),
            'companyId' => $message->companyId,
            'messageCreatedAt' => $message->createdAt->format(\DATE_ATOM),
            'changed' => $changed,
            'ruleId' => $ruleId,
            'ruleName' => $ruleName,
        ]);

        $this->entityManager->clear(CashTransaction::class);
    }
}

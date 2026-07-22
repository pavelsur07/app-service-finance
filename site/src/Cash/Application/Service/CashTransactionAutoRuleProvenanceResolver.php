<?php

declare(strict_types=1);

namespace App\Cash\Application\Service;

use App\Cash\Application\DTO\CashTransactionAutoRuleProvenance;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Shared\Entity\AuditLog;
use App\Shared\Repository\AuditLogRepository;
use Psr\Log\LoggerInterface;

final readonly class CashTransactionAutoRuleProvenanceResolver
{
    private const FIELDS = [
        'cashflowCategory',
        'projectDirection',
        'responsibilityCenterId',
    ];

    public function __construct(
        private AuditLogRepository $auditLogRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(CashTransaction $transaction): CashTransactionAutoRuleProvenance
    {
        $audits = $this->auditLogRepository->findBy([
            'companyId' => (string) $transaction->getCompany()->getId(),
            'entityClass' => CashTransaction::class,
            'entityId' => (string) $transaction->getId(),
        ], ['createdAt' => 'DESC'], 50);
        if (50 === count($audits)) {
            $this->logger->warning('Auto-rule provenance unresolved because audit history was truncated.', [
                'companyId' => (string) $transaction->getCompany()->getId(),
                'transactionId' => (string) $transaction->getId(),
            ]);

            return new CashTransactionAutoRuleProvenance([]);
        }

        $currentValues = [
            'cashflowCategory' => $transaction->getCashflowCategory()?->getId(),
            'projectDirection' => $transaction->getProjectDirection()?->getId(),
            'responsibilityCenterId' => $transaction->getResponsibilityCenterId(),
        ];
        $autoAssignedFields = [];

        foreach (self::FIELDS as $field) {
            $autoAssignedFields[$field] = $this->latestChangeIsAutoAssigned(
                $audits,
                $field,
                $currentValues[$field],
            );
        }

        return new CashTransactionAutoRuleProvenance($autoAssignedFields);
    }

    /** @param list<AuditLog> $audits */
    private function latestChangeIsAutoAssigned(array $audits, string $field, ?string $currentValue): bool
    {
        $latestTimestamp = null;
        $latestAudits = [];

        foreach ($audits as $audit) {
            $diff = $audit->getDiff();
            if (!$this->touchesField($diff, $field)) {
                continue;
            }

            $timestamp = $audit->getCreatedAt()->format('U');
            $latestTimestamp ??= $timestamp;
            if ($timestamp !== $latestTimestamp) {
                break;
            }

            $latestAudits[] = $diff;
        }

        if ([] === $latestAudits) {
            return false;
        }

        foreach ($latestAudits as $diff) {
            if (!is_array($diff)
                || !isset($diff['autoRules'][$field]['id'], $diff['autoRules'][$field]['revision'])
                || !isset($diff['changes'])
                || !array_key_exists($field, $diff['changes'])
                || ($diff['changes'][$field]['after'] ?? null) !== $currentValue) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed>|null $diff */
    private function touchesField(?array $diff, string $field): bool
    {
        return is_array($diff)
            && (array_key_exists($field, $diff)
                || isset($diff['changes']) && array_key_exists($field, $diff['changes']));
    }
}

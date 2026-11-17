<?php

declare(strict_types=1);

namespace App\Cash\Application\AutoRule;

use App\Cash\Application\Service\AutoRule\CashAutoRuleConfirmation;
use App\Cash\Application\Service\AutoRule\CashTransactionAutoRuleTargetValidator;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\Company;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class SaveCashTransactionAutoRuleAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private CashAutoRuleConfirmation $confirmation,
        private CashTransactionAutoRuleTargetValidator $targetValidator,
    ) {
    }

    public function createDraft(Company $company, ?string $actorUserId, ?string $id = null): CashTransactionAutoRule
    {
        return new CashTransactionAutoRule(
            $id ?? Uuid::uuid7()->toString(), $company, '', CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY, createdByUserId: $actorUserId,
        );
    }

    public function __invoke(CashTransactionAutoRule $draft, ?CashTransactionAutoRule $original, string $actorId, string $token): void
    {
        [$expectedRevision, $expectedVersions] = $this->confirmation->verify($draft, $actorId, null === $original, $token);
        $companyId = (string) $draft->getCompany()->getId();
        $ruleId = (string) $draft->getId();
        $receipt = hash('sha256', $token);

        $this->targetValidator->assertValidChange(
            $companyId, $original?->getProjectDirection()?->getId(), $original?->getResponsibilityCenterId(),
            $draft->getProjectDirection()?->getId(), $draft->getResponsibilityCenterId(),
        );
        $violations = $this->validator->validate($draft);
        if (count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = (string) $violation->getMessage();
            }
            throw new \DomainException(implode('; ', $messages));
        }

        $confirmed = $this->entityManager->wrapInTransaction(function () use ($draft, $original, $actorId, $companyId, $ruleId, $receipt, $expectedRevision, $expectedVersions): bool {
            $db = $this->entityManager->getConnection();
            // FOR UPDATE компании блокирует вставки правил через FK; блокировки всех её
            // правил охватывают также включение неактивных, удаление и служебный CLI.
            $db->fetchOne('SELECT id FROM companies WHERE id = ? FOR UPDATE', [$companyId]);
            $rows = $db->fetchAllAssociative(
                'SELECT id, revision, CASE WHEN is_active THEN revision END AS active_revision FROM cash_transaction_auto_rule WHERE company_id = ? ORDER BY id FOR UPDATE',
                [$companyId],
            );
            if (false !== $db->fetchOne(
                "SELECT id FROM audit_log WHERE company_id = ? AND entity_class = ? AND entity_id = ? AND actor_user_id = ? AND diff::jsonb ->> 'ruleConfirmation' = ? LIMIT 1",
                [$companyId, CashTransactionAutoRule::class, $ruleId, $actorId, $receipt],
            )) {
                return true;
            }

            $revision = 0;
            $active = [];
            foreach ($rows as $row) {
                if ($ruleId === $row['id']) {
                    $revision = (int) $row['revision'];
                }
                if (null !== $row['active_revision']) {
                    $active[(string) $row['id']] = (int) $row['active_revision'];
                }
            }
            if ($revision !== $expectedRevision || $expectedVersions !== CashAutoRuleConfirmation::versionsHash($active)) {
                return false;
            }

            if (null === $original) {
                $this->entityManager->persist($draft);
            } else {
                $original->approveDraft($draft, $actorId);
            }
            $this->entityManager->persist(new AuditLog(
                $companyId, CashTransactionAutoRule::class, $ruleId, AuditLogAction::UPDATE,
                ['ruleConfirmation' => $receipt, 'previewRevision' => $expectedRevision], $actorId,
            ));

            return true;
        });
        if (!$confirmed) {
            throw new \DomainException('Набор или версия правил изменились. Повторите проверку.');
        }
    }
}

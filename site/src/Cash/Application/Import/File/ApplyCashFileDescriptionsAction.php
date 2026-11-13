<?php

declare(strict_types=1);

namespace App\Cash\Application\Import\File;

use App\Cash\Application\Service\AutoRule\AutoRuleDispatchGuard;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Application\Service\Import\File\CashFileDescriptionPlan;
use App\Cash\Application\Service\Import\File\CashFileImportService;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @phpstan-import-type InputRow from CashFileDescriptionPlan
 */
final readonly class ApplyCashFileDescriptionsAction
{
    public function __construct(
        private CashFileDescriptionPlan $plans,
        private CashFileImportService $importer,
        private CashTransactionRepository $transactions,
        private EntityManagerInterface $em,
        private AutoRuleDispatchGuard $guard,
    ) {
    }

    /** @return array<int, InputRow> */
    public function __invoke(CashFileImportJob $job): array
    {
        $options = $job->getOptions();
        $hash = $options['description_plan'] ?? null;
        if (!is_string($hash)) {
            throw new \DomainException('План импорта отсутствует.');
        }
        $plan = $this->plans->load((string) $job->getCompany()->getId(), $hash);
        if ($plan['accountId'] !== $job->getMoneyAccount()->getId() || $plan['fileHash'] !== $job->getFileHash()
            || $plan['mappingHash'] !== hash('sha256', json_encode($job->getMapping(), \JSON_THROW_ON_ERROR))) {
            throw new \DomainException('Настройки импорта изменились.');
        }
        $actualHash = $this->importer->withJobFile($job, static fn (string $path): string => (string) hash_file('sha256', $path), false);
        if (!hash_equals($plan['contentHash'], $actualHash)) {
            throw new \DomainException('Файл изменился после предпросмотра. Загрузите файл заново.');
        }
        $options['description_content_hash'] = $plan['contentHash'];
        $job->setOptions($options);
        $selected = $options['selected_descriptions'] ?? [];
        if (!is_array($selected)) {
            throw new \DomainException('Некорректный выбор описаний.');
        }
        $finished = $options['description_finished'] ?? [];
        if (!is_array($finished)) {
            throw new \DomainException('Некорректный прогресс импорта.');
        }
        $pending = [];
        foreach ($selected as $rowId) {
            if (!is_string($rowId) || !isset($plan['rows'][$rowId]) || 'update_description' !== $plan['rows'][$rowId]['action']) {
                throw new \DomainException('Выбрана недоступная строка.');
            }
            if (!isset($finished[$rowId])) {
                $pending[$rowId] = $plan['rows'][$rowId];
            }
        }
        foreach (array_chunk($pending, 100, true) as $chunk) {
            $previouslyManaged = [];
            foreach ($this->em->getUnitOfWork()->getIdentityMap() as $entities) {
                foreach ($entities as $entity) {
                    $previouslyManaged[spl_object_id($entity)] = true;
                }
            }
            $results = $this->em->wrapInTransaction(function () use ($job, $chunk, $plan, $finished): array {
                $ids = [];
                foreach ($chunk as $row) {
                    if (null !== $row['transactionId']) {
                        $ids[] = $row['transactionId'];
                    }
                }
                $locked = [];
                foreach ($this->transactions->findForDescriptionUpdate((string) $job->getCompany()->getId(), $plan['accountId'], $ids) as $transaction) {
                    $locked[(string) $transaction->getId()] = $transaction;
                }
                $results = [];
                foreach ($chunk as $rowId => $row) {
                    $transaction = null === $row['transactionId'] ? null : ($locked[$row['transactionId']] ?? null);
                    $results[$rowId] = 'conflict';
                    if (!$transaction instanceof CashTransaction || null !== $transaction->getDeletedAt()
                        || CashFileDescriptionPlan::snapshot($transaction) !== $row['snapshot']) {
                        continue;
                    }
                    $description = $row['input']['description'];
                    if (null === $description || '' === trim($description)) {
                        continue;
                    }
                    $before = $transaction->getDescription();
                    $transaction->overrideImportedDescription($description);
                    $transaction->setUpdatedAt(new \DateTimeImmutable());
                    $audit = new AuditLog(
                        (string) $job->getCompany()->getId(), CashTransaction::class,
                        (string) $transaction->getId(), AuditLogAction::UPDATE,
                        ['description' => [$before, $description]], $plan['actor'],
                    );
                    $this->em->persist($audit);
                    $results[$rowId] = 'updated';
                }
                $current = $job->getOptions();
                $current['description_finished'] = $finished + $results;
                $job->setOptions($current);
                // One flush commits descriptions, explicit audit and progress per chunk.
                $this->guard->suppress(fn () => $this->em->flush());

                return $results;
            });
            $finished += $results;
            // Retain the caller's managed graph (job/company/account). Release the
            // whole graph loaded by this chunk, including splits/documents: detaching
            // only its transaction would leave managed children referencing it.
            foreach ($this->em->getUnitOfWork()->getIdentityMap() as $entities) {
                foreach ($entities as $entity) {
                    if (!isset($previouslyManaged[spl_object_id($entity)])) {
                        $this->em->detach($entity);
                    }
                }
            }
        }
        // Re-plan creation against the current DB; concurrent imports since preview
        // must never turn a stale create into another transaction or an implicit update.
        $fresh = $this->importer->withJobFile($job, fn (string $path): array => $this->plans->build($path, $job->getMoneyAccount(), $job->getMapping(), $job->getFileHash(), $plan['actor']));
        $allowed = [];
        $creationConflicts = [];
        $alreadyPresent = 0;
        $summary = array_fill_keys(['create', 'unchanged', 'update_description', 'conflict', 'invalid'], 0);
        foreach ($plan['rows'] as $id => $row) {
            ++$summary[$row['action']];
            if ('create' === $row['action'] && $row['input'] !== ($fresh['rows'][$id]['input'] ?? null)) {
                $creationConflicts[$id] = 'Данные строки изменились после предпросмотра';
                continue;
            }
            if ('create' === $row['action'] && 'create' === ($fresh['rows'][$id]['action'] ?? null)) {
                $allowed[(int) substr($id, 1)] = $row['input'];
            } elseif ('create' === $row['action'] && 'unchanged' === ($fresh['rows'][$id]['action'] ?? null)) {
                ++$alreadyPresent;
            } elseif ('create' === $row['action'] && !in_array($fresh['rows'][$id]['action'] ?? '', ['unchanged', 'create'], true)) {
                $creationConflicts[$id] = 'Операция изменилась после предпросмотра';
            }
        }
        $current = $job->getOptions();
        $job->getImportLog()?->setErrorsCount($summary['invalid']);
        $job->getImportLog()?->setSkippedDuplicates($summary['unchanged'] + $alreadyPresent);
        $current['description_summary'] = $summary;
        $current['description_skipped_by_user'] = $summary['update_description'] - count(array_unique($selected));
        $current['creation_conflicts'] = $creationConflicts;
        $current['creation_already_present'] = $alreadyPresent;
        $job->setOptions($current);
        $this->em->wrapInTransaction(fn () => $this->em->flush());

        return $allowed;
    }
}

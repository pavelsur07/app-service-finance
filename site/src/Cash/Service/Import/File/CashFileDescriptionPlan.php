<?php

declare(strict_types=1);

namespace App\Cash\Service\Import\File;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Ramsey\Uuid\Uuid;

/**
 * @phpstan-type InputRow array{rowId: string, date: string, amount: string, direction: string, currency: string, docNumber: ?string, description: ?string, counterparty: ?string, ok: bool, errors: list<string>}
 *
 * @phpstan-import-type ImportSnapshot from CashTransactionRepository as Snapshot
 *
 * @phpstan-type PlanRow array{input: InputRow, action: string, reason: string, transactionId: ?string, oldDescription: ?string, snapshot: ?Snapshot}
 * @phpstan-type Plan array{version: int, id: string, companyId: string, accountId: string, actor: ?string, fileHash: string, mappingHash: string, contentHash: string, rows: array<string, PlanRow>}
 */
final readonly class CashFileDescriptionPlan
{
    public function __construct(
        private CashFileImportService $reader,
        private CashFileDescriptionMatcher $matcher,
        private CashTransactionRepository $transactions,
        private ObjectStorageInterface $storage,
        private \App\Company\Domain\Service\CounterpartyNameNormalizer $counterpartyNames,
    ) {
    }

    /**
     * @param array<string, mixed> $mapping
     *
     * @return Plan
     */
    public function build(string $path, MoneyAccount $account, array $mapping, string $fileHash, ?string $actor): array
    {
        if ('xls' === strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            // Preserve the existing legacy converter; no new format implementation.
            $plan = $this->reader->withReadablePath($path, fn (string $readablePath): array => $this->build($readablePath, $account, $mapping, $fileHash, $actor));
            $plan['contentHash'] = (string) hash_file('sha256', $path);

            return $plan;
        }
        $rows = [];
        foreach ($this->reader->normalizedRows($path, $mapping, $account->getCurrency()) as $item) {
            $rows[] = CashFileImportService::planInput($item['normalized'], $item['rowNumber']);
        }
        $dates = array_column(array_filter($rows, static fn (array $r): bool => $r['ok']), 'date');
        $snapshots = [];
        if ([] !== $dates) {
            foreach ($this->transactions->findImportCandidates(
                (string) $account->getCompany()->getId(), (string) $account->getId(),
                new \DateTimeImmutable(min($dates), new \DateTimeZone('UTC')),
                (new \DateTimeImmutable(max($dates), new \DateTimeZone('UTC')))->modify('+1 day'),
            ) as $snapshot) {
                $snapshots[$snapshot['id']] = $snapshot;
            }
        }
        $matchingRows = $rows;
        $matchingCandidates = array_values($snapshots);
        foreach ($matchingRows as &$matchingRow) {
            $matchingRow['counterparty'] = $this->counterpartyKey($matchingRow['counterparty']);
        }
        unset($matchingRow);
        foreach ($matchingCandidates as &$matchingCandidate) {
            $matchingCandidate['counterparty'] = $this->counterpartyKey($matchingCandidate['counterparty']);
        }
        unset($matchingCandidate);
        $matches = $this->matcher->plan($matchingRows, $matchingCandidates);
        $planRows = [];
        foreach ($rows as $row) {
            $match = $matches[$row['rowId']];
            $planRows[$row['rowId']] = ['input' => $row, 'action' => $match['action'], 'reason' => $match['reason'], 'transactionId' => $match['transactionId'], 'oldDescription' => $match['oldDescription'], 'snapshot' => null === $match['transactionId'] ? null : $snapshots[$match['transactionId']]];
        }

        return [
            'version' => 1, 'id' => Uuid::uuid7()->toString(), 'companyId' => (string) $account->getCompany()->getId(),
            'accountId' => (string) $account->getId(), 'actor' => $actor, 'fileHash' => $fileHash,
            'mappingHash' => hash('sha256', json_encode($mapping, \JSON_THROW_ON_ERROR)), 'contentHash' => (string) hash_file('sha256', $path), 'rows' => $planRows,
        ];
    }

    private function counterpartyKey(?string $name): ?string
    {
        if (null === $name || '' === trim($name)) {
            return null;
        }
        $normalized = $this->counterpartyNames->normalize($name);

        return $normalized->core.'|'.($normalized->legalFormHint ?? '');
    }

    /** @return Snapshot */
    public static function snapshot(CashTransaction $t): array
    {
        return [
            'id' => (string) $t->getId(), 'date' => $t->getOccurredAt()->format('Y-m-d'),
            'amount' => $t->getAmount(), 'direction' => $t->getDirection()->value, 'currency' => $t->getCurrency(),
            'docNumber' => $t->getDocNumber(), 'description' => $t->getDescription(),
            'counterparty' => $t->getCounterparty()?->getName(), 'occurredAt' => $t->getOccurredAt()->format('Y-m-d'),
            'counterpartyId' => $t->getCounterparty()?->getId(), 'importSource' => $t->getImportSource(),
        ];
    }

    /** @param Plan $plan */
    public function save(array $plan): string
    {
        try {
            $json = json_encode($plan, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            throw new \DomainException('План содержит некорректный текст. Проверьте кодировку UTF-8.', previous: $exception);
        }
        $hash = hash('sha256', $json);
        try {
            $this->storage->write('cash-file-plans/'.$plan['companyId'].'/'.$hash.'.json', $json);
        } catch (\RuntimeException $exception) {
            throw new \App\Shared\Service\Storage\ObjectStorageException('Не удалось сохранить план импорта.', previous: $exception);
        }

        return $hash;
    }

    /** @return Plan */
    public function load(string $companyId, string $hash): array
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new \DomainException('Некорректный план импорта.');
        }
        $json = $this->storage->read('cash-file-plans/'.$companyId.'/'.$hash.'.json');
        if (!hash_equals($hash, hash('sha256', $json))) {
            throw new \DomainException('План импорта повреждён.');
        }
        /** @var Plan $plan */
        $plan = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (1 !== $plan['version'] || $plan['companyId'] !== $companyId) {
            throw new \DomainException('План импорта устарел.');
        }

        return $plan;
    }
}

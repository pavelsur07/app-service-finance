<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\Import\File;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Repository\Transaction\CashTransactionRepository;

final readonly class CashFileDuplicatePlanner
{
    public function __construct(private CashFileNormalizedRows $rows, private CashTransactionRepository $cashTransactionRepository)
    {
    }

    /**
     * Дубль считается по количеству: каждая транзакция, бывшая в БД до импорта, закрывает не
     * больше одной строки файла. Совпадения распределяются по всему файлу до записи, поэтому
     * результат не зависит ни от порядка строк, ни от нарезки на пачки, ни от уже закоммиченных
     * пачек самого импорта: повтор файла ничего не создаёт, прерванный импорт дописывает
     * недостающее.
     *
     * Номер документа есть не у всех источников (файлы без колонки номера, выписки 1С с номером),
     * поэтому пустой номер совпадает с любым. Для каждого dedupe_hash: сначала строки с номером
     * закрывают транзакции того же номера; оставшиеся строки с номером — транзакции без номера
     * (у них нет другого варианта); строки без номера — всё, что осталось. Такое распределение
     * даёт максимум совпадений. Параллельные задания одного счёта сериализует
     * CashFileImportHandler — иначе снимки обоих были бы одинаковыми.
     *
     * @return array<string, array<string, int>>
     */
    public function plan(string $filePath, CashFileImportJob $job): array
    {
        $companyId = (string) $job->getCompany()->getId();
        $accountId = (string) $job->getMoneyAccount()->getId();

        /** @var array<string, array<string, int>> $fileCounts */
        $fileCounts = [];
        foreach ($this->rows->iterate($filePath, $job->getMapping(), $job->getMoneyAccount()->getCurrency()) as $item) {
            $normalized = $item['normalized'];
            if (false === $normalized['ok'] || !$normalized['occurredAt'] || !$normalized['direction'] || !$normalized['amount']) {
                continue;
            }

            $hash = $this->hash(
                $companyId,
                $accountId,
                $normalized['occurredAt']->setTimezone(new \DateTimeZone('UTC')),
                (int) str_replace('.', '', $normalized['amount']),
                $normalized['description'] ?? '',
            );
            $doc = trim((string) $normalized['docNumber']);
            $fileCounts[$hash][$doc] = ($fileCounts[$hash][$doc] ?? 0) + 1;
        }

        $quota = [];
        foreach ($fileCounts as $hash => $fileDocs) {
            $quota[$hash] = $this->allocateExistingMatches(
                $fileDocs,
                $this->cashTransactionRepository->countActiveByCompanyDedupeGroupedByDocNumber($companyId, $hash),
            );
        }

        return $quota;
    }

    /**
     * @param array<string, int> $fileDocs номер документа → строк в файле
     * @param array<string, int> $existing номер документа → транзакций в БД
     *
     * @return array<string, int> номер документа → сколько строк файла уже импортированы
     */
    private function allocateExistingMatches(array $fileDocs, array $existing): array
    {
        $matched = [];
        $unmatchedNumbered = [];
        foreach ($fileDocs as $doc => $count) {
            $doc = (string) $doc;
            if ('' === $doc) {
                continue;
            }
            $exact = min($count, $existing[$doc] ?? 0);
            $matched[$doc] = $exact;
            $existing[$doc] = ($existing[$doc] ?? 0) - $exact;
            $unmatchedNumbered[$doc] = $count - $exact;
        }

        ksort($unmatchedNumbered, \SORT_STRING);
        $blank = $existing[''] ?? 0;
        foreach ($unmatchedNumbered as $doc => $count) {
            $viaBlank = min($count, $blank);
            $matched[(string) $doc] += $viaBlank;
            $blank -= $viaBlank;
        }
        $existing[''] = $blank;

        if (isset($fileDocs[''])) {
            $matched[''] = min($fileDocs[''], array_sum($existing));
        }

        return $matched;
    }

    private function normalizePurposeForDedupe(?string $value): string
    {
        $value = (string) $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[\(\)\[\]\{\}]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value;
    }

    public function hash(
        string $companyId,
        string $moneyAccountId,
        \DateTimeImmutable $occurredAtUtc,
        int $amountMinor,
        string $purposeRaw,
    ): string {
        $payload = $companyId
            .'|'.$moneyAccountId
            .'|'.$occurredAtUtc->format('Y-m-d')
            .'|'.$amountMinor
            .'|'.$this->normalizePurposeForDedupe($purposeRaw);

        return hash('sha256', $payload);
    }
}

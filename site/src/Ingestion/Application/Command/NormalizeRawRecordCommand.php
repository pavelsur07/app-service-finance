<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Command;

use Webmozart\Assert\Assert;

final readonly class NormalizeRawRecordCommand
{
    /**
     * `restrictToDates` ограничивает разбор днями, которыми снапшот владеет.
     *
     * Снапшот accrual-by-day покрывает окно в несколько дней, но авторитетом
     * является лишь для части из них: на остальные есть более свежий снапшот.
     * Реплей без ограничения пишет строки за всё окно, и на чужих днях рядом с
     * актуальной строкой появляется вторая — суммы удваиваются, а prune их не
     * убирает, потому что сносит перекрытых, а не перекрывающих.
     *
     * Пустой список — без ограничения: так работает обычная нормализация, где
     * свежий снапшот законно владеет всем своим окном.
     *
     * @param list<string> $restrictToDates дни в формате Y-m-d
     */
    public function __construct(
        public string $rawRecordId,
        public string $companyId,
        public bool $forceReplay = false,
        public array $restrictToDates = [],
    ) {
        Assert::uuid($this->rawRecordId);
        Assert::uuid($this->companyId);
        Assert::allRegex($this->restrictToDates, '/^\d{4}-\d{2}-\d{2}$/');
    }
}

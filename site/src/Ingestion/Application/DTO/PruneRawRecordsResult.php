<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Итог одного прогона удаления устаревшего сырья.
 *
 * `deleted` и `bytesFreed` отвечают на разные вопросы: сколько записей ушло и
 * сколько места это освободило. Одна большая выгрузка весит как тысяча мелких,
 * и по числу записей объём не восстановить.
 *
 * `candidateBytes` — сколько весят ОТОБРАННЫЕ записи, независимо от того,
 * удаляем мы их или только считаем. Ради этого числа dry-run и существует:
 * решение включать удаление принимается по объёму, а не по числу строк.
 *
 * `heldByIssues` — записи, которые удалять нельзя, потому что они служат
 * доказательством для НЕРАЗОБРАННОЙ проблемы. Без отдельного счётчика «нечего
 * удалять» и «удалять нельзя» выглядели бы одинаково, хотя реакция на них
 * разная: во втором случае нужно разобрать очередь.
 *
 * `orphanedObjects` — строка удалена, а объект в хранилище удалить не вышло.
 * Порядок именно такой намеренно: висячий указатель ломает чтение, а
 * осиротевший объект лишь занимает место — и путь к нему уходит в лог, чтобы
 * его можно было убрать вручную.
 */
final readonly class PruneRawRecordsResult
{
    public function __construct(
        public int $candidates = 0,
        public int $candidateBytes = 0,
        public int $deleted = 0,
        public int $bytesFreed = 0,
        public int $heldByIssues = 0,
        public int $orphanedObjects = 0,
    ) {
    }

    public function with(
        int $candidates = 0,
        int $candidateBytes = 0,
        int $deleted = 0,
        int $bytesFreed = 0,
        int $heldByIssues = 0,
        int $orphanedObjects = 0,
    ): self {
        return new self(
            $this->candidates + $candidates,
            $this->candidateBytes + $candidateBytes,
            $this->deleted + $deleted,
            $this->bytesFreed + $bytesFreed,
            $this->heldByIssues + $heldByIssues,
            $this->orphanedObjects + $orphanedObjects,
        );
    }
}

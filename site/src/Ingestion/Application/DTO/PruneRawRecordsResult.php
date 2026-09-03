<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Итог одного прогона удаления устаревшего сырья.
 *
 * `prunedPayloads` и `bytesFreed` отвечают на разные вопросы: у скольких
 * записей принято решение об очистке и сколько места удаление реально
 * освободило. Сами записи остаются — удаляется только объект в хранилище.
 *
 * `bytesFreed` считает лишь те объекты, которые к моменту удаления ЕЩЁ БЫЛИ на
 * месте. Повтор после сбоя коммита подтверждает отсутствие, а не освобождает
 * что-то ещё, и отчитываться о том же объёме дважды было бы враньём. Одна большая выгрузка весит как тысяча мелких,
 * и по числу записей объём не восстановить.
 *
 * `candidateBytes` — сколько весят ОТОБРАННЫЕ записи, независимо от того,
 * удаляем мы их или только считаем. Ради этого числа dry-run и существует:
 * решение включать удаление принимается по объёму, а не по числу строк.
 *
 * `pendingRetries` и `pendingBytes` — незавершённая работа прошлых прогонов:
 * решение закоммичено, объект ещё на месте. Она обслуживается ПЕРВОЙ и из
 * ОБЩЕГО с новыми кандидатами бюджета, поэтому dry-run обязан показывать её
 * отдельным числом. Без него прогноз врал бы дважды: он не упоминал бы работу,
 * которую execute сделает в первую очередь, и завышал бы число новых
 * кандидатов, до которых бюджет может не дойти вовсе.
 *
 * `heldByIssues` — записи, которые удалять нельзя, потому что они служат
 * доказательством для НЕРАЗОБРАННОЙ проблемы. Без отдельного счётчика «нечего
 * удалять» и «удалять нельзя» выглядели бы одинаково, хотя реакция на них
 * разная: во втором случае нужно разобрать очередь.
 *
 * `orphanedObjects` — запись помечена, а объект в хранилище удалить не вышло.
 * Порядок именно такой намеренно: запись, утверждающая, что нагрузка на месте,
 * ломает чтение, а осиротевший объект лишь занимает место — и путь к нему
 * уходит в лог, чтобы его можно было убрать вручную.
 */
final readonly class PruneRawRecordsResult
{
    public function __construct(
        public int $candidates = 0,
        public int $candidateBytes = 0,
        public int $pendingRetries = 0,
        public int $pendingBytes = 0,
        public int $prunedPayloads = 0,
        public int $bytesFreed = 0,
        public int $heldByIssues = 0,
        public int $orphanedObjects = 0,
    ) {
    }

    public function with(
        int $candidates = 0,
        int $candidateBytes = 0,
        int $pendingRetries = 0,
        int $pendingBytes = 0,
        int $prunedPayloads = 0,
        int $bytesFreed = 0,
        int $heldByIssues = 0,
        int $orphanedObjects = 0,
    ): self {
        return new self(
            $this->candidates + $candidates,
            $this->candidateBytes + $candidateBytes,
            $this->pendingRetries + $pendingRetries,
            $this->pendingBytes + $pendingBytes,
            $this->prunedPayloads + $prunedPayloads,
            $this->bytesFreed + $bytesFreed,
            $this->heldByIssues + $heldByIssues,
            $this->orphanedObjects + $orphanedObjects,
        );
    }
}

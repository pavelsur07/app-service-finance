<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Результат одного step run по raw-документу.
 *
 * `preservedLinkedRows > 0` — частичная переобработка: generated rows, уже
 * привязанные к финансовому документу, сохранены и не перезаписаны.
 * Это штатный успех шага, а не ошибка.
 */
final readonly class ProcessRawDocumentResult
{
    public function __construct(
        public int $processedRows,
        public int $preservedLinkedRows = 0,
    ) {
    }
}

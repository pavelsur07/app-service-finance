<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

/** Пакет принятия предложений для UI (moysklad_mapping_bulk_jobs). */
final readonly class MappingBulkJob
{
    public function __construct(
        public string $id, public string $origin, public string $scope, public string $status,
        public ?int $total, public int $processed, public int $accepted, public int $skipped, public int $conflicts, public int $failed,
        public \DateTimeImmutable $createdAt, public ?\DateTimeImmutable $finishedAt,
        public ?string $undoStatus, public int $undone, public int $undoSkipped,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $utc = new \DateTimeZone('UTC');
        $date = static fn (?string $value): ?\DateTimeImmutable => null === $value ? null : new \DateTimeImmutable($value, $utc);

        return new self((string) $row['id'], (string) $row['origin'], (string) $row['scope'], (string) $row['status'],
            null === $row['total'] ? null : (int) $row['total'], (int) $row['processed'], (int) $row['accepted'], (int) $row['skipped'],
            (int) $row['conflicts'], (int) $row['failed'], $date((string) $row['created_at']) ?? new \DateTimeImmutable(), $date($row['finished_at']),
            $row['undo_status'], (int) $row['undone'], (int) $row['undo_skipped']);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true) || in_array($this->undoStatus, ['queued', 'running'], true);
    }

    /** Отменить можно завершённый пакет с принятыми строками, если отмены ещё не было. */
    public function isUndoable(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true) && null === $this->undoStatus && $this->accepted > 0;
    }
}

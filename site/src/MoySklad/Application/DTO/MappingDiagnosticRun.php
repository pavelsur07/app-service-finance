<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

final readonly class MappingDiagnosticRun
{
    /** @param array<string,int> $counts */
    public function __construct(public string $id, public string $status, public int $processed, public int $total, public array $counts, public \DateTimeImmutable $requestedAt, public ?\DateTimeImmutable $checkedAt, public ?string $errorReason, public string $inputFingerprint, public ?string $sizeCharacteristicId)
    {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self((string) $row['id'], (string) $row['status'], (int) $row['processed'], (int) $row['total'], json_decode((string) $row['counts'], true, 512, \JSON_THROW_ON_ERROR),
            new \DateTimeImmutable((string) $row['requested_at'], new \DateTimeZone('UTC')), null === $row['finished_at'] ? null : new \DateTimeImmutable((string) $row['finished_at'], new \DateTimeZone('UTC')), $row['error_reason'], (string) $row['input_fingerprint'], $row['size_characteristic_id']);
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'running' => 'Расчёт выполняется', 'completed' => 'Проверка завершена', 'stale' => 'Данные изменились во время расчёта', 'failed' => 'Техническая ошибка расчёта', default => 'Ожидает расчёта',
        };
    }
}

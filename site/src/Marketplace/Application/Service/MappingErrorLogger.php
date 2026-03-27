<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Entity\MappingError;
use App\Marketplace\Repository\MappingErrorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Логирует неизвестные затраты маркетплейса.
 *
 * Вызывается из OzonCostsRawProcessor когда service_name
 * не найден в OzonServiceCategoryMap.
 *
 * Upsert: если запись за (company, marketplace, year, month, service_name)
 * уже существует — обновляет сумму и счётчик строк.
 * Если нет — создаёт новую.
 *
 * flush() НЕ вызывается — ответственность процессора.
 */
final class MappingErrorLogger
{
    /** @var array<string, bool> — in-memory дедупликация в рамках одного батча */
    private array $seenInBatch = [];

    public function __construct(
        private readonly MappingErrorRepository  $repository,
        private readonly EntityManagerInterface  $em,
    ) {
    }

    /**
     * Зафиксировать неизвестную затрату.
     *
     * @param array<string, mixed>|null $sampleRaw — образец сырой строки для диагностики
     */
    public function log(
        string $companyId,
        string $marketplace,
        int $year,
        int $month,
        string $serviceName,
        string $operationType,
        float $amount,
        ?array $sampleRaw = null,
    ): void {
        $existing = $this->repository->findForUpsert(
            $companyId, $marketplace, $year, $month, $serviceName,
        );

        if ($existing !== null) {
            $existing->incrementAmount(abs($amount));
            return;
        }

        // Дедупликация новых записей внутри батча (до flush)
        $key = implode('|', [$companyId, $marketplace, $year, $month, $serviceName]);
        if (isset($this->seenInBatch[$key])) {
            return;
        }
        $this->seenInBatch[$key] = true;

        $error = new MappingError(
            id:            Uuid::uuid7()->toString(),
            companyId:     $companyId,
            marketplace:   $marketplace,
            year:          $year,
            month:         $month,
            serviceName:   $serviceName,
            operationType: $operationType,
            totalAmount:   abs($amount),
            rowsCount:     1,
            sampleRawJson: $sampleRaw !== null ? $this->sanitizeSample($sampleRaw) : null,
        );

        $this->em->persist($error);
    }

    /**
     * Сбросить дедупликацию — вызывать после em->flush() в конце батча.
     */
    public function resetBatch(): void
    {
        $this->seenInBatch = [];
    }

    /**
     * Оставляем только диагностически полезные поля, убираем PII.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function sanitizeSample(array $raw): array
    {
        return [
            'operation_id'        => $raw['operation_id'] ?? null,
            'operation_type'      => $raw['operation_type'] ?? null,
            'operation_type_name' => $raw['operation_type_name'] ?? null,
            'type'                => $raw['type'] ?? null,
            'amount'              => $raw['amount'] ?? null,
            'services'            => array_map(
                static fn(array $s) => ['name' => $s['name'] ?? null, 'price' => $s['price'] ?? null],
                (array) ($raw['services'] ?? []),
            ),
        ];
    }
}

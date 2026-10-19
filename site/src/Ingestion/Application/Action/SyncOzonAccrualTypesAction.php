<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\DTO\OzonAccrualTypesSyncResult;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Entity\OzonAccrualType;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\NotOzonAccrualTypesRawRecordException;
use App\Ingestion\Exception\RawRecordNotFoundException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Ingestion\Repository\OzonAccrualTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Раскладывает версию справочника Ozon `accrual/types` из сырой записи в
 * таблицу `ingest_ozon_accrual_types`.
 *
 * Сырьё читается в скоупе компании, которой принадлежит запись, а таблица
 * общая: у всех кабинетов версия одна и та же, и четыре прогона за ночь
 * дают одно изменение и три пустых.
 *
 * Версия старше уже применённой (повтор сообщения, ручной прогон по старой
 * записи) только добавляет неизвестные типы: откатить название или пометить
 * тип пропавшим по ней нельзя. Версия без единой валидной строки не меняет
 * ничего — пустой ответ Ozon не должен «удалить» весь справочник; версия, где
 * часть строк не разобралась, пропавших не помечает по той же причине.
 *
 * Прогоны сериализуются advisory-блокировкой внутри транзакции: иначе два
 * воркера (или ручная команда рядом с воркером) вставили бы один новый тип
 * дважды, а прогон старой версии мог бы записать свои названия поверх новой.
 */
final readonly class SyncOzonAccrualTypesAction
{
    private const LOCK_KEY = 'ingest_ozon_accrual_types_sync';

    public function __construct(
        private IngestRawRecordRepository $rawRecordRepository,
        private RawStorageFacade $rawStorageFacade,
        private OzonAccrualTypeRepository $typeRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Сырая запись — версия справочника типов Ozon. Её синхронизация идёт и для
     * DONE-записи: повторно полученная прежняя версия дедуплицируется в неё.
     */
    public static function appliesTo(IngestRawRecord $rawRecord): bool
    {
        return IngestSource::OZON === $rawRecord->getSource()
            && OzonResourceType::ACCRUAL_TYPES === $rawRecord->getResourceType();
    }

    public function __invoke(string $companyId, string $rawRecordId, bool $dryRun = false): OzonAccrualTypesSyncResult
    {
        Assert::uuid($companyId);
        Assert::uuid($rawRecordId);

        $rawRecord = $this->rawRecordRepository->findByIdAndCompany($rawRecordId, $companyId);
        if (null === $rawRecord) {
            throw new RawRecordNotFoundException('Raw record not found for requested company.');
        }
        if (!self::appliesTo($rawRecord)) {
            throw new NotOzonAccrualTypesRawRecordException(sprintf('Raw record %s is not an Ozon accrual types dictionary.', $rawRecordId));
        }

        [$rows, $skippedRows] = $this->parseRows($this->rawStorageFacade->read($rawRecordId, $companyId));
        // Время версии — когда Ozon отдал это содержимое последний раз, а не когда
        // его впервые сохранили: одинаковое сырьё дедуплицируется по hash, и при
        // A → B → A вернувшаяся версия A иначе считалась бы старше B.
        $fetchedAt = $rawRecord->getLastSeenAt();

        if ($dryRun) {
            return $this->apply($companyId, $rawRecordId, $fetchedAt, $rows, $skippedRows, true);
        }

        /** @var OzonAccrualTypesSyncResult $result */
        $result = $this->entityManager->wrapInTransaction(function () use ($companyId, $rawRecordId, $fetchedAt, $rows, $skippedRows): OzonAccrualTypesSyncResult {
            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtextextended(:lockKey, 0))',
                ['lockKey' => self::LOCK_KEY],
            );

            return $this->apply($companyId, $rawRecordId, $fetchedAt, $rows, $skippedRows, false);
        });

        return $result;
    }

    /**
     * @param array<int, array{0: string, 1: ?string}> $rows
     */
    private function apply(
        string $companyId,
        string $rawRecordId,
        \DateTimeImmutable $fetchedAt,
        array $rows,
        int $skippedRows,
        bool $dryRun,
    ): OzonAccrualTypesSyncResult {
        if ([] === $rows) {
            $this->logger->warning('Ozon accrual types version has no valid rows; dictionary left unchanged.', [
                'companyId' => $companyId,
                'rawRecordId' => $rawRecordId,
                'skippedRows' => $skippedRows,
            ]);

            return new OzonAccrualTypesSyncResult(rawRecordId: $rawRecordId, skippedRows: $skippedRows, empty: true, dryRun: $dryRun);
        }

        $latestApplied = $this->typeRepository->findLatestVersionFetchedAt();
        $stale = null !== $latestApplied && $fetchedAt < $latestApplied;
        $existing = $this->typeRepository->findAllIndexedByTypeId();

        $created = [];
        $updated = [];
        $removed = [];
        $unchanged = 0;

        foreach ($rows as $typeId => [$name, $description]) {
            $type = $existing[$typeId] ?? null;
            if (null === $type) {
                $created[] = $typeId;
                if (!$dryRun) {
                    $type = new OzonAccrualType($typeId, $name, $description, $rawRecordId, $fetchedAt);
                    // В уже применённой, более новой версии этого типа нет —
                    // значит, Ozon его убрал; активным он появиться не должен.
                    if ($stale) {
                        $type->markRemoved($rawRecordId, $latestApplied);
                    }
                    $this->entityManager->persist($type);
                }
                continue;
            }

            if ($stale || $fetchedAt < $type->getVersionFetchedAt()) {
                ++$unchanged;
                continue;
            }

            $differs = $this->differs($type, $name, $description);
            if ($differs) {
                $updated[] = $typeId;
            } else {
                ++$unchanged;
            }
            if (!$dryRun) {
                $type->applyVersion($name, $description, $rawRecordId, $fetchedAt);
            }
        }

        if (!$stale && $skippedRows > 0) {
            $this->logger->warning('Ozon accrual types version has unparsed rows; missing types are not marked removed.', [
                'companyId' => $companyId,
                'rawRecordId' => $rawRecordId,
                'skippedRows' => $skippedRows,
            ]);
        }

        if (!$stale && 0 === $skippedRows) {
            foreach ($existing as $typeId => $type) {
                if (isset($rows[$typeId]) || $type->isRemoved() || $fetchedAt < $type->getVersionFetchedAt()) {
                    continue;
                }

                $removed[] = $typeId;
                if (!$dryRun) {
                    $type->markRemoved($rawRecordId, $fetchedAt);
                }
            }
        }

        $result = new OzonAccrualTypesSyncResult(
            rawRecordId: $rawRecordId,
            rows: count($rows),
            unchanged: $unchanged,
            skippedRows: $skippedRows,
            createdTypeIds: $created,
            updatedTypeIds: $updated,
            removedTypeIds: $removed,
            stale: $stale,
            dryRun: $dryRun,
        );

        if ($dryRun) {
            return $result;
        }

        $this->entityManager->flush();

        $this->logger->info('Ozon accrual types dictionary synced.', [
            'companyId' => $companyId,
            'rawRecordId' => $rawRecordId,
            'rows' => $result->rows,
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'unchanged' => $unchanged,
            'skippedRows' => $skippedRows,
            'stale' => $stale,
        ]);

        return $result;
    }

    /**
     * @param iterable<mixed> $rawRows
     *
     * @return array{0: array<int, array{0: string, 1: ?string}>, 1: int}
     */
    private function parseRows(iterable $rawRows): array
    {
        $rows = [];
        $skipped = 0;

        foreach ($rawRows as $row) {
            if (!is_array($row) || true === ($row['_ingestion_empty'] ?? false)) {
                continue;
            }

            $typeId = $this->typeId($row['id'] ?? $row['type_id'] ?? $row['typeId'] ?? null);
            $name = $this->stringValue($row['name'] ?? null);
            if (null === $typeId || null === $name || mb_strlen($name) > OzonAccrualType::NAME_MAX_LENGTH) {
                ++$skipped;
                continue;
            }

            $rows[$typeId] = [$name, $this->stringValue($row['description'] ?? null)];
        }

        ksort($rows);

        return [$rows, $skipped];
    }

    private function differs(OzonAccrualType $type, string $name, ?string $description): bool
    {
        $description = null === $description ? null : mb_substr($description, 0, OzonAccrualType::DESCRIPTION_MAX_LENGTH);

        return $type->getName() !== $name
            || $type->getDescription() !== $description
            || $type->isRemoved();
    }

    private function typeId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !ctype_digit(trim($value))) {
            return null;
        }

        $typeId = (int) trim($value);

        return $typeId > 0 ? $typeId : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' !== $value ? $value : null;
    }
}

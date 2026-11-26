<?php

declare(strict_types=1);

namespace App\OneC\Application;

use App\OneC\Exception\OneCApiException;
use Ramsey\Uuid\Uuid;

/**
 * Оболочка части пакета. Документы на этапе приёма не разбираются:
 * проверяется только, что это список не длиннее MAX_DOCUMENTS.
 */
final readonly class PackageEnvelope
{
    public const KIND_INVOICES = 'invoices';
    private const MAX_DOCUMENTS = 500;
    private const MAX_PARTS = 10000;
    /** Скобки и запятые вне строк: 500 документов по ~100 полей с запасом. */
    private const MAX_JSON_ELEMENTS = 200000;

    /** @param list<mixed> $warnings */
    private function __construct(
        public string $packageId,
        public string $baseId,
        public string $kind,
        public string $processorVersion,
        public string $periodFrom,
        public string $periodTo,
        public bool $includeOpenOutsidePeriod,
        public int $part,
        public int $partsTotal,
        public array $warnings,
        public int $documentCount,
    ) {
    }

    public static function parse(string $json): self
    {
        if (JsonComplexity::exceeds($json, self::MAX_JSON_ELEMENTS)) {
            throw new OneCApiException(413, 'payload_too_large', 'Слишком много элементов JSON в части.');
        }
        try {
            $data = json_decode($json, false, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalid('Некорректный JSON.');
        }
        if (!$data instanceof \stdClass) {
            throw self::invalid('Ожидается JSON-объект.');
        }
        if (self::KIND_INVOICES !== ($data->kind ?? null)) {
            throw self::invalid('Поддерживается только kind=invoices.');
        }
        $processorVersion = $data->processor_version ?? null;
        if (!\is_string($processorVersion) || \strlen($processorVersion) > 255 || !preg_match('/^\d+(?:\.\d+)*$/D', $processorVersion)) {
            throw self::invalid('Версия обработки должна состоять из чисел, разделённых точками.');
        }
        $from = self::date($data->period_from ?? null);
        $to = self::date($data->period_to ?? null);
        if ($from > $to) {
            throw self::invalid('Начало периода позже конца.');
        }
        $part = $data->part ?? null;
        $total = $data->parts_total ?? null;
        if (!\is_int($part) || !\is_int($total) || $part < 1 || $part > $total || $total > self::MAX_PARTS) {
            throw self::invalid('Номер части — целое от 1 до parts_total, частей не больше 10 000.');
        }
        if (!\is_bool($data->include_open_outside_period ?? null)) {
            throw self::invalid('include_open_outside_period — true или false.');
        }
        $warnings = $data->warnings ?? [];
        $documents = $data->documents ?? null;
        if (!\is_array($warnings) || !array_is_list($warnings) || !\is_array($documents) || !array_is_list($documents)) {
            throw self::invalid('warnings и documents — массивы.');
        }
        if (\count($documents) > self::MAX_DOCUMENTS) {
            throw self::invalid('В части не больше 500 документов.');
        }

        return new self(
            self::uuid($data->package_id ?? null),
            self::uuid($data->base_id ?? null),
            self::KIND_INVOICES,
            $processorVersion,
            $from,
            $to,
            $data->include_open_outside_period,
            $part,
            $total,
            self::warnings($warnings),
            \count($documents),
        );
    }

    /**
     * @param list<mixed> $warnings
     *
     * @return list<mixed>
     */
    private static function warnings(array $warnings): array
    {
        $json = json_encode($warnings, \JSON_THROW_ON_ERROR);
        // PostgreSQL JSONB не хранит U+0000.
        if (str_contains($json, '\u0000')) {
            throw self::invalid('Предупреждения содержат нулевой символ.');
        }

        return json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }

    private static function uuid(mixed $value): string
    {
        if (!\is_string($value) || !Uuid::isValid($value)) {
            throw self::invalid('Некорректный UUID пакета или базы.');
        }

        return Uuid::fromString($value)->toString();
    }

    private static function date(mixed $value): string
    {
        $date = \is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw self::invalid('Дата периода — в формате ГГГГ-ММ-ДД.');
        }

        return $value;
    }

    private static function invalid(string $message): OneCApiException
    {
        return new OneCApiException(422, 'invalid_package', $message);
    }
}

<?php

declare(strict_types=1);

namespace App\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Application\DTO\ServiceCoverageReport;
use App\ServiceGateway\Application\DTO\ServiceCoverageReportRow;
use App\ServiceGateway\Application\DTO\ServiceCoverageStatusCounts;
use App\ServiceGateway\Exception\ContractViolationException;

/**
 * Строгий разбор ответа по services/_contracts/service-coverage/v1/schema.json:
 * лишние и недостающие ключи, типы, форматы дат, `period_key`, счётчики ≥ 0.
 * Библиотеки JSON Schema в проекте нет, поэтому схема повторена здесь
 * вручную; при изменении схемы — новая версия контракта и новый парсер.
 *
 * Вход — JSON, декодированный объектами (`json_decode` без `associative`):
 * только так объект `{}` отличим от массива `[]`.
 *
 * Время приводится к часовому поясу приложения: БД хранит его без зоны.
 */
final readonly class ServiceCoverageV1Parser
{
    public const CONTRACT = 'service-coverage/v1';

    private const TOP_KEYS = ['schema_version', 'service', 'generated_at', 'from', 'to', 'cabinets_total', 'rows'];
    private const ROW_KEYS = ['resource_type', 'period_key', 'cabinets_expected', 'by_status', 'last_success_at'];
    private const STATUS_KEYS = ['done', 'running', 'pending', 'not_ready', 'failed_retryable', 'failed_permanent'];
    private const PERIOD_KEY = '/^(\d{4}-\d{2}-\d{2}|\d{4}-\d{2}|ref)$/D';

    public function parse(mixed $data): ServiceCoverageReport
    {
        $data = $this->object($data, '$', self::TOP_KEYS);
        if ('1' !== $data['schema_version']) {
            throw $this->violation('$.schema_version', 'must be "1"');
        }

        $rows = $data['rows'];
        if (!\is_array($rows)) {
            throw $this->violation('$.rows', 'must be an array');
        }
        $parsed = [];
        $seen = [];
        foreach ($rows as $i => $row) {
            $path = sprintf('$.rows[%d]', $i);
            $item = $this->row($row, $path);
            $key = $item->resourceType."\0".$item->periodKey;
            if (isset($seen[$key])) {
                throw $this->violation($path, 'duplicate resource_type and period_key');
            }
            $seen[$key] = true;
            $parsed[] = $item;
        }

        return new ServiceCoverageReport(
            service: $this->nonEmptyString($data['service'], '$.service'),
            generatedAt: $this->dateTime($data['generated_at'], '$.generated_at'),
            periodFrom: $this->date($data['from'], '$.from'),
            periodTo: $this->date($data['to'], '$.to'),
            cabinetsTotal: $this->count($data['cabinets_total'], '$.cabinets_total'),
            rows: $parsed,
        );
    }

    private function row(mixed $row, string $path): ServiceCoverageReportRow
    {
        $row = $this->object($row, $path, self::ROW_KEYS);
        $periodKey = $this->nonEmptyString($row['period_key'], $path.'.period_key');
        if (1 !== preg_match(self::PERIOD_KEY, $periodKey)) {
            throw $this->violation($path.'.period_key', 'must be YYYY-MM-DD, YYYY-MM or ref');
        }

        $status = $this->object($row['by_status'], $path.'.by_status', self::STATUS_KEYS);
        $counts = [];
        foreach (self::STATUS_KEYS as $key) {
            $counts[$key] = $this->count($status[$key], $path.'.by_status.'.$key);
        }

        return new ServiceCoverageReportRow(
            resourceType: $this->nonEmptyString($row['resource_type'], $path.'.resource_type'),
            periodKey: $periodKey,
            cabinetsExpected: $this->count($row['cabinets_expected'], $path.'.cabinets_expected'),
            byStatus: new ServiceCoverageStatusCounts(
                done: $counts['done'],
                running: $counts['running'],
                pending: $counts['pending'],
                notReady: $counts['not_ready'],
                failedRetryable: $counts['failed_retryable'],
                failedPermanent: $counts['failed_permanent'],
            ),
            lastSuccessAt: null === $row['last_success_at'] ? null : $this->dateTime($row['last_success_at'], $path.'.last_success_at'),
        );
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $path, array $keys): array
    {
        if (!$value instanceof \stdClass) {
            throw $this->violation($path, 'must be an object');
        }
        $value = get_object_vars($value);
        $actual = array_map('strval', array_keys($value));
        $missing = array_diff($keys, $actual);
        if ([] !== $missing) {
            throw $this->violation($path, 'missing '.implode(', ', $missing));
        }
        $extra = array_diff($actual, $keys);
        if ([] !== $extra) {
            throw $this->violation($path, 'unexpected '.implode(', ', $extra));
        }

        /* @var array<string, mixed> $value */
        return $value;
    }

    private function nonEmptyString(mixed $value, string $path): string
    {
        if (!\is_string($value) || '' === $value) {
            throw $this->violation($path, 'must be a non-empty string');
        }

        return $value;
    }

    private function count(mixed $value, string $path): int
    {
        if (!\is_int($value) || $value < 0) {
            throw $this->violation($path, 'must be a non-negative integer');
        }

        return $value;
    }

    private function date(mixed $value, string $path): \DateTimeImmutable
    {
        $raw = $this->nonEmptyString($value, $path);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (false === $date || self::hasParseWarnings() || $date->format('Y-m-d') !== $raw) {
            throw $this->violation($path, 'must be a date YYYY-MM-DD');
        }

        return $date;
    }

    private function dateTime(mixed $value, string $path): \DateTimeImmutable
    {
        $raw = $this->nonEmptyString($value, $path);
        // createFromFormat нормализует 30 февраля в март и сообщает об этом
        // только предупреждением — такие значения контракт не допускает.
        $parsed = \DateTimeImmutable::createFromFormat(\DATE_RFC3339, $raw);
        if (false === $parsed || self::hasParseWarnings()) {
            $parsed = \DateTimeImmutable::createFromFormat(\DATE_RFC3339_EXTENDED, $raw);
        }
        if (false === $parsed || self::hasParseWarnings()) {
            throw $this->violation($path, 'must be an RFC 3339 date-time');
        }

        return $parsed->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private static function hasParseWarnings(): bool
    {
        $errors = \DateTimeImmutable::getLastErrors();

        return false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
    }

    private function violation(string $path, string $reason): ContractViolationException
    {
        return ContractViolationException::at(self::CONTRACT, $path, $reason);
    }
}

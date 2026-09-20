<?php

declare(strict_types=1);

namespace App\MoySklad\Application;

use App\MoySklad\Domain\CounterpartySnapshot;
use App\MoySklad\Exception\CounterpartySyncException;
use Ramsey\Uuid\Uuid;

final class CounterpartyPageParser
{
    /** @param array<string, mixed> $page
     * @return list<CounterpartySnapshot>
     */
    public function parse(array $page, string $accountId, bool $archived): array
    {
        if (!Uuid::isValid($accountId) || !isset($page['meta'], $page['rows']) || !is_array($page['meta']) || !is_array($page['rows']) || !array_is_list($page['rows'])) {
            throw new CounterpartySyncException('invalid_response');
        }

        $snapshots = [];
        foreach ($page['rows'] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !Uuid::isValid($row['id']) || !is_string($row['accountId'] ?? null) || !Uuid::isValid($row['accountId']) || strtolower($row['accountId']) !== strtolower($accountId) || !is_bool($row['archived'] ?? null) || $row['archived'] !== $archived) {
                throw new CounterpartySyncException('invalid_response');
            }

            $snapshots[] = new CounterpartySnapshot(
                strtolower($row['id']),
                $this->requiredString($row, 'name', 255),
                $this->requiredString($row, 'companyType', 64),
                $this->optionalString($row, 'legalTitle', 4096),
                $this->optionalString($row, 'inn', 255),
                $this->optionalString($row, 'kpp', 255),
                $this->optionalString($row, 'ogrn', 255),
                $this->optionalString($row, 'ogrnip', 255),
                $this->optionalString($row, 'legalAddress', 255),
                $archived,
                $this->parseUpdated($row['updated'] ?? null),
            );
        }

        return $snapshots;
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $key, int $maxLength): string
    {
        $value = $this->optionalString($row, $key, $maxLength);
        if (null === $value) {
            throw new CounterpartySyncException('invalid_response');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function optionalString(array $row, string $key, int $maxLength): ?string
    {
        if (!array_key_exists($key, $row) || null === $row[$key]) {
            return null;
        }
        if (!is_string($row[$key])) {
            throw new CounterpartySyncException('invalid_response');
        }
        $value = trim($row[$key]);
        if (mb_strlen($value) > $maxLength) {
            throw new CounterpartySyncException('invalid_response');
        }

        return '' === $value ? null : $value;
    }

    private function parseUpdated(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{3})?$/D', $value)) {
            throw new CounterpartySyncException('invalid_response');
        }
        $format = str_contains($value, '.') ? '!Y-m-d H:i:s.v' : '!Y-m-d H:i:s';
        $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('Europe/Moscow'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $date || false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new CounterpartySyncException('invalid_response');
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}

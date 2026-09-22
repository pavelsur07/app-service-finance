<?php

declare(strict_types=1);

namespace App\MoySklad\Application;

use App\MoySklad\Application\DTO\ParsedPage;
use App\MoySklad\Domain\StoreSnapshot;
use App\MoySklad\Exception\StockSyncException;
use Ramsey\Uuid\Uuid;

final class StorePageParser
{
    /** @param array<string, mixed> $page
     * @return ParsedPage<StoreSnapshot>
     */
    public function parse(array $page, string $accountId, bool $archived): ParsedPage
    {
        if (!Uuid::isValid($accountId)) {
            throw new StockSyncException('invalid_response');
        }

        [$size, $limit, $offset, $rows] = $this->page($page);
        $stores = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $meta = $row['meta'] ?? null;
            $href = is_array($meta) ? ($meta['href'] ?? null) : null;
            if (!is_string($id) || !Uuid::isValid($id)
                || !is_string($row['accountId'] ?? null) || strtolower($row['accountId']) !== strtolower($accountId)
                || !is_bool($row['archived'] ?? null) || $row['archived'] !== $archived
                || !is_array($meta) || 'store' !== ($meta['type'] ?? null) || !is_string($href)
                || 1 !== preg_match('~^https://api\.moysklad\.ru/api/remap/1\.2/entity/store/([0-9a-fA-F-]{36})$~D', $href, $match)
                || !Uuid::isValid($match[1]) || strtolower($match[1]) !== strtolower($id)) {
                throw new StockSyncException('invalid_response');
            }

            $stores[] = new StoreSnapshot(
                strtolower($id),
                $this->requiredString($row, 'name', 255, true),
                $this->requiredString($row, 'externalCode', 255),
                $this->optionalString($row, 'code', 255),
                $this->requiredString($row, 'pathName', 4096),
                $archived,
                $this->parseUpdated($row['updated'] ?? null),
            );
        }

        return new ParsedPage($size, $limit, $offset, $stores);
    }

    /** @param array<string, mixed> $page
     * @return array{int, int, int, list<array<string, mixed>>}
     */
    private function page(array $page): array
    {
        if (!isset($page['meta'], $page['rows']) || !is_array($page['meta']) || 'store' !== ($page['meta']['type'] ?? null)
            || !is_array($page['rows']) || !array_is_list($page['rows'])) {
            throw new StockSyncException('invalid_response');
        }
        $size = $page['meta']['size'] ?? null;
        $limit = $page['meta']['limit'] ?? null;
        $offset = $page['meta']['offset'] ?? null;
        if (!is_int($size) || $size < 0 || !is_int($limit) || $limit < 1 || $limit > 1000
            || !is_int($offset) || $offset < 0 || $offset > $size
            || count($page['rows']) !== max(0, min($limit, $size - $offset))) {
            throw new StockSyncException('invalid_response');
        }
        foreach ($page['rows'] as $row) {
            if (!is_array($row)) {
                throw new StockSyncException('invalid_response');
            }
        }

        return [$size, $limit, $offset, $page['rows']];
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $key, int $maxLength, bool $nonEmpty = false): string
    {
        if (!is_string($row[$key] ?? null)) {
            throw new StockSyncException('invalid_response');
        }
        $value = trim($row[$key]);
        if (mb_strlen($value) > $maxLength || ($nonEmpty && '' === $value)) {
            throw new StockSyncException('invalid_response');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function optionalString(array $row, string $key, int $maxLength): ?string
    {
        if (!array_key_exists($key, $row) || null === $row[$key]) {
            return null;
        }
        $value = $this->requiredString($row, $key, $maxLength);

        return '' === $value ? null : $value;
    }

    private function parseUpdated(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{3})?$/D', $value)) {
            throw new StockSyncException('invalid_response');
        }
        $format = str_contains($value, '.') ? '!Y-m-d H:i:s.v' : '!Y-m-d H:i:s';
        $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('Europe/Moscow'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $date || false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new StockSyncException('invalid_response');
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}

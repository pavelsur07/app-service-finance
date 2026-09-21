<?php

declare(strict_types=1);

namespace App\MoySklad\Application;

use App\MoySklad\Domain\ProductSnapshot;
use App\MoySklad\Domain\VariantSnapshot;
use App\MoySklad\Exception\CatalogSyncException;
use Ramsey\Uuid\Uuid;

final class CatalogPageParser
{
    /** @param array<string, mixed> $page
     * @return list<ProductSnapshot>
     */
    public function parseProducts(array $page, string $accountId, bool $archived): array
    {
        $this->assertAccountId($accountId);
        $snapshots = [];
        foreach ($this->rows($page) as $row) {
            $this->assertIdentity($row, $accountId, $archived, 'product');
            $variantsCount = $row['variantsCount'] ?? null;
            if (!is_int($variantsCount) || $variantsCount < 0) {
                throw new CatalogSyncException('invalid_response');
            }
            $snapshots[] = new ProductSnapshot(
                strtolower($row['id']),
                $this->requiredString($row, 'name', 255, true),
                $this->requiredString($row, 'externalCode', 255),
                $this->optionalString($row, 'code', 255),
                $this->optionalString($row, 'article', 255),
                $variantsCount,
                $archived,
                $this->parseUpdated($row['updated'] ?? null),
            );
        }

        return $snapshots;
    }

    /** @param array<string, mixed> $page
     * @return list<VariantSnapshot>
     */
    public function parseVariants(array $page, string $accountId, bool $archived): array
    {
        $this->assertAccountId($accountId);
        $snapshots = [];
        foreach ($this->rows($page) as $row) {
            $this->assertIdentity($row, $accountId, $archived, 'variant');
            $parentMeta = is_array($row['product'] ?? null) ? ($row['product']['meta'] ?? null) : null;
            $parentHref = is_array($parentMeta) ? ($parentMeta['href'] ?? null) : null;
            if (!is_array($parentMeta) || ($parentMeta['type'] ?? null) !== 'product' || !is_string($parentHref)
                || 1 !== preg_match('~^https://api\.moysklad\.ru/api/remap/1\.2/entity/product/([0-9a-fA-F-]{36})$~D', $parentHref, $match)
                || !Uuid::isValid($match[1])) {
                throw new CatalogSyncException('invalid_response');
            }
            $rawCharacteristics = $row['characteristics'] ?? null;
            if (!is_array($rawCharacteristics) || !array_is_list($rawCharacteristics)) {
                throw new CatalogSyncException('invalid_response');
            }
            $characteristics = [];
            foreach ($rawCharacteristics as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null) || !Uuid::isValid($item['id'])) {
                    throw new CatalogSyncException('invalid_response');
                }
                $characteristics[] = [
                    'id' => strtolower($item['id']),
                    'name' => $this->requiredString($item, 'name', 255, true),
                    'value' => $this->requiredString($item, 'value', 255),
                ];
            }
            $snapshots[] = new VariantSnapshot(
                strtolower($row['id']),
                strtolower($match[1]),
                $this->requiredString($row, 'name', 255, true),
                $this->requiredString($row, 'externalCode', 255),
                $this->optionalString($row, 'code', 255),
                $this->optionalString($row, 'article', 255),
                $characteristics,
                $archived,
                $this->parseUpdated($row['updated'] ?? null),
            );
        }

        return $snapshots;
    }

    /** @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private function rows(array $page): array
    {
        if (!isset($page['meta'], $page['rows']) || !is_array($page['meta']) || !is_array($page['rows']) || !array_is_list($page['rows'])) {
            throw new CatalogSyncException('invalid_response');
        }
        foreach ($page['rows'] as $row) {
            if (!is_array($row)) {
                throw new CatalogSyncException('invalid_response');
            }
        }

        return $page['rows'];
    }

    /** @param array<string, mixed> $row */
    private function assertIdentity(array $row, string $accountId, bool $archived, string $type): void
    {
        $meta = $row['meta'] ?? null;
        if (!is_string($row['id'] ?? null) || !Uuid::isValid($row['id'])
            || !is_string($row['accountId'] ?? null) || strtolower($row['accountId']) !== strtolower($accountId)
            || !is_bool($row['archived'] ?? null) || $row['archived'] !== $archived
            || !is_array($meta) || ($meta['type'] ?? null) !== $type) {
            throw new CatalogSyncException('invalid_response');
        }
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $key, int $maxLength, bool $nonEmpty = false): string
    {
        if (!is_string($row[$key] ?? null)) {
            throw new CatalogSyncException('invalid_response');
        }
        $value = trim($row[$key]);
        if (mb_strlen($value) > $maxLength || ($nonEmpty && '' === $value)) {
            throw new CatalogSyncException('invalid_response');
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

    private function assertAccountId(string $accountId): void
    {
        if (!Uuid::isValid($accountId)) {
            throw new CatalogSyncException('invalid_response');
        }
    }

    private function parseUpdated(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{3})?$/D', $value)) {
            throw new CatalogSyncException('invalid_response');
        }
        $format = str_contains($value, '.') ? '!Y-m-d H:i:s.v' : '!Y-m-d H:i:s';
        $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('Europe/Moscow'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $date || false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new CatalogSyncException('invalid_response');
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}

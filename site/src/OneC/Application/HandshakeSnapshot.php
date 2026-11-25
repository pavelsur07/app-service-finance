<?php

declare(strict_types=1);

namespace App\OneC\Application;

use Ramsey\Uuid\Uuid;

final readonly class HandshakeSnapshot
{
    /**
     * @param array<string, string> $versions
     * @param list<array{guid: string, name: string, inn: ?string, kpp: ?string}> $organizations
     */
    private function __construct(public string $baseId, public array $versions, public array $organizations)
    {
    }

    public static function parse(string $json): self
    {
        $data = json_decode($json, false, 64, \JSON_THROW_ON_ERROR);
        if (!$data instanceof \stdClass || !\is_array($data->organizations ?? null)) {
            throw new \InvalidArgumentException('Передайте полный список организаций.');
        }
        $baseId = self::uuid($data->base_id ?? null);
        $versions = [];
        foreach (['config_name', 'config_version', 'platform_version', 'processor_version'] as $key) {
            $versions[$key] = self::text($data->{$key} ?? null);
            if ('config_name' !== $key && !preg_match('/^\d+(?:\.\d+)*$/D', $versions[$key])) {
                throw new \InvalidArgumentException('Версия должна состоять из чисел, разделённых точками.');
            }
        }
        if (isset($data->config_synonym)) {
            $versions['config_synonym'] = self::text($data->config_synonym);
        }
        $organizations = [];
        foreach ($data->organizations as $row) {
            if (!$row instanceof \stdClass) {
                throw new \InvalidArgumentException('Некорректная запись организации.');
            }
            $guid = self::uuid($row->guid ?? null);
            if (isset($organizations[$guid])) {
                throw new \InvalidArgumentException('GUID организации повторяется.');
            }
            $inn = self::taxId($row->inn ?? null, '/^(?:\d{10}|\d{12})$/D');
            $organizations[$guid] = [
                'guid' => $guid,
                'name' => self::text($row->name ?? null),
                'inn' => null !== $inn && 10 === \strlen($inn) ? $inn : null,
                'kpp' => self::taxId($row->kpp ?? null, '/^\d{4}[0-9A-Z]{2}\d{3}$/D'),
            ];
        }

        return new self($baseId, $versions, array_values($organizations));
    }

    private static function uuid(mixed $value): string
    {
        if (!\is_string($value) || !Uuid::isValid($value)) {
            throw new \InvalidArgumentException('Некорректный UUID базы или организации.');
        }

        return Uuid::fromString($value)->toString();
    }

    private static function text(mixed $value): string
    {
        if (!\is_string($value) || '' === trim($value) || mb_strlen($value) > 255 || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Некорректное название или версия.');
        }

        return trim($value);
    }

    private static function taxId(mixed $value, string $pattern): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value) || !preg_match($pattern, $value)) {
            throw new \InvalidArgumentException('Некорректный формат ИНН или КПП.');
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Marketplace\Domain\ValueObject;

/**
 * Неизменяемый Value Object для уникального ключа листинга маркетплейса.
 */
final readonly class ListingKey
{
    public const UNKNOWN_SIZE = 'UNKNOWN';

    private string $marketplaceSku;

    private string $size;

    /**
     * @param string      $marketplaceSku SKU листинга в маркетплейсе
     * @param string|null $size           Размер (если отсутствует/пустой, используется UNKNOWN)
     */
    public function __construct(string $marketplaceSku, ?string $size)
    {
        $this->marketplaceSku = trim($marketplaceSku);

        $normalizedSize = $size !== null ? trim($size) : null;
        $this->size = ($normalizedSize === null || $normalizedSize === '')
            ? self::UNKNOWN_SIZE
            : $normalizedSize;
    }

    /**
     * Возвращает SKU маркетплейса.
     */
    public function marketplaceSku(): string
    {
        return $this->marketplaceSku;
    }

    /**
     * Возвращает размер листинга.
     */
    public function size(): string
    {
        return $this->size;
    }

    /**
     * Возвращает строковое представление ключа в формате marketplaceSku:size.
     */
    public function toString(): string
    {
        return sprintf('%s:%s', $this->marketplaceSku, $this->size);
    }

    /**
     * Создаёт объект из строкового ключа формата marketplaceSku:size.
     */
    public static function fromString(string $key): self
    {
        [$marketplaceSku, $size] = array_pad(explode(':', $key), 2, null);

        return new self($marketplaceSku, $size);
    }
}

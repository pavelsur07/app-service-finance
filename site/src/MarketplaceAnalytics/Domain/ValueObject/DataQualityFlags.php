<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\ValueObject;

use App\MarketplaceAnalytics\Enum\DataQualityFlag;

final readonly class DataQualityFlags
{
    /**
     * @param DataQualityFlag[] $flags
     */
    public function __construct(
        public array $flags,
    ) {}

    public function hasFlag(DataQualityFlag $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    public function addFlag(DataQualityFlag $flag): self
    {
        if ($this->hasFlag($flag)) {
            return $this;
        }

        return new self([...$this->flags, $flag]);
    }

    public function isComplete(): bool
    {
        return empty($this->flags);
    }

    public function toArray(): array
    {
        return array_map(fn(DataQualityFlag $f) => $f->value, $this->flags);
    }

    public static function fromArray(array $data): self
    {
        return new self(array_map(fn($v) => DataQualityFlag::from($v), $data));
    }

    public static function empty(): self
    {
        return new self([]);
    }
}

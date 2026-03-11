<?php

declare(strict_types=1);

namespace App\Catalog\DTO;

final class ImportRowError
{
    public const REASON_DUPLICATE  = 'duplicate';
    public const REASON_VALIDATION = 'validation';

    public function __construct(
        public readonly int    $rowNumber,
        public readonly string $reason,
        public readonly string $message,
    ) {
    }

    public static function duplicate(int $rowNumber, string $message): self
    {
        return new self($rowNumber, self::REASON_DUPLICATE, $message);
    }

    public static function validation(int $rowNumber, string $message): self
    {
        return new self($rowNumber, self::REASON_VALIDATION, $message);
    }

    public function toArray(): array
    {
        return [
            'row'     => $this->rowNumber,
            'reason'  => $this->reason,
            'message' => $this->message,
        ];
    }
}

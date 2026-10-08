<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Performance;

/**
 * Счётчики, которые замеряемый код сообщает изнутри {@see PerformanceRecorder::measure()}.
 * Только объёмы — никаких значений строк, сумм или тел ответов.
 */
final class PerformanceProbe
{
    public ?int $rows = null;
    public ?int $bytes = null;

    public function rows(int $rows): self
    {
        $this->rows = $rows;

        return $this;
    }

    public function bytes(int|float|null $bytes): self
    {
        $this->bytes = null === $bytes ? null : (int) $bytes;

        return $this;
    }
}

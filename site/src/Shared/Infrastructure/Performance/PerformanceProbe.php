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

    /**
     * Принимает что угодно (например, `ResponseInterface::getInfo()` возвращает mixed):
     * нечисловое значение игнорируется — вызов из бизнес-кода не может бросить TypeError.
     */
    public function bytes(mixed $bytes): self
    {
        $this->bytes = \is_int($bytes) || \is_float($bytes) ? (int) $bytes : null;

        return $this;
    }
}

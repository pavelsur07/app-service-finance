<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use Psr\Clock\ClockInterface;

/**
 * Часы, которые двигаются на секунду при каждом обращении.
 *
 * Нужны, чтобы проверять момент СНЯТИЯ отметки, а не только её наличие:
 * подключение — это до тысячи последовательных HTTP-запросов, и одна общая
 * отметка приписала бы первому ответу время последнего. С неподвижными часами
 * такой дефект неотличим от исправного поведения.
 *
 * Шаг в секунду, хотя отметки наблюдения теперь хранят микросекунды
 * (`datetime_immutable_us`): секундная разница читается в ошибке теста без
 * вглядывания в шестой знак, а проверяемое свойство от величины шага не
 * зависит.
 */
final class AdvancingClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new \DateTimeImmutable();
    }

    public function now(): \DateTimeImmutable
    {
        $this->now = $this->now->modify('+1 second');

        return $this->now;
    }
}

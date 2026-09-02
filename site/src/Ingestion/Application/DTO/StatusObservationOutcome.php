<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Итог одного наблюдения статуса.
 *
 * Два ответа, а не один: «наблюдение принято» и «состояние изменилось» —
 * разные вопросы. Принятым считается любое наблюдение не старше текущей
 * отметки, в том числе повторяющее тот же статус; изменением — только то,
 * которое действительно сдвинуло статус или его сырую строку. Один общий
 * флаг превращал бы каждый успешный часовой опрос в «изменение» и делал
 * счётчик бесполезным ровно там, где он нужен: заметить, что опрос идёт, а
 * статусы почему-то стоят.
 */
final readonly class StatusObservationOutcome
{
    public function __construct(
        public bool $accepted,
        public bool $changed,
    ) {
    }

    public static function rejected(): self
    {
        return new self(false, false);
    }
}

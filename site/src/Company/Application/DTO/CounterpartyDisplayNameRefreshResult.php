<?php

declare(strict_types=1);

namespace App\Company\Application\DTO;

final class CounterpartyDisplayNameRefreshResult
{
    public int $processed = 0;

    /** Отображаемое название стало другим (в том числе появилось впервые). */
    public int $changed = 0;

    /** Было отображаемое название, по новой версии правил — нераспознано (входит в changed). */
    public int $becameUnrecognised = 0;

    public int $unchanged = 0;

    /** Строку переименовали или задали ручное название во время прогона — не записана. */
    public int $skippedConcurrent = 0;

    /** Правила не уверены — показывается сырое название (входит в changed/unchanged). */
    public int $unrecognised = 0;

    /**
     * Примеры для отчёта «было → станет» (не больше заданного числа).
     *
     * @var list<array{id: string, companyId: string, raw: string, before: ?string, after: string}>
     */
    public array $changedSamples = [];

    /**
     * Примеры нераспознанных — на ручной разбор или новое правило.
     *
     * @var list<array{id: string, companyId: string, raw: string}>
     */
    public array $unrecognisedSamples = [];
}

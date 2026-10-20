<?php

declare(strict_types=1);

namespace App\Company\Application\DTO;

final class CompanyOnboardingBackfillResult
{
    /** Просмотрено компаний. */
    public int $companies = 0;

    /**
     * Компании с пробелами онбординга: id → пункт → сколько записей недостаёт.
     *
     * @var array<string, array<string, int>>
     */
    public array $candidates = [];

    /**
     * Итог по пунктам среди всех кандидатов.
     *
     * @var array<string, int>
     */
    public array $totals = [];

    /** Компаний дозаполнено (только при execute). */
    public int $completed = 0;

    /** 1, если дозаполнение остановилось на ошибке. */
    public int $failed = 0;
}

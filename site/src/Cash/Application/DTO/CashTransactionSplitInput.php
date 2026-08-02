<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Одна строка формы разбивки.
 *
 * Сумма хранится строкой: приводить её к float ради формы значило бы терять копейки
 * ровно там, где они считаются.
 */
final class CashTransactionSplitInput
{
    #[Assert\NotBlank(message: 'Выберите статью ДДС.')]
    public ?string $cashflowCategoryId = null;

    #[Assert\NotBlank(message: 'Укажите сумму.')]
    #[Assert\Regex(
        // Ноль домен и так отвергает, но там это общая ошибка формы. Здесь она
        // привязана к полю, поэтому пользователь видит, какую строку править.
        pattern: '/^(?!0+([.,]0{1,2})?$)\d+([.,]\d{1,2})?$/',
        message: 'Сумма должна быть положительным числом не более чем с двумя знаками после запятой.',
    )]
    public ?string $amount = null;

    public function normalizedAmount(): string
    {
        return str_replace(',', '.', (string) $this->amount);
    }
}

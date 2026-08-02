<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Состав разбивки, пришедший из формы.
 *
 * Инварианты набора — равенство суммы, уникальность категорий, запрет на категории
 * с документами ОПиУ — проверяет агрегат в CashTransaction::replaceSplits(). Здесь
 * только то, что можно проверить до обращения к домену: строки есть, поля заполнены,
 * формат суммы корректен.
 */
final class CashTransactionSplitsInput
{
    /** @var list<CashTransactionSplitInput> */
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'Добавьте хотя бы одну строку разбивки.')]
    public array $rows = [];
}

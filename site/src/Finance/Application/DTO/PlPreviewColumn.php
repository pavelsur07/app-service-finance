<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

use Webmozart\Assert\Assert;

/**
 * Колонка предпросмотра ОПиУ: суммы по статьям в знаке документа marketplace_pl
 * (строка с is_negative уже со знаком «−»; сторно затрат — «+»).
 */
final readonly class PlPreviewColumn
{
    /**
     * @param array<string, string> $amountsByCategoryId plCategoryId → сумма (bcmath-строка)
     */
    public function __construct(
        public string $key,
        public array $amountsByCategoryId,
    ) {
        Assert::stringNotEmpty($key);
        foreach ($amountsByCategoryId as $amount) {
            Assert::numeric($amount);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Balance\Application\DTO;

/** Счёт Баланса для выбора в других модулях: неархивный, со всей цепочкой статей в активном состоянии. */
final readonly class BalancePostingAccount
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $articlePath,
        public bool $isAsset,
    ) {
    }

    public function label(): string
    {
        return $this->articlePath.' / '.$this->name;
    }
}

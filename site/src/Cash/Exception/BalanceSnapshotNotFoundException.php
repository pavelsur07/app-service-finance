<?php

declare(strict_types=1);

namespace App\Cash\Exception;

final class BalanceSnapshotNotFoundException extends \RuntimeException implements CashApiExceptionInterface
{
    public function __construct()
    {
        parent::__construct('Снапшот остатка за выбранную дату не найден. Выполните пересчёт остатков.');
    }

    public function errorCode(): string
    {
        return 'balance_snapshot_not_found';
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
